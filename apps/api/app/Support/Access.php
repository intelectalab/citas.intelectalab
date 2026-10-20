<?php

namespace App\Support;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Access
{
    public const NOT_READY = 'Falta actualizar la base de datos: carga init.sql, seed.sql y seed_demo.sql.';

    public const ACTIONS = ['view' => 'Ver', 'create' => 'Crear', 'edit' => 'Editar', 'delete' => 'Eliminar'];

    public const SPECIAL = [
        'override'  => 'Forzar horario',
        'emergency' => 'Acceso de emergencia',
        'forgive'   => 'Perdonar faltas',
        'use'       => 'Registrar uso a mano',
    ];

    public const CATALOG = [
        'agenda' => ['label' => 'Agenda y citas', 'module' => 'agenda', 'actions' => [
            'view'   => 'Ver la agenda y las citas',
            'create' => 'Agendar citas',
            'edit'   => 'Confirmar, registrar la llegada, atender, reprogramar y cancelar citas',
            'override' => 'Agendar aunque el horario no esté disponible: fuera del horario de atención, en una pausa o sobre un bloqueo. Nunca permite dos citas a la misma hora',
        ]],
        'waitlist' => ['label' => 'Lista de espera', 'module' => 'agenda', 'actions' => [
            'view'   => 'Ver la lista de espera',
            'create' => 'Anotar a alguien en la lista de espera',
            'edit'   => 'Cambiar o cerrar un registro de la lista de espera',
        ]],
        'recalls' => ['label' => 'Controles y seguimiento', 'module' => 'agenda', 'actions' => [
            'view'   => 'Ver los controles pendientes',
            'create' => 'Registrar un control',
            'edit'   => 'Cambiar un control o darlo por realizado',
        ]],
        'blocks' => ['label' => 'Bloqueos y ausencias', 'module' => 'agenda', 'actions' => [
            'view'   => 'Ver bloqueos y ausencias',
            'create' => 'Registrar un bloqueo o una ausencia',
            'edit'   => 'Cambiar un bloqueo o una ausencia',
            'delete' => 'Eliminar un bloqueo o una ausencia',
        ]],
        'holidays' => ['label' => 'Feriados', 'module' => 'agenda', 'actions' => [
            'view'   => 'Ver los feriados',
            'create' => 'Agregar feriados',
            'edit'   => 'Cambiar un feriado',
            'delete' => 'Eliminar un feriado',
        ]],
        'attention' => ['label' => 'Tiempos de atención', 'module' => 'agenda', 'actions' => [
            'view' => 'Ver los tiempos de atención',
        ]],
        'customers' => ['label' => '{clients}', 'module' => 'crm', 'actions' => [
            'view'   => 'Ver {clients} y su página',
            'create' => 'Registrar {clients}',
            'edit'   => 'Cambiar los datos de {clients}',
            'delete' => 'Eliminar {clients}',
            'forgive' => 'Perdonar las inasistencias de quien reservó en línea, para que pueda volver a reservar',
        ]],
        'records' => ['label' => 'Fichas de atención', 'module' => 'records', 'actions' => [
            'view' => 'Ver fichas de atención',
            'edit' => 'Llenar, firmar y corregir fichas de atención',
            'emergency' => 'Abrir por una emergencia una ficha privada que escribió otra persona. Queda registrado quién la abrió y por qué',
        ]],
        'record_templates' => ['label' => 'Plantillas de ficha', 'module' => 'records', 'actions' => [
            'create' => 'Crear plantillas de ficha',
            'edit'   => 'Cambiar plantillas de ficha',
            'delete' => 'Eliminar plantillas de ficha',
        ]],
        'services' => ['label' => 'Servicios', 'module' => 'agenda', 'actions' => [
            'view'   => 'Ver los servicios',
            'create' => 'Crear servicios y categorías',
            'edit'   => 'Cambiar servicios y categorías (también sus precios)',
            'delete' => 'Eliminar servicios y categorías',
        ]],
        'professionals' => ['label' => '{pros} y horarios', 'module' => 'agenda', 'actions' => [
            'view'   => 'Ver {pros} y los horarios del equipo',
            'create' => 'Registrar {pros}',
            'edit'   => 'Cambiar los datos y el horario de {pros}',
            'delete' => 'Eliminar {pros}',
        ]],
        'rooms' => ['label' => 'Salas y equipos', 'module' => 'resources', 'actions' => [
            'view'   => 'Ver salas y equipos',
            'create' => 'Registrar salas y equipos',
            'edit'   => 'Cambiar salas y equipos, o ponerlos fuera de uso',
            'delete' => 'Eliminar salas y equipos',
        ]],
        'pos' => ['label' => 'Punto de venta', 'module' => 'pos', 'actions' => [
            'view'   => 'Abrir el punto de venta y ver lo que falta cobrar',
            'create' => 'Armar ventas en el carrito con citas, servicios y productos, y dejarlas por cobrar',
            'edit'   => 'Cambiar una venta que se dejó por cobrar, mientras no tenga pagos',
        ]],
        'sales' => ['label' => 'Ventas y pagos', 'module' => 'pos', 'actions' => [
            'view'   => 'Ver las ventas y los pagos',
            'create' => 'Cobrar y emitir el comprobante',
            'delete' => 'Anular una venta o devolver dinero',
        ]],
        'cash' => ['label' => 'Caja', 'module' => 'pos', 'actions' => [
            'view'   => 'Ver la caja y sus turnos',
            'create' => 'Agregar una caja, cambiarle el nombre o ponerla fuera de uso',
            'edit'   => 'Abrir la caja, anotar movimientos y cerrarla',
        ]],
        'coupons' => ['label' => 'Cupones', 'module' => 'pos', 'actions' => [
            'view'   => 'Ver los cupones',
            'create' => 'Crear cupones',
            'edit'   => 'Cambiar cupones',
            'delete' => 'Eliminar cupones',
        ]],
        'products' => ['label' => 'Productos', 'module' => 'pos', 'actions' => [
            'view'   => 'Ver los productos',
            'create' => 'Registrar productos y sus categorías',
            'edit'   => 'Cambiar productos y sus precios',
            'delete' => 'Eliminar productos',
        ]],
        'packages' => ['label' => 'Paquetes', 'module' => 'packages', 'actions' => [
            'view'   => 'Ver los paquetes de los clientes y el catálogo de paquetes',
            'create' => 'Crear paquetes en el catálogo',
            'edit'   => 'Cambiar paquetes del catálogo y dar más días a un paquete vendido',
            'delete' => 'Anular un paquete vendido, devolver lo no usado y quitar paquetes del catálogo',
            'use'    => 'Descontar a mano una sesión de un paquete, sin cita (queda registrado quién y por qué)',
        ]],
        'messages' => ['label' => 'Mensajes a clientes', 'module' => null, 'actions' => [
            'view'   => 'Ver los mensajes enviados y programados a los clientes',
            'create' => 'Escribir un mensaje a un cliente, para ahora o programado',
            'edit'   => 'Cambiar o cancelar un mensaje programado y reintentar los que fallaron',
        ]],
        'branches' => ['label' => 'Sucursales', 'module' => null, 'actions' => [
            'view'   => 'Ver las sucursales',
            'create' => 'Crear sucursales',
            'edit'   => 'Cambiar una sucursal, su horario o desactivarla',
        ]],
        'company' => ['label' => 'Empresa', 'module' => null, 'actions' => [
            'view' => 'Ver los datos de la empresa',
            'edit' => 'Cambiar los datos de la empresa',
        ]],
        'settings' => ['label' => 'Configuración', 'module' => null, 'actions' => [
            'view' => 'Ver la configuración de la empresa',
            'edit' => 'Cambiar la configuración de la empresa',
        ]],
        'users' => ['label' => 'Usuarios y roles', 'module' => null, 'actions' => [
            'view'   => 'Ver los usuarios, los roles y sus permisos',
            'create' => 'Crear usuarios y roles',
            'edit'   => 'Cambiar usuarios, roles y permisos',
            'delete' => 'Eliminar roles',
        ]],
    ];

