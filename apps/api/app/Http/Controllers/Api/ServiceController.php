<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Service;
use App\Models\Status;
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

        return response()->json($query->orderBy('created_at', 'desc')->get());
    }

    private function rules(Request $request, ?string $serviceId = null): array
    {
        $tenantId = $request->user()->tenant_id;

        return [
            'category_id' => ['nullable', Rule::exists('service_categories', 'id')->where('tenant_id', $tenantId)],
            'name' => 'required|string|max:150',
            'code' => 'nullable|string|max:30',
            'duration_minutes' => 'required|integer|min:1',
            'buffer_before_minutes' => 'nullable|integer|min:0',
            'buffer_after_minutes' => 'nullable|integer|min:0',
            'price' => 'required|numeric|min:0',
            'requires_deposit' => 'sometimes|boolean',
            'deposit_type' => ['sometimes', Rule::in(['percent', 'amount'])],
            'deposit_value' => 'sometimes|numeric|min:0|max:99999',
            'online_booking' => 'sometimes|boolean',
            'is_virtual' => 'sometimes|boolean',
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
    ];

    private function normalize(array $data, ?Service $service = null): array
    {
        $kind = $data['kind'] ?? $service?->kind ?? Service::KIND_INDIVIDUAL;

        if ($kind !== Service::KIND_GROUP) {
            $data['capacity'] = 1;
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

        $service = Service::create($validated);

        return response()->json($service->load('category'), 201);
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

        $service->update($this->normalize($validated, $service));

        return response()->json($service->load(['category', 'status']));
    }

    public function destroy(Request $request, $id)
    {
        $currentUser = $request->user();

        $service = Service::where('tenant_id', $currentUser->tenant_id)->findOrFail($id);
        $service->delete();

        return response()->json(['message' => 'Servicio eliminado correctamente']);
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
