<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Service;
use App\Models\Status;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        $currentUser = $request->user();

        $query = Service::with(['category', 'status'])
        ->where('tenant_id', $currentUser->tenant_id);

        if ($request->filled('search')) {
            $search = (string) $request->search;
            $query->where(function ($q) use ($search) {
                \App\Support\Search::contains($q, 'name', $search);
                \App\Support\Search::orContains($q, "coalesce(code, '')", $search);
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('status_id')) {
            $query->where('status_id', $request->status_id);
        }

        match ($request->query('tab_status')) {
            'active', 'inactive' => $query->whereHas('status', fn ($q) => $q->where('slug', $request->query('tab_status'))),
            default => null,
        };

        $services = $query->orderBy('created_at', 'desc')->get();
        $prices = Service::branchPricesReady()
            ? DB::table('service_branch_prices')->where('tenant_id', $currentUser->tenant_id)->get(['service_id', 'branch_id', 'price'])->groupBy('service_id')
            : collect();

        $rooms = \App\Support\RoomBooking::ready()
            ? DB::table('service_rooms')->where('tenant_id', $currentUser->tenant_id)->get(['service_id', 'room_id'])->groupBy('service_id')
            : collect();

        $professionals = \App\Support\ServiceProfessionals::map($currentUser->tenant_id);
        $team = \App\Support\CatalogExtras::activeProfessionals($currentUser->tenant_id);
        $teamNames = $team->pluck('display_name', 'id');
        $roomNames = $rooms->isNotEmpty() ? DB::table('rooms')->where('tenant_id', $currentUser->tenant_id)->pluck('name', 'id') : collect();
        $branchNames = DB::table('branches')->where('tenant_id', $currentUser->tenant_id)->orderBy('name')->pluck('name', 'id');

        return response()->json($services->map(function (Service $service) use ($rooms, $professionals, $prices, $teamNames, $roomNames, $branchNames) {
            $roomIds = ($rooms[$service->id] ?? collect())->pluck('room_id')->values()->all();
            $proIds = $professionals[$service->id] ?? [];
            $doers = $proIds ? $teamNames->only($proIds) : $teamNames;

            return $service->toArray() + [
                'room_ids'      => $roomIds,
                'room_names'    => collect($roomIds)->map(fn ($id) => $roomNames[$id] ?? null)->filter()->sort()->values()->all(),
                'professional_ids' => $proIds,
                'professional_names' => $doers->values()->sort()->values()->all(),
                'branch_names'  => ($service->branch_ids ?: null) ? collect($service->branch_ids)->map(fn ($id) => $branchNames[$id] ?? null)->filter()->sort()->values()->all() : [],
                'branch_prices' => ($prices[$service->id] ?? collect())->map(fn ($row) => ['branch_id' => $row->branch_id, 'price' => (float) $row->price])->values()->all(),
            ];
        }));
    }

    public function impact(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;
        $service = Service::where('tenant_id', $tenantId)->findOrFail($id);
        $query = $this->futureAppointments($service);

        return response()->json([
            'future_count' => (clone $query)->count(),
            'has_history'  => DB::table('appointments')->where('service_id', $service->id)->exists(),
            'appointments' => (clone $query)->with(['customer:id,first_name,last_name', 'branch:id,name,timezone', 'professional:id,display_name'])->orderBy('starts_at')->limit(20)->get()
                ->map(fn ($a) => [
                    'id'           => $a->id,
                    'code'         => $a->code,
                    'starts_at'    => $a->starts_at->copy()->setTimezone($a->branch?->timezone ?: 'America/Lima')->format('Y-m-d\TH:i'),
                    'customer'     => $a->customer ? \App\Support\Person::name($a->customer) : null,
                    'professional' => $a->professional?->display_name,
                ]),
        ]);
    }

    public function nextCode(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $categoryId = $request->query('category_id');
        $name = $categoryId ? DB::table('service_categories')->where('tenant_id', $tenantId)->where('id', $categoryId)->value('name') : null;

        return response()->json(['code' => self::suggestCode($tenantId, $name)]);
    }

    public static function suggestCode(string $tenantId, ?string $categoryName): string
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper(Str::ascii(explode(' ', trim((string) $categoryName))[0] ?? '')));
        $prefix = strlen($letters) >= 2 ? substr($letters, 0, 3) : 'SRV';
        $highest = DB::table('services')->where('tenant_id', $tenantId)->whereNotNull('code')
            ->pluck('code')
            ->map(fn ($code) => preg_match('/^' . $prefix . '-(\d+)$/i', trim((string) $code), $m) ? (int) $m[1] : 0)
            ->max() ?? 0;

        return $prefix . '-' . str_pad((string) ($highest + 1), 2, '0', STR_PAD_LEFT);
    }

    private function futureAppointments(Service $service)
    {
        return \App\Models\Appointment::where('tenant_id', $service->tenant_id)
            ->where('service_id', $service->id)
            ->where('starts_at', '>', now())
            ->whereIn('status', [\App\Support\AppointmentFlow::PENDING, \App\Support\AppointmentFlow::CONFIRMED, \App\Support\AppointmentFlow::CHECKED_IN]);
    }

    private function saveBranchPrices(Service $service, ?array $rows): void
    {
        if ($rows === null || !Service::branchPricesReady()) {
            return;
        }

        DB::table('service_branch_prices')->where('service_id', $service->id)->delete();

        $seen = [];
        foreach ($rows as $row) {
            $price = $row['price'] ?? null;

            if ($price === null || $price === '' || isset($seen[$row['branch_id']]) || round((float) $price, 2) === round((float) $service->price, 2)) {
                continue;
            }

            $seen[$row['branch_id']] = true;
            DB::table('service_branch_prices')->insert([
                'tenant_id'  => $service->tenant_id,
                'service_id' => $service->id,
                'branch_id'  => $row['branch_id'],
                'price'      => round((float) $price, 2),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function rules(Request $request, ?string $serviceId = null): array
    {
        $tenantId = $request->user()->tenant_id;

        return [
            'category_id' => ['nullable', Rule::exists('service_categories', 'id')->where('tenant_id', $tenantId)],
            'name' => 'required|string|max:150',
            'code' => ['nullable', 'string', 'max:30', function ($attribute, $value, $fail) use ($tenantId, $serviceId) {
                if (is_string($value) && \App\Support\CatalogExtras::codeTaken($tenantId, $value, $serviceId)) {
                    $fail('Ya hay otro servicio con ese código. Usa uno distinto.');
                }
            }],
            'description' => 'sometimes|nullable|string|max:2000',
            'requires_record' => 'sometimes|boolean',
            'duration_minutes' => 'required|integer|min:5|max:720',
            'branch_prices' => 'sometimes|nullable|array',
            'branch_prices.*.branch_id' => ['required', 'uuid', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'branch_prices.*.price' => 'nullable|numeric|min:0|max:9999999',
            'buffer_before_minutes' => 'nullable|integer|min:0',
            'buffer_after_minutes' => 'nullable|integer|min:0',
            'price' => 'required|numeric|min:0',
            'branch_ids' => 'sometimes|nullable|array',
            'branch_ids.*' => ['uuid', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'room_ids' => 'sometimes|nullable|array|max:50',
            'room_ids.*' => ['uuid', Rule::exists('rooms', 'id')->where('tenant_id', $tenantId)->where('kind', 'room')],
            'professional_ids' => 'sometimes|nullable|array|max:200',
            'professional_ids.*' => ['uuid', Rule::exists('professionals', 'id')->where('tenant_id', $tenantId)],
            'requires_deposit' => 'sometimes|boolean',
            'deposit_type' => ['sometimes', Rule::in(['percent', 'amount'])],
            'deposit_value' => 'sometimes|numeric|min:0|max:99999',
            'online_booking' => 'sometimes|boolean',
            'is_virtual' => 'sometimes|boolean',
            'video_url' => 'sometimes|' . \App\Support\VideoLinks::rule(),
            'kind' => ['sometimes', Rule::in([Service::KIND_INDIVIDUAL, Service::KIND_GROUP])],
            'capacity' => 'sometimes|integer|min:1|max:500',
            'recall_label' => 'nullable|string|max:150|required_with:recall_days',
            'recall_days' => 'nullable|integer|min:1|max:3650|required_with:recall_label',
            'recall_service_id' => [
                'nullable',
                Rule::exists('services', 'id')->where('tenant_id', $tenantId),
                Rule::notIn(array_filter([$serviceId])),
            ],
            'status_id' => [
                $serviceId ? 'required' : 'nullable',
                Rule::exists('statuses', 'id')->whereIn('module_id', \App\Models\StatusModule::where('name', 'services')->pluck('id')->all()),
            ],
        ];
    }

    private const MESSAGES = [
        'status_id.required' => 'Elige el estado del servicio: Activo o Inactivo.',
        'status_id.exists'   => 'Ese estado no es válido para un servicio.',
        'duration_minutes.min' => 'La duración mínima de un servicio es de 5 minutos.',
        'duration_minutes.max' => 'La duración máxima de un servicio es de 12 horas (720 minutos).',
    ];

    private function normalize(array $data, ?Service $service = null): array
    {
        $kind = $data['kind'] ?? $service?->kind ?? Service::KIND_INDIVIDUAL;

        if ($kind !== Service::KIND_GROUP) {
            $data['capacity'] = 1;
        }

        if (array_key_exists('code', $data)) {
            $data['code'] = trim((string) $data['code']) ?: null;
        }

        if (!\App\Support\CatalogExtras::serviceFields()) {
            unset($data['description'], $data['requires_record']);
        } elseif (array_key_exists('description', $data)) {
            $data['description'] = trim((string) $data['description']) ?: null;
        }

        if (!\App\Support\VideoLinks::ready()) {
            unset($data['video_url']);
        } elseif (array_key_exists('video_url', $data)) {
            $data['video_url'] = trim((string) $data['video_url']) ?: null;
        }

        if (!\Illuminate\Support\Facades\Schema::hasColumn('services', 'deposit_type')) {
            unset($data['deposit_type'], $data['deposit_value']);
        } elseif ($data['requires_deposit'] ?? $service?->requires_deposit) {
            $type = $data['deposit_type'] ?? $service?->deposit_type ?? 'percent';
            $value = (float) ($data['deposit_value'] ?? $service?->deposit_value ?? 30);
            $price = (float) ($data['price'] ?? $service?->price ?? 0);

            if ($value <= 0) {
                throw \Illuminate\Validation\ValidationException::withMessages(['deposit_value' => 'Escribe cuánto es el adelanto: debe ser mayor que cero.']);
            }

            if ($type === 'percent' && $value > 100) {
                throw \Illuminate\Validation\ValidationException::withMessages(['deposit_value' => 'El adelanto en porcentaje va de 1 a 100.']);
            }

            if ($type === 'amount' && $value > $price) {
                throw \Illuminate\Validation\ValidationException::withMessages(['deposit_value' => 'El adelanto no puede ser mayor que el precio del servicio.']);
            }
        }

        if (array_key_exists('recall_label', $data) && empty($data['recall_label'])) {
            $data['recall_label'] = null;
            $data['recall_days'] = null;
            $data['recall_service_id'] = null;
        }

        return $data;
    }

    private function saveBranches(Service $service, ?array $branchIds): void
    {
        if ($branchIds === null || !\Illuminate\Support\Facades\Schema::hasTable('service_branches')) {
            return;
        }

        DB::table('service_branches')->where('service_id', $service->id)->delete();
        DB::table('service_branches')->insert(array_map(fn ($id) => ['tenant_id' => $service->tenant_id, 'service_id' => $service->id, 'branch_id' => $id], array_values(array_unique($branchIds))));
        Service::forgetBranchMap($service->tenant_id);
    }

    public function store(Request $request)
    {
        $currentUser = $request->user();

        $validated = $request->validate($this->rules($request), self::MESSAGES);

        if (($validated['kind'] ?? null) === Service::KIND_GROUP && !$this->canUseClasses($request)) {
            return $this->classesDisabled();
        }

        if (empty($validated['status_id'])) {
            $defaultStatus = Status::whereHas('module', function($q) {
                $q->where('name', 'services');
            })->where('is_default', true)->first();

            abort_unless($defaultStatus, 422, 'No hay un estado por defecto para los servicios: elige uno.');
            $validated['status_id'] = $defaultStatus->id;
        }

        $validated = $this->normalize($validated);
        $validated['id'] = (string) Str::uuid();
        $validated['tenant_id'] = $currentUser->tenant_id;
        $validated['buffer_before_minutes'] = $validated['buffer_before_minutes'] ?? 0;
        $validated['buffer_after_minutes'] = $validated['buffer_after_minutes'] ?? 0;

        $branchIds = $validated['branch_ids'] ?? null;
        $branchPrices = $validated['branch_prices'] ?? null;
        $roomIds = $validated['room_ids'] ?? null;
        $professionalIds = $validated['professional_ids'] ?? null;
        unset($validated['branch_ids'], $validated['branch_prices'], $validated['room_ids'], $validated['professional_ids']);
        $service = Service::create($validated);
        $this->saveBranches($service, $branchIds);
        \App\Support\RoomBooking::save($service, $roomIds);
        \App\Support\ServiceProfessionals::save($service, $professionalIds, \App\Support\ServiceProfessionals::visibleTo($currentUser));
        $this->saveBranchPrices($service, $branchPrices);
        $this->audit($request, 'service.created', $service, [], array_filter($this->auditable($service->fresh()), fn ($value) => $value !== null && $value !== false));

        return response()->json($service->load('category')->toArray() + ['room_ids' => \App\Support\RoomBooking::roomIdsOf($service->id), 'professional_ids' => \App\Support\ServiceProfessionals::idsOf($service->id)], 201);
    }

    public function update(Request $request, $id)
    {
        $currentUser = $request->user();
        $service = Service::where('tenant_id', $currentUser->tenant_id)->findOrFail($id);

        $validated = $request->validate($this->rules($request, $service->id), self::MESSAGES);

        if (($validated['kind'] ?? null) === Service::KIND_GROUP
            && $service->kind !== Service::KIND_GROUP
            && !$this->canUseClasses($request)) {
            return $this->classesDisabled();
        }

        $previous = $this->auditable($service);
        $branchIds = array_key_exists('branch_ids', $validated) ? ($validated['branch_ids'] ?? []) : null;
        $branchPrices = array_key_exists('branch_prices', $validated) ? ($validated['branch_prices'] ?? []) : null;
        $roomIds = array_key_exists('room_ids', $validated) ? ($validated['room_ids'] ?? []) : null;
        $professionalIds = array_key_exists('professional_ids', $validated) ? ($validated['professional_ids'] ?? []) : null;
        $wasActive = $service->status?->slug !== 'inactive';
        unset($validated['branch_ids'], $validated['branch_prices'], $validated['room_ids'], $validated['professional_ids']);
        $service->update($this->normalize($validated, $service));
        $this->saveBranches($service, $branchIds);
        \App\Support\RoomBooking::save($service, $roomIds);
        \App\Support\ServiceProfessionals::save($service, $professionalIds, \App\Support\ServiceProfessionals::visibleTo($currentUser));
        $this->saveBranchPrices($service, $branchPrices);
        [$before, $after] = Audit::changed($previous, $this->auditable($service->fresh()));

        if ($after) {
            $this->audit($request, 'service.updated', $service, $before, $after);
        }

        $service->load(['category', 'status']);
        $future = $wasActive && $service->status?->slug === 'inactive' ? $this->futureAppointments($service)->count() : 0;

        return response()->json($service->toArray() + [
            'room_ids'            => \App\Support\RoomBooking::roomIdsOf($service->id),
            'professional_ids'    => \App\Support\ServiceProfessionals::idsOf($service->id),
            'branch_prices'       => collect($service->branchPrices())->map(fn ($price, $branch) => ['branch_id' => $branch, 'price' => $price])->values()->all(),
            'future_appointments' => $future,
            'notice'              => $future > 0 ? "Quedó inactivo. Ya no se puede agendar, pero sus {$future} citas por venir se mantienen: revísalas en Citas." : null,
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $currentUser = $request->user();

        $service = Service::where('tenant_id', $currentUser->tenant_id)->with('status')->findOrFail($id);
        $future = $this->futureAppointments($service)->count();
        $history = DB::table('appointments')->where('service_id', $service->id)->exists()
            || (\Illuminate\Support\Facades\Schema::hasTable('sale_items') && DB::table('sale_items')->where('reference_id', $service->id)->exists());

        if ($future > 0 || $history) {
            $inactive = Status::whereHas('module', fn ($q) => $q->where('name', 'services'))->whereNull('tenant_id')->where('slug', 'inactive')->value('id');

            if ($inactive && $service->status_id !== $inactive) {
                $previous = $this->auditable($service);
                $service->update(['status_id' => $inactive]);
                [$before, $after] = Audit::changed($previous, $this->auditable($service->fresh()));
                $this->audit($request, 'service.deactivated', $service, $before, $after + ['reason' => $future > 0 ? 'Tiene citas por venir' : 'Tiene historial']);
            }

            return response()->json([
                'deactivated'         => true,
                'future_appointments' => $future,
                'message'             => $future > 0
                    ? "No se eliminó: tiene {$future} " . ($future === 1 ? 'cita por venir' : 'citas por venir') . '. Lo dejamos inactivo para que ya no se agende; esas citas se mantienen.'
                    : 'No se eliminó porque ya tiene citas o ventas en su historial. Lo dejamos inactivo para que ya no se agende.',
            ]);
        }

        DB::transaction(function () use ($service) {
            DB::table('service_professionals')->where('service_id', $service->id)->delete();
            $service->delete();
        });
        Audit::log($request, 'service.deleted', [
            'tenant'       => $currentUser->tenant_id,
            'entity_type'  => 'service',
            'entity_id'    => $service->id,
            'entity_label' => $service->name,
        ]);

        return response()->json(['message' => 'Servicio eliminado correctamente']);
    }

    private function auditable(Service $service): array
    {
        $deposit = null;

        if ($service->requires_deposit) {
            $deposit = ($service->deposit_type ?? 'percent') === 'amount'
                ? (float) $service->deposit_value
                : rtrim(rtrim(number_format((float) ($service->deposit_value ?? 0), 2, '.', ''), '0'), '.') . ' %';
        }

        return [
            'name'                  => $service->name,
            'code'                  => $service->code,
            'category'              => $service->category_id ? \App\Models\ServiceCategory::where('id', $service->category_id)->value('name') : null,
            'duration_minutes'      => (int) $service->duration_minutes,
            'buffer_before_minutes' => (int) $service->buffer_before_minutes,
            'buffer_after_minutes'  => (int) $service->buffer_after_minutes,
            'price'                 => (float) $service->price,
            'status'                => $service->status_id ? Status::where('id', $service->status_id)->value('name') : null,
            'online_booking'        => (bool) $service->online_booking,
            'is_virtual'            => (bool) $service->is_virtual,
            'branches'              => ($ids = Service::branchMap($service->tenant_id)[$service->id] ?? []) ? DB::table('branches')->whereIn('id', $ids)->orderBy('name')->pluck('name')->implode(', ') : 'Todas las sedes',
            'service_kind'          => $service->kind === Service::KIND_GROUP ? 'Grupal' : 'Individual',
            'capacity'              => (int) $service->capacity,
            'rooms'                 => ($roomIds = \App\Support\RoomBooking::roomIdsOf($service->id)) ? DB::table('rooms')->whereIn('id', $roomIds)->orderBy('name')->pluck('name')->implode(', ') : null,
            'professionals'         => \App\Support\ServiceProfessionals::names($service->id),
            'deposit'               => $deposit,
            'recall_label'          => $service->recall_label,
            'recall_days'           => $service->recall_days,
            'recall_service'        => $service->recall_service_id ? Service::where('id', $service->recall_service_id)->value('name') : null,
        ] + (\App\Support\CatalogExtras::serviceFields() ? [
            'description'           => $service->description,
            'requires_record'       => (bool) $service->requires_record,
        ] : []);
    }

    private function audit(Request $request, string $action, Service $service, array $before, array $after): void
    {
        Audit::log($request, $action, [
            'tenant'       => $request->user()->tenant_id,
            'entity_type'  => 'service',
            'entity_id'    => $service->id,
            'entity_label' => $service->name,
            'before'       => $before ?: null,
            'after'        => $after ?: null,
        ]);
    }

    private function canUseClasses(Request $request): bool
    {
        $tenant = $request->user()->tenant;

        return !$tenant || $tenant->hasModule('classes');
    }

    private function classesDisabled()
    {
        return response()->json([
            'message' => 'Tu empresa no tiene activo el módulo de clases grupales.',
            'code' => 'MODULE_DISABLED',
            'module' => 'classes',
        ], 403);
    }
}