    private const EVERYTHING = ['view', 'create', 'edit', 'delete'];

    public const FACTORY = [
        'owner' => [
            'name' => 'Administrador',
            'description' => 'Puede todo. No se le pueden quitar permisos.',
            'scope' => 'tenant',
            'permissions' => [],
        ],
        'manager' => [
            'name' => 'Gerente de sede',
            'description' => 'Lleva el día a día de una sede: agenda, equipo, ventas, caja y su personal.',
            'scope' => 'branch',
            'permissions' => [
                'agenda' => ['view', 'create', 'edit', 'override'], 'waitlist' => self::EVERYTHING, 'recalls' => self::EVERYTHING,
                'blocks' => self::EVERYTHING, 'holidays' => self::EVERYTHING, 'attention' => ['view'],
                'customers' => ['view', 'create', 'edit', 'delete', 'forgive'], 'records' => ['view'], 'record_templates' => self::EVERYTHING,
                'services' => self::EVERYTHING, 'professionals' => self::EVERYTHING, 'rooms' => self::EVERYTHING,
                'pos' => ['view', 'create', 'edit'], 'products' => self::EVERYTHING,
                'sales' => self::EVERYTHING, 'cash' => self::EVERYTHING, 'coupons' => self::EVERYTHING,
                'packages' => ['view', 'create', 'edit', 'delete', 'use'], 'messages' => ['view', 'create', 'edit'],
                'branches' => ['view'],
                'users' => ['view', 'create', 'edit'],
            ],
        ],
        'reception' => [
            'name' => 'Recepción',
            'description' => 'Agenda, recibe y cobra a quien llega. No elimina ni devuelve dinero.',
            'scope' => 'branch',
            'permissions' => [
                'agenda' => ['view', 'create', 'edit'], 'waitlist' => ['view', 'create', 'edit'], 'recalls' => ['view', 'create', 'edit'],
                'blocks' => ['view'], 'holidays' => ['view'],
                'customers' => ['view', 'create', 'edit'],
                'services' => ['view'], 'professionals' => ['view'], 'rooms' => ['view'],
                'pos' => ['view', 'create', 'edit'], 'products' => ['view'],
                'sales' => ['view', 'create'], 'cash' => ['view', 'edit'], 'coupons' => ['view'],
                'packages' => ['view', 'use'], 'messages' => ['view', 'create', 'edit'],
            ],
        ],
        'cashier' => [
            'name' => 'Caja',
            'description' => 'Cobra y lleva la caja. Ve la agenda y los clientes sin cambiarlos.',
            'scope' => 'branch',
            'permissions' => [
                'agenda' => ['view'], 'customers' => ['view'],
                'pos' => ['view', 'create', 'edit'], 'products' => ['view'],
                'sales' => ['view', 'create'], 'cash' => ['view', 'edit'], 'coupons' => ['view'],
                'packages' => ['view'], 'messages' => ['view'],
            ],
        ],
        'professional' => [
            'name' => 'Profesional',
            'description' => 'Atiende sus propias citas y llena sus fichas. Solo ve lo suyo.',
            'scope' => 'own',
            'permissions' => [
                'agenda' => ['view', 'create', 'edit'], 'waitlist' => ['view', 'create', 'edit'], 'recalls' => ['view', 'create', 'edit'],
                'blocks' => self::EVERYTHING, 'holidays' => ['view'], 'attention' => ['view'],
                'customers' => ['view', 'create', 'edit'], 'records' => ['view', 'edit'],
                'services' => ['view'], 'rooms' => ['view'],
                'sales' => ['create'],
            ],
        ],
    ];

