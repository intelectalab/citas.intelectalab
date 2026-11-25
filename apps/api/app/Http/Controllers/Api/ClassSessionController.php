<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Access;
use App\Support\AppointmentFlow;
use App\Support\AppointmentScheduler;
use App\Support\Audit;
use App\Support\ClassSessions;
use App\Support\Packages;
use App\Support\Person;
use App\Support\Sales;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClassSessionController extends Controller
{
    private function tenant(Request $request): Tenant
    {
        abort_unless(ClassSessions::ready(), 503, ClassSessions::NOT_READY);
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 403);

        return $tenant;
    }

    private function own(User $user): ?string
    {
        if (Access::scopeOf($user) !== 'own') {
            return null;
        }

        return Professional::where('tenant_id', $user->tenant_id)->where('user_id', $user->id)->value('id') ?? Access::NO_BRANCH;
    }

    private function session(Request $request, Tenant $tenant, string $id): object
    {
        $row = DB::table('class_sessions')->where('tenant_id', $tenant->id)->where('id', $id)->first();
        abort_unless($row, 404, 'No encontramos esa clase.');
        Access::assertBranch($request->user(), $row->branch_id);
        $own = $this->own($request->user());
        abort_if($own && $row->professional_id !== $own, 403, 'Solo puedes ver tus propias clases.');

        return $row;
    }

    private function enrollment(Request $request, Tenant $tenant, string $id): array
    {
        $row = DB::table('class_enrollments')->where('tenant_id', $tenant->id)->where('id', $id)->first();
        abort_unless($row, 404, 'No encontramos esa inscripción.');

        return [$row, $this->session($request, $tenant, $row->class_session_id)];
    }

    private function audit(Request $request, Tenant $tenant, string $action, object $session, array $after = []): void
    {
        $local = ClassSessions::local($tenant, $session, 'starts_at');

        Audit::log($request, $action, [
            'tenant'       => $tenant->id,
            'entity_type'  => 'class_session',
            'entity_id'    => $session->id,
            'entity_label' => "{$session->title} · " . $local->format('d/m/Y H:i'),
            'branch_id'    => $session->branch_id,
            'after'        => array_filter($after, fn ($value) => $value !== null),
        ]);
    }

    private function options(Request $request, Tenant $tenant): array
    {
        $user = $request->user();
        $limit = Access::branchLimit($user);
        $own = $this->own($user);

        $branches = Branch::where('tenant_id', $tenant->id)->where('status', 'active')->when($limit, fn ($q, $id) => $q->where('id', $id))->orderBy('name')->get(['id', 'name']);
        $services = Service::where('tenant_id', $tenant->id)->where('kind', Service::KIND_GROUP)->with('status')->orderBy('name')->get()
            ->reject(fn (Service $s) => $s->status?->slug === 'inactive');
        $assigned = DB::table('service_professionals')->whereIn('service_id', $services->pluck('id'))->get()->groupBy('service_id');
        $schedules = DB::table('professional_schedules')->where('tenant_id', $tenant->id)->get(['professional_id', 'branch_id'])->groupBy('professional_id');

        return [
            'branches'      => $branches->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values(),
            'services'      => $services->map(fn (Service $s) => [
                'id'               => $s->id,
                'name'             => $s->name,
                'capacity'         => (int) $s->capacity,
                'duration_minutes' => (int) $s->duration_minutes,
                'price'            => round((float) $s->price, 2),
                'branch_ids'       => $s->branch_ids,
                'professional_ids' => ($assigned[$s->id] ?? collect())->pluck('professional_id')->values(),
            ])->values(),
            'professionals' => Professional::where('tenant_id', $tenant->id)->with('status')->when($own, fn ($q, $id) => $q->where('id', $id))->orderBy('display_name')->get()
                ->reject(fn (Professional $p) => $p->status?->slug === 'inactive')
                ->map(fn (Professional $p) => ['id' => $p->id, 'display_name' => $p->display_name, 'branch_ids' => ($schedules[$p->id] ?? collect())->pluck('branch_id')->unique()->values()])->values(),
            'rooms'         => DB::table('rooms')->where('tenant_id', $tenant->id)->where('kind', 'room')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'branch_id'])
                ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'branch_id' => $r->branch_id])->values(),
        ];
    }

    private function can(Request $request, Tenant $tenant): array
    {
        $user = $request->user();

        return [
            'create'   => $user->allows('classes.create'),
            'edit'     => $user->allows('classes.edit'),
            'cancel'   => $user->allows('classes.cancel'),
            'charge'   => $tenant->hasModule('pos') && Sales::ready() && $user->allows('pos.create'),
            'packages' => Packages::enabled($tenant) && $user->allows('packages.use'),
            'override' => (bool) AppointmentFlow::rule($tenant, 'agenda.allow_override') && $user->allows('agenda.override'),
            'customers' => $user->allows('customers.create'),
        ];
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);
        $validated = $request->validate([
            'from'      => 'nullable|date_format:Y-m-d',
            'to'        => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'branch_id' => 'nullable|uuid',
        ]);
        $zone = $tenant->zone();
        $from = isset($validated['from']) ? Carbon::createFromFormat('Y-m-d', $validated['from'], $zone)->startOfDay() : now($zone)->startOfDay()->subDays(30);
        $to = isset($validated['to']) ? Carbon::createFromFormat('Y-m-d', $validated['to'], $zone)->startOfDay()->addDay() : now($zone)->startOfDay()->addDays(365);
        abort_if($from->diffInDays($to) > 400, 422, 'Se puede ver hasta un año a la vez.');

        $limit = Access::branchLimit($request->user());
        $own = $this->own($request->user());

        $rows = DB::table('class_sessions')
            ->where('tenant_id', $tenant->id)
            ->when($limit, fn ($q, $id) => $q->where('branch_id', $id))
            ->when(!$limit && !empty($validated['branch_id']), fn ($q) => $q->where('branch_id', $validated['branch_id']))
            ->when($own, fn ($q, $id) => $q->where('professional_id', $id))
            ->where('starts_at', '>=', $from->toIso8601String())
            ->where('starts_at', '<', $to->toIso8601String())
            ->orderBy('starts_at')
            ->limit(1000)
            ->get();

        return response()->json([
            'classes' => ClassSessions::presentMany($tenant, $rows),
            'options' => $this->options($request, $tenant),
            'can'     => $this->can($request, $tenant),
            'rules'   => [
                'waitlist'     => (bool) AppointmentFlow::rule($tenant, 'classes.waitlist'),
                'auto_promote' => (bool) AppointmentFlow::rule($tenant, 'classes.auto_promote'),
            ],
            'max_weeks' => ClassSessions::MAX_WEEKS,
        ]);
    }

    public function show(Request $request, string $class)
    {
        $tenant = $this->tenant($request);
        $row = $this->session($request, $tenant, $class);

        return response()->json($this->detail($request, $tenant, $row));
    }

    private function detail(Request $request, Tenant $tenant, object $row, array $extra = []): array
    {
        $row = DB::table('class_sessions')->where('id', $row->id)->first();
        $enrollments = DB::table('class_enrollments')
            ->where('class_session_id', $row->id)
            ->orderByRaw("CASE status WHEN 'waitlist' THEN 1 WHEN 'cancelled' THEN 2 ELSE 0 END")
            ->orderBy('created_at')
            ->get();

        $series = $row->series_id
            ? DB::table('class_sessions')->where('tenant_id', $tenant->id)->where('series_id', $row->series_id)->where('id', '!=', $row->id)->where('status', 'scheduled')->where('starts_at', '>', now()->toIso8601String())->orderBy('starts_at')->limit(12)->get()
            : collect();

        return ClassSessions::presentMany($tenant, collect([$row]))[0] + [
            'enrollments' => ClassSessions::presentEnrollments($tenant, $enrollments, $row),
            'series'      => ClassSessions::presentMany($tenant, $series),
            'options'     => $this->options($request, $tenant),
            'can'         => $this->can($request, $tenant),
            'rules'       => ['waitlist' => (bool) AppointmentFlow::rule($tenant, 'classes.waitlist')],
        ] + $extra;
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'service_id'      => [$creating ? 'required' : 'sometimes', 'uuid'],
            'professional_id' => [$creating ? 'required' : 'sometimes', 'uuid'],
            'branch_id'       => [$creating ? 'required' : 'sometimes', 'uuid'],
            'room_id'         => 'nullable|uuid',
            'date'            => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'time'            => [$creating ? 'required' : 'sometimes', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'capacity'        => 'nullable|integer|min:1|max:500',
            'repeat_weeks'    => 'nullable|integer|min:1|max:' . ClassSessions::MAX_WEEKS,
            'online_booking'  => 'nullable|boolean',
            'notes'           => 'nullable|string|max:1000',
            'force'           => 'nullable|boolean',
        ], [
            'time.regex' => 'Escribe la hora como 19:00.',
        ]);
    }

    private function resolve(Request $request, Tenant $tenant, array $data): array
    {
        $branch = Branch::where('tenant_id', $tenant->id)->find($data['branch_id']);
        if (!$branch) {
            throw ValidationException::withMessages(['branch_id' => 'Elige la sede de la clase.']);
        }
        Access::assertBranch($request->user(), $branch->id, true);

        $service = Service::where('tenant_id', $tenant->id)->with('status')->find($data['service_id']);
        if (!$service || $service->kind !== Service::KIND_GROUP) {
            throw ValidationException::withMessages(['service_id' => 'Elige un servicio marcado como clase grupal (en Servicios).']);
        }

        $professional = Professional::where('tenant_id', $tenant->id)->with('status')->find($data['professional_id']);
        if (!$professional) {
            throw ValidationException::withMessages(['professional_id' => 'Elige quién da la clase.']);
        }

        $own = $this->own($request->user());
        abort_if($own && $professional->id !== $own, 403, 'Solo puedes programar tus propias clases.');

        if (!empty($data['room_id']) && !DB::table('rooms')->where('tenant_id', $tenant->id)->where('id', $data['room_id'])->where('branch_id', $branch->id)->exists()) {
            throw ValidationException::withMessages(['room_id' => 'Esa sala no es de esta sede.']);
        }

        return [$branch, $service, $professional];
    }

    private function reasons(array $check): string
    {
        return collect($check['reasons'])->map(fn ($r) => $r['message'] . ($r['detail'] ? " ({$r['detail']})" : ''))->implode(' · ');
    }

    public function store(Request $request)
    {
        $tenant = $this->tenant($request);
        $data = $this->validated($request, true);
        [$branch, $service, $professional] = $this->resolve($request, $tenant, $data);
        $zone = AppointmentScheduler::timezone($branch, $tenant);
        $weeks = (int) ($data['repeat_weeks'] ?? 1);
        $capacity = (int) ($data['capacity'] ?? $service->capacity);
        $force = !empty($data['force']) && $this->can($request, $tenant)['override'];
        $first = Carbon::createFromFormat('Y-m-d H:i', "{$data['date']} {$data['time']}", $zone);

        $result = DB::transaction(function () use ($tenant, $branch, $service, $professional, $data, $weeks, $capacity, $force, $first, $request) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['classes:' . $tenant->id]);
            $series = $weeks > 1 ? (string) Str::uuid() : null;
            $created = [];
            $skipped = [];

            for ($week = 0; $week < $weeks; $week++) {
                $start = $first->copy()->addWeeks($week);
                $check = ClassSessions::check($tenant, $service, $professional, $branch, $data['room_id'] ?? null, $start);

                if (!$check['ok'] && !($force && $check['overridable'])) {
                    if ($weeks === 1) {
                        throw ValidationException::withMessages(['time' => 'No se puede programar a esa hora: ' . $this->reasons($check) . '.'])->status(409);
                    }
                    $skipped[] = ['date' => $start->toDateString(), 'reason' => $this->reasons($check)];
                    continue;
                }

                $id = (string) Str::uuid();
                DB::table('class_sessions')->insert([
                    'id'              => $id,
                    'tenant_id'       => $tenant->id,
                    'branch_id'       => $branch->id,
                    'service_id'      => $service->id,
                    'professional_id' => $professional->id,
                    'room_id'         => $data['room_id'] ?? null,
                    'series_id'       => $series,
                    'title'           => $service->name,
                    'starts_at'       => $start->copy()->utc(),
                    'ends_at'         => $start->copy()->addMinutes((int) $service->duration_minutes)->utc(),
                    'capacity'        => $capacity,
                    'online_booking'  => array_key_exists('online_booking', $data) ? (bool) $data['online_booking'] : (bool) $service->online_booking,
                    'notes'           => $data['notes'] ?? null,
                    'created_by'      => $request->user()->id,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
                $created[] = $id;
            }

            return compact('created', 'skipped');
        });

        if ($result['created'] === []) {
            throw ValidationException::withMessages(['time' => 'No se pudo programar ninguna clase: ' . collect($result['skipped'])->map(fn ($s) => Carbon::parse($s['date'])->format('d/m') . ": {$s['reason']}")->implode('; ') . '.'])->status(409);
        }

        $first = DB::table('class_sessions')->where('id', $result['created'][0])->first();
        $this->audit($request, $tenant, 'class.created', $first, ['capacity' => $capacity, 'weeks' => count($result['created'])]);

        return response()->json([
            'class'   => ClassSessions::presentMany($tenant, collect([$first]))[0],
            'created' => count($result['created']),
            'skipped' => $result['skipped'],
        ], 201);
    }

    public function update(Request $request, string $class)
    {
        $tenant = $this->tenant($request);
        $row = $this->session($request, $tenant, $class);
        abort_if($row->status === 'cancelled', 422, 'Esta clase está cancelada: ya no se cambia.');
        abort_if(Carbon::parse($row->ends_at)->isPast(), 422, 'La clase ya terminó: ya no se cambia.');

        $input = $this->validated($request, false);
        $zone = ClassSessions::local($tenant, $row, 'starts_at')->getTimezone();
        $current = Carbon::parse($row->starts_at)->setTimezone($zone);
        $data = $input + [
            'service_id'      => $row->service_id,
            'professional_id' => $row->professional_id,
            'branch_id'       => $row->branch_id,
            'date'            => $current->toDateString(),
            'time'            => $current->format('H:i'),
        ];
        if (!array_key_exists('room_id', $input)) {
            $data['room_id'] = $row->room_id;
        }

        [$branch, $service, $professional] = $this->resolve($request, $tenant, $data);
        $start = Carbon::createFromFormat('Y-m-d H:i', "{$data['date']} {$data['time']}", AppointmentScheduler::timezone($branch, $tenant));
        $capacity = (int) ($data['capacity'] ?? $row->capacity);
        $taken = ClassSessions::counts([$row->id])[$row->id]['taken'];

        if ($capacity < $taken) {
            throw ValidationException::withMessages(['capacity' => "Ya hay {$taken} inscritos: el cupo no puede ser menor."]);
        }

        $moved = !$start->equalTo($current) || $professional->id !== $row->professional_id || ($data['room_id'] ?? null) !== $row->room_id || $branch->id !== $row->branch_id;

        if ($moved) {
            $check = ClassSessions::check($tenant, $service, $professional, $branch, $data['room_id'] ?? null, $start, $row->id);
            $force = !empty($data['force']) && $this->can($request, $tenant)['override'];

            if (!$check['ok'] && !($force && $check['overridable'])) {
                throw ValidationException::withMessages(['time' => 'No se puede mover a esa hora: ' . $this->reasons($check) . '.'])->status(409);
            }
        }

        DB::table('class_sessions')->where('id', $row->id)->update([
            'branch_id'       => $branch->id,
            'professional_id' => $professional->id,
            'room_id'         => $data['room_id'] ?? null,
            'starts_at'       => $start->copy()->utc(),
            'ends_at'         => $start->copy()->addMinutes((int) $service->duration_minutes)->utc(),
            'capacity'        => $capacity,
            'online_booking'  => array_key_exists('online_booking', $input) ? (bool) $input['online_booking'] : (bool) $row->online_booking,
            'notes'           => array_key_exists('notes', $input) ? $input['notes'] : $row->notes,
            'updated_at'      => now(),
        ]);

        $fresh = DB::table('class_sessions')->where('id', $row->id)->first();
        $promoted = 0;

        if ($capacity > (int) $row->capacity && AppointmentFlow::rule($tenant, 'classes.auto_promote') && Carbon::parse($fresh->starts_at)->isFuture()) {
            DB::transaction(function () use ($tenant, $fresh, &$promoted) {
                ClassSessions::lock($tenant->id, $fresh->id);
                while (ClassSessions::promoteNext($tenant, $fresh)) {
                    $promoted++;
                }
            });
        }

        $this->audit($request, $tenant, 'class.updated', $fresh, Audit::changed((array) $row, (array) $fresh) ? ['moved' => $moved ? 'sí' : null, 'capacity' => $capacity] : []);

        return response()->json($this->detail($request, $tenant, $fresh, ['result' => ['promoted' => $promoted]]));
    }

    public function cancel(Request $request, string $class)
    {
        $tenant = $this->tenant($request);
        $row = $this->session($request, $tenant, $class);
        $reason = trim((string) $request->validate(['reason' => 'required|string|min:3|max:255'], [
            'reason.required' => 'Escribe por qué se cancela: se les avisa a los inscritos.',
            'reason.min'      => 'Escribe por qué se cancela: se les avisa a los inscritos.',
        ])['reason']);

        $result = ClassSessions::cancel($tenant, $row->id, $reason, $request->user());
        $this->audit($request, $tenant, 'class.cancelled', $row, ['reason' => $reason, 'notified' => $result['notified']]);

        return response()->json($this->detail($request, $tenant, $row, ['result' => ['notified' => $result['notified'], 'paid_sales' => $result['paid_sales']]]));
    }

    public function enroll(Request $request, string $class)
    {
        $tenant = $this->tenant($request);
        $row = $this->session($request, $tenant, $class);
        $customerId = $request->validate(['customer_id' => 'required|uuid'], ['customer_id.required' => 'Elige a quién inscribir.'])['customer_id'];
        $customer = Customer::where('tenant_id', $tenant->id)->find($customerId);

        if (!$customer) {
            throw ValidationException::withMessages(['customer_id' => 'No encontramos a esa persona.']);
        }

        $result = ClassSessions::enroll($tenant, $row->id, $customer, 'staff', $request->user());
        $this->audit($request, $tenant, $result['enrollment']->status === 'waitlist' ? 'class.waitlisted' : 'class.enrolled', $row, ['customer' => Person::name($customer)]);

        return response()->json($this->detail($request, $tenant, $row, ['result' => ['status' => $result['enrollment']->status]]), 201);
    }

    public function leave(Request $request, string $enrollment)
    {
        $tenant = $this->tenant($request);
        [$row, $session] = $this->enrollment($request, $tenant, $enrollment);
        $result = ClassSessions::leave($tenant, $row->id, $request->user());
        $customer = Customer::where('tenant_id', $tenant->id)->find($row->customer_id);
        $this->audit($request, $tenant, 'class.left', $session, ['customer' => Person::name($customer)]);
        $promoted = $result['promoted'] ? Person::name(Customer::find($result['promoted']->customer_id)) : null;

        return response()->json($this->detail($request, $tenant, $session, ['result' => ['promoted' => $promoted]]));
    }

    public function promote(Request $request, string $enrollment)
    {
        $tenant = $this->tenant($request);
        [$row, $session] = $this->enrollment($request, $tenant, $enrollment);
        abort_if($row->status !== 'waitlist', 422, 'Esta persona no está en la lista de espera.');
        abort_if($session->status === 'cancelled', 422, 'La clase se canceló.');

        $done = DB::transaction(function () use ($tenant, $session, $row) {
            $locked = ClassSessions::lock($tenant->id, $session->id);

            return ClassSessions::promoteNext($tenant, $locked, $row->id);
        });

        if (!$done) {
            throw ValidationException::withMessages(['enrollment' => 'La clase está completa: sube el cupo o espera a que alguien salga.']);
        }

        $this->audit($request, $tenant, 'class.promoted', $session, ['customer' => Person::name(Customer::find($row->customer_id))]);

        return response()->json($this->detail($request, $tenant, $session));
    }

    public function attendance(Request $request, string $enrollment)
    {
        $tenant = $this->tenant($request);
        [$row, $session] = $this->enrollment($request, $tenant, $enrollment);
        $status = $request->validate(['status' => ['required', Rule::in(['attended', 'no_show', 'enrolled'])]])['status'];
        ClassSessions::mark($tenant, $row->id, $status);

        if ($status === 'no_show') {
            $this->audit($request, $tenant, 'class.no_show', $session, ['customer' => Person::name(Customer::find($row->customer_id))]);
        }

        return response()->json($this->detail($request, $tenant, $session));
    }

    public function attendAll(Request $request, string $class)
    {
        $tenant = $this->tenant($request);
        $row = $this->session($request, $tenant, $class);
        abort_if(Carbon::parse($row->starts_at)->isFuture(), 422, 'La asistencia se marca cuando la clase ya empezó.');
        abort_if($row->status === 'cancelled', 422, 'La clase se canceló.');

        $count = DB::table('class_enrollments')->where('class_session_id', $row->id)->where('status', 'enrolled')
            ->update(['status' => 'attended', 'attended_at' => now(), 'updated_at' => now()]);

        return response()->json($this->detail($request, $tenant, $row, ['result' => ['marked' => $count]]));
    }

    public function charge(Request $request, string $enrollment)
    {
        $tenant = $this->tenant($request);
        abort_unless($this->can($request, $tenant)['charge'], 403, 'Para cobrar necesitas el punto de venta y el permiso de vender.');
        [$row, $session] = $this->enrollment($request, $tenant, $enrollment);
        $sale = ClassSessions::charge($tenant, $row->id, $request->user());
        $this->audit($request, $tenant, 'class.charged', $session, ['customer' => Person::name(Customer::find($row->customer_id)), 'sale' => $sale->code]);

        return response()->json($this->detail($request, $tenant, $session, ['result' => ['sale' => ['id' => $sale->id, 'code' => $sale->code, 'status' => $sale->status, 'total' => round((float) $sale->total, 2)]]]));
    }

    public function usePackage(Request $request, string $enrollment)
    {
        $tenant = $this->tenant($request);
        abort_unless($this->can($request, $tenant)['packages'], 403, 'Necesitas el permiso de usar paquetes.');
        [$row, $session] = $this->enrollment($request, $tenant, $enrollment);
        $packageId = $request->validate(['package_id' => 'nullable|uuid'])['package_id'] ?? null;
        ClassSessions::usePackage($tenant, $row->id, $packageId, $request->user());
        $this->audit($request, $tenant, 'class.package_used', $session, ['customer' => Person::name(Customer::find($row->customer_id))]);

        return response()->json($this->detail($request, $tenant, $session));
    }
}
