<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Usuarios y roles de la empresa: quién entra al sistema, con qué rol y qué
 * puede hacer cada quien. Las rutas ya exigen el permiso users.*; aquí van las
 * reglas que protegen a la empresa de quedarse sin administrador.
 */
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

    /** Cuentas que el plan permite además de la del dueño, y cuántas hay activas. */
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

    /** Por qué no se le pueden cambiar el rol ni los permisos a esta persona; null si se puede. */
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

    /**
     * Gente de la sede de quien consulta: con esa sede asignada, o profesionales
     * que atienden ahí. null si quien consulta ve toda la empresa.
     */
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

    /** Los roles valen para toda la empresa: los cambia quien ve toda la empresa. */
    private function assertWholeCompany(User $actor): void
    {
        abort_if(Access::branchLimit($actor) !== null, 403, 'Los roles valen para todas las sedes: los crea y los cambia quien administra la empresa.');
    }

    private function isAdminRole(?Role $role): bool
    {
        return in_array($role?->slug, ['owner', 'admin'], true);
    }

    private function presentRole(?Role $role): ?array
    {
        return $role ? ['id' => $role->id, 'slug' => $role->slug, 'name' => $role->name] : null;
    }

    private function roleOptions(Tenant $tenant, ?User $actor = null): array
    {
        // Quien trabaja solo con su sede no da roles que ven toda la empresa.
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

    // ---------------------------------------------------------------- Usuarios

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
            // La sede con la que trabaja quien consulta; null si ve toda la empresa.
            'my_branch' => $limit ? ['id' => $limit, 'name' => DB::table('branches')->where('id', $limit)->value('name')] : null,
            'users'    => $users->map(function (User $u) use ($actor, $professionals, $branches, $exceptions, $tenant) {
                $role = $u->roles->firstWhere('tenant_id', $tenant->id);

                return [
                    'id'              => $u->id,
                    'name'            => trim("{$u->first_name} {$u->last_name}"),
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
                'name'            => trim("{$target->first_name} {$target->last_name}"),
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
        ]);
    }

    private function validateUser(Request $request, Tenant $tenant, ?User $target = null): array
    {
        // El correo se guarda en minúsculas: así "Ana@..." y "ana@..." no pasan por dos cuentas.
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

    /** Reglas del rol elegido: quién puede darlo y a quién. */
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
            // Su personal no se le va a otra sede.
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
            abort_if($role->slug === 'manager' && empty($validated['branch_id']), 422, 'Elige la sede que tiene a su cargo.');
            abort_if(($validated['branch_id'] ?? null) !== $target->branch_id && Access::branchLimit($actor) !== null && ($validated['branch_id'] ?? null) !== Access::branchLimit($actor), 403, Access::otherBranch($actor));
        }

        // Volver a activar una cuenta ocupa otra vez un lugar del plan.
        if ($statusChanges && $status === 'active' && ($blocked = $this->limitReached($tenant))) {
            return response()->json(['message' => $blocked, 'code' => 'PLAN_LIMIT'], 422);
        }

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
                // Lo que se le quitó o agregó era respecto de su rol anterior.
                DB::table('user_permissions')->where('user_id', $target->id)->delete();
            }

            // Sin esto seguiría dentro con la sesión que ya tenía abierta.
            if ($status !== 'active') {
                $target->tokens()->delete();
            }
        });

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

        return response()->json(['email' => $target->email, 'temporary_password' => $password]);
    }

    /** Enciende o apaga un permiso a una persona, respecto de lo que trae su rol. */
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

        return $this->showUser($request, $target->id);
    }

    /** Deja a la persona con lo que trae su rol, sin nada quitado ni agregado. */
    public function resetUserPermissions(Request $request, string $user)
    {
        $tenant = $this->tenant($request);
        $actor = $request->user();
        $target = $this->findUser($tenant, $user);
        $this->assertMyStaff($actor, $target);

        abort_if($locked = $this->lockedReason($target, $actor, Access::roleOf($target)), 422, $locked ?? '');

        DB::table('user_permissions')->where('user_id', $target->id)->delete();

        return $this->showUser($request, $target->id);
    }

    // ------------------------------------------------------------------- Roles

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
            // El administrador no se recorta.
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
            'users'       => $people->map(fn (User $u) => [
                'id'              => $u->id,
                'name'            => trim("{$u->first_name} {$u->last_name}"),
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
        // Copiar del administrador es copiar todo; nadie reparte más de lo que tiene.
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

        $role->update(['name' => $validated['name'], 'description' => $validated['description'] ?? null]);

        return response()->json(['id' => $role->id]);
    }

    /** Enciende o apaga un permiso del rol: rige al instante para todos los que lo tienen. */
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

        // Una excepción que ya coincide con el rol deja de ser excepción.
        DB::table('user_permissions')->where('permission_id', $permissionId)->where('granted', (bool) $validated['on'])
            ->whereIn('user_id', DB::table('user_roles')->where('role_id', $role->id)->pluck('user_id'))->delete();

        return $this->showRole($request, $role->id);
    }

    /** Qué tanto ve quien tiene el rol: toda la empresa, solo su sede o solo lo suyo. */
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

        $role->update(['scope' => $validated['scope']]);

        // Quien tiene el rol y no tiene sede, desde ahora no ve nada de sedes: se avisa.
        $withoutBranch = $validated['scope'] === 'branch'
            ? User::whereIn('id', DB::table('user_roles')->where('role_id', $role->id)->pluck('user_id'))->whereNull('branch_id')->count()
            : 0;

        return response()->json($this->showRole($request, $role->id)->getData(true) + ['without_branch' => $withoutBranch]);
    }

    /** Devuelve un rol de fábrica a los permisos con que nació. */
    public function restoreRole(Request $request, string $role)
    {
        $tenant = $this->tenant($request);
        $this->assertWholeCompany($request->user());
        $role = $this->findRole($tenant, $role);

        abort_unless($role->is_system && isset(Access::FACTORY[$role->slug]) && !$this->isAdminRole($role), 422, 'Solo los roles de fábrica se pueden restablecer.');
        abort_unless($request->user()->isTenantAdmin(), 403, 'Solo un administrador restablece un rol de fábrica.');

        Access::setRolePermissions($role->id, Access::factoryPermissions($role->slug));
        // También vuelve a ver lo que veía al nacer (su sede, toda la empresa).
        if (Access::scopeReady()) {
            $role->update(['scope' => Access::FACTORY[$role->slug]['scope']]);
        }

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

        return response()->json(['deleted' => true]);
    }
}