    public const SCOPES = [
        'tenant' => 'Toda la empresa',
        'branch' => 'Solo su sede',
        'own'    => 'Solo lo suyo',
    ];

    public const NO_BRANCH = '00000000-0000-0000-0000-000000000000';

    private static ?bool $ready = null;
    private static ?bool $scoped = null;

    public static function scopeReady(): bool
    {
        return self::$scoped ??= Schema::hasColumn('roles', 'scope');
    }

    public static function scopeOf(User $user): string
    {
        if (!$user->tenant_id) {
            return 'tenant';
        }

        if ($user->scopeCache !== null) {
            return $user->scopeCache;
        }

        if ($user->isTenantAdmin()) {
            return $user->scopeCache = 'tenant';
        }

        $role = self::roleOf($user);
        $scope = $role && self::scopeReady() && $role->permissions_seeded_at ? $role->scope : (self::FACTORY[$role?->slug]['scope'] ?? 'tenant');

        return $user->scopeCache = isset(self::SCOPES[$scope]) ? $scope : 'tenant';
    }

    public static function branchLimit(mixed $user): ?string
    {
        if (!$user instanceof User || self::scopeOf($user) !== 'branch') {
            return null;
        }

        return $user->branch_id ?: self::NO_BRANCH;
    }

    public static function assertBranch(mixed $user, ?string $branchId, bool $changing = false): void
    {
        $limit = self::branchLimit($user);

        if ($limit === null || $branchId === $limit) {
            return;
        }

        if ($branchId === null && !$changing) {
            return;
        }

        abort(403, self::otherBranch($user, $branchId === null));
    }

