<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RecordTemplate;
use App\Models\Service;
use App\Models\Tenant;
use App\Support\RecordFields;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecordTemplateController extends Controller
{
    private function tenant(Request $request, bool $mustAdmin = false): Tenant
    {
        $user = $request->user();
        abort_unless($user->tenant, 403);
        abort_if(
            $mustAdmin && !$user->allows('record_templates.create') && !$user->allows('record_templates.edit') && !$user->allows('record_templates.delete'),
            403,
            'Tu cuenta no tiene permiso para cambiar las plantillas de ficha.',
        );

        return $user->tenant;
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);

        $templates = RecordTemplate::where('tenant_id', $tenant->id)
            ->with('services:id')
            ->withCount('records')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $owner = $templates->flatMap(fn (RecordTemplate $t) => $t->services->mapWithKeys(fn ($s) => [$s->id => $t->id]));

        return response()->json([
            'can_edit'  => $request->user()->allows('record_templates.edit'),
            'templates' => $templates->map(fn (RecordTemplate $t) => $this->present($t)),
            'services'  => Service::where('tenant_id', $tenant->id)
                ->where('kind', Service::KIND_INDIVIDUAL)
                ->with('category:id,name')
                ->orderBy('name')
                ->get()
                ->map(fn (Service $s) => [
                    'id'          => $s->id,
                    'name'        => $s->name,
                    'category'    => $s->category?->name,
                    'template_id' => $owner[$s->id] ?? null,
                ]),
        ]);
    }

    public function store(Request $request)
    {
        $tenant = $this->tenant($request, true);
        $data = $this->validated($request, $tenant);

        $template = DB::transaction(function () use ($tenant, $data) {
            $template = RecordTemplate::create([
                'tenant_id'       => $tenant->id,
                'name'            => $data['name'],
                'description'     => $data['description'],
                'fields'          => $data['fields'],
                'consent_text'    => $data['consent_text'],
                'require_consent' => $data['require_consent'],
                'is_default'      => !RecordTemplate::where('tenant_id', $tenant->id)->exists(),
            ]);

            $this->syncServices($template, $data['service_ids']);

            return $template;
        });

        return response()->json($this->present($template->fresh()->load('services:id')->loadCount('records')), 201);
    }

    public function update(Request $request, string $recordTemplate)
    {
        $tenant = $this->tenant($request, true);
        $template = RecordTemplate::where('tenant_id', $tenant->id)->findOrFail($recordTemplate);
        $data = $this->validated($request, $tenant, $template);

        DB::transaction(function () use ($template, $data) {
            $fieldsChanged = $template->fields != $data['fields'];

            $template->update([
                'name'            => $data['name'],
                'description'     => $data['description'],
                'fields'          => $data['fields'],
                'consent_text'    => $data['consent_text'],
                'require_consent' => $data['require_consent'],
                'version'         => $template->version + ($fieldsChanged ? 1 : 0),
            ]);

            $this->syncServices($template, $data['service_ids']);
        });

        return response()->json($this->present($template->fresh()->load('services:id')->loadCount('records')));
    }

    public function destroy(Request $request, string $recordTemplate)
    {
        $tenant = $this->tenant($request, true);
        $template = RecordTemplate::where('tenant_id', $tenant->id)->findOrFail($recordTemplate);

        $template->delete();

        return response()->json(['message' => 'Plantilla eliminada correctamente']);
    }

    private function validated(Request $request, Tenant $tenant, ?RecordTemplate $template = null): array
    {
        $validated = $request->validate([
            'name'             => ['required', 'string', 'max:150', Rule::unique('record_templates', 'name')->where('tenant_id', $tenant->id)->ignore($template?->id)],
            'description'      => 'nullable|string|max:255',
            'fields'           => 'present|array|max:60',
            'fields.*.key'     => 'nullable|string|max:60',
            'fields.*.label'   => 'nullable|string|max:150',
            'fields.*.type'    => ['required', Rule::in(RecordFields::TYPES)],
            'consent_text'     => 'nullable|string|max:5000',
            'require_consent'  => 'boolean',
            'service_ids'      => 'present|array',
            'service_ids.*'    => 'uuid',
        ], [
            'name.required' => 'Ponle nombre a la ficha.',
            'name.unique'   => 'Ya tienes una ficha con ese nombre.',
        ]);

        $fields = RecordFields::clean($request->input('fields', []));

        if ($problems = RecordFields::problems($fields)) {
            throw ValidationException::withMessages(['fields' => $problems[0]]);
        }

        $consent = trim((string) ($validated['consent_text'] ?? ''));

        if (!empty($validated['require_consent']) && $consent === '') {
            throw ValidationException::withMessages(['consent_text' => 'Escribe el texto del consentimiento o desmarca que es obligatorio.']);
        }

        $serviceIds = Service::where('tenant_id', $tenant->id)->whereIn('id', $validated['service_ids'])->pluck('id')->all();

        return [
            'name'            => trim($validated['name']),
            'description'     => trim((string) ($validated['description'] ?? '')) ?: null,
            'fields'          => $fields,
            'consent_text'    => $consent ?: null,
            'require_consent' => !empty($validated['require_consent']),
            'service_ids'     => $serviceIds,
        ];
    }

    private function syncServices(RecordTemplate $template, array $serviceIds): void
    {
        DB::table('record_template_services')
            ->where('tenant_id', $template->tenant_id)
            ->where(fn ($q) => $q->where('record_template_id', $template->id)->orWhereIn('service_id', $serviceIds))
            ->delete();

        DB::table('record_template_services')->insert(array_map(fn ($serviceId) => [
            'tenant_id'          => $template->tenant_id,
            'record_template_id' => $template->id,
            'service_id'         => $serviceId,
        ], $serviceIds));
    }

    private function present(RecordTemplate $template): array
    {
        return [
            'id'              => $template->id,
            'name'            => $template->name,
            'description'     => $template->description,
            'fields'          => $template->fields ?? [],
            'consent_text'    => $template->consent_text,
            'require_consent' => $template->require_consent,
            'is_default'      => $template->is_default,
            'version'         => $template->version,
            'service_ids'     => $template->services->pluck('id')->values(),
            'records_count'   => $template->records_count ?? 0,
        ];
    }
}
