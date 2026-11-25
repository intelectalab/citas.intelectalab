<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Professional;
use App\Models\Status;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Search;
use App\Support\PublicBooking;

class ProfessionalController extends Controller
{
    public function index(Request $request)
    {
        $currentUser = $request->user();

        if (!$currentUser || !$currentUser->tenant_id) {
            return response()->json([]);
        }

        $query = Professional::where('tenant_id', $currentUser->tenant_id)
            ->with(['status', 'specialties', 'user', 'typeDocument']);

        if ($limit = Access::branchLimit($currentUser)) {
            $query->where(fn ($q) => $q->whereIn('id', Access::professionalsAt($limit))
                ->orWhereNotIn('id', \Illuminate\Support\Facades\DB::table('professional_schedules')->select('professional_id')));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $terms = explode(' ', trim($search));

            $query->where(function($q) use ($terms) {
                foreach ($terms as $term) {
                    if (empty($term)) continue;
                    
                    $q->where(function($subQ) use ($term) {
                        Search::contains($subQ, 'display_name', $term);
                        Search::orContains($subQ, "coalesce(license_number, '')", $term);
                        Search::orContains($subQ, "coalesce(document_number, '')", $term);
                        $subQ->orWhereHas('user', function($userQuery) use ($term) {
                            Search::contains($userQuery, "coalesce(first_name, '')", $term);
                            Search::orContains($userQuery, "coalesce(last_name, '')", $term);
                            Search::orContains($userQuery, "coalesce(email, '')", $term);
                        });
                    });
                }
            });
        }

        if ($request->filled('status')) {
            $status = $request->input('status');
            $query->where(function ($outer) use ($status) {
                $outer->whereHas('status', function($q) use ($status) {
                    $q->where('slug', $status)->orWhere('name', $status);
                })->when($status === 'active', fn ($q) => $q->orWhereNull('status_id'));
            });
        }

        $professionals = $query->get();

        return response()->json($professionals);
    }

    private function planLimitReached(?\App\Models\Tenant $tenant): ?string
    {
        $limits = $tenant?->planLimits() ?? [];
        $usage = $tenant ? $tenant->usage() : [];

        foreach (['professionals' => 'profesionales', 'users' => 'cuentas de usuario además de la del dueño'] as $key => $label) {
            $limit = $limits[$key] ?? null;

            if (is_numeric($limit) && ($usage[$key] ?? 0) >= (int) $limit) {
                return "Tu plan permite hasta {$limit} {$label} y ya llegaste a ese número. Para agregar otro, cambia de plan.";
            }
        }

        return null;
    }

    private function commissionFields(Request $request, array $validated): array
    {
        if (!\App\Support\Commissions::ready() || !$request->user()->allows('commissions.rules')) {
            return [];
        }

        $fields = [];
        $keys = ['commission_percent'] + (\Illuminate\Support\Facades\Schema::hasColumn('professionals', 'goal_month') ? [1 => 'goal_month'] : []);

        foreach ($keys as $key) {
            if (array_key_exists($key, $validated)) {
                $value = $validated[$key];
                $fields[$key] = $value === null || $value === '' ? null : round((float) $value, 2);
            }
        }

        return $fields;
    }

    private function onlineField(array $validated): array
    {
        return PublicBooking::professionalsFlag() && array_key_exists('online_booking', $validated)
            ? ['online_booking' => (bool) $validated['online_booking']]
            : [];
    }

    private function profileFields(array $validated): array
    {
        $fields = [];

        if (array_key_exists('color_code', $validated)) {
            $fields['color_code'] = $validated['color_code'] ? strtoupper($validated['color_code']) : '#022340';
        }

        if (array_key_exists('bio', $validated)) {
            $fields['bio'] = trim((string) $validated['bio']) ?: null;
        }

        return $fields;
    }