    public static function professionalsAt(string $branchId)
    {
        return DB::table('professional_schedules')->where('branch_id', $branchId)->select('professional_id');
    }

    public static function worksAt(string $professionalId, string $branchId): bool
    {
        return DB::table('professional_schedules')->where('branch_id', $branchId)->where('professional_id', $professionalId)->exists();
    }

    public static function onlyBranch(User $user): string
    {
        $name = $user->branch_id ? DB::table('branches')->where('id', $user->branch_id)->value('name') : null;

        return $name ? "Tu cuenta trabaja solo con {$name}." : self::otherBranch($user);
    }

    public static function otherBranch(User $user, bool $wholeCompany = false): string
    {
        if (!$user->branch_id) {
            return 'Tu cuenta trabaja solo con su sede, pero aún no tiene una asignada. Pídele a quien administra tu empresa que te asigne tu sede.';
        }

        $name = DB::table('branches')->where('id', $user->branch_id)->value('name');

        return $wholeCompany
            ? "Esto vale para todas las sedes y tu cuenta trabaja solo con {$name}. Pídeselo a quien administra tu empresa."
            : "Esto es de otra sede. Tu cuenta trabaja solo con {$name}.";
    }

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('user_permissions') && Schema::hasColumn('roles', 'permissions_seeded_at');
    }

    public static function all(): array
    {
        $names = [];

        foreach (self::CATALOG as $key => $definition) {
            foreach (array_keys($definition['actions']) as $action) {
                $names[] = "{$key}.{$action}";
            }
        }

        return $names;
    }

    public static function factoryPermissions(string $slug): array
    {
        if ($slug === 'owner') {
            return self::all();
        }

        $names = [];

        foreach (self::FACTORY[$slug]['permissions'] ?? [] as $key => $actions) {
            foreach ($actions as $action) {
                if (isset(self::CATALOG[$key]['actions'][$action])) {
                    $names[] = "{$key}.{$action}";
                }
            }
        }

        return $names;
    }

    public static function roleOf(User $user): ?Role
    {
        return $user->roles()->where('roles.tenant_id', $user->tenant_id)->orderBy('roles.created_at')->first();
    }

    public static function of(User $user): array
    {
        if (!$user->tenant_id) {
            return [];
        }

        if ($user->accessCache !== null) {
            return $user->accessCache;
        }

        if ($user->isTenantAdmin()) {
            return $user->accessCache = self::all();
        }

        $role = self::roleOf($user);

        if (!$role) {
            return $user->accessCache = [];
        }

        if (!self::ready()) {
            return $user->accessCache = self::factoryPermissions($role->slug);
        }

        if (isset(self::FACTORY[$role->slug]) && !$role->permissions_seeded_at) {
            self::ensureRoles($user->tenant);
        }

        $names = array_fill_keys(self::rolePermissions($role->id), true);

        foreach (self::overrides($user->id) as $name => $granted) {
            if ($granted) {
                $names[$name] = true;
            } else {
                unset($names[$name]);
            }
        }

        return $user->accessCache = array_values(array_intersect(self::all(), array_keys($names)));
    }

    public static function allows(User $user, string $permission): bool
    {
        return in_array($permission, self::of($user), true);
    }

    public static function rolePermissions(string $roleId): array
    {
        return DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $roleId)
            ->pluck('permissions.name')
            ->all();
    }

    public static function overrides(string $userId): array
    {
        if (!self::ready()) {
            return [];
        }

        return DB::table('user_permissions')
            ->join('permissions', 'permissions.id', '=', 'user_permissions.permission_id')
            ->where('user_permissions.user_id', $userId)
            ->pluck('user_permissions.granted', 'permissions.name')
            ->map(fn ($granted) => (bool) $granted)
            ->all();
    }

    public static function permissionIds(): array
    {
        $ids = DB::table('permissions')->whereIn('name', self::all())->pluck('id', 'name')->all();
        $missing = array_diff(self::all(), array_keys($ids));

        if ($missing) {
            DB::table('permissions')->insertOrIgnore(array_map(function (string $name) {
                [$key, $action] = explode('.', $name);

                return [
                    'id'          => (string) Str::uuid(),
                    'name'        => $name,
                    'module'      => $key,
                    'description' => str_replace(['{clients}', '{pros}'], ['clientes', 'profesionales'], self::CATALOG[$key]['actions'][$action]),
                ];
            }, array_values($missing)));

            $ids = DB::table('permissions')->whereIn('name', self::all())->pluck('id', 'name')->all();
        }

        return $ids;
    }

    public static function setRolePermissions(string $roleId, array $names): void
    {
        $ids = self::permissionIds();

        DB::table('role_permissions')->where('role_id', $roleId)->delete();
        DB::table('role_permissions')->insert(array_map(
            fn (string $name) => ['role_id' => $roleId, 'permission_id' => $ids[$name]],
            array_values(array_intersect($names, array_keys($ids))),
        ));
    }

    public static function ensureRoles(Tenant $tenant): void
    {
        if (!self::ready()) {
            return;
        }

        DB::transaction(function () use ($tenant) {
            foreach (self::FACTORY as $slug => $definition) {
                $role = Role::firstOrNew(['tenant_id' => $tenant->id, 'slug' => $slug]);

                if ($role->exists && $role->permissions_seeded_at) {
                    continue;
                }

                $role->fill([
                    'name'                  => $definition['name'],
                    'description'           => $definition['description'],
                    'is_system'             => true,
                    'permissions_seeded_at' => now(),
                ] + (self::scopeReady() ? ['scope' => $definition['scope']] : []))->save();

                self::setRolePermissions($role->id, $slug === 'owner' ? [] : self::factoryPermissions($slug));
            }
        });
    }

    public static function grid(Tenant $tenant, array $roleNames, array $overrides = [], bool $everything = false): array
    {
        $vocabulary = $tenant->vocabulary();
        $words = [
            '{clients}' => Str::lower($vocabulary['clients'] ?? 'clientes'),
            '{pros}'    => Str::lower($vocabulary['pros'] ?? 'profesionales'),
        ];
        $text = fn (string $value) => Str::ucfirst(strtr($value, $words));
        $modules = [];

        foreach (self::CATALOG as $key => $definition) {
            if ($definition['module'] && !$tenant->hasModule($definition['module'])) {
                continue;
            }

            $actions = [];

            foreach ($definition['actions'] as $action => $hint) {
                $name = "{$key}.{$action}";
                $fromRole = $everything || in_array($name, $roleNames, true);
                $override = $everything ? null : ($overrides[$name] ?? null);

                $actions[] = [
                    'action'   => $action,
                    'name'     => $name,
                    'label'    => self::ACTIONS[$action] ?? self::SPECIAL[$action],
                    'special'  => isset(self::SPECIAL[$action]),
                    'hint'     => $text($hint),
                    'role'     => $fromRole,
                    'override' => $override === $fromRole ? null : $override,
                    'on'       => $override ?? $fromRole,
                ];
            }

            $modules[] = ['key' => $key, 'label' => $text($definition['label']), 'actions' => $actions];
        }

        return $modules;
    }

    public static function denial(string $permission, ?Tenant $tenant = null): string
    {
        [$key, $action] = array_pad(explode('.', $permission), 2, '');
        $hint = self::CATALOG[$key]['actions'][$action] ?? null;

        if (!$hint) {
            return 'Tu cuenta no tiene permiso para hacer esto.';
        }

        $vocabulary = $tenant?->vocabulary() ?? [];
        $hint = strtr($hint, [
            '{clients}' => Str::lower($vocabulary['clients'] ?? 'clientes'),
            '{pros}'    => Str::lower($vocabulary['pros'] ?? 'profesionales'),
        ]);

        return 'Tu cuenta no tiene permiso para esto: ' . Str::lcfirst($hint) . '. Pídeselo a quien administra tu empresa.';
    }
}
