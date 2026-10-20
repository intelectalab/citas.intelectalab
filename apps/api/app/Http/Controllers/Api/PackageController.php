<?php

namespace App\Http\Controllers\Api;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\Service;
use App\Models\Tenant;
use App\Support\Access;
use App\Support\AppointmentFlow;
use App\Support\AppointmentScheduler;
use App\Support\Cash;
use App\Support\Currency;
use App\Support\Packages;
use App\Support\Sales;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PackageController extends AppointmentController
{
    private function ready(Request $request): Tenant
    {
        abort_unless(Packages::ready(), 503, Packages::NOT_READY);
        $tenant = $this->tenant($request);
        Packages::expireDue($tenant->id);

        return $tenant;
    }

    private function options(): array
    {
        $list = fn (array $labels) => collect($labels)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values();

        return [
            'modes'              => $list(Packages::MODES),
            'frequencies'        => $list(Packages::FREQUENCIES),
            'professional_modes' => $list(Packages::PROFESSIONAL_MODES),
            'late_policies'      => $list(Packages::LATE_POLICIES),
            'refund_policies'    => $list(Packages::REFUND_POLICIES),
            'statuses'           => $list(Packages::STATUS_LABELS),
            'weekdays'           => Packages::WEEKDAYS,
        ];
    }

    private function plans(Tenant $tenant): array
    {
        $plans = DB::table('package_plans')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->orderBy('name')->get();
        $items = Packages::planItems($plans->pluck('id')->all());
        $sold = DB::table('customer_packages')->where('tenant_id', $tenant->id)->whereIn('package_plan_id', $plans->pluck('id'))->groupBy('package_plan_id')->selectRaw('package_plan_id, count(*) as total')->pluck('total', 'package_plan_id');

        return $plans->map(fn ($p) => Packages::presentPlan($tenant, $p, $items[$p->id] ?? collect(), (int) ($sold[$p->id] ?? 0)))->values()->all();
    }

    public function index(Request $request)
    {
        $tenant = $this->ready($request);
        $user = $request->user();
        $customerId = $request->validate(['customer_id' => 'nullable|uuid'])['customer_id'] ?? null;

        $packages = DB::table('customer_packages')
            ->where('tenant_id', $tenant->id)
            ->when($customerId, fn ($q, $id) => $q->where('customer_id', $id))
            ->orderByDesc('purchased_at')
            ->limit(1000)
            ->get();

        return response()->json([
            'currency' => Currency::of($tenant),
            'packages' => Packages::presentMany($packages),
            'plans'    => $customerId ? [] : $this->plans($tenant),
            'options'  => $this->options(),
            'can'      => [
                'create' => $user->allows('packages.create'),
                'edit'   => $user->allows('packages.edit'),
                'delete' => $user->allows('packages.delete'),
                'use'    => $user->allows('packages.use'),
                'sell'   => $tenant->hasModule('pos') && $user->allows('pos.create'),
            ],
        ]);
    }

    public function show(Request $request, string $package)
    {
        $tenant = $this->ready($request);
        $row = DB::table('customer_packages')->where('tenant_id', $tenant->id)->where('id', $package)->first();
        abort_unless($row, 404);

        return response()->json($this->detail($request, $tenant, $row));
    }

    private function detail(Request $request, Tenant $tenant, object $row): array
    {
        $user = $request->user();
        $timezone = $tenant->zone();
        $customer = Customer::where('tenant_id', $tenant->id)->find($row->customer_id);
        $data = Packages::presentMany(collect([$row]))[0];
        $c = $data['conditions'];
        $iso = fn ($value) => $value ? Carbon::parse($value)->toIso8601String() : null;

        $usages = DB::table('package_usages as u')
            ->join('services as s', 's.id', '=', 'u.service_id')
            ->leftJoin('appointments as a', 'a.id', '=', 'u.appointment_id')
            ->leftJoin('professionals as p', 'p.id', '=', DB::raw('coalesce(u.professional_id, a.professional_id)'))
            ->leftJoin('users as us', 'us.id', '=', 'u.created_by')
            ->where('u.customer_package_id', $row->id)
            ->orderByDesc('u.used_at')
            ->get(['u.id', 'u.kind', 'u.note', 'u.used_at', 's.name as service', 'a.id as appointment_id', 'a.code as appointment_code', 'a.starts_at', 'p.id as professional_id', 'p.display_name', 'us.first_name', 'us.last_name'])
            ->map(fn ($u) => [
                'id'           => $u->id,
                'kind'         => $u->kind,
                'kind_label'   => Packages::USAGE_KINDS[$u->kind] ?? $u->kind,
                'note'         => $u->note,
                'at'           => $iso($u->starts_at ?? $u->used_at),
                'service'      => $u->service,
                'appointment'  => $u->appointment_id ? ['id' => $u->appointment_id, 'code' => $u->appointment_code] : null,
                'professional' => $u->professional_id ? ['id' => $u->professional_id, 'name' => $u->display_name] : null,
                'by'           => \App\Support\Person::name($u) ?: null,
            ]);

        $appointments = Appointment::where('customer_package_id', $row->id)
            ->whereNotIn('id', DB::table('package_usages')->whereNotNull('appointment_id')->select('appointment_id'))
            ->whereNotIn('status', AppointmentFlow::RELEASED)
            ->with(['service:id,name', 'professional:id,display_name', 'branch'])
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Appointment $a) => [
                'id'           => $a->id,
                'code'         => $a->code,
                'starts_at'    => $a->starts_at->copy()->setTimezone(AppointmentScheduler::timezone($a->branch ?? new Branch(), $tenant))->format(self::LOCAL_FORMAT),
                'status'       => $a->status,
                'status_name'  => AppointmentFlow::name($tenant, $a->status),
                'service'      => $a->service?->name,
                'professional' => $a->professional ? ['id' => $a->professional->id, 'name' => $a->professional->display_name] : null,
                'branch'       => $a->branch?->name,
            ]);

        $events = DB::table('customer_package_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.created_by')
            ->where('e.customer_package_id', $row->id)
            ->orderByDesc('e.created_at')
            ->get(['e.*', 'u.first_name', 'u.last_name'])
            ->map(fn ($e) => [
                'id'     => $e->id,
                'kind'   => $e->kind,
                'detail' => $e->detail,
                'amount' => $e->amount !== null ? round((float) $e->amount, 2) : null,
                'by'     => \App\Support\Person::name($e) ?: null,
                'at'     => $iso($e->created_at),
            ]);

        $sale = $row->sale_id ? DB::table('sales')->where('id', $row->sale_id)->first(['id', 'code', 'status', 'branch_id']) : null;
        $professional = $row->professional_id ? Professional::find($row->professional_id) : null;
        $branch = $row->branch_id ? Branch::find($row->branch_id) : null;
        $itemsForTerms = array_map(fn ($i) => ['name' => $i['name'], 'sessions' => $i['total'], 'price' => $i['unit_price']], $data['items']);

        return $data + [
            'customer'      => $customer ? ['id' => $customer->id, 'name' => \App\Support\Person::name($customer), 'phone' => $customer->phone] : null,
            'terms_text'    => $c['terms_text'] ?? Packages::terms($tenant, $c, $itemsForTerms, $data['currency']),
            'cancel_reason' => $row->cancel_reason,
            'cancelled_at'  => $iso($row->cancelled_at),
            'sale'          => $sale ? ['id' => $sale->id, 'code' => $sale->code, 'status' => $sale->status, 'status_label' => Sales::STATUS_LABELS[$sale->status] ?? $sale->status] : null,
            'branch'        => $branch ? ['id' => $branch->id, 'name' => $branch->name] : null,
            'professional'  => $professional ? ['id' => $professional->id, 'name' => $professional->display_name] : null,
            'usages'        => $usages,
            'appointments'  => $appointments,
            'events'        => $events,
            'refund'        => in_array($row->status, ['active', 'expired'], true) ? Packages::refundQuote($tenant, $row) : null,
            'refund_methods' => collect(Sales::METHODS)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'cash_sessions' => $sale && Sales::ready() ? Cash::openSessions($tenant->id, $sale->branch_id)->map(fn ($s) => ['id' => $s->id, 'register_name' => $s->register_name])->values() : [],
            'can'           => [
                'edit'     => $user->allows('packages.edit'),
                'delete'   => $user->allows('packages.delete'),
                'use'      => $user->allows('packages.use'),
                'schedule' => $user->allows('agenda.create') && $row->status === 'active',
            ],
            'today'         => now($timezone)->format('Y-m-d'),
        ];
    }

    private function planInput(Request $request, Tenant $tenant, ?string $ignoreId = null): array
    {
        $validated = $request->validate([
            'name'              => ['required', 'string', 'max:150', function ($attribute, $value, $fail) use ($tenant, $ignoreId) {
                $exists = DB::table('package_plans')->where('tenant_id', $tenant->id)->whereNull('deleted_at')
                    ->whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $value))])
                    ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists();

                if ($exists) {
                    $fail('Ya tienes un paquete con ese nombre.');
                }
            }],
            'description'       => 'nullable|string|max:500',
            'price'             => 'required|numeric|min:0.01|max:9999999',
            'validity_days'     => 'required|integer|min:1|max:3650',
            'status'            => ['nullable', Rule::in(['active', 'inactive'])],
            'booking_mode'      => ['required', Rule::in(array_keys(Packages::MODES))],
            'frequency'         => ['required', Rule::in(array_keys(Packages::FREQUENCIES))],
            'custom_days'       => 'nullable|array|max:7',
            'custom_days.*.weekday' => 'required|integer|min:0|max:6|distinct',
            'custom_days.*.time'    => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'repeat_weeks'      => ['nullable', Rule::in([1, 2])],
            'professional_mode' => ['required', Rule::in(array_keys(Packages::PROFESSIONAL_MODES))],
            'late_policy'       => ['required', Rule::in(array_keys(Packages::LATE_POLICIES))],
            'refund_policy'     => ['required', Rule::in(array_keys(Packages::REFUND_POLICIES))],
            'terms'             => 'nullable|string|max:2000',
            'items'             => 'required|array|min:1|max:10',
            'items.*.service_id' => ['required', 'uuid', 'distinct', Rule::exists('services', 'id')->where('tenant_id', $tenant->id)],
            'items.*.sessions'  => 'required|integer|min:1|max:200',
        ], [
            'name.required'          => 'Escribe el nombre del paquete.',
            'price.required'         => 'Escribe el precio del paquete.',
            'price.min'              => 'El precio debe ser mayor que cero.',
            'validity_days.required' => 'Escribe cuántos días vale el paquete.',
            'validity_days.min'      => 'La vigencia es de al menos 1 día.',
            'items.required'         => 'Agrega al menos un servicio.',
            'items.min'              => 'Agrega al menos un servicio.',
            'items.*.service_id.distinct' => 'Ese servicio ya está en el paquete: súmale sesiones en vez de repetirlo.',
            'items.*.service_id.exists'   => 'Uno de los servicios ya no existe.',
            'items.*.sessions.min'   => 'Cada servicio lleva al menos 1 sesión.',
            'custom_days.*.weekday.distinct' => 'Ese día ya está elegido.',
            'custom_days.*.time.regex' => 'Elige la hora con el reloj.',
        ]);

        if ($validated['booking_mode'] === 'scheduled' && count($validated['items']) > 1) {
            throw ValidationException::withMessages(['items' => 'Con fechas acordadas, el paquete lleva un solo servicio (por ejemplo, 10 sesiones de terapia). Para combinar servicios, elige "Libre".']);
        }

        if ($validated['booking_mode'] === 'scheduled' && $validated['frequency'] === 'custom' && empty($validated['custom_days'])) {
            throw ValidationException::withMessages(['custom_days' => 'Elige al menos un día de la semana.']);
        }

        return $validated;
    }

    private function savePlan(Tenant $tenant, array $data, ?string $id = null): string
    {
        $values = [
            'name'              => trim($data['name']),
            'description'       => trim((string) ($data['description'] ?? '')) ?: null,
            'price'             => round((float) $data['price'], 2),
            'currency'          => Currency::of($tenant),
            'validity_days'     => (int) $data['validity_days'],
            'status'            => $data['status'] ?? 'active',
            'booking_mode'      => $data['booking_mode'],
            'frequency'         => $data['frequency'],
            'custom_days'       => json_encode($data['frequency'] === 'custom' ? collect($data['custom_days'] ?? [])->map(fn ($d) => ['weekday' => (int) $d['weekday'], 'time' => $d['time'] ?? null])->sortBy('weekday')->values()->all() : []),
            'repeat_weeks'      => (int) ($data['repeat_weeks'] ?? 1),
            'professional_mode' => $data['professional_mode'],
            'late_policy'       => $data['late_policy'],
            'refund_policy'     => $data['refund_policy'],
            'terms'             => trim((string) ($data['terms'] ?? '')) ?: null,
            'updated_at'        => now(),
        ];

        return DB::transaction(function () use ($tenant, $data, $values, $id) {
            if ($id) {
                DB::table('package_plans')->where('id', $id)->update($values);
                DB::table('package_plan_items')->where('package_plan_id', $id)->delete();
            } else {
                $id = (string) Str::uuid();
                DB::table('package_plans')->insert($values + ['id' => $id, 'tenant_id' => $tenant->id, 'created_at' => now()]);
            }

            DB::table('package_plan_items')->insert(collect($data['items'])->values()->map(fn ($i, $position) => [
                'id'              => (string) Str::uuid(),
                'tenant_id'       => $tenant->id,
                'package_plan_id' => $id,
                'service_id'      => $i['service_id'],
                'sessions'        => (int) $i['sessions'],
                'position'        => $position,
            ])->all());

            return $id;
        });
    }

    private function onePlan(Tenant $tenant, string $id): array
    {
        return collect($this->plans($tenant))->firstWhere('id', $id);
    }

    public function storePlan(Request $request)
    {
        $tenant = $this->ready($request);
        $id = $this->savePlan($tenant, $this->planInput($request, $tenant));

        return response()->json($this->onePlan($tenant, $id), 201);
    }

    public function updatePlan(Request $request, string $plan)
    {
        $tenant = $this->ready($request);
        $row = DB::table('package_plans')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->where('id', $plan)->first();
        abort_unless($row, 404);
        $this->savePlan($tenant, $this->planInput($request, $tenant, $row->id), $row->id);

        return response()->json($this->onePlan($tenant, $row->id));
    }

    public function destroyPlan(Request $request, string $plan)
    {
        $tenant = $this->ready($request);
        $row = DB::table('package_plans')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->where('id', $plan)->first();
        abort_unless($row, 404);

        $sold = DB::table('customer_packages')->where('package_plan_id', $row->id)->exists();
        $inCart = DB::table('sale_items as i')->join('sales as s', 's.id', '=', 'i.sale_id')->where('i.item_type', 'package')->where('i.reference_id', $row->id)->whereIn('s.status', ['pending', 'partial'])->exists();

        if ($inCart) {
            throw ValidationException::withMessages(['plan' => 'Este paquete está en una venta que quedó por cobrar. Cóbrala o anúlala antes de eliminarlo.']);
        }

        if ($sold) {
            DB::table('package_plans')->where('id', $row->id)->update(['deleted_at' => now(), 'status' => 'inactive', 'updated_at' => now()]);
        } else {
            DB::table('package_plans')->where('id', $row->id)->delete();
        }

        return response()->json(['kept' => $sold]);
    }

    public function use(Request $request, string $package)
    {
        $tenant = $this->ready($request);
        $validated = $request->validate([
            'service_id' => 'required|uuid',
            'note'       => 'required|string|min:3|max:255',
        ], [
            'service_id.required' => 'Elige el servicio.',
            'note.required'       => 'Escribe por qué se registra a mano. Ej. "sesión atendida en una campaña, sin cita".',
            'note.min'            => 'Escribe un poco más sobre por qué se registra a mano.',
        ]);

        DB::transaction(fn () => Packages::manualUse($tenant, $package, $validated['service_id'], trim($validated['note']), $request->user()));

        return response()->json($this->detail($request, $tenant, DB::table('customer_packages')->where('id', $package)->first()));
    }

    public function extend(Request $request, string $package)
    {
        $tenant = $this->ready($request);
        $validated = $request->validate([
            'days'   => 'required|integer|min:1|max:365',
            'reason' => 'required|string|min:3|max:255',
        ], [
            'days.required'   => 'Escribe cuántos días más.',
            'reason.required' => 'Escribe el motivo. Ej. "estuvo con descanso médico".',
        ]);

        $row = DB::transaction(fn () => Packages::extend($tenant, $package, (int) $validated['days'], trim($validated['reason']), $request->user()));

        return response()->json($this->detail($request, $tenant, $row));
    }

    public function cancel(Request $request, string $package)
    {
        $tenant = $this->ready($request);
        $validated = $request->validate([
            'refund'          => 'required|boolean',
            'amount'          => 'nullable|required_if:refund,true|numeric|min:0.01|max:9999999',
            'method'          => ['nullable', 'required_if:refund,true', Rule::in(array_keys(Sales::METHODS))],
            'cash_session_id' => 'nullable|uuid',
            'reason'          => 'required|string|min:3|max:255',
        ], [
            'reason.required'    => 'Escribe por qué se anula. Ej. "se mudó de ciudad".',
            'amount.required_if' => 'Escribe cuánto se devuelve.',
            'method.required_if' => 'Elige cómo se devuelve el dinero.',
        ]);

        $result = DB::transaction(fn () => Packages::cancel($tenant, $package, $validated, $request->user()));

        return response()->json($this->detail($request, $tenant, DB::table('customer_packages')->where('id', $package)->first()) + ['result' => $result]);
    }

    private function slotInput(Request $request): array
    {
        return $request->validate([
            'slots'                   => 'required|array|min:1|max:' . Packages::MAX_SLOTS,
            'slots.*.date'            => 'required|date_format:Y-m-d',
            'slots.*.time'            => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'slots.*.professional_id' => 'nullable|uuid',
            'branch_id'               => 'nullable|uuid',
        ], [
            'slots.required' => 'Elige al menos una fecha.',
        ]);
    }

    public function schedule(Request $request, string $package)
    {
        $tenant = $this->ready($request);
        $validated = $this->slotInput($request);
        $row = DB::table('customer_packages')->where('tenant_id', $tenant->id)->where('id', $package)->first();
        abort_unless($row, 404);

        if ($row->status !== 'active') {
            throw ValidationException::withMessages(['slots' => "El paquete {$row->code} está " . mb_strtolower(Packages::STATUS_LABELS[$row->status] ?? $row->status) . '.']);
        }

        $branchId = $validated['branch_id'] ?? $row->branch_id;
        Access::assertBranch($request->user(), $branchId, true);

        if (Packages::presentMany(collect([$row]), false)[0]['sessions_available'] <= 0) {
            throw ValidationException::withMessages(['slots' => "Al paquete {$row->code} no le quedan sesiones libres para agendar."]);
        }

        $result = DB::transaction(fn () => Packages::book($tenant, DB::table('customer_packages')->where('id', $row->id)->lockForUpdate()->first(), $validated['slots'], $request->user(), $branchId));

        return response()->json($this->detail($request, $tenant, DB::table('customer_packages')->where('id', $row->id)->first()) + ['result' => $result]);
    }

    public function preview(Request $request)
    {
        $tenant = $this->ready($request);

        $validated = $request->validate([
            'branch_id'           => 'required|uuid',
            'customer_id'         => 'required|uuid',
            'plan_id'             => 'nullable|uuid',
            'package_id'          => 'nullable|uuid',
            'professional_id'     => 'nullable|uuid',
            'first_date'          => 'nullable|date_format:Y-m-d',
            'time'                => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'frequency'           => ['nullable', Rule::in(array_keys(Packages::FREQUENCIES))],
            'custom_days'         => 'nullable|array|max:7',
            'custom_days.*.weekday' => 'required|integer|min:0|max:6',
            'custom_days.*.time'  => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'repeat_weeks'        => ['nullable', Rule::in([1, 2])],
            'count'               => 'nullable|integer|min:1|max:' . Packages::MAX_SLOTS,
            'slots'               => 'nullable|array|max:' . Packages::MAX_SLOTS,
            'slots.*.date'        => 'nullable|date_format:Y-m-d',
            'slots.*.time'        => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'slots.*.professional_id' => 'nullable|uuid',
        ]);

        $branch = Branch::where('tenant_id', $tenant->id)->findOrFail($validated['branch_id']);
        Access::assertBranch($request->user(), $branch->id);
        Customer::where('tenant_id', $tenant->id)->findOrFail($validated['customer_id']);

        if (!empty($validated['package_id'])) {
            $package = DB::table('customer_packages')->where('tenant_id', $tenant->id)->where('id', $validated['package_id'])->first();
            abort_unless($package, 404);
            $c = Packages::conditions($package);
            $item = Packages::presentMany(collect([$package]), false)[0]['items'][0] ?? null;
            $serviceId = $item['service_id'] ?? null;
            $count = (int) ($item['available'] ?? 0);
            $expires = Carbon::parse($package->expires_at);
        } else {
            $plan = DB::table('package_plans')->where('tenant_id', $tenant->id)->where('id', $validated['plan_id'] ?? null)->first();
            abort_unless($plan, 404);
            $c = Packages::conditionsOf($plan);
            $first = Packages::planItems([$plan->id])[$plan->id]->first() ?? null;
            $serviceId = $first->service_id ?? null;
            $count = (int) ($first->sessions ?? 0);
            $expires = Packages::expiresFrom($tenant, now(), $c['validity_days']);
        }

        $service = Service::where('tenant_id', $tenant->id)->with('status')->findOrFail($serviceId);
        $c['frequency'] = $validated['frequency'] ?? $c['frequency'];
        $c['custom_days'] = $validated['custom_days'] ?? $c['custom_days'];
        $c['repeat_weeks'] = (int) ($validated['repeat_weeks'] ?? $c['repeat_weeks']);
        $count = min($count, (int) ($validated['count'] ?? $count));
        $timezone = AppointmentScheduler::timezone($branch, $tenant);

        $slots = $validated['slots'] ?? null;

        if ($slots === null) {
            $first = !empty($validated['first_date']) ? Carbon::createFromFormat('Y-m-d', $validated['first_date'], $timezone)->startOfDay() : now($timezone)->addDay()->startOfDay();
            $slots = Packages::generate($c, $first, $validated['time'] ?? null, $count);
        }

        $checked = Packages::check($tenant, $branch, $service, $validated['customer_id'], $slots, $validated['professional_id'] ?? null, $expires);

        return response()->json([
            'service'    => ['id' => $service->id, 'name' => $service->name, 'duration_minutes' => (int) $service->duration_minutes],
            'count'      => $count,
            'expires_at' => $expires->copy()->setTimezone($timezone)->format('Y-m-d'),
            'slots'      => $checked,
            'ok'         => collect($checked)->every(fn ($s) => $s['ok']),
            'professionals' => Professional::where('tenant_id', $tenant->id)
                ->whereIn('id', Access::professionalsAt($branch->id))
                ->when(DB::table('service_professionals')->where('service_id', $service->id)->pluck('professional_id')->all(), fn ($q, $ids) => $q->whereIn('id', $ids))
                ->with('status')->orderBy('display_name')->get()
                ->reject(fn (Professional $p) => $p->status?->slug === 'inactive')
                ->map(fn (Professional $p) => ['id' => $p->id, 'name' => $p->display_name])->values(),
        ]);
    }

    public function freeTimes(Request $request)
    {
        $tenant = $this->ready($request);

        $validated = $request->validate([
            'branch_id'       => 'required|uuid',
            'service_id'      => 'required|uuid',
            'date'            => 'required|date_format:Y-m-d',
            'professional_id' => 'nullable|uuid',
            'customer_id'     => 'nullable|uuid',
        ]);

        $branch = Branch::where('tenant_id', $tenant->id)->findOrFail($validated['branch_id']);
        Access::assertBranch($request->user(), $branch->id);
        $service = Service::where('tenant_id', $tenant->id)->with('status')->findOrFail($validated['service_id']);
        $customerId = !empty($validated['customer_id']) ? Customer::where('tenant_id', $tenant->id)->findOrFail($validated['customer_id'])->id : null;
        $professional = !empty($validated['professional_id']) ? Professional::where('tenant_id', $tenant->id)->with('status')->findOrFail($validated['professional_id']) : null;
        $date = Carbon::createFromFormat('Y-m-d', $validated['date'], AppointmentScheduler::timezone($branch, $tenant))->startOfDay();

        return response()->json(Packages::freeTimes($tenant, $branch, $service, $date, $professional, $customerId));
    }
}
