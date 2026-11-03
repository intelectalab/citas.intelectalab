<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Access;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AccessController extends Controller
{
    private function tenant(Request $request): Tenant
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 403, 'Esta cuenta no pertenece a una empresa.');
        abort_unless(Access::ready(), 503, Access::NOT_READY);

        Access::ensureRoles($tenant);

        return $tenant->loadMissing(['settings', 'activeSubscription.plan', 'businessType']);
    }

    private function findUser(Tenant $tenant, string $id): User
    {
        return User::where('tenant_id', $tenant->id)->findOrFail($id);
    }

    private function findRole(Tenant $tenant, string $id): Role
    {
        return Role::where('tenant_id', $tenant->id)->findOrFail($id);
    }

    private function auditUser(User $user, ?string $roleName): array
    {
        return [
            'name'           => trim("{$user->first_name} {$user->last_name}"),
            'email'          => $user->email,
            'phone'          => $user->phone,
            'role'           => $roleName,
            'branch'         => $user->branch_id ? DB::table('branches')->where('id', $user->branch_id)->value('name') : null,
            'account_active' => $user->status === 'active',
        ];
    }

    private function audit(Request $request, Tenant $tenant, string $action, string $type, string $id, ?string $label, array $before = [], array $after = [], ?string $branchId = null): void
    {
        Audit::log($request, $action, array_filter([
            'tenant'       => $tenant->id,
            'entity_type'  => $type,
            'entity_id'    => $id,
            'entity_label' => $label,
            'branch_id'    => $branchId,
            'before'       => $before ?: null,
            'after'        => $after ?: null,
        ], fn ($value) => $value !== null));
    }

    private function usage(Tenant $tenant): array
    {
        $limit = $tenant->activeSubscription?->plan?->limits['users'] ?? null;

        return ['used' => $tenant->usage()['users'], 'limit' => is_numeric($limit) ? (int) $limit : null];
    }

    private function limitReached(Tenant $tenant): ?string
    {
        $usage = $this->usage($tenant);

        return $usage['limit'] !== null && $usage['used'] >= $usage['limit']
            ? "Tu plan permite hasta {$usage['limit']} cuentas de usuario además de la del dueño y ya tienes {$usage['used']} activas. Para agregar otra, cambia de plan o desactiva una cuenta."
            : null;
    }

    private function lockedReason(User $target, User $actor, ?Role $role): ?string
    {
        if ($target->is_owner) {
            return 'Es la cuenta del dueño de la empresa: siempre puede todo.';
        }

        if ($target->id === $actor->id) {
            return 'Es tu propia cuenta: tus permisos los cambia otro administrador.';
        }

        return null;
    }

    private function staffOfBranch(User $actor): ?array
    {
        $limit = Access::branchLimit($actor);

        if ($limit === null) {
            return null;
        }

        return User::where('tenant_id', $actor->tenant_id)
            ->where(fn ($q) => $q->where('branch_id', $limit)
                ->orWhereIn('id', DB::table('professionals')->whereIn('id', Access::professionalsAt($limit))->whereNotNull('user_id')->select('user_id')))
            ->pluck('id')
            ->push($actor->id)
            ->all();
    }

    private function assertMyStaff(User $actor, User $target): void
    {
        $mine = $this->staffOfBranch($actor);
        abort_if($mine !== null && !in_array($target->id, $mine, true), 403, 'Esta persona no es de tu sede. ' . Access::onlyBranch($actor));
    }

    private function assertWholeCompany(User $actor): void
    {
        abort_if(Access::branchLimit($actor) !== null, 403, 'Los roles valen para todas las sedes: los crea y los cambia quien administra la empresa.');
    }

    private function scopeOfRole(Role $role): string
    {
        return Access::scopeReady() ? $role->scope : (Access::FACTORY[$role->slug]['scope'] ?? 'tenant');
    }

    private function openCashOf(User $user): ?object
    {
        return DB::table('cash_sessions as s')
            ->join('cash_registers as r', 'r.id', '=', 's.cash_register_id')
            ->join('branches as b', 'b.id', '=', 's.branch_id')
            ->where('s.opened_by', $user->id)->where('s.status', 'open')
            ->first(['s.id', 's.branch_id', 'r.name as register_name', 'b.name as branch_name']);
    }

    private function isAdminRole(?Role $role): bool
    {
        return in_array($role?->slug, ['owner', 'admin'], true);
    }

    private function presentRole(?Role $role): ?array
    {
        return $role ? ['id' => $role->id, 'slug' => $role->slug, 'name' => $role->name, 'scope' => $this->scopeOfRole($role)] : null;
    }

    private function roleOptions(Tenant $tenant, ?User $actor = null): array
    {
        $scoped = $actor && Access::branchLimit($actor) !== null && Access::scopeReady();

        return Role::where('tenant_id', $tenant->id)->when($scoped, fn ($q) => $q->where('scope', '!=', 'tenant'))->get()
            ->sortBy(fn (Role $r) => [array_search($r->slug, array_keys(Access::FACTORY), true) === false ? 99 : array_search($r->slug, array_keys(Access::FACTORY), true), $r->name])
            ->map(fn (Role $r) => [
                'id'          => $r->id,
                'slug'        => $r->slug,
                'name'        => $r->name,
                'description' => $r->description,
                'is_system'   => (bool) $r->is_system,
                'scope'       => Access::scopeReady() ? $r->scope : (Access::FACTORY[$r->slug]['scope'] ?? 'tenant'),
            ])->values()->all();
    }

    private function branchOptions(Tenant $tenant, ?User $actor = null): array
    {
        return DB::table('branches')->where('tenant_id', $tenant->id)->where('status', 'active')
            ->when($actor ? Access::branchLimit($actor) : null, fn ($q, $id) => $q->where('id', $id))
            ->orderBy('name')->get(['id', 'name'])->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->all();
    }

    public function users(Request $request)
    {
        $tenant = $this->tenant($request);
        $actor = $request->user();

        $mine = $this->staffOfBranch($actor);
        $users = User::where('tenant_id', $tenant->id)->when($mine !== null, fn ($q) => $q->whereIn('id', $mine))
            ->with('roles')->orderBy('first_name')->orderBy('last_name')->get();
        $limit = Access::branchLimit($actor);
        $professionals = DB::table('professionals')->where('tenant_id', $tenant->id)->whereNotNull('user_id')->pluck('id', 'user_id');
        $branches = DB::table('branches')->where('tenant_id', $tenant->id)->pluck('name', 'id');
        $exceptions = DB::table('user_permissions')->where('tenant_id', $tenant->id)
            ->selectRaw('user_id, count(*) filter (where granted) as granted, count(*) filter (where not granted) as revoked')
            ->groupBy('user_id')->get()->keyBy('user_id');

        return response()->json([
            'usage'    => $this->usage($tenant),
            'blocked'  => $this->limitReached($tenant),
            'can'      => ['create' => $actor->allows('users.create'), 'edit' => $actor->allows('users.edit')],
            'roles'    => $this->roleOptions($tenant, $actor),
            'branches' => $this->branchOptions($tenant, $actor),
            'my_branch' => $limit ? ['id' => $limit, 'name' => DB::table('branches')->where('id', $limit)->value('name')] : null,
            'users'    => $users->map(function (User $u) use ($actor, $professionals, $branches, $exceptions, $tenant) {
                $role = $u->roles->firstWhere('tenant_id', $tenant->id);

                return [
                    'id'              => $u->id,
                    'name'            => \App\Support\Person::name($u),
                    'first_name'      => $u->first_name,
                    'last_name'       => $u->last_name,
                    'email'           => $u->email,
                    'phone'           => $u->phone,
                    'status'          => $u->status,
                    'is_owner'        => (bool) $u->is_owner,
                    'is_self'         => $u->id === $actor->id,
                    'role'            => $this->presentRole($role),
                    'branch'          => $u->branch_id ? ['id' => $u->branch_id, 'name' => $branches[$u->branch_id] ?? ''] : null,
                    'professional_id' => $professionals[$u->id] ?? null,
                    'last_login_at'   => $u->last_login_at ?? null,
                    'security'        => \App\Support\AccountSecurity::present($u),
                    'granted'         => (int) ($exceptions[$u->id]->granted ?? 0),
                    'revoked'         => (int) ($exceptions[$u->id]->revoked ?? 0),
                    'created_at'      => $u->created_at,
                ];
            })->values(),
        ]);
    }

    public function showUser(Request $request, string $user)
    {
        $tenant = $this->tenant($request);
        $actor = $request->user();
        $target = $this->findUser($tenant, $user);
        $this->assertMyStaff($actor, $target);
        $role = Access::roleOf($target);
        $everything = $target->isTenantAdmin();

        return response()->json([
            'user' => [
                'id'              => $target->id,
                'name'            => \App\Support\Person::name($target),
                'first_name'      => $target->first_name,
                'last_name'       => $target->last_name,
                'email'           => $target->email,
                'phone'           => $target->phone,
                'status'          => $target->status,
                'is_owner'        => (bool) $target->is_owner,
                'is_self'         => $target->id === $actor->id,
                'role'            => $this->presentRole($role),
                'branch'          => $target->branch_id ? ['id' => $target->branch_id, 'name' => DB::table('branches')->where('id', $target->branch_id)->value('name')] : null,
                'professional_id' => DB::table('professionals')->where('tenant_id', $tenant->id)->where('user_id', $target->id)->value('id'),
                'created_at'      => $target->created_at,
                'last_login_at'   => $target->last_login_at ?? null,
                'security'        => \App\Support\AccountSecurity::present($target),
            ],
            'everything' => $everything,
            'locked'     => $this->lockedReason($target, $actor, $role)
                ?? ($everything ? 'Tiene el rol Administrador: puede todo y no se le quitan permisos. Para limitarla, cámbiale el rol.' : null),
            'can_edit'   => $actor->allows('users.edit'),
            'grid'       => Access::grid($tenant, $role ? Access::rolePermissions($role->id) : [], Access::overrides($target->id), $everything),
            'roles'      => $this->roleOptions($tenant, $actor),
            'branches'   => $this->branchOptions($tenant, $actor),
            'scope'      => Access::scopeOf($target),
            'scope_label' => Access::SCOPES[Access::scopeOf($target)],
            'admin_only'  => Access::adminOnly($tenant),
            'history'     => Access::history($tenant->id, 'user', $target->id),
        ]);
    }

    private function validateUser(Request $request, Tenant $tenant, ?User $target = null): array
    {
        $request->merge([
            'phone'     => $request->phone ?: null,
            'branch_id' => $request->branch_id ?: null,
            'email'     => is_string($request->email) ? Str::lower(trim($request->email)) : $request->email,
        ]);

        return $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'email'      => ['required', 'email', 'max:190', Rule::unique('users', 'email')->where('tenant_id', $tenant->id)->ignore($target?->id)],
            'phone'      => 'nullable|string|max:30',
            'role_id'    => ['required', 'uuid', Rule::exists('roles', 'id')->where('tenant_id', $tenant->id)],
            'branch_id'  => ['nullable', 'uuid', Rule::exists('branches', 'id')->where('tenant_id', $tenant->id)],
            'status'     => 'sometimes|in:active,inactive',
        ], [
            'email.unique'     => 'Ya hay una cuenta con ese correo en tu empresa.',
            'email.email'      => 'Escribe un correo válido, por ejemplo ana@miempresa.pe.',
            'role_id.required' => 'Elige el rol de esta persona.',
            'first_name.required' => 'Escribe sus nombres.',
            'last_name.required'  => 'Escribe sus apellidos.',
            'email.required'      => 'Escribe su correo: con él entra al sistema.',
        ]);
    }

    private function assertRoleAllowed(Role $role, User $actor, bool $isProfessional, ?string $branchId): void
    {
        abort_if($this->isAdminRole($role) && !$actor->isTenantAdmin(), 403, 'Solo un administrador puede dar el rol Administrador.');
        abort_if($role->slug === 'professional' && !$isProfessional, 422, 'El rol Profesional es para quien atiende citas: regístralo desde la pantalla de profesionales y su cuenta se crea sola.');
        abort_if($role->slug !== 'professional' && $isProfessional, 422, 'Esta persona atiende citas, así que conserva el rol Profesional. Si necesita hacer algo más, enciéndele esos permisos en su página.');
        abort_if($role->slug === 'manager' && !$branchId, 422, 'Elige la sede que tiene a su cargo.');

        $scope = Access::scopeReady() ? $role->scope : (Access::FACTORY[$role->slug]['scope'] ?? 'tenant');
        abort_if($scope === 'branch' && !$branchId, 422, "Quien tiene el rol {$role->name} trabaja solo con su sede: elige cuál es.");

        if (($limit = Access::branchLimit($actor)) !== null) {
            abort_if($scope === 'tenant', 403, "El rol {$role->name} ve toda la empresa: solo lo da quien administra la empresa.");
            abort_if($branchId !== $limit, 403, 'Solo puedes registrar personal de tu sede. ' . Access::onlyBranch($actor));
        }
    }

    private function setRole(User $user, Role $role): void
    {
        DB::table('user_roles')->where('user_id', $user->id)->delete();
        DB::table('user_roles')->insert(['tenant_id' => $user->tenant_id, 'user_id' => $user->id, 'role_id' => $role->id]);
    }

    public function storeUser(Request $request)
    {
        $tenant = $this->tenant($request);
        $actor = $request->user();

        if ($blocked = $this->limitReached($tenant)) {
            return response()->json(['message' => $blocked, 'code' => 'PLAN_LIMIT'], 422);
        }

        $validated = $this->validateUser($request, $tenant);
        $validated['branch_id'] = ($validated['branch_id'] ?? null) ?: Access::branchLimit($actor);
        $role = $this->findRole($tenant, $validated['role_id']);
        $this->assertRoleAllowed($role, $actor, false, $validated['branch_id'] ?? null);

        $password = Str::password(10, symbols: false);

        $user = DB::transaction(function () use ($validated, $tenant, $role, $password) {
            $user = User::create([
                'id'            => (string) Str::uuid(),
                'tenant_id'     => $tenant->id,
                'branch_id'     => $validated['branch_id'] ?? null,
                'first_name'    => $validated['first_name'],
                'last_name'     => $validated['last_name'],
                'email'         => Str::lower($validated['email']),
                'phone'         => $validated['phone'] ?? null,
                'password_hash' => Hash::make($password),
                'status'        => 'active',
            ]);

            $this->setRole($user, $role);

            return $user;
        });

        Access::logChange($request, $tenant, 'user', $user->id, "Creó la cuenta con el rol {$role->name}.");
        $this->audit($request, $tenant, 'user.created', 'user', $user->id, trim("{$user->first_name} {$user->last_name}"), [], array_filter($this->auditUser($user, $role->name), fn ($value) => $value !== null && $value !== ''), $user->branch_id);

        return response()->json(['id' => $user->id, 'email' => $user->email, 'temporary_password' => $password], 201);
    }

    public function updateUser(Request $request, string $user)
    {
        $tenant = $this->tenant($request);
        $actor = $request->user();
        $target = $this->findUser($tenant, $user);
        $this->assertMyStaff($actor, $target);
        $validated = $this->validateUser($request, $tenant, $target);
        if (Access::branchLimit($actor) !== null && !DB::table('professionals')->where('user_id', $target->id)->exists()) {
            $validated['branch_id'] = Access::branchLimit($actor);
        }
        $role = $this->findRole($tenant, $validated['role_id']);
        $current = Access::roleOf($target);
        $status = $validated['status'] ?? $target->status;
        $roleChanges = $current?->id !== $role->id;
        $statusChanges = $status !== $target->status;

        if ($roleChanges || $statusChanges) {
            abort_if($target->is_owner, 422, 'La cuenta del dueño no cambia de rol ni se desactiva.');
            abort_if($target->id === $actor->id, 422, 'No puedes cambiar tu propio rol ni desactivar tu propia cuenta: pídeselo a otro administrador.');
            abort_if($this->isAdminRole($current) && !$actor->isTenantAdmin(), 403, 'Solo un administrador puede cambiar a otro administrador.');
        }

        if ($roleChanges) {
            $isProfessional = DB::table('professionals')->where('tenant_id', $tenant->id)->where('user_id', $target->id)->exists();
            $this->assertRoleAllowed($role, $actor, $isProfessional, $validated['branch_id'] ?? null);
        } else {
            abort_if($this->scopeOfRole($role) === 'branch' && empty($validated['branch_id']), 422, "Quien tiene el rol {$role->name} trabaja solo con su sede: elige cuál es.");
            abort_if(($validated['branch_id'] ?? null) !== $target->branch_id && Access::branchLimit($actor) !== null && ($validated['branch_id'] ?? null) !== Access::branchLimit($actor), 403, Access::otherBranch($actor));
        }

        $branchChanges = ($validated['branch_id'] ?? null) !== $target->branch_id;

        if (($branchChanges || ($statusChanges && $status !== 'active')) && ($cash = $this->openCashOf($target))) {
            return response()->json([
                'message' => "{$target->first_name} tiene abierta la {$cash->register_name} de {$cash->branch_name}. Que cierre su turno en Caja antes de "
                    . ($branchChanges ? 'cambiarle la sede' : 'desactivar su cuenta') . '.',
                'code'    => 'OPEN_CASH',
            ], 422);
        }

        if ($statusChanges && $status === 'active' && ($blocked = $this->limitReached($tenant))) {
            return response()->json(['message' => $blocked, 'code' => 'PLAN_LIMIT'], 422);
        }

        $auditBefore = $this->auditUser($target, $current?->name);
        $branchBefore = $target->branch_id;

        DB::transaction(function () use ($target, $validated, $role, $roleChanges, $status) {
            $target->update([
                'first_name' => $validated['first_name'],
                'last_name'  => $validated['last_name'],
                'email'      => Str::lower($validated['email']),
                'phone'      => $validated['phone'] ?? null,
                'branch_id'  => $validated['branch_id'] ?? null,
                'status'     => $status,
            ]);

            if ($roleChanges) {
                $this->setRole($target, $role);
                DB::table('user_permissions')->where('user_id', $target->id)->delete();
            }

            if ($status !== 'active') {
                $target->tokens()->delete();
            }
        });

        if ($roleChanges) {
            Access::logChange($request, $tenant, 'user', $target->id, "Le cambió el rol de " . ($current?->name ?? 'ninguno') . " a {$role->name}. Se borraron sus permisos propios.");
        }
        if ($statusChanges) {
            Access::logChange($request, $tenant, 'user', $target->id, $status === 'active' ? 'Activó la cuenta.' : 'Desactivó la cuenta.');
        }
        if ($branchChanges) {
            $branchName = ($validated['branch_id'] ?? null) ? DB::table('branches')->where('id', $validated['branch_id'])->value('name') : null;
            Access::logChange($request, $tenant, 'user', $target->id, $branchName ? "Le cambió la sede a {$branchName}." : 'Le quitó la sede.');
        }

        $auditAfter = $this->auditUser($target, $role->name);
        $label = $auditAfter['name'];
        if ($statusChanges) {
            $this->audit($request, $tenant, $status === 'active' ? 'user.activated' : 'user.deactivated', 'user', $target->id, $label, [], [], $target->branch_id);
            unset($auditBefore['account_active'], $auditAfter['account_active']);
        }
        [$from, $to] = Audit::changed($auditBefore, $auditAfter);
        if ($to) {
            $this->audit($request, $tenant, 'user.updated', 'user', $target->id, $label, $from, $to, $target->branch_id ?? $branchBefore);
        }

        return response()->json(['id' => $target->id]);
    }

    public function resetPassword(Request $request, string $user)
    {
        $tenant = $this->tenant($request);
        $actor = $request->user();
        $target = $this->findUser($tenant, $user);
        $this->assertMyStaff($actor, $target);

        abort_if($target->is_owner, 422, 'La contraseña del dueño no se cambia desde aquí.');
        abort_if($target->id === $actor->id, 422, 'Esta es tu propia cuenta.');
        abort_if($this->isAdminRole(Access::roleOf($target)) && !$actor->isTenantAdmin(), 403, 'Solo un administrador puede cambiar a otro administrador.');

        $password = Str::password(10, symbols: false);
        $target->update(['password_hash' => Hash::make($password)]);
        $target->tokens()->delete();
        Access::logChange($request, $tenant, 'user', $target->id, 'Le dio una contraseña nueva.');
        $this->audit($request, $tenant, 'user.password_reset', 'user', $target->id, trim("{$target->first_name} {$target->last_name}"), [], [], $target->branch_id);

        return response()->json(['email' => $target->email, 'temporary_password' => $password]);
    }

    public function security(Request $request, string $user)
    {
        $tenant = $this->tenant($request);
        $actor = $request->user();
        $target = $this->findUser($tenant, $user);
        $this->assertMyStaff($actor, $target);
        abort_unless(\App\Support\AccountSecurity::ready(), 503, 'Falta actualizar la base de datos: ejecuta php artisan migrate.');

        $validated = $request->validate(['action' => ['required', Rule::in(['unlock', 'require_code', 'default_code'])]]);
        abort_if($validated['action'] !== 'unlock' && !$actor->isTenantAdmin(), 403, 'Solo un administrador cambia quién entra con código.');
        abort_if($validated['action'] !== 'unlock' && $target->mfa_mode === 'off', 422, 'El equipo de la plataforma le quitó el código de ingreso a esta persona. Pídeles que lo vuelvan a activar.');
        $name = trim("{$target->first_name} {$target->last_name}");

        if ($validated['action'] === 'unlock') {
            \App\Support\AccountSecurity::unlock($target);
            Access::logChange($request, $tenant, 'user', $target->id, 'Le quitó el bloqueo por intentos fallidos.');
            $this->audit($request, $tenant, 'user.unlocked', 'user', $target->id, $name, [], [], $target->branch_id);
        } else {
            $mode = $validated['action'] === 'require_code' ? 'required' : null;
            $before = $target->mfa_mode;
            $target->forceFill(['mfa_mode' => $mode])->saveQuietly();
            Access::logChange($request, $tenant, 'user', $target->id, $mode ? 'Le pidió el código por correo al entrar.' : 'Volvió a lo de su rol para el código al entrar.');
            $this->audit($request, $tenant, 'user.mfa_changed', 'user', $target->id, $name, ['mfa_mode' => $before ? 'Siempre' : 'Según su rol'], ['mfa_mode' => $mode ? 'Siempre' : 'Según su rol'], $target->branch_id);
        }

        return $this->showUser($request, $user);
    }

    public function setUserPermission(Request $request, string $user)
    {
        $tenant = $this->tenant($request);
        $actor = $request->user();
        $target = $this->findUser($tenant, $user);
        $this->assertMyStaff($actor, $target);
        $role = Access::roleOf($target);

        $validated = $request->validate(['permission' => ['required', Rule::in(Access::all())], 'on' => 'required|boolean'], ['permission.in' => 'Ese permiso no existe.']);
        $name = $validated['permission'];
        $on = (bool) $validated['on'];

        abort_if($locked = $this->lockedReason($target, $actor, $role), 422, $locked ?? '');
        abort_if($target->isTenantAdmin(), 422, 'Tiene el rol Administrador: puede todo y no se le quitan permisos. Para limitarla, cámbiale el rol.');
        abort_unless($role, 422, 'Primero dale un rol a esta persona.');
        abort_unless($actor->allows($name), 403, 'No puedes dar ni quitar un permiso que tú no tienes.');

        $permissionId = Access::permissionIds()[$name];
        $fromRole = in_array($name, Access::rolePermissions($role->id), true);

        if ($on === $fromRole) {
            DB::table('user_permissions')->where('user_id', $target->id)->where('permission_id', $permissionId)->delete();
        } else {
            DB::table('user_permissions')->updateOrInsert(
                ['user_id' => $target->id, 'permission_id' => $permissionId],
                ['tenant_id' => $tenant->id, 'granted' => $on, 'created_by' => $actor->id, 'created_at' => now()],
            );
        }

        $what = Access::permissionText($name, $tenant);
        Access::logChange($request, $tenant, 'user', $target->id, $on === $fromRole ? "Lo dejó como su rol: {$what}." : ($on ? "Le agregó: {$what}." : "Le quitó: {$what}."));

        return $this->showUser($request, $target->id);
    }

    public function resetUserPermissions(Request $request, string $user)
    {
        $tenant = $this->tenant($request);
        $actor = $request->user();
        $target = $this->findUser($tenant, $user);
        $this->assertMyStaff($actor, $target);

        abort_if($locked = $this->lockedReason($target, $actor, Access::roleOf($target)), 422, $locked ?? '');

        $removed = DB::table('user_permissions')->where('user_id', $target->id)->delete();

        if ($removed > 0) {
            Access::logChange($request, $tenant, 'user', $target->id, "La volvió a lo de su rol: se borraron {$removed} " . ($removed === 1 ? 'cambio propio' : 'cambios propios') . '.');
        }

        return $this->showUser($request, $target->id);
    }

    private function presentRoleRow(Role $role, int $users, int $permissions, int $total): array
    {
        $admin = $this->isAdminRole($role);

        return [
            'id'          => $role->id,
            'slug'        => $role->slug,
            'name'        => $role->name,
            'description' => $role->description,
            'is_system'   => (bool) $role->is_system,
            'scope'       => Access::scopeReady() ? $role->scope : (Access::FACTORY[$role->slug]['scope'] ?? 'tenant'),
            'locked'      => $admin,
            'users'       => $users,
            'permissions' => $admin ? $total : $permissions,
            'total'       => $total,
            'created_at'  => $role->created_at,
        ];
    }

    public function roles(Request $request)
    {
        $tenant = $this->tenant($request);
        $actor = $request->user();
        $total = collect(Access::grid($tenant, []))->sum(fn ($m) => count($m['actions']));
        $shown = collect(Access::grid($tenant, []))->flatMap(fn ($m) => array_column($m['actions'], 'name'))->all();
        $users = DB::table('user_roles')->join('users', 'users.id', '=', 'user_roles.user_id')
            ->where('users.tenant_id', $tenant->id)->selectRaw('user_roles.role_id, count(*) as total')->groupBy('user_roles.role_id')->pluck('total', 'role_id');

        $whole = Access::branchLimit($actor) === null;

        return response()->json([
            'can'   => ['create' => $whole && $actor->allows('users.create'), 'edit' => $whole && $actor->allows('users.edit'), 'delete' => $whole && $actor->allows('users.delete')],
            'roles' => collect($this->roleOptions($tenant))->map(function (array $option) use ($tenant, $users, $total, $shown) {
                $role = Role::find($option['id']);

                return $this->presentRoleRow($role, (int) ($users[$role->id] ?? 0), count(array_intersect(Access::rolePermissions($role->id), $shown)), $total);
            })->values(),
        ]);
    }

    public function showRole(Request $request, string $role)
    {
        $tenant = $this->tenant($request);
        $actor = $request->user();
        $role = $this->findRole($tenant, $role);
        $admin = $this->isAdminRole($role);
        $names = Access::rolePermissions($role->id);
        $grid = Access::grid($tenant, $names, [], $admin);
        $total = collect($grid)->sum(fn ($m) => count($m['actions']));
        $on = collect($grid)->sum(fn ($m) => count(array_filter($m['actions'], fn ($a) => $a['on'])));
        $professionals = DB::table('professionals')->where('tenant_id', $tenant->id)->whereNotNull('user_id')->pluck('id', 'user_id');

        $people = User::where('tenant_id', $tenant->id)
            ->whereIn('id', DB::table('user_roles')->where('role_id', $role->id)->pluck('user_id'))
            ->orderBy('first_name')->get();

        $whole = Access::branchLimit($actor) === null;
        $mine = $this->staffOfBranch($actor);
        $people = $mine === null ? $people : $people->whereIn('id', $mine)->values();

        return response()->json([
            'role'        => $this->presentRoleRow($role, $people->count(), $on, $total),
            'can_edit'    => $whole && $actor->allows('users.edit') && !$admin,
            'can_delete'  => $whole && $actor->allows('users.delete') && !$role->is_system,
            'can_restore' => $whole && $actor->allows('users.edit') && $role->is_system && !$admin,
            'scopes'      => Access::SCOPES,
            'scope_ready' => Access::scopeReady(),
            'grid'        => $grid,
            'admin_only'  => Access::adminOnly($tenant),
            'history'     => Access::history($tenant->id, 'role', $role->id),
            'users'       => $people->map(fn (User $u) => [
                'id'              => $u->id,
                'name'            => \App\Support\Person::name($u),
                'email'           => $u->email,
                'status'          => $u->status,
                'is_owner'        => (bool) $u->is_owner,
                'professional_id' => $professionals[$u->id] ?? null,
            ])->values(),
        ]);
    }

    public function storeRole(Request $request)
    {
        $tenant = $this->tenant($request);
        $actor = $request->user();
        $this->assertWholeCompany($actor);

        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->where('tenant_id', $tenant->id)],
            'description' => 'nullable|string|max:255',
            'copy_from'   => ['nullable', 'uuid', Rule::exists('roles', 'id')->where('tenant_id', $tenant->id)],
        ], [
            'name.required' => 'Ponle un nombre al rol, por ejemplo "Asistente".',
            'name.unique'   => 'Ya hay un rol con ese nombre.',
        ]);

        $source = !empty($validated['copy_from']) ? $this->findRole($tenant, $validated['copy_from']) : null;
        $names = $source ? ($this->isAdminRole($source) ? Access::all() : Access::rolePermissions($source->id)) : [];
        $names = array_values(array_filter($names, fn (string $name) => $actor->allows($name)));

        $role = DB::transaction(function () use ($tenant, $validated, $names, $source) {
            $role = Role::create([
                'tenant_id'             => $tenant->id,
                'name'                  => $validated['name'],
                'slug'                  => 'custom-' . Str::lower(Str::random(10)),
                'description'           => $validated['description'] ?? null,
                'is_system'             => false,
                'permissions_seeded_at' => now(),
            ] + (Access::scopeReady() ? ['scope' => $source && $source->scope !== 'own' && !$this->isAdminRole($source) ? $source->scope : 'tenant'] : []));

            Access::setRolePermissions($role->id, $names);

            return $role;
        });

        Access::logChange($request, $tenant, 'role', $role->id, $source ? "Creó el rol copiando a {$source->name}." : 'Creó el rol, sin permisos.');
        $this->audit($request, $tenant, 'role.created', 'role', $role->id, $role->name, [], array_filter([
            'name'        => $role->name,
            'description' => $role->description,
            'copied_from' => $source?->name,
        ]));

        return response()->json(['id' => $role->id], 201);
    }

    public function updateRole(Request $request, string $role)
    {
        $tenant = $this->tenant($request);
        $this->assertWholeCompany($request->user());
        $role = $this->findRole($tenant, $role);

        abort_if($this->isAdminRole($role), 422, 'El rol Administrador no se cambia.');

        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->where('tenant_id', $tenant->id)->ignore($role->id)],
            'description' => 'nullable|string|max:255',
        ], [
            'name.required' => 'El rol necesita un nombre.',
            'name.unique'   => 'Ya hay un rol con ese nombre.',
        ]);

        $oldName = $role->name;
        $roleBefore = ['name' => $role->name, 'description' => $role->description];
        $role->update(['name' => $validated['name'], 'description' => $validated['description'] ?? null]);

        [$from, $to] = Audit::changed($roleBefore, ['name' => $role->name, 'description' => $role->description]);
        if ($to) {
            $this->audit($request, $tenant, 'role.updated', 'role', $role->id, $role->name, $from, $to);
        }

        if ($oldName !== $role->name) {
            Access::logChange($request, $tenant, 'role', $role->id, "Le cambió el nombre de {$oldName} a {$role->name}.");
        }

        return response()->json(['id' => $role->id]);
    }

    public function setRolePermission(Request $request, string $role)
    {
        $tenant = $this->tenant($request);
        $actor = $request->user();
        $this->assertWholeCompany($actor);
        $role = $this->findRole($tenant, $role);

        $validated = $request->validate(['permission' => ['required', Rule::in(Access::all())], 'on' => 'required|boolean'], ['permission.in' => 'Ese permiso no existe.']);
        $name = $validated['permission'];

        abort_if($this->isAdminRole($role), 422, 'El rol Administrador puede todo y no se recorta.');
        abort_unless($actor->allows($name), 403, 'No puedes dar ni quitar un permiso que tú no tienes.');
        abort_if(Access::roleOf($actor)?->id === $role->id && !$actor->isTenantAdmin(), 422, 'Es tu propio rol: sus permisos los cambia un administrador.');

        $permissionId = Access::permissionIds()[$name];

        if ($validated['on']) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permissionId]);
        } else {
            DB::table('role_permissions')->where('role_id', $role->id)->where('permission_id', $permissionId)->delete();
        }

        DB::table('user_permissions')->where('permission_id', $permissionId)->where('granted', (bool) $validated['on'])
            ->whereIn('user_id', DB::table('user_roles')->where('role_id', $role->id)->pluck('user_id'))->delete();

        $what = Access::permissionText($name, $tenant);
        Access::logChange($request, $tenant, 'role', $role->id, $validated['on'] ? "Encendió: {$what}." : "Apagó: {$what}.");

        return $this->showRole($request, $role->id);
    }

    public function setRoleScope(Request $request, string $role)
    {
        $tenant = $this->tenant($request);
        $this->assertWholeCompany($request->user());
        abort_unless(Access::scopeReady(), 503, Access::NOT_READY);
        $role = $this->findRole($tenant, $role);

        $validated = $request->validate(['scope' => ['required', Rule::in(array_keys(Access::SCOPES))]], ['scope.in' => 'Elige qué tanto ve.']);

        abort_if($this->isAdminRole($role), 422, 'El Administrador ve toda la empresa.');
        abort_if($role->slug === 'professional' && $validated['scope'] !== 'own', 422, 'El rol Profesional ve solo lo suyo: sus citas, sus fichas y sus controles.');
        abort_if($role->slug !== 'professional' && $validated['scope'] === 'own', 422, '"Solo lo suyo" es para quien atiende citas: el rol Profesional.');

        if ($validated['scope'] === 'branch') {
            $missing = User::whereIn('id', DB::table('user_roles')->where('role_id', $role->id)->pluck('user_id'))
                ->whereNull('branch_id')->orderBy('first_name')->get();
            $assign = (array) $request->input('branches', []);
            $active = DB::table('branches')->where('tenant_id', $tenant->id)->where('status', 'active')->pluck('id')->all();
            $pending = $missing->reject(fn (User $u) => in_array($assign[$u->id] ?? null, $active, true));

            if ($pending->isNotEmpty()) {
                return response()->json([
                    'message'  => ($missing->count() === 1 ? 'Una persona con este rol no tiene' : "{$missing->count()} personas con este rol no tienen")
                        . ' sede asignada. Elige la sede de cada una para que siga viendo su agenda, su caja y sus ventas.',
                    'code'     => 'NEEDS_BRANCH',
                    'people'   => $missing->map(fn (User $u) => ['id' => $u->id, 'name' => \App\Support\Person::name($u), 'email' => $u->email])->values(),
                    'branches' => $this->branchOptions($tenant),
                ], 422);
            }

            DB::transaction(function () use ($missing, $assign, $role) {
                foreach ($missing as $user) {
                    $user->update(['branch_id' => $assign[$user->id]]);
                }
                $role->update(['scope' => 'branch']);
            });
            Access::logChange($request, $tenant, 'role', $role->id, 'Cambió qué ve: ' . Access::SCOPES['branch'] . ($missing->count() ? " y asignó sede a {$missing->count()} " . ($missing->count() === 1 ? 'persona' : 'personas') : '') . '.');

            return response()->json($this->showRole($request, $role->id)->getData(true) + ['assigned' => $missing->count()]);
        }

        $role->update(['scope' => $validated['scope']]);
        Access::logChange($request, $tenant, 'role', $role->id, 'Cambió qué ve: ' . Access::SCOPES[$validated['scope']] . '.');

        return response()->json($this->showRole($request, $role->id)->getData(true) + ['assigned' => 0]);
    }

    public function restoreRole(Request $request, string $role)
    {
        $tenant = $this->tenant($request);
        $this->assertWholeCompany($request->user());
        $role = $this->findRole($tenant, $role);

        abort_unless($role->is_system && isset(Access::FACTORY[$role->slug]) && !$this->isAdminRole($role), 422, 'Solo los roles de fábrica se pueden restablecer.');
        abort_unless($request->user()->isTenantAdmin(), 403, 'Solo un administrador restablece un rol de fábrica.');

        Access::setRolePermissions($role->id, Access::factoryPermissions($role->slug));
        if (Access::scopeReady()) {
            $role->update(['scope' => Access::FACTORY[$role->slug]['scope']]);
        }
        Access::logChange($request, $tenant, 'role', $role->id, 'Lo volvió a como viene de fábrica.');

        return $this->showRole($request, $role->id);
    }

    public function destroyRole(Request $request, string $role)
    {
        $tenant = $this->tenant($request);
        $this->assertWholeCompany($request->user());
        $role = $this->findRole($tenant, $role);

        abort_if($role->is_system, 422, 'Los roles de fábrica no se eliminan.');

        $people = DB::table('user_roles')->where('role_id', $role->id)->count();
        abort_if($people > 0, 422, $people === 1
            ? 'Una persona tiene este rol. Cámbiale el rol antes de eliminarlo.'
            : "{$people} personas tienen este rol. Cámbiales el rol antes de eliminarlo.");

        $role->delete();
        $this->audit($request, $tenant, 'role.deleted', 'role', $role->id, $role->name, array_filter(['name' => $role->name, 'description' => $role->description]));

        return response()->json(['deleted' => true]);
    }
}