    private function syncServices(Request $request, Professional $professional, array $validated): void
    {
        if (!array_key_exists('service_ids', $validated) || !$request->user()->allows('services.edit')) {
            return;
        }

        $alone = \App\Support\ServiceProfessionals::lastOnes($professional, $validated['service_ids'] ?? []);

        if ($alone) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'service_ids' => "Nadie más hace " . implode(', ', $alone) . ". Para quitárselo, primero marca a otra persona en ese servicio (en Servicios).",
            ]);
        }

        \App\Support\ServiceProfessionals::syncFor($professional, $validated['service_ids'] ?? []);
    }

    private function auditProfessional(Professional $professional): array
    {
        $professional->loadMissing(['user', 'status', 'specialties']);

        return [
            'display_name'   => $professional->display_name,
            'name'           => $professional->user ? trim("{$professional->user->first_name} {$professional->user->last_name}") : null,
            'email'          => $professional->user?->email,
            'license_number' => $professional->license_number,
            'phone'          => $professional->phone,
            'commission'     => $professional->commission_percent !== null ? (float) $professional->commission_percent . ' %' : null,
            'goal_month'     => $professional->goal_month !== null ? (float) $professional->goal_month : null,
            'online_booking' => PublicBooking::professionalsFlag() ? ($professional->online_booking === false ? 'No' : 'Sí') : null,
            'status'         => $professional->status?->name,
            'specialties'    => $professional->specialties->pluck('name')->sort()->values()->all(),
            'agenda_color'   => $professional->color_code,
            'bio'            => $professional->bio,
            'services'       => \App\Models\Service::whereIn('id', \App\Support\ServiceProfessionals::doneBy($professional))->orderBy('name')->pluck('name')->implode(', ') ?: null,
        ];
    }

    private function audit(Request $request, string $action, Professional $professional, array $before = [], array $after = []): void
    {
        Audit::log($request, $action, array_filter([
            'tenant'       => $request->user()->tenant_id,
            'entity_type'  => 'professional',
            'entity_id'    => $professional->id,
            'entity_label' => $professional->display_name,
            'before'       => $before ?: null,
            'after'        => $after ?: null,
        ], fn ($value) => $value !== null));
    }

    private function assertOwnBranch(\App\Models\User $user, string $professionalId): void
    {
        $limit = Access::branchLimit($user);

        if ($limit === null || Access::worksAt($professionalId, $limit)
            || !\Illuminate\Support\Facades\DB::table('professional_schedules')->where('professional_id', $professionalId)->exists()) {
            return;
        }

        abort(403, 'Este profesional atiende en otra sede. ' . Access::onlyBranch($user));
    }

    public function store(Request $request)
    {
        $currentUser = $request->user();

        if (!$currentUser || !$currentUser->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if ($blocked = $this->planLimitReached($currentUser->tenant)) {
            return response()->json(['message' => $blocked, 'code' => 'PLAN_LIMIT'], 422);
        }

        $request->merge([
            'type_document_id' => $request->type_document_id ?: null,
            'status_id' => $request->status_id ?: null,
            'document_number' => $request->document_number ?: null,
            'license_number' => $request->license_number ?: null,
            'phone' => $request->phone ?: null,
        ]);

        $validated = $request->validate([
            'display_name' => 'required|string|max:150',
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => 'required|email|max:150|unique:users,email',
            'type_document_id' => 'nullable|exists:type_documents,id',
            'document_number' => 'nullable|string|max:30',
            'license_number' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:30',
            'status_id' => 'nullable|exists:statuses,id',
            'specialties' => 'nullable|array',
            'specialties.*' => 'exists:specialties,id',
            'commission_percent' => 'nullable|numeric|min:0|max:100',
            'goal_month' => 'nullable|numeric|min:0|max:99999999',
            'online_booking' => 'sometimes|boolean',
            'color_code' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'bio' => 'sometimes|nullable|string|max:1000',
            'service_ids' => 'sometimes|array|max:500',
            'service_ids.*' => ['uuid', \Illuminate\Validation\Rule::exists('services', 'id')->where('tenant_id', $currentUser->tenant_id)],
        ], ['commission_percent.max' => 'La comisión no puede pasar de 100 %.', 'goal_month.min' => 'La meta no puede ser negativa.']);
        $commission = $this->commissionFields($request, $validated);

        $rawPassword = $validated['document_number'] ?? \Illuminate\Support\Str::random(8);

        $user = \App\Models\User::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $currentUser->tenant_id,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'password_hash' => \Illuminate\Support\Facades\Hash::make($rawPassword),
            'status' => 'active',
        ]);

        \App\Support\Access::ensureRoles($currentUser->tenant);
        $roleProfessional = \App\Models\Role::firstOrCreate(
            ['tenant_id' => $currentUser->tenant_id, 'slug' => 'professional'],
            [
                'id'          => (string) \Illuminate\Support\Str::uuid(),
                'name'        => 'Profesional',
                'description' => 'Atiende sus propias citas',
                'is_system'   => true,
            ]
        );

        if ($roleProfessional) {
            \Illuminate\Support\Facades\DB::table('user_roles')->insert([
                'tenant_id' => $currentUser->tenant_id,
                'user_id' => $user->id,
                'role_id' => $roleProfessional->id,
            ]);
        }

        $statusId = $validated['status_id'] ?? \App\Models\Status::whereHas('module', function($q) {
            $q->where('name', 'professionals');
        })->where('slug', 'active')->value('id');

        $professional = Professional::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $currentUser->tenant_id,
            'user_id' => $user->id,
            'type_document_id' => $validated['type_document_id'] ?? null,
            'document_number' => $validated['document_number'] ?? null,
            'display_name' => $validated['display_name'],
            'license_number' => $validated['license_number'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'status_id' => $statusId,
        ] + $commission + $this->onlineField($validated) + $this->profileFields($validated));

        if (isset($validated['specialties'])) {
            $syncData = [];
            foreach ($validated['specialties'] as $specialtyId) {
                $syncData[$specialtyId] = ['tenant_id' => $currentUser->tenant_id];
            }
            $professional->specialties()->sync($syncData);
        }

        $this->syncServices($request, $professional, $validated);
        $this->audit($request, 'professional.created', $professional, [], array_filter($this->auditProfessional($professional), fn ($value) => $value !== null && $value !== '' && $value !== []));

        return response()->json([
            'professional' => $professional->load(['status', 'specialties', 'user', 'typeDocument']),
            'temporary_password' => $rawPassword,
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $currentUser = $request->user();

        $professional = Professional::where('tenant_id', $currentUser->tenant_id)
            ->with(['status', 'specialties', 'user', 'schedules', 'services', 'typeDocument'])
            ->findOrFail($id);

        abort_if(Access::scopeOf($currentUser) === 'own' && $professional->user_id !== $currentUser->id, 403, 'Con tu rol solo puedes ver tu propia página.');

        return response()->json($professional);
    }

    public function update(Request $request, $id)
    {
        $currentUser = $request->user();

        $professional = Professional::where('tenant_id', $currentUser->tenant_id)
            ->with('user')
            ->findOrFail($id);
        $this->assertOwnBranch($currentUser, $professional->id);

        $request->merge([
            'type_document_id' => $request->type_document_id ?: null,
            'status_id' => $request->status_id ?: null,
            'document_number' => $request->document_number ?: null,
            'license_number' => $request->license_number ?: null,
            'phone' => $request->phone ?: null,
        ]);

        $validated = $request->validate([
            'display_name' => 'sometimes|required|string|max:150',
            'first_name' => 'sometimes|required|string|max:100',
            'last_name' => 'sometimes|required|string|max:100',
            'email' => 'sometimes|required|email|max:150|unique:users,email,' . optional($professional->user)->id,
            'type_document_id' => 'nullable|exists:type_documents,id',
            'document_number' => 'nullable|string|max:30',
            'license_number' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:30',
            'status_id' => 'nullable|exists:statuses,id',
            'specialties' => 'nullable|array',
            'specialties.*' => 'exists:specialties,id',
            'commission_percent' => 'nullable|numeric|min:0|max:100',
            'goal_month' => 'nullable|numeric|min:0|max:99999999',
            'online_booking' => 'sometimes|boolean',
            'color_code' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'bio' => 'sometimes|nullable|string|max:1000',
            'service_ids' => 'sometimes|array|max:500',
            'service_ids.*' => ['uuid', \Illuminate\Validation\Rule::exists('services', 'id')->where('tenant_id', $currentUser->tenant_id)],
            'future_action' => 'sometimes|nullable|in:keep',
        ], ['commission_percent.max' => 'La comisión no puede pasar de 100 %.', 'goal_month.min' => 'La meta no puede ser negativa.']);
        $commission = $this->commissionFields($request, $validated);

        $deactivating = !empty($validated['status_id'])
            && $validated['status_id'] !== $professional->status_id
            && \App\Models\Status::where('id', $validated['status_id'])->value('slug') === 'inactive';
        $future = $deactivating ? \App\Support\ProfessionalHistory::future($professional)->count() : 0;

        if ($future > 0 && ($validated['future_action'] ?? null) !== 'keep') {
            return response()->json([
                'code'         => 'HAS_FUTURE',
                'message'      => "{$professional->display_name} tiene {$future} " . ($future === 1 ? 'cita por venir' : 'citas por venir') . '. Pásalas a otra persona o confirma que quieres desactivarlo igual.',
                'future_count' => $future,
                'appointments' => \App\Support\ProfessionalHistory::futureList($professional),
            ], 422);
        }

        $auditBefore = $this->auditProfessional($professional);

        if (array_key_exists('service_ids', $validated) && $currentUser->allows('services.edit') && ($alone = \App\Support\ServiceProfessionals::lastOnes($professional, $validated['service_ids'] ?? []))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['service_ids' => 'Nadie más hace ' . implode(', ', $alone) . '. Para quitárselo, primero marca a otra persona en ese servicio (en Servicios).']);
        }

        if ($professional->user) {
            $userData = [];
            if (isset($validated['first_name'])) $userData['first_name'] = $validated['first_name'];
            if (isset($validated['last_name'])) $userData['last_name'] = $validated['last_name'];
            if (isset($validated['email'])) $userData['email'] = $validated['email'];
            
            if (!empty($userData)) {
                $professional->user->update($userData);
            }
        }

        $professional->update([
            'display_name' => $validated['display_name'] ?? $professional->display_name,
            'type_document_id' => array_key_exists('type_document_id', $validated) ? $validated['type_document_id'] : $professional->type_document_id,
            'document_number' => array_key_exists('document_number', $validated) ? $validated['document_number'] : $professional->document_number,
            'license_number' => array_key_exists('license_number', $validated) ? $validated['license_number'] : $professional->license_number,
            'phone' => array_key_exists('phone', $validated) ? $validated['phone'] : $professional->phone,
            'status_id' => $validated['status_id'] ?? $professional->status_id,
        ] + $commission + $this->onlineField($validated) + $this->profileFields($validated));

        if (isset($validated['specialties'])) {
            $syncData = [];
            foreach ($validated['specialties'] as $specialtyId) {
                $syncData[$specialtyId] = ['tenant_id' => $currentUser->tenant_id];
            }
            $professional->specialties()->sync($syncData);
        }

        $this->syncServices($request, $professional, $validated);
        $professional->load(['status', 'specialties', 'user', 'typeDocument']);
        [$from, $to] = Audit::changed($auditBefore, $this->auditProfessional($professional));
        if ($to) {
            $this->audit($request, 'professional.updated', $professional, $from, $to + ($future > 0 ? ['future_kept' => $future] : []));
        }

        return response()->json($professional->toArray() + ($future > 0 ? [
            'future_appointments' => $future,
            'notice'              => "Quedó inactivo. Sus {$future} " . ($future === 1 ? 'cita por venir sigue agendada' : 'citas por venir siguen agendadas') . ': pásalas a otra persona desde Citas.',
        ] : []));
    }

    public function destroy(Request $request, $id)
    {
        $currentUser = $request->user();

        $professional = Professional::where('tenant_id', $currentUser->tenant_id)
            ->findOrFail($id);
        $this->assertOwnBranch($currentUser, $professional->id);

        if (\App\Support\ProfessionalHistory::has($professional)) {
            $future = \App\Support\ProfessionalHistory::future($professional)->count();

            return response()->json([
                'code'           => 'HAS_HISTORY',
                'message'        => "No se puede eliminar a {$professional->display_name} porque ya tiene citas, ventas, fichas o comisiones. Desactívalo: ya no se podrá agendar y su historia y sus comisiones se guardan.",
                'can_deactivate' => true,
                'future_count'   => $future,
            ], 409);
        }

        $auditBefore = array_filter($this->auditProfessional($professional), fn ($value) => $value !== null && $value !== '' && $value !== []);
        \App\Support\ProfessionalHistory::forget($professional);
        $this->audit($request, 'professional.deleted', $professional, $auditBefore);

        return response()->json(['message' => 'Se eliminó correctamente.']);
    }
}