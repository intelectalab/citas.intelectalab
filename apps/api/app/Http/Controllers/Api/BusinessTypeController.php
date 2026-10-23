<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\BusinessType;
use App\Models\SaasModule;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BusinessTypeController extends Controller
{
    public function index(Request $request)
    {
        $query = BusinessType::withCount('tenants');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                \App\Support\Search::contains($q, 'name', (string) $search);
                \App\Support\Search::orContains($q, 'slug', (string) $search);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $types = $query->orderBy('sort_order')->get()->makeVisible('template');
        $types->each(fn (BusinessType $type) => $type->setAttribute('outdated_tenants_count', count($this->outdatedTenants($type))));

        return response()->json($types);
    }

    public function show(string $id)
    {
        $type = BusinessType::withCount('tenants')->findOrFail($id)->makeVisible('template');
        $type->setAttribute('outdated_tenants_count', count($this->outdatedTenants($type)));

        return response()->json($type);
    }

    public function tenants(string $id)
    {
        $type = BusinessType::findOrFail($id);
        $outdated = collect($this->outdatedTenants($type))->keyBy('id');

        $tenants = Tenant::where('business_type_id', $type->id)->orderBy('name')->get()->map(function (Tenant $tenant) use ($type, $outdated) {
            $current = Tenant::profileFromType($type);
            $own = $tenant->businessProfile('modules_off');

            return [
                'id'        => $tenant->id,
                'name'      => $tenant->name,
                'status'    => $tenant->status,
                'synced_at' => $tenant->business_profile_synced_at,
                'outdated'  => $outdated->has($tenant->id) ? $outdated[$tenant->id]['parts'] : [],
                'modules_gained' => array_values(array_diff($own, $current['modules_off'])),
                'modules_lost'   => array_values(array_diff($current['modules_off'], $own)),
            ];
        });

        return response()->json($tenants);
    }

    public function apply(Request $request, string $id)
    {
        $type = BusinessType::findOrFail($id);

        $validated = $request->validate([
            'tenant_ids'   => 'required|array|min:1',
            'tenant_ids.*' => ['uuid', Rule::exists('tenants', 'id')->where('business_type_id', $type->id)],
            'parts'        => 'sometimes|array|min:1',
            'parts.*'      => [Rule::in(Tenant::PROFILE_PARTS)],
        ], [
            'tenant_ids.*.exists' => 'Una de las empresas no pertenece a este rubro.',
        ]);

        $parts = $validated['parts'] ?? Tenant::PROFILE_PARTS;
        $applied = 0;

        foreach (Tenant::whereIn('id', $validated['tenant_ids'])->get() as $tenant) {
            $changed = array_values(array_intersect($tenant->outdatedProfileParts($type), $parts));

            if (empty($changed)) {
                continue;
            }

            $before = $tenant->business_profile;
            $tenant->fillBusinessProfile($parts)->save();
            $applied++;

            AuditEvent::record($request, 'tenant.business_profile_applied', $tenant, ['business_profile' => $before], [
                'business_profile' => $tenant->business_profile,
                'parts' => $changed,
            ]);
        }

        return response()->json([
            'message' => $applied === 1 ? 'Se actualizó 1 empresa.' : "Se actualizaron {$applied} empresas.",
            'applied' => $applied,
        ]);
    }

    private function outdatedTenants(BusinessType $type): array
    {
        return Tenant::where('business_type_id', $type->id)->get()
            ->map(fn (Tenant $tenant) => ['id' => $tenant->id, 'parts' => $tenant->outdatedProfileParts($type)])
            ->filter(fn ($row) => !empty($row['parts']))
            ->values()
            ->all();
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $type = BusinessType::create($validated);

        return response()->json($type->makeVisible('template'), 201);
    }

    public function update(Request $request, string $id)
    {
        $type = BusinessType::findOrFail($id);
        $type->update($this->validated($request, $type));

        return response()->json($type->makeVisible('template'));
    }

    public function destroy(string $id)
    {
        $type = BusinessType::findOrFail($id);

        if (Tenant::where('business_type_id', $type->id)->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar el rubro porque hay empresas que lo usan. Puedes desactivarlo para que no aparezca en el registro.',
            ], 422);
        }

        $type->delete();

        return response()->json(['message' => 'Rubro eliminado correctamente']);
    }

    private function validated(Request $request, ?BusinessType $type = null): array
    {
        $optionalModules = SaasModule::where('is_core', false)->pluck('key')->all();
        $required = $type ? 'sometimes|required' : 'required';

        $validated = $request->validate([
            'name'        => "$required|string|max:150",
            'short_name'  => "$required|string|max:80",
            'slug'        => ['nullable', 'string', 'max:50', 'alpha_dash', Rule::unique('business_types', 'slug')->ignore($type?->id)],
            'description' => 'nullable|string|max:500',
            'icon'        => 'nullable|string|max:50',
            'color'       => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sort_order'  => 'sometimes|integer|min:0',
            'status'      => ['sometimes', Rule::in(['active', 'inactive'])],

            'vocabulary'   => 'sometimes|array',
            'vocabulary.*' => 'nullable|string|max:40',

            'modules_off'   => 'sometimes|array',
            'modules_off.*' => ['string', 'distinct', Rule::in($optionalModules)],

            'default_rules'   => 'sometimes|array',
            'default_rules.*' => ['nullable', fn ($attribute, $value, $fail) => is_scalar($value) || $fail('Cada regla debe ser un número, un texto o verdadero/falso.')],

            'dependents'          => 'sometimes|array',
            'dependents.enabled'  => 'sometimes|boolean',
            'dependents.kind'     => 'nullable|string|max:50',
            'dependents.required' => 'sometimes|boolean',

            'template'                                              => 'sometimes|array',
            'template.categories'                                   => 'sometimes|array',
            'template.categories.*.name'                            => 'required|string|max:120|distinct',
            'template.categories.*.services'                        => 'sometimes|array',
            'template.categories.*.services.*.name'                 => 'required|string|max:150',
            'template.categories.*.services.*.code'                 => 'nullable|string|max:30',
            'template.categories.*.services.*.duration_minutes'     => 'required|integer|min:1',
            'template.categories.*.services.*.price'                => 'required|numeric|min:0',
            'template.categories.*.services.*.buffer_after_minutes' => 'sometimes|integer|min:0',
            'template.categories.*.services.*.uses_record'          => 'sometimes|boolean',
            'template.categories.*.services.*.requires_deposit'     => 'sometimes|boolean',
            'template.categories.*.services.*.online_booking'       => 'sometimes|boolean',
            'template.categories.*.services.*.is_virtual'           => 'sometimes|boolean',
            'template.categories.*.services.*.kind'                 => ['sometimes', Rule::in(['individual', 'group'])],
            'template.categories.*.services.*.capacity'             => 'sometimes|integer|min:1',
            'template.categories.*.services.*.recall'               => 'sometimes|array',
            'template.categories.*.services.*.recall.label'         => 'required_with:template.categories.*.services.*.recall|string|max:150',
            'template.categories.*.services.*.recall.days'          => 'required_with:template.categories.*.services.*.recall|integer|min:1',
            'template.record_template'                              => 'nullable|array',
            'template.record_template.name'                         => 'required_with:template.record_template|string|max:150',
            'template.record_template.fields'                       => 'sometimes|array',
            'template.record_template.fields.*.key'                 => 'required|string|max:60',
            'template.record_template.fields.*.label'               => 'required|string|max:150',
            'template.record_template.fields.*.type'                => ['required', Rule::in(['text', 'textarea', 'number', 'select', 'multiselect', 'date', 'boolean', 'scale', 'odontogram', 'next_control'])],
        ], [
            'modules_off.*.in' => 'Ese módulo no existe o es un módulo base, que no se puede apagar.',
            'color.regex'      => 'El color debe tener el formato #RRGGBB.',
        ]);

        if (isset($validated['vocabulary'])) {
            $validated['vocabulary'] = collect($validated['vocabulary'])
                ->only(array_keys(Tenant::DEFAULT_VOCABULARY))
                ->map(fn ($value) => trim((string) $value))
                ->filter()
                ->all();
        }

        if (isset($validated['modules_off'])) {
            $validated['modules_off'] = array_values($validated['modules_off']);
        }

        if (array_key_exists('dependents', $validated)) {
            $dependents = $validated['dependents'];
            $validated['dependents'] = !empty($dependents['enabled'])
                ? ['enabled' => true, 'kind' => $dependents['kind'] ?? null, 'required' => !empty($dependents['required'])]
                : [];
        }

        if ($request->has('template')) {
            $validated['template'] = $request->input('template') ?: [];
        }

        if ($request->has('default_rules')) {
            $validated['default_rules'] = $request->input('default_rules') ?: [];
        }

        return $validated;
    }
}
