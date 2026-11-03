<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerDependent;
use App\Models\Professional;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\WalkinEntry;
use App\Support\Access;
use App\Support\AppointmentFlow;
use App\Support\Audit;
use App\Support\Branding;
use App\Support\Currency;
use App\Support\Notifier;
use App\Support\Walkin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WalkinController extends Controller
{
    private const HISTORY_DAYS = 90;

    private const RELATIONS = ['customer', 'dependent', 'service', 'professional', 'branch', 'appointment'];

    private function tenant(Request $request): Tenant
    {
        $user = $request->user();
        abort_unless($user?->tenant_id, 403);
        abort_unless(Walkin::ready(), 503, Walkin::NOT_READY);

        return $user->tenant;
    }

    private function ownProfessionalId(Request $request, Tenant $tenant): ?string
    {
        $user = $request->user();
        if (!$user || Access::scopeOf($user) !== 'own') {
            return null;
        }

        return Professional::where('tenant_id', $tenant->id)->where('user_id', $user->id)->value('id') ?: Access::NO_BRANCH;
    }

    private function find(Request $request, Tenant $tenant, WalkinEntry $walkin): WalkinEntry
    {
        abort_unless($walkin->tenant_id === $tenant->id, 404);
        Access::assertBranch($request->user(), $walkin->branch_id);

        return $walkin;
    }

    private function activeServices(Tenant $tenant)
    {
        return Service::where('tenant_id', $tenant->id)
            ->where('kind', Service::KIND_INDIVIDUAL)
            ->with('status')
            ->orderBy('name')
            ->get()
            ->reject(fn (Service $s) => $s->status?->slug === 'inactive')
            ->values();
    }

    private function activeService(Tenant $tenant, string $id): Service
    {
        $service = $this->activeServices($tenant)->firstWhere('id', $id);
        abort_unless($service, 422, 'Ese servicio no está disponible.');

        return $service;
    }

    private function preferred(Tenant $tenant, Branch $branch, Service $service, ?string $id): ?Professional
    {
        if (!$id) {
            return null;
        }

        $professional = Walkin::workingToday($tenant, $branch)->firstWhere('id', $id);
        abort_unless($professional && Walkin::canDo($service, $professional), 422, 'Esa persona no atiende hoy este servicio en esta sede.');

        return $professional;
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);
        $branchLimit = Access::branchLimit($request->user());
        $ownPro = $this->ownProfessionalId($request, $tenant);
        $history = $request->query('view') === 'history';

        $branches = Branch::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->when($branchLimit, fn ($q) => $q->where('id', $branchLimit))
            ->orderBy('name')
            ->get(['id', 'name', 'timezone']);

        $chosen = $request->query('branch_id');
        $scope = $chosen && $branches->contains('id', $chosen) ? $branches->where('id', $chosen) : $branches;

        $today = Carbon::now($tenant->zone())->startOfDay();
        $from = $history ? $today->copy()->subDays(self::HISTORY_DAYS) : $today;
        $to = $history ? $today : $today->copy()->addDay();

        $entries = WalkinEntry::with(self::RELATIONS)
            ->where('tenant_id', $tenant->id)
            ->whereIn('branch_id', $scope->pluck('id'))
            ->when($ownPro, fn ($q) => $q->where(fn ($sub) => $sub->whereNull('professional_id')->orWhere('professional_id', $ownPro)))
            ->where('arrived_at', '>=', $from->toIso8601String())
            ->where('arrived_at', '<', $to->toIso8601String())
            ->orderByDesc('arrived_at')
            ->limit(2000)
            ->get()
            ->sortBy([['arrived_at', 'asc'], ['created_at', 'asc']])
            ->values();

        $estimates = [];
        if (!$history) {
            foreach ($scope as $branch) {
                $estimates += Walkin::estimates($tenant, $branch);
            }
        }

        $noShows = $this->noShowCounts($tenant, $entries->pluck('customer_id')->filter()->unique()->values()->all());

        $todayCounts = $history
            ? WalkinEntry::where('tenant_id', $tenant->id)
                ->whereIn('branch_id', $scope->pluck('id'))
                ->when($ownPro, fn ($q) => $q->where(fn ($sub) => $sub->whereNull('professional_id')->orWhere('professional_id', $ownPro)))
                ->where('arrived_at', '>=', $today->toIso8601String())
                ->groupBy('status')
                ->selectRaw('status, count(*) as total')
                ->pluck('total', 'status')
                ->all()
            : $entries->countBy('status')->all();

        $served = $entries->whereIn('status', [WalkinEntry::SERVING, WalkinEntry::DONE])->whereNotNull('waited_minutes');

        return response()->json([
            'entries' => $entries->map(fn (WalkinEntry $e) => $this->present($e, $tenant, $estimates[$e->id] ?? null, $noShows))->values(),
            'summary' => [
                'waiting'          => (int) ($todayCounts[WalkinEntry::WAITING] ?? 0),
                'serving'          => (int) ($todayCounts[WalkinEntry::SERVING] ?? 0),
                'done'             => (int) ($todayCounts[WalkinEntry::DONE] ?? 0),
                'left'             => (int) ($todayCounts[WalkinEntry::LEFT] ?? 0),
                'avg_wait_minutes' => $served->isNotEmpty() ? (int) round((float) $served->avg('waited_minutes')) : null,
            ],
            'context' => [
                'branches'      => $branches->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name])->values(),
                'services'      => $this->activeServices($tenant)->map(fn (Service $s) => [
                    'id'               => $s->id,
                    'name'             => $s->name,
                    'branch_ids'       => $s->branch_ids,
                    'duration_minutes' => (int) $s->duration_minutes,
                    'price'            => (float) $s->price,
                ])->values(),
                'professionals' => Professional::where('tenant_id', $tenant->id)
                    ->when($branchLimit, fn ($q, $id) => $q->whereIn('id', Access::professionalsAt($id)))
                    ->orderBy('display_name')
                    ->get(['id', 'display_name']),
                'dependents'    => $tenant->dependents(),
                'rules'         => Walkin::rules($tenant),
                'kiosk_url'     => $tenant->code ? '/kiosko/' . $tenant->code : null,
                'history_days'  => self::HISTORY_DAYS,
            ],
        ]);
    }

    public function choices(Request $request)
    {
        $tenant = $this->tenant($request);

        $validated = $request->validate([
            'branch_id'  => 'required|uuid',
            'service_id' => 'required|uuid',
            'walkin_id'  => 'nullable|uuid',
        ]);

        Access::assertBranch($request->user(), $validated['branch_id']);
        $branch = Branch::where('tenant_id', $tenant->id)->where('status', 'active')->findOrFail($validated['branch_id']);
        $service = $this->activeService($tenant, $validated['service_id']);

        return response()->json(Walkin::choices($tenant, $branch, $service, $validated['walkin_id'] ?? null));
    }

    public function store(Request $request)
    {
        $tenant = $this->tenant($request);
        $dependentsRule = $tenant->dependents();

        $validated = $request->validate([
            'branch_id'             => 'required|uuid',
            'customer_id'           => 'nullable|uuid',
            'first_name'            => 'required_without:customer_id|nullable|string|max:100',
            'last_name'             => 'nullable|string|max:100',
            'phone'                 => 'nullable|string|max:40',
            'customer_dependent_id' => ($dependentsRule['enabled'] && $dependentsRule['required']) ? 'required|uuid' : 'nullable|uuid',
            'service_id'            => 'required|uuid',
            'professional_id'       => 'nullable|uuid',
            'notes'                 => 'nullable|string|max:500',
        ], [
            'branch_id.required'             => 'Elige la sede.',
            'first_name.required_without'    => 'Elige o escribe el nombre de quien espera.',
            'customer_dependent_id.required' => 'Elige a quién se atenderá.',
            'service_id.required'            => 'Elige el servicio.',
        ]);

        Access::assertBranch($request->user(), $validated['branch_id']);
        $branch = Branch::where('tenant_id', $tenant->id)->where('status', 'active')->findOrFail($validated['branch_id']);
        $service = $this->activeService($tenant, $validated['service_id']);
        $professional = $this->preferred($tenant, $branch, $service, $validated['professional_id'] ?? null);

        $result = DB::transaction(function () use ($request, $tenant, $branch, $service, $professional, $validated) {
            Walkin::lock($branch);

            $customer = $this->resolveCustomer($tenant, $validated);

            if (!empty($validated['customer_dependent_id'])) {
                abort_unless(
                    CustomerDependent::where('tenant_id', $tenant->id)->where('customer_id', $customer->id)->whereKey($validated['customer_dependent_id'])->exists(),
                    422,
                    'Esa persona no está registrada a nombre de quien espera.',
                );
            }

            if ($duplicate = $this->activeToday($tenant, $branch, $customer)) {
                return response()->json([
                    'code'    => 'DUPLICATE',
                    'message' => "Esta persona ya está en la cola de hoy con el turno {$duplicate->ticket}.",
                ], 422);
            }

            return WalkinEntry::create([
                'tenant_id'             => $tenant->id,
                'branch_id'             => $branch->id,
                'customer_id'           => $customer->id,
                'customer_dependent_id' => $validated['customer_dependent_id'] ?? null,
                'service_id'            => $service->id,
                'professional_id'       => $professional?->id,
                'ticket'                => Walkin::nextTicket($tenant, $branch),
                'status'                => WalkinEntry::WAITING,
                'source'                => 'staff',
                'notes'                 => $validated['notes'] ?? null,
                'arrived_at'            => now(),
                'created_by'            => $request->user()->id,
            ]);
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $entry = $result->load(self::RELATIONS);
        $estimate = Walkin::estimates($tenant, $branch)[$entry->id] ?? null;

        Notifier::walkinAdded($tenant, $entry, $estimate['wait_minutes'] ?? null);
        $this->audit($request, 'walkin.created', $entry, ['professional' => $professional?->display_name ?? 'El primero libre']);

        return response()->json([
            'message' => "Anotado en la cola con el turno {$entry->ticket}.",
            'data'    => $this->present($entry, $tenant, $estimate),
        ], 201);
    }

    public function update(Request $request, WalkinEntry $walkin)
    {
        $tenant = $this->tenant($request);
        $this->find($request, $tenant, $walkin);
        abort_unless($walkin->status === WalkinEntry::WAITING, 422, 'Solo se cambia a quien sigue en espera.');

        $validated = $request->validate([
            'service_id'            => 'required|uuid',
            'professional_id'       => 'nullable|uuid',
            'customer_dependent_id' => 'nullable|uuid',
            'notes'                 => 'nullable|string|max:500',
        ]);

        $walkin->loadMissing('branch');
        $service = $this->activeService($tenant, $validated['service_id']);
        $professional = $this->preferred($tenant, $walkin->branch, $service, $validated['professional_id'] ?? null);

        if (!empty($validated['customer_dependent_id'])) {
            abort_unless(
                CustomerDependent::where('tenant_id', $tenant->id)->where('customer_id', $walkin->customer_id)->whereKey($validated['customer_dependent_id'])->exists(),
                422,
                'Esa persona no está registrada a nombre de quien espera.',
            );
        }

        $walkin->update([
            'service_id'            => $service->id,
            'professional_id'       => $professional?->id,
            'customer_dependent_id' => $validated['customer_dependent_id'] ?? null,
            'notes'                 => $validated['notes'] ?? null,
        ]);

        $walkin->load(self::RELATIONS);

        return response()->json([
            'message' => 'Turno actualizado.',
            'data'    => $this->present($walkin, $tenant, Walkin::estimates($tenant, $walkin->branch)[$walkin->id] ?? null),
        ]);
    }

    public function options(Request $request, WalkinEntry $walkin)
    {
        $tenant = $this->tenant($request);
        $this->find($request, $tenant, $walkin);
        $walkin->loadMissing(['branch', 'service', 'professional']);
        abort_unless($walkin->branch && $walkin->service, 422, 'Este turno no tiene sede o servicio.');

        $estimate = Walkin::estimates($tenant, $walkin->branch)[$walkin->id] ?? null;

        return response()->json([
            'candidates'             => Walkin::candidates($tenant, $walkin->branch, $walkin->service),
            'preferred_professional' => $walkin->professional ? ['id' => $walkin->professional->id, 'display_name' => $walkin->professional->display_name] : null,
            'suggested_professional' => $estimate['suggested_professional'] ?? null,
            'wait_minutes'           => $estimate['wait_minutes'] ?? null,
            'can_override'           => (bool) AppointmentFlow::rule($tenant, 'agenda.allow_override') && $request->user()->allows('agenda.override'),
        ]);
    }

    public function serve(Request $request, WalkinEntry $walkin)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();
        $this->find($request, $tenant, $walkin);
        abort_unless($user->allows('agenda.create'), 403, Access::denial('agenda.create', $tenant));
        abort_unless($walkin->status === WalkinEntry::WAITING, 422, 'Esta persona ya no está en espera.');

        $validated = $request->validate([
            'professional_id' => 'required|uuid',
            'override_reason' => 'nullable|string|max:255',
        ], ['professional_id.required' => 'Elige quién la atiende.']);

        $walkin->loadMissing(['branch', 'service']);
        $start = Walkin::now($tenant, $walkin->branch)->addMinute()->startOfMinute();

        $booking = $this->call($request, 'POST', [
            'customer_id'           => $walkin->customer_id,
            'customer_dependent_id' => $walkin->customer_dependent_id,
            'service_id'            => $walkin->service_id,
            'professional_id'       => $validated['professional_id'],
            'branch_id'             => $walkin->branch_id,
            'starts_at'             => $start->format('Y-m-d\TH:i'),
            'notes'                 => $walkin->notes,
            'override_reason'       => $validated['override_reason'] ?? null,
            'walkin_id'             => $walkin->id,
        ], fn ($controller, $sub) => $controller->store($sub));

        if ($booking->getStatusCode() >= 400) {
            return $booking;
        }

        $appointment = Appointment::findOrFail($booking->getData(true)['id']);
        $pending = $user->allows('agenda.edit') ? $this->startAttention($request, $tenant, $appointment) : 'No tienes permiso para iniciar la atención: pídeselo a quien la atiende.';

        $walkin->refresh()->load(self::RELATIONS);
        $this->audit($request, 'walkin.served', $walkin, [
            'professional'     => $walkin->professional?->display_name,
            'appointment_code' => $appointment->code,
            'waited_minutes'   => $walkin->waited_minutes,
        ]);

        return response()->json([
            'message'     => $pending
                ? "Cita {$appointment->code} creada. {$pending}"
                : "En atención con {$walkin->professional?->display_name}.",
            'pending'     => $pending,
            'data'        => $this->present($walkin, $tenant),
            'appointment' => ['id' => $appointment->id, 'code' => $appointment->code],
        ]);
    }

    private function startAttention(Request $request, Tenant $tenant, Appointment $appointment): ?string
    {
        $checkin = (bool) AppointmentFlow::rule($tenant, 'agenda.checkin_step');

        for ($step = 0; $step < 3; $step++) {
            $status = $appointment->refresh()->status;

            $target = match ($status) {
                AppointmentFlow::PENDING    => $checkin ? AppointmentFlow::CHECKED_IN : AppointmentFlow::CONFIRMED,
                AppointmentFlow::CONFIRMED  => $checkin ? AppointmentFlow::CHECKED_IN : AppointmentFlow::IN_PROGRESS,
                AppointmentFlow::CHECKED_IN => AppointmentFlow::IN_PROGRESS,
                default                     => null,
            };

            if (!$target) {
                return null;
            }

            $response = $this->call($request, 'POST', ['status' => $target], fn ($controller, $sub) => $controller->changeStatus($sub, $appointment->id));

            if ($response->getStatusCode() >= 400) {
                return $response->getData(true)['message'] ?? 'La atención no se pudo iniciar.';
            }
        }

        return null;
    }

    private function call(Request $request, string $method, array $data, callable $action): JsonResponse
    {
        $sub = Request::create('/api/appointments', $method, $data);
        $sub->headers->set('Accept', 'application/json');
        $sub->setUserResolver(fn () => $request->user());

        return $action(app(AppointmentController::class), $sub);
    }

    public function changeStatus(Request $request, WalkinEntry $walkin)
    {
        $tenant = $this->tenant($request);
        $this->find($request, $tenant, $walkin);

        $validated = $request->validate([
            'status' => 'required|in:left,waiting',
        ]);

        if ($validated['status'] === WalkinEntry::LEFT) {
            abort_unless($walkin->status === WalkinEntry::WAITING, 422, 'Solo se retira a quien sigue en espera. Si ya tiene cita, cancélala desde la cita.');

            $walkin->update([
                'status'         => WalkinEntry::LEFT,
                'left_at'        => now(),
                'waited_minutes' => max(0, (int) $walkin->arrived_at->diffInMinutes(now())),
            ]);

            $this->audit($request, 'walkin.left', $walkin, ['waited_minutes' => $walkin->waited_minutes]);
        } else {
            abort_unless($walkin->status === WalkinEntry::LEFT && !$walkin->appointment_id, 422, 'Solo se vuelve a poner en espera a quien se retiró de la cola.');
            abort_unless($walkin->arrived_at->copy()->setTimezone($tenant->zone())->isSameDay(Carbon::now($tenant->zone())), 422, 'Ese turno es de otro día. Anótalo de nuevo.');

            $walkin->update(['status' => WalkinEntry::WAITING, 'left_at' => null, 'waited_minutes' => null]);
        }

        $walkin->load(self::RELATIONS);

        return response()->json([
            'message' => 'Turno actualizado.',
            'data'    => $this->present($walkin, $tenant),
        ]);
    }

    private function publicTenant(string $code): Tenant
    {
        abort_unless(preg_match('/^\d{' . Tenant::CODE_LENGTH . '}$/', $code), 404, 'El código del kiosko no es válido.');
        abort_unless(Walkin::ready(), 503, Walkin::NOT_READY);

        $tenant = Tenant::with(['settings', 'businessType'])->where('code', $code)->where('status', 'active')->first();

        abort_unless($tenant, 404, 'No encontramos una empresa activa con este código.');
        abort_unless($tenant->hasModule('walkin'), 403, 'Esta empresa no atiende por orden de llegada.');
        abort_unless(Walkin::rules($tenant)['self_checkin'], 403, 'El kiosko está apagado en este momento. Acércate a recepción.');

        return $tenant;
    }

    private function publicBranch(Tenant $tenant, ?string $id): Branch
    {
        $branches = Branch::where('tenant_id', $tenant->id)->where('status', 'active')->orderBy('name')->get();
        abort_if($branches->isEmpty(), 404, 'No hay sedes disponibles.');

        return ($id ? $branches->firstWhere('id', $id) : null) ?? $branches->first();
    }

    public function kioskInfo(string $code, Request $request)
    {
        $tenant = $this->publicTenant($code);
        $branch = $this->publicBranch($tenant, $request->query('branch_id'));
        $working = Walkin::workingToday($tenant, $branch);
        $currency = Currency::of($tenant);
        $branding = Branding::present($tenant);

        return response()->json([
            'tenant'        => [
                'name'       => $tenant->name,
                'logo_url'   => $branding['logo_url'] ?? null,
                'vocabulary' => $tenant->vocabulary(),
            ],
            'branch'        => ['id' => $branch->id, 'name' => $branch->name, 'address' => $branch->address],
            'branches'      => Branch::where('tenant_id', $tenant->id)->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'services'      => $this->activeServices($tenant)
                ->filter(fn (Service $s) => $s->offeredAt($branch->id) && $working->contains(fn (Professional $p) => Walkin::canDo($s, $p)))
                ->map(fn (Service $s) => [
                    'id'               => $s->id,
                    'name'             => $s->name,
                    'duration_minutes' => (int) $s->duration_minutes,
                    'price_label'      => Currency::format((float) $s->price, $currency),
                ])->values(),
            'waiting_count' => Walkin::waitingToday($tenant, $branch)->count(),
            'max_wait'      => Walkin::rules($tenant)['max_wait'],
        ]);
    }

    public function kioskChoices(string $code, Request $request)
    {
        $tenant = $this->publicTenant($code);

        $validated = $request->validate([
            'branch_id'  => 'nullable|uuid',
            'service_id' => 'required|uuid',
        ]);

        $branch = $this->publicBranch($tenant, $validated['branch_id'] ?? null);
        $choices = Walkin::choices($tenant, $branch, $this->activeService($tenant, $validated['service_id']));

        return response()->json([
            'first_free'    => ['wait_minutes' => $choices['first_free']['wait_minutes']],
            'professionals' => $choices['professionals'],
        ]);
    }

    public function kioskLookup(string $code, Request $request)
    {
        $tenant = $this->publicTenant($code);

        $validated = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\d{9}$/'],
        ]);

        return response()->json(['found' => (bool) $this->findCustomerByPhone($tenant->id, $validated['phone'])]);
    }

    public function kioskRegister(string $code, Request $request)
    {
        $tenant = $this->publicTenant($code);
        $rules = Walkin::rules($tenant);

        $validated = $request->validate([
            'branch_id'       => 'nullable|uuid',
            'phone'           => ['required', 'string', 'regex:/^\d{9}$/'],
            'name'            => 'nullable|string|max:120',
            'service_id'      => 'required|uuid',
            'professional_id' => 'nullable|uuid',
        ], [
            'phone.required'      => 'Escribe tu celular de 9 dígitos.',
            'phone.regex'         => 'Escribe un celular de 9 dígitos.',
            'service_id.required' => 'Elige el servicio.',
        ]);

        $branch = $this->publicBranch($tenant, $validated['branch_id'] ?? null);
        $service = $this->activeService($tenant, $validated['service_id']);
        $professional = $this->preferred($tenant, $branch, $service, $validated['professional_id'] ?? null);

        $result = DB::transaction(function () use ($tenant, $branch, $service, $professional, $validated, $rules) {
            Walkin::lock($branch);

            $customer = $this->findCustomerByPhone($tenant->id, $validated['phone']);

            if ($customer && ($duplicate = $this->activeToday($tenant, $branch, $customer))) {
                return response()->json([
                    'code'    => 'DUPLICATE',
                    'message' => "Ya estás en la cola de hoy con el turno {$duplicate->ticket}.",
                    'ticket'  => $duplicate->ticket,
                ], 409);
            }

            $phantom = new WalkinEntry(['service_id' => $service->id, 'professional_id' => $professional?->id]);
            $phantom->id = 'new';
            $phantom->setRelation('service', $service);
            $wait = Walkin::estimates($tenant, $branch, null, $phantom)['new']['wait_minutes'];

            if ($wait === null || $wait > $rules['max_wait']) {
                return response()->json([
                    'code'         => 'WAIT_TOO_LONG',
                    'message'      => $wait === null
                        ? 'Hoy ya no queda espacio para atenderte sin cita. Te recomendamos reservar una cita para otro día.'
                        : "La espera sería de unos {$wait} minutos. Te recomendamos reservar una cita.",
                    'wait_minutes' => $wait,
                ], 409);
            }

            if (!$customer) {
                $name = trim((string) ($validated['name'] ?? ''));
                abort_if($name === '', 422, 'Escribe tu nombre.');
                [$first, $last] = $this->splitName($name);
                $customer = $this->newCustomer($tenant, $first, $last, '+51 ' . $validated['phone'], 'Se registró en el kiosko de la cola sin cita');
            }

            return WalkinEntry::create([
                'tenant_id'       => $tenant->id,
                'branch_id'       => $branch->id,
                'customer_id'     => $customer->id,
                'service_id'      => $service->id,
                'professional_id' => $professional?->id,
                'ticket'          => Walkin::nextTicket($tenant, $branch),
                'status'          => WalkinEntry::WAITING,
                'source'          => 'kiosk',
                'arrived_at'      => now(),
            ]);
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $estimate = Walkin::estimates($tenant, $branch)[$result->id] ?? null;
        Notifier::walkinAdded($tenant, $result, $estimate['wait_minutes'] ?? null);

        return response()->json([
            'ticket'       => $result->ticket,
            'position'     => $estimate['position'] ?? null,
            'wait_minutes' => $estimate['wait_minutes'] ?? null,
            'estimated_at' => $estimate['estimated_at'] ?? null,
            'service_name' => $service->name,
            'branch_name'  => $branch->name,
        ], 201);
    }

    private function activeToday(Tenant $tenant, Branch $branch, Customer $customer): ?WalkinEntry
    {
        $now = Walkin::now($tenant, $branch);

        return WalkinEntry::where('tenant_id', $tenant->id)
            ->where('branch_id', $branch->id)
            ->where('customer_id', $customer->id)
            ->whereIn('status', WalkinEntry::ACTIVE)
            ->where('arrived_at', '>=', $now->copy()->startOfDay()->toIso8601String())
            ->first();
    }

    private function resolveCustomer(Tenant $tenant, array $validated): Customer
    {
        if (!empty($validated['customer_id'])) {
            return Customer::where('tenant_id', $tenant->id)->findOrFail($validated['customer_id']);
        }

        $digits = preg_replace('/\D+/', '', (string) ($validated['phone'] ?? ''));
        if (strlen($digits) >= 9 && ($existing = $this->findCustomerByPhone($tenant->id, substr($digits, -9)))) {
            return $existing;
        }

        return $this->newCustomer(
            $tenant,
            trim((string) $validated['first_name']),
            trim((string) ($validated['last_name'] ?? '')) ?: '-',
            !empty($validated['phone']) ? trim((string) $validated['phone']) : null,
            'Se registró en la cola sin cita',
        );
    }

    private function newCustomer(Tenant $tenant, string $first, string $last, ?string $phone, string $notes): Customer
    {
        $customer = Customer::create([
            'tenant_id'  => $tenant->id,
            'first_name' => $first,
            'last_name'  => $last,
            'phone'      => $phone,
            'notes'      => $notes,
        ]);

        return $customer;
    }

    private function findCustomerByPhone(string $tenantId, string $nineDigits): ?Customer
    {
        return Customer::where('tenant_id', $tenantId)
            ->whereNotNull('phone')
            ->whereRaw("regexp_replace(phone, '\\D', '', 'g') LIKE ?", ['%' . $nineDigits])
            ->orderByDesc('created_at')
            ->first();
    }

    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];

        return [array_shift($parts) ?: 'Cliente', implode(' ', $parts) ?: '-'];
    }

    private function noShowCounts(Tenant $tenant, array $customerIds): array
    {
        if (!$customerIds) {
            return [];
        }

        return DB::table('appointments')
            ->where('tenant_id', $tenant->id)
            ->whereIn('customer_id', $customerIds)
            ->where('status', AppointmentFlow::NO_SHOW)
            ->groupBy('customer_id')
            ->selectRaw('customer_id, count(*) as total')
            ->pluck('total', 'customer_id')
            ->all();
    }

    private function audit(Request $request, string $action, WalkinEntry $entry, array $after): void
    {
        $entry->loadMissing(['customer', 'service']);
        $name = trim(($entry->customer?->first_name ?? '') . ' ' . ($entry->customer?->last_name === '-' ? '' : ($entry->customer?->last_name ?? '')));

        Audit::log($request, $action, [
            'tenant'       => $entry->tenant_id,
            'entity_type'  => 'walkin_entry',
            'entity_id'    => $entry->id,
            'entity_label' => "{$entry->ticket} · {$name} — " . ($entry->service?->name ?? ''),
            'branch_id'    => $entry->branch_id,
            'after'        => ['ticket' => $entry->ticket] + array_filter($after, fn ($value) => $value !== null),
        ]);
    }

    private function present(WalkinEntry $e, Tenant $tenant, ?array $estimate = null, array $noShows = []): array
    {
        $zone = $e->branch?->timezone ?: $tenant->zone();
        $local = fn (?Carbon $date) => $date?->copy()->setTimezone($zone)->format('H:i');

        return [
            'id'                     => $e->id,
            'ticket'                 => $e->ticket,
            'status'                 => $e->status,
            'status_label'           => WalkinEntry::STATUSES[$e->status] ?? $e->status,
            'source'                 => $e->source,
            'source_label'           => $e->source === 'kiosk' ? 'Kiosko' : 'Recepción',
            'notes'                  => $e->notes,
            'waited_minutes'         => $e->waited_minutes,
            'elapsed_minutes'        => $e->status === WalkinEntry::WAITING ? max(0, (int) $e->arrived_at->diffInMinutes(now())) : $e->waited_minutes,
            'arrived_at'             => $e->arrived_at?->toIso8601String(),
            'arrived_time'           => $local($e->arrived_at),
            'served_time'            => $local($e->served_at),
            'done_time'              => $local($e->done_at),
            'left_time'              => $local($e->left_at),
            'position'               => $estimate['position'] ?? null,
            'wait_minutes'           => $estimate['wait_minutes'] ?? null,
            'estimated_at'           => $estimate['estimated_at'] ?? null,
            'suggested_professional' => $estimate['suggested_professional'] ?? null,
            'branch_id'              => $e->branch_id,
            'branch'                 => $e->branch ? ['id' => $e->branch->id, 'name' => $e->branch->name] : null,
            'customer_id'            => $e->customer_id,
            'customer'               => $e->customer ? [
                'id'            => $e->customer->id,
                'first_name'    => $e->customer->first_name,
                'last_name'     => $e->customer->last_name,
                'full_name'     => trim($e->customer->first_name . ' ' . ($e->customer->last_name === '-' ? '' : $e->customer->last_name)),
                'phone'         => $e->customer->phone,
                'no_show_count' => (int) ($noShows[$e->customer->id] ?? 0),
            ] : null,
            'customer_dependent_id'  => $e->customer_dependent_id,
            'dependent'              => $e->dependent ? ['id' => $e->dependent->id, 'name' => $e->dependent->name] : null,
            'service_id'             => $e->service_id,
            'service'                => $e->service ? [
                'id'               => $e->service->id,
                'name'             => $e->service->name,
                'duration_minutes' => (int) $e->service->duration_minutes,
                'price'            => (float) $e->service->price,
            ] : null,
            'professional_id'        => $e->professional_id,
            'professional'           => $e->professional ? ['id' => $e->professional->id, 'display_name' => $e->professional->display_name] : null,
            'appointment_id'         => $e->appointment_id,
            'appointment'            => $e->appointment ? [
                'id'          => $e->appointment->id,
                'code'        => $e->appointment->code,
                'status'      => $e->appointment->status,
                'status_name' => AppointmentFlow::name($tenant, $e->appointment->status),
            ] : null,
        ];
    }
}
