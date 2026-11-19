<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerDependent;
use App\Support\Audit;
use App\Support\CustomerStages;
use App\Support\Search;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{

    private function tenantQuery(Request $request)
    {
        return Customer::where('tenant_id', $request->user()->tenant_id);
    }

    private function findForTenant(Request $request, string $id): Customer
    {
        return $this->tenantQuery($request)->findOrFail($id);
    }

    private function audit(Request $request, string $action, Customer $customer, array $before = [], array $after = []): void
    {
        Audit::log($request, $action, array_filter([
            'tenant'       => $request->user()->tenant_id,
            'entity_type'  => 'customer',
            'entity_id'    => $customer->id,
            'entity_label' => trim("{$customer->first_name} {$customer->last_name}"),
            'before'       => $before ?: null,
            'after'        => $after ?: null,
        ], fn ($value) => $value !== null));
    }

    private const HIDDEN = ['document_number', 'blood_type', 'medical_notes'];

    public static function auditable(Customer $customer): array
    {
        $customer->loadMissing(['typeDocument', 'status', 'managerUser']);

        return [
            'name'                    => trim("{$customer->first_name} {$customer->last_name}"),
            'document_type'           => $customer->typeDocument?->name,
            'document_number'         => $customer->document_number,
            'birth_date'              => $customer->birth_date?->format('Y-m-d'),
            'biological_sex'          => ['M' => 'Masculino', 'F' => 'Femenino', 'O' => 'Otro'][$customer->biological_sex] ?? null,
            'blood_type'              => $customer->blood_type,
            'email'                   => $customer->email,
            'phone'                   => $customer->phone,
            'emergency_contact_name'  => $customer->emergency_contact_name,
            'emergency_contact_phone' => $customer->emergency_contact_phone,
            'status'                  => $customer->status?->name,
            'manager'                 => $customer->managerUser ? \App\Support\Person::name($customer->managerUser) : null,
            'address'                 => $customer->address,
            'district'                => $customer->district,
            'department'              => $customer->department,
            'postal_code'             => $customer->postal_code,
            'medical_notes'           => $customer->medical_notes,
        ] + (\App\Support\CustomerProfile::fieldsReady() ? [
            'home_branch'             => $customer->home_branch_id ? DB::table('branches')->where('id', $customer->home_branch_id)->value('name') : null,
            'preferred_professional'  => $customer->preferred_professional_id ? DB::table('professionals')->where('id', $customer->preferred_professional_id)->value('display_name') : null,
            'alert'                   => $customer->alert,
        ] : []);
    }

    private function extraRules(Request $request): array
    {
        if (!\App\Support\CustomerProfile::fieldsReady()) {
            return [];
        }

        $tenantId = $request->user()->tenant_id;

        return [
            'home_branch_id'            => ['nullable', 'uuid', \Illuminate\Validation\Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'preferred_professional_id' => ['nullable', 'uuid', \Illuminate\Validation\Rule::exists('professionals', 'id')->where('tenant_id', $tenantId)],
            'alert'                     => 'nullable|string|max:160',
        ];
    }

    private const EXTRA_MESSAGES = [
        'home_branch_id.exists'            => 'Esa sede no es de tu empresa.',
        'preferred_professional_id.exists' => 'Ese profesional no es de tu empresa.',
        'alert.max'                        => 'La alerta debe ser corta: hasta 160 letras.',
    ];

    private function normalizePhones(Request $request, array $validated): array
    {
        $prefix = \App\Support\Phones::prefixFor($request->user()->tenant);
        $labels = ['phone' => 'El celular', 'emergency_contact_phone' => 'El teléfono de emergencia'];

        foreach ($labels as $field => $label) {
            if (!array_key_exists($field, $validated) || $validated[$field] === null || trim((string) $validated[$field]) === '') {
                continue;
            }

            $normalized = \App\Support\Phones::normalize($validated[$field], $prefix);

            if ($normalized === null) {
                throw \Illuminate\Validation\ValidationException::withMessages([$field => "{$label} no parece un número: escribe entre 6 y 15 números, con o sin +{$prefix}."]);
            }

            $validated[$field] = $normalized;
        }

        if (array_key_exists('alert', $validated) && $validated['alert'] !== null) {
            $validated['alert'] = trim($validated['alert']) ?: null;
        }

        return $validated;
    }

    public static function auditChanges(array $before, array $after): array
    {
        [$from, $to] = Audit::changed($before, $after);

        foreach (self::HIDDEN as $key) {
            if (array_key_exists($key, $to)) {
                unset($from[$key]);
                $to[$key] = $after[$key] === null || $after[$key] === '' ? 'Se borró' : ($before === [] ? 'Registrado' : 'Cambió');
            }
        }

        return [$from, $to];
    }

    private function relations(Request $request): array
    {
        $relations = ['typeDocument', 'status'];

        if (\App\Support\CustomerProfile::fieldsReady()) {
            $relations[] = 'homeBranch:id,name';
            $relations[] = 'preferredProfessional:id,display_name';
        }

        if ($this->usesDependents($request)) {
            $relations[] = 'dependents';
        }

        return $relations;
    }

    private function withoutClinicalFields(Request $request, array $validated): array
    {
        if (empty($request->user()->tenant?->rules()['customers.clinical_fields'])) {
            unset($validated['blood_type']);
        }

        return $validated;
    }

    private function usesDependents(Request $request): bool
    {
        return (bool) $request->user()->tenant?->dependents()['enabled'];
    }

    public function index(Request $request)
    {
        $query = $this->tenantQuery($request)->with($this->relations($request));

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search, $request) {
                Search::contains($q, "coalesce(first_name, '') || ' ' || coalesce(last_name, '')", $search);

                foreach (['document_number', 'phone', 'email', 'code'] as $column) {
                    Search::orContains($q, $column, $search);
                }

                foreach (\App\Support\Phones::searchVariants($search, \App\Support\Phones::prefixFor($request->user()->tenant)) as $digits) {
                    $q->orWhereRaw("regexp_replace(coalesce(phone, ''), '[^0-9]', '', 'g') like ?", ['%' . $digits . '%']);
                }
            });
        }

        if (($tagId = $request->input('tag_id')) && \Illuminate\Support\Str::isUuid($tagId) && \App\Support\CustomerProfile::tagsReady()) {
            $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('customer_tag_assignments as cta')
                ->whereColumn('cta.customer_id', 'customers.id')->where('cta.customer_tag_id', $tagId));
        }

        if ($biologicalSex = $request->input('biological_sex')) {
            $query->where('biological_sex', $biologicalSex);
        }

        if ($statusId = $request->input('status_id')) {
            $query->where('status_id', $statusId);
        }

        $perPage = min(max((int) $request->input('per_page', 10), 1), 500);

        $customers = $query->orderBy('created_at', 'desc')->paginate($perPage);

        if ($tenant = $request->user()->tenant) {
            $ids = $customers->getCollection()->pluck('id')->all();
            $states = \App\Support\OnlineBooking::states($tenant, $ids);
            $visits = $this->visits($tenant->id, $ids);
            $local = fn (?string $value) => $value ? \Illuminate\Support\Carbon::parse($value)->setTimezone($tenant->zone())->format('Y-m-d\TH:i:s') : null;
            $tags = \App\Support\CustomerProfile::tagsFor($ids);
            $customers->getCollection()->each(function (Customer $c) use ($states, $visits, $local, $tags) {
                $c->setAttribute('tags', $tags[$c->id] ?? []);
                $c->setAttribute('online_booking', $states[$c->id] ?? null);
                $c->setAttribute('last_visit', $local($visits[$c->id]->last_visit ?? null));
                $c->setAttribute('next_visit', $local($visits[$c->id]->next_visit ?? null));
            });
        }

        return response()->json($customers);
    }

    private function visits(string $tenantId, array $ids): array
    {
        if (!$ids) {
            return [];
        }

        return DB::table('appointments')
            ->where('tenant_id', $tenantId)
            ->whereIn('customer_id', $ids)
            ->groupBy('customer_id')
            ->selectRaw("customer_id, max(starts_at) FILTER (WHERE status = 'completed') AS last_visit, min(starts_at) FILTER (WHERE status IN ('pending', 'confirmed') AND starts_at > now()) AS next_visit")
            ->get()
            ->keyBy('customer_id')
            ->all();
    }

    private function moveStage(Request $request, Customer $customer, array $validated): void
    {
        if (!array_key_exists('pipeline_stage_id', $validated) || !CustomerStages::ready() || !$request->user()->tenant) {
            return;
        }

        if ($validated['pipeline_stage_id'] === CustomerStages::AUTO) {
            CustomerStages::release($request->user()->tenant, $customer->id);

            return;
        }

        CustomerStages::move($request->user()->tenant, $customer->id, $validated['pipeline_stage_id'] ?: null, $request->user(), 'form');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'birth_date' => 'nullable|date',
            'biological_sex' => 'nullable|string|in:M,F,O',
            'blood_type' => 'nullable|string|max:10',
            'type_document_id' => 'nullable|uuid',
            'document_number' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:50',
            'status_id' => 'nullable|uuid',
            'manager_user_id' => 'nullable|uuid',
            'pipeline_stage_id' => ['nullable', 'string', fn ($attribute, $value, $fail) => $value === CustomerStages::AUTO || \Illuminate\Support\Str::isUuid($value) ? null : $fail('Esa columna no es válida.')],
            'address' => 'nullable|string|max:255',
            'district' => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'medical_notes' => 'nullable|string',
            'dependents' => 'sometimes|array|max:20',
        ] + CustomerDependent::rules('dependents.*.') + $this->extraRules($request), self::EXTRA_MESSAGES);

        $this->assertUniqueDocument($request, $validated['document_number'] ?? null);
        $validated = $this->normalizePhones($request, $validated);
        $dependents = $validated['dependents'] ?? [];
        $stage = array_intersect_key($validated, ['pipeline_stage_id' => true]);
        unset($validated['dependents'], $validated['pipeline_stage_id']);
        $validated = $this->withoutClinicalFields($request, $validated);

        try {
            DB::beginTransaction();

            $tenantId = $request->user()->tenant_id ?? null;
            

            $validated['code'] = Customer::nextCode($tenantId);
            $validated['tenant_id'] = $tenantId;

            $customer = Customer::create($validated);

            $customer->syncPortalPassword();
            $this->moveStage($request, $customer, $stage);

            if ($this->usesDependents($request)) {
                $kind = $request->user()->tenant->dependents()['kind'];

                foreach ($dependents as $dependent) {
                    $customer->dependents()->create($dependent + ['tenant_id' => $tenantId, 'kind' => $kind]);
                }
            }

            DB::commit();

            [, $created] = self::auditChanges([], array_filter(self::auditable($customer), fn ($value) => $value !== null && $value !== ''));
            $this->audit($request, 'customer.created', $customer, [], ['code' => $customer->code] + $created);

            return response()->json([
                'message' => 'Cliente registrado con éxito',
                'customer' => $customer->load($this->relations($request))
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al registrar el cliente',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Request $request, string $customer)
    {
        $customer = $this->findForTenant($request, $customer);
        $tenant = $request->user()->tenant;

        $customer->setAttribute('online_booking', $tenant ? \App\Support\OnlineBooking::state($tenant, $customer->id) : null);

        return response()->json([
            'customer' => $customer->load($this->relations($request)),
            'can_forgive_booking' => $request->user()->allows('customers.forgive') && \App\Support\OnlineBooking::canForgive(),
            'consents' => \App\Support\Messaging::ready() ? MessageController::consentsOf($customer->id) : null,
        ]);
    }

    public function forgiveBooking(Request $request, string $customer)
    {
        abort_unless($request->user()->allows('customers.forgive'), 403, 'Tu cuenta no tiene permiso para perdonar inasistencias.');
        abort_unless(\App\Support\OnlineBooking::canForgive(), 503, 'Falta actualizar la base de datos: ejecuta php artisan migrate.');

        $customer = $this->findForTenant($request, $customer);
        \Illuminate\Support\Facades\DB::table('customers')->where('id', $customer->id)->update(['booking_strikes_reset_at' => now()]);
        $this->audit($request, 'customer.no_shows_forgiven', $customer);

        return response()->json(['online_booking' => null]);
    }

    public function update(Request $request, string $customer)
    {
        $customer = $this->findForTenant($request, $customer);

        $validated = $request->validate([
            'first_name' => 'sometimes|required|string|max:255',
            'last_name' => 'sometimes|required|string|max:255',
            'birth_date' => 'nullable|date',
            'biological_sex' => 'nullable|string|in:M,F,O',
            'blood_type' => 'nullable|string|max:10',
            'type_document_id' => 'nullable|uuid',
            'document_number' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:50',
            'status_id' => 'nullable|uuid',
            'manager_user_id' => 'nullable|uuid',
            'pipeline_stage_id' => ['nullable', 'string', fn ($attribute, $value, $fail) => $value === CustomerStages::AUTO || \Illuminate\Support\Str::isUuid($value) ? null : $fail('Esa columna no es válida.')],
            'address' => 'nullable|string|max:255',
            'district' => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'medical_notes' => 'nullable|string',
        ] + $this->extraRules($request), self::EXTRA_MESSAGES);

        if (array_key_exists('document_number', $validated)) {
            $this->assertUniqueDocument($request, $validated['document_number'], $customer->id);
        }

        $validated = $this->normalizePhones($request, $validated);

        $stage = array_intersect_key($validated, ['pipeline_stage_id' => true]);
        unset($validated['pipeline_stage_id']);
        $auditBefore = self::auditable($customer);

        $customer->update($this->withoutClinicalFields($request, $validated));

        $customer->syncPortalPassword($customer->wasChanged('document_number'));
        $this->moveStage($request, $customer, $stage);
        $customer->refresh();

        [$from, $to] = self::auditChanges($auditBefore, self::auditable($customer->unsetRelations()));

        if ($from || $to) {
            $this->audit($request, 'customer.updated', $customer, $from, $to);
        }

        return response()->json([
            'message' => 'Cliente actualizado con éxito',
            'customer' => $customer->load($this->relations($request))
        ]);
    }


    public function portalAccess(Request $request, string $customer)
    {
        $customer = $this->findForTenant($request, $customer);
        $validated = $request->validate(['enabled' => 'required|boolean']);

        $turnedOn = $validated['enabled'] && $customer->portal_enabled !== true;
        $wasEnabled = $customer->portal_enabled;
        $customer->forceFill(['portal_enabled' => $validated['enabled']])->save();

        if ($wasEnabled !== (bool) $validated['enabled']) {
            $this->audit($request, $validated['enabled'] ? 'customer.portal_enabled' : 'customer.portal_disabled', $customer);
        }

        if (!$validated['enabled']) {
            $customer->tokens()->delete();
        } else {
            $customer->syncPortalPassword();

            if ($turnedOn && $request->user()->tenant) {
                \App\Support\Messaging::portalAccess($request->user()->tenant, $customer, $request->user());
            }
        }

        return response()->json([
            'message'  => $validated['enabled'] ? 'Acceso al portal encendido' : 'Acceso al portal apagado',
            'customer' => $customer->load($this->relations($request)),
        ]);
    }

    public function resetPortalPassword(Request $request, string $customer)
    {
        $customer = $this->findForTenant($request, $customer);

        if (!$customer->resetPortalPassword()) {
            return response()->json([
                'message' => 'Para darle acceso al portal, registra su correo y su número de documento.',
                'code'    => 'MISSING_DATA',
            ], 422);
        }

        $customer->tokens()->delete();
        $this->audit($request, 'customer.portal_password_reset', $customer);

        if ($request->user()->tenant) {
            \App\Support\Messaging::portalAccess($request->user()->tenant, $customer, $request->user());
        }

        return response()->json([
            'message'  => 'Su contraseña volvió a ser su número de documento.',
            'customer' => $customer->load($this->relations($request)),
        ]);
    }

    public function destroy(Request $request, string $customer)
    {
        $customer = $this->findForTenant($request, $customer);
        $customer->tokens()->delete();
        $customer->delete();
        $this->audit($request, 'customer.deleted', $customer, array_filter([
            'email' => $customer->email,
            'phone' => $customer->phone,
        ]));

        return response()->json([
            'message' => 'Cliente eliminado correctamente'
        ]);
    }

    private function assertUniqueDocument(Request $request, ?string $document, ?string $exceptId = null): void
    {
        $other = \App\Support\CustomerRecords::duplicateByDocument($request->user()->tenant_id, $document, $exceptId);

        if ($other) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'document_number' => 'Ya hay una ficha con ese documento: ' . \App\Support\Person::name($other) . ($other->code ? " ({$other->code})" : '') . '. Búscala en vez de crear otra; si son la misma persona, únelas desde su ficha.',
            ]);
        }
    }

    public function mergePreview(Request $request, string $customer)
    {
        $keep = $this->findForTenant($request, $customer);
        $validated = $request->validate(['duplicate_id' => 'required|uuid']);
        $duplicate = $this->findForTenant($request, $validated['duplicate_id']);
        abort_if($keep->id === $duplicate->id, 422, 'Elige otra ficha.');

        $count = fn (string $table) => \Illuminate\Support\Facades\Schema::hasTable($table) ? DB::table($table)->where('customer_id', $duplicate->id)->count() : 0;

        return response()->json([
            'duplicate' => ['id' => $duplicate->id, 'code' => $duplicate->code, 'name' => \App\Support\Person::name($duplicate), 'document_number' => $duplicate->document_number, 'phone' => $duplicate->phone, 'email' => $duplicate->email],
            'counts'    => [
                'appointments' => $count('appointments'),
                'sales'        => $count('sales'),
                'packages'     => $count('customer_packages'),
                'records'      => $count('care_records'),
                'quotes'       => $count('quotes'),
            ],
            'fills' => collect(\App\Support\CustomerRecords::FILLABLE_FROM_DUPLICATE)
                ->filter(fn ($field) => ($keep->{$field} === null || $keep->{$field} === '') && $duplicate->{$field} !== null && $duplicate->{$field} !== '')
                ->values(),
        ]);
    }

    public function merge(Request $request, string $customer)
    {
        $keep = $this->findForTenant($request, $customer);
        $validated = $request->validate(['duplicate_id' => 'required|uuid']);
        $duplicate = $this->findForTenant($request, $validated['duplicate_id']);
        abort_if($keep->id === $duplicate->id, 422, 'No puedes unir una ficha consigo misma.');

        $result = DB::transaction(fn () => \App\Support\CustomerRecords::merge($keep, $duplicate));

        $this->audit($request, 'customer.merged', $keep, ['Ficha unida' => trim(\App\Support\Person::name($duplicate) . ' ' . ($duplicate->code ?? ''))], ['Registros movidos' => array_sum($result['moved']), 'Datos completados' => implode(', ', $result['filled']) ?: null]);

        return response()->json([
            'message'  => 'Fichas unidas: todo lo de ' . \App\Support\Person::name($duplicate) . ' ahora está en esta ficha.',
            'customer' => $keep->fresh()->load($this->relations($request)),
            'moved'    => $result['moved'],
        ]);
    }

    public function anonymize(Request $request, string $customer)
    {
        abort_unless(\App\Support\CustomerRecords::ready(), 503, 'Falta actualizar la base de datos para borrar datos personales (php artisan migrate).');
        $customer = $this->findForTenant($request, $customer);
        $request->validate(['confirm' => 'required|in:BORRAR'], ['confirm.in' => 'Escribe BORRAR para confirmar.', 'confirm.required' => 'Escribe BORRAR para confirmar.']);

        if ($customer->anonymized_at) {
            return response()->json(['message' => 'Los datos personales de esta ficha ya se borraron.'], 422);
        }

        DB::transaction(fn () => \App\Support\CustomerRecords::anonymize($customer));
        $this->audit($request, 'customer.anonymized', $customer, ['Código' => $customer->code], ['Datos personales' => 'Borrados', 'Notas internas' => 'Borradas']);

        return response()->json([
            'message'  => 'Se borraron los datos personales y las notas internas. Sus citas, ventas y fichas de atención se conservan sin su nombre.',
            'customer' => $customer->fresh()->load($this->relations($request)),
        ]);
    }
}