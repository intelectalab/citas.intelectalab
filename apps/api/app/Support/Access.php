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
    public const NOT_READY = 'Falta actualizar la base de datos: ejecuta php artisan migrate.';

    public const ACTIONS = ['view' => 'Ver', 'create' => 'Crear', 'edit' => 'Editar', 'delete' => 'Eliminar'];

    public const SPECIAL = [
        'override'  => 'Forzar horario',
        'emergency' => 'Acceso de emergencia',
        'forgive'   => 'Perdonar faltas',
        'use'       => 'Registrar uso a mano',
        'export'    => 'Exportar',
        'others'    => 'Turnos de otros',
    ];

    public const CATALOG = [
        'agenda' => ['label' => 'Agenda y citas', 'module' => 'agenda', 'actions' => [
            'view'   => 'Ver la agenda y las citas',
            'create' => 'Agendar citas',
            'edit'   => 'Confirmar, registrar la llegada, atender, reprogramar y cancelar citas',
            'override' => 'Agendar aunque el horario no esté disponible: fuera del horario de atención, en una pausa o sobre un bloqueo. Nunca permite dos citas a la misma hora',
        ]],
        'walkin' => ['label' => 'Cola sin cita', 'module' => 'walkin', 'actions' => [
            'view'   => 'Ver la cola sin cita y el tiempo estimado de espera',
            'create' => 'Anotar en la cola a quien llega sin cita',
            'edit'   => 'Pasar a atención a quien espera, cambiar su preferencia o registrar que se retiró',
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
        'classes' => ['label' => 'Clases', 'module' => 'classes', 'actions' => [
            'view' => 'Entrar a las clases (en construcción)',
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
        'professionals' => ['label' => '{pros}', 'module' => 'agenda', 'actions' => [
            'view'   => 'Ver {pros} y su página',
            'create' => 'Registrar {pros}',
            'edit'   => 'Cambiar los datos de {pros}: documento, colegiatura, servicios que atiende y sedes',
            'delete' => 'Eliminar {pros}',
        ]],
        'schedules' => ['label' => 'Horarios del equipo', 'module' => 'agenda', 'actions' => [
            'view' => 'Ver los horarios de atención del equipo',
            'edit' => 'Cambiar el horario de atención de {pros}: días, horas y sede',
        ]],
        'rooms' => ['label' => 'Salas y equipos', 'module' => 'resources', 'actions' => [
            'view'   => 'Ver salas y equipos',
            'create' => 'Registrar salas y equipos',
            'edit'   => 'Cambiar salas y equipos, o ponerlos fuera de uso',
            'delete' => 'Eliminar salas y equipos',
        ]],
        'quotes' => ['label' => 'Presupuestos', 'module' => 'quotes', 'actions' => [
            'view'   => 'Ver los presupuestos, planes de tratamiento y cronogramas de cuotas',
            'create' => 'Crear presupuestos y planes de tratamiento para {clients}',
            'edit'   => 'Cambiar presupuestos, aprobarlos, registrar sesiones avanzadas y gestionar cuotas',
            'delete' => 'Anular o rechazar presupuestos',
        ]],
        'pos' => ['label' => 'Punto de venta', 'module' => 'pos', 'actions' => [
            'view'   => 'Abrir el punto de venta y ver lo que falta cobrar',
            'create' => 'Armar ventas en el carrito con citas, servicios y productos, y dejarlas por cobrar',
            'edit'   => 'Cambiar una venta que se dejó por cobrar, mientras no tenga pagos',
        ]],
        'sales' => ['label' => 'Ventas', 'module' => 'pos', 'actions' => [
            'view'   => 'Ver las ventas, su detalle y sus pagos',
            'create' => 'Cobrar y emitir el comprobante',
            'delete' => 'Anular una venta o devolver dinero',
        ]],
        'payments' => ['label' => 'Pagos', 'module' => 'pos', 'actions' => [
            'view' => 'Ver todos los pagos: cuánto entró por cada medio (efectivo, tarjeta, transferencia…) y las devoluciones',
        ]],
        'cash' => ['label' => 'Caja', 'module' => 'pos', 'actions' => [
            'view'   => 'Ver la caja y sus turnos',
            'create' => 'Agregar una caja, cambiarle el nombre o ponerla fuera de uso',
            'edit'   => 'Abrir la caja, anotar movimientos y cerrarla',
            'others' => 'Ver y manejar los turnos de caja que abrieron otras personas. Sin este permiso, cada quien ve y cierra solo su propio turno',
        ]],
        'coupons' => ['label' => 'Cupones', 'module' => 'pos', 'actions' => [
            'view'   => 'Ver los cupones',
            'create' => 'Crear cupones',
            'edit'   => 'Cambiar cupones',
            'delete' => 'Eliminar cupones',
        ]],
        'products' => ['label' => 'Productos', 'module' => 'pos|inventory', 'actions' => [
            'view'   => 'Ver los productos',
            'create' => 'Registrar productos y sus categorías',
            'edit'   => 'Cambiar productos y sus precios',
            'delete' => 'Eliminar productos',
        ]],
        'inventory' => ['label' => 'Inventario', 'module' => 'inventory', 'actions' => [
            'view'   => 'Ver las existencias, el kárdex, las órdenes de compra y los traslados entre sedes',
            'create' => 'Registrar entradas, salidas, órdenes de compra, proveedores y traslados entre sedes',
            'edit'   => 'Hacer conteos físicos, recibir mercadería, configurar costos, stocks mínimos e insumos por servicio',
            'delete' => 'Cancelar órdenes de compra o traslados y eliminar proveedores',
        ]],
        'packages' => ['label' => 'Paquetes', 'module' => 'packages', 'actions' => [
            'view'   => 'Ver los paquetes de los clientes y el catálogo de paquetes',
            'create' => 'Crear paquetes en el catálogo',
            'edit'   => 'Cambiar paquetes del catálogo y dar más días a un paquete vendido',
            'delete' => 'Anular un paquete vendido, devolver lo no usado y quitar paquetes del catálogo',
            'use'    => 'Descontar a mano una sesión de un paquete, sin cita (queda registrado quién y por qué)',
        ]],
        'loyalty' => ['label' => 'Puntos y gift cards', 'module' => 'loyalty', 'actions' => [
            'view'   => 'Ver los puntos acumulados por {clients} y las tarjetas de regalo (gift cards)',
            'create' => 'Emitir tarjetas de regalo y registrar puntos por compras o referidos',
            'edit'   => 'Ajustar puntos, descontar saldo o ampliar el vencimiento de una gift card',
            'delete' => 'Anular tarjetas de regalo vigentes',
        ]],
        'messages' => ['label' => 'Mensajes a clientes', 'module' => null, 'actions' => [
            'view'   => 'Ver los mensajes enviados y programados a los clientes',
            'create' => 'Escribir un mensaje a un cliente, para ahora o programado',
            'edit'   => 'Cambiar o cancelar un mensaje programado y reintentar los que fallaron',
        ]],
        'campaigns' => ['label' => 'Campañas', 'module' => 'marketing', 'actions' => [
            'view'   => 'Ver las campañas y sus resultados',
            'create' => 'Crear campañas para enviar ahora o programadas, y duplicar una campaña',
            'edit'   => 'Cambiar o cancelar una campaña programada',
        ]],
        'automations' => ['label' => 'Automatizaciones', 'module' => 'automations', 'actions' => [
            'view' => 'Ver las automatizaciones y sus resultados',
            'edit' => 'Encender o apagar automatizaciones y cambiar su texto, cupón y horario',
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
        'reports' => ['label' => 'Reportes', 'module' => null, 'actions' => [
            'view'  => 'Ver los reportes de ventas, citas, {clients}, {pros} y servicios',
            'money' => 'Ver los montos en los reportes: ingresos, ticket promedio y cuánto gastó cada cliente',
            'own'   => 'Ver sus propios números: sus citas atendidas, su producción, su ocupación y sus {clients}',
        ]],
        'audit' => ['label' => 'Auditoría', 'module' => null, 'actions' => [
            'view' => 'Ver la auditoría: quién hizo qué y cuándo en la empresa. Con un rol de una sede, solo lo de su sede',
        ]],
        'data' => ['label' => 'Exportar datos', 'module' => null, 'actions' => [
            'export' => 'Descargar en Excel o CSV las listas que puede ver, con los filtros que tenga puestos. Cada descarga queda registrada',
        ]],
    ];

    public const SECTIONS = ['start' => 'Inicio', 'agenda' => 'Agenda', 'care' => 'Atención', 'team' => 'Equipo y catálogo', 'sales' => 'Ventas', 'inventory' => 'Inventario', 'loyalty' => 'Fidelización', 'marketing' => 'Marketing', 'company' => 'Tu empresa', 'footer' => 'Al pie del menú', 'other' => 'Fuera del menú'];

    public const LAYOUT = [
        'reports'          => ['section' => 'start', 'screens' => ['Reportes'], 'short' => ['view' => 'Ver', 'money' => 'Ver montos', 'own' => 'Sus números']],
        'agenda'           => ['section' => 'agenda', 'screens' => ['Agenda', 'Citas'], 'short' => ['view' => 'Ver', 'create' => 'Agendar', 'edit' => 'Confirmar, atender y cancelar']],
        'walkin'           => ['section' => 'agenda', 'screens' => ['Cola sin cita'], 'short' => ['view' => 'Ver', 'create' => 'Anotar', 'edit' => 'Atender o retirar']],
        'waitlist'         => ['section' => 'agenda', 'screens' => ['Lista de espera'], 'short' => ['view' => 'Ver', 'create' => 'Anotar', 'edit' => 'Cambiar o cerrar']],
        'recalls'          => ['section' => 'agenda', 'screens' => ['Controles y seguimiento'], 'short' => ['view' => 'Ver', 'create' => 'Registrar', 'edit' => 'Cambiar o dar por hecho']],
        'classes'          => ['section' => 'agenda', 'screens' => ['Clases'], 'building' => true, 'short' => ['view' => 'Entrar']],
        'blocks'           => ['section' => 'agenda', 'screens' => ['Bloqueos y ausencias'], 'short' => ['view' => 'Ver', 'create' => 'Registrar', 'edit' => 'Cambiar', 'delete' => 'Eliminar']],
        'holidays'         => ['section' => 'agenda', 'screens' => [], 'note' => 'Es la pestaña Feriados de Bloqueos y ausencias.', 'short' => ['view' => 'Ver', 'create' => 'Agregar', 'edit' => 'Cambiar', 'delete' => 'Eliminar']],
        'attention'        => ['section' => 'agenda', 'screens' => ['Tiempos de atención'], 'short' => ['view' => 'Ver']],
        'customers'        => ['section' => 'care', 'screens' => ['{Clients}'], 'short' => ['view' => 'Ver', 'create' => 'Registrar', 'edit' => 'Cambiar datos', 'delete' => 'Eliminar']],
        'quotes'           => ['section' => 'care', 'screens' => ['Presupuestos'], 'short' => ['view' => 'Ver', 'create' => 'Crear planes', 'edit' => 'Aprobar y avanzar sesiones', 'delete' => 'Anular']],
        'records'          => ['section' => 'care', 'screens' => ['Fichas de atención'], 'short' => ['view' => 'Ver', 'edit' => 'Llenar, firmar y corregir']],
        'record_templates' => ['section' => 'care', 'screens' => [], 'note' => 'Se arman dentro de Fichas de atención.', 'short' => ['create' => 'Crear', 'edit' => 'Cambiar', 'delete' => 'Eliminar']],
        'services'         => ['section' => 'team', 'screens' => ['Servicios'], 'short' => ['view' => 'Ver', 'create' => 'Crear', 'edit' => 'Cambiar y poner precios', 'delete' => 'Eliminar']],
        'professionals'    => ['section' => 'team', 'screens' => ['{Pros}'], 'short' => ['view' => 'Ver', 'create' => 'Registrar', 'edit' => 'Cambiar datos', 'delete' => 'Eliminar']],
        'schedules'        => ['section' => 'team', 'screens' => ['Horarios del equipo'], 'short' => ['view' => 'Ver', 'edit' => 'Cambiar horarios']],
        'rooms'            => ['section' => 'team', 'screens' => ['Salas y equipos'], 'short' => ['view' => 'Ver', 'create' => 'Registrar', 'edit' => 'Cambiar', 'delete' => 'Eliminar']],
        'pos'              => ['section' => 'sales', 'screens' => ['Punto de venta'], 'short' => ['view' => 'Abrir', 'create' => 'Armar ventas', 'edit' => 'Cambiar una venta por cobrar']],
        'sales'            => ['section' => 'sales', 'screens' => ['Ventas'], 'short' => ['view' => 'Ver', 'create' => 'Cobrar', 'delete' => 'Anular y devolver']],
        'payments'         => ['section' => 'sales', 'screens' => ['Pagos'], 'short' => ['view' => 'Ver']],
        'cash'             => ['section' => 'sales', 'screens' => ['Caja'], 'short' => ['view' => 'Ver', 'create' => 'Agregar cajas', 'edit' => 'Abrir, mover y cerrar', 'others' => 'Turnos de otros']],
        'products'         => ['section' => 'inventory', 'screens' => ['Productos y stock'], 'short' => ['view' => 'Ver', 'create' => 'Registrar', 'edit' => 'Cambiar y poner precios', 'delete' => 'Eliminar']],
        'inventory'        => ['section' => 'inventory', 'screens' => ['Kárdex', 'Traslados', 'Compras'], 'short' => ['view' => 'Ver', 'create' => 'Mover, comprar y trasladar', 'edit' => 'Contar, recibir y ajustar', 'delete' => 'Cancelar y eliminar']],
        'packages'         => ['section' => 'loyalty', 'screens' => ['Paquetes'], 'short' => ['view' => 'Ver', 'create' => 'Crear', 'edit' => 'Cambiar y dar más días', 'delete' => 'Anular y devolver']],
        'loyalty'          => ['section' => 'loyalty', 'screens' => ['Puntos', 'Gift cards'], 'short' => ['view' => 'Ver', 'create' => 'Emitir y acreditar', 'edit' => 'Ajustar y canjear', 'delete' => 'Anular']],
        'campaigns'        => ['section' => 'marketing', 'screens' => ['Campañas'], 'short' => ['view' => 'Ver', 'create' => 'Crear', 'edit' => 'Cambiar o cancelar']],
        'automations'      => ['section' => 'marketing', 'screens' => ['Automatizaciones'], 'short' => ['view' => 'Ver', 'edit' => 'Encender y cambiar']],
        'messages'         => ['section' => 'marketing', 'screens' => ['Mensajes'], 'short' => ['view' => 'Ver', 'create' => 'Escribir', 'edit' => 'Cambiar, cancelar y reintentar']],
        'coupons'          => ['section' => 'marketing', 'screens' => ['Cupones'], 'short' => ['view' => 'Ver', 'create' => 'Crear', 'edit' => 'Cambiar', 'delete' => 'Eliminar']],
        'branches'         => ['section' => 'company', 'screens' => ['Sucursales'], 'short' => ['view' => 'Ver', 'create' => 'Crear', 'edit' => 'Cambiar']],
        'company'          => ['section' => 'company', 'screens' => ['Empresa'], 'short' => ['view' => 'Ver', 'edit' => 'Cambiar']],
        'users'            => ['section' => 'company', 'screens' => ['Usuarios y roles'], 'short' => ['view' => 'Ver', 'create' => 'Crear', 'edit' => 'Cambiar y dar permisos', 'delete' => 'Eliminar roles']],
        'audit'            => ['section' => 'company', 'screens' => ['Auditoría'], 'short' => ['view' => 'Ver']],
        'settings'         => ['section' => 'footer', 'screens' => ['Configuración'], 'short' => ['view' => 'Ver', 'edit' => 'Cambiar']],
        'data'             => ['section' => 'other', 'screens' => [], 'note' => 'No es una pantalla: es el botón Exportar de las listas.', 'short' => []],
    ];

    public const ADMIN_ONLY = [
        ['name' => 'Membresías', 'module' => 'memberships', 'section' => 'loyalty', 'before' => 'packages'],
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
                'agenda' => ['view', 'create', 'edit', 'override'], 'walkin' => ['view', 'create', 'edit'], 'classes' => ['view'], 'waitlist' => self::EVERYTHING, 'recalls' => self::EVERYTHING,
                'blocks' => self::EVERYTHING, 'holidays' => self::EVERYTHING, 'attention' => ['view'],
                'customers' => ['view', 'create', 'edit', 'delete', 'forgive'], 'records' => ['view'], 'record_templates' => self::EVERYTHING,
                'services' => self::EVERYTHING, 'professionals' => self::EVERYTHING, 'schedules' => ['view', 'edit'], 'rooms' => self::EVERYTHING,
                'quotes' => self::EVERYTHING, 'pos' => ['view', 'create', 'edit'], 'products' => self::EVERYTHING, 'inventory' => self::EVERYTHING,
                'sales' => self::EVERYTHING, 'payments' => ['view'], 'cash' => [...self::EVERYTHING, 'others'], 'coupons' => self::EVERYTHING,
                'packages' => ['view', 'create', 'edit', 'delete', 'use'], 'loyalty' => self::EVERYTHING, 'messages' => ['view', 'create', 'edit'],
                'campaigns' => ['view', 'create', 'edit'], 'automations' => ['view'], 'reports' => ['view', 'money'],
                'branches' => ['view'],
                'users' => ['view', 'create', 'edit'],
                'data' => ['export'],
            ],
        ],
        'reception' => [
            'name' => 'Recepción',
            'description' => 'Agenda, recibe y cobra a quien llega. No elimina ni devuelve dinero.',
            'scope' => 'branch',
            'permissions' => [
                'agenda' => ['view', 'create', 'edit'], 'walkin' => ['view', 'create', 'edit'], 'classes' => ['view'], 'waitlist' => ['view', 'create', 'edit'], 'recalls' => ['view', 'create', 'edit'],
                'blocks' => ['view'], 'holidays' => ['view'],
                'customers' => ['view', 'create', 'edit'],
                'services' => ['view'], 'professionals' => ['view'], 'schedules' => ['view'], 'rooms' => ['view'],
                'quotes' => ['view', 'create', 'edit'], 'pos' => ['view', 'create', 'edit'], 'products' => ['view'], 'inventory' => ['view', 'create'],
                'sales' => ['view', 'create'], 'payments' => ['view'], 'cash' => ['view', 'edit'], 'coupons' => ['view'],
                'packages' => ['view', 'use'], 'loyalty' => ['view', 'create', 'edit'], 'messages' => ['view', 'create', 'edit'],
            ],
        ],
        'cashier' => [
            'name' => 'Caja',
            'description' => 'Cobra y lleva la caja. Ve la agenda y los clientes sin cambiarlos.',
            'scope' => 'branch',
            'permissions' => [
                'agenda' => ['view'], 'customers' => ['view'],
                'quotes' => ['view'], 'pos' => ['view', 'create', 'edit'], 'products' => ['view'],
                'sales' => ['view', 'create'], 'payments' => ['view'], 'cash' => ['view', 'edit'], 'coupons' => ['view'],
                'packages' => ['view'], 'loyalty' => ['view', 'create'], 'messages' => ['view'],
            ],
        ],
        'professional' => [
            'name' => 'Profesional',
            'description' => 'Atiende sus propias citas y llena sus fichas. Solo ve lo suyo.',
            'scope' => 'own',
            'permissions' => [
                'agenda' => ['view', 'create', 'edit'], 'walkin' => ['view', 'create', 'edit'], 'classes' => ['view'], 'waitlist' => ['view', 'create', 'edit'], 'recalls' => ['view', 'create', 'edit'],
                'blocks' => self::EVERYTHING, 'holidays' => ['view'], 'attention' => ['view'],
                'customers' => ['view', 'create', 'edit'], 'records' => ['view', 'edit'],
                'services' => ['view'], 'rooms' => ['view'],
                'quotes' => ['view', 'create', 'edit'], 'sales' => ['create'],
                'reports' => ['own'],
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

    public static function idleMinutes(Tenant $tenant, User $user): int
    {
        $byRole = (array) AppointmentFlow::rule($tenant, 'session.idle_by_role');
        $role = self::roleOf($user);

        return (int) ($role && !empty($byRole[$role->id]) ? $byRole[$role->id] : AppointmentFlow::rule($tenant, 'session.idle_minutes'));
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
        $screenWords = ['{Clients}' => Str::ucfirst($vocabulary['clients'] ?? 'Clientes'), '{Pros}' => Str::ucfirst($vocabulary['pros'] ?? 'Profesionales')];
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
                    'short'    => isset(self::SPECIAL[$action]) ? ($name === 'data.export' ? 'Exportar datos' : self::SPECIAL[$action]) : (self::LAYOUT[$key]['short'][$action] ?? self::ACTIONS[$action]),
                    'special'  => isset(self::SPECIAL[$action]),
                    'hint'     => $text($hint),
                    'role'     => $fromRole,
                    'override' => $override === $fromRole ? null : $override,
                    'on'       => $override ?? $fromRole,
                ];
            }

            $layout = self::LAYOUT[$key] ?? ['section' => 'company', 'screens' => []];
            $modules[] = [
                'key'      => $key,
                'label'    => $text($definition['label']),
                'section'  => $layout['section'],
                'section_label' => self::SECTIONS[$layout['section']],
                'screens'  => array_map(fn ($screen) => strtr($screen, $screenWords), $layout['screens']),
                'note'     => $layout['note'] ?? null,
                'building' => (bool) ($layout['building'] ?? false),
                'actions'  => $actions,
            ];
        }

        $order = array_flip(array_keys(self::SECTIONS));
        $position = array_flip(array_keys(self::LAYOUT));
        usort($modules, fn ($a, $b) => [$order[$a['section']], $position[$a['key']] ?? 99] <=> [$order[$b['section']], $position[$b['key']] ?? 99]);

        return $modules;
    }

    public static function adminOnly(Tenant $tenant): array
    {
        return collect(self::ADMIN_ONLY)
            ->filter(fn ($item) => $tenant->hasModule($item['module']))
            ->map(fn ($item) => ['name' => $item['name'], 'section' => $item['section'], 'section_label' => self::SECTIONS[$item['section']], 'before' => $item['before']])
            ->values()->all();
    }

    public static function permissionText(string $name, Tenant $tenant): string
    {
        [$key, $action] = array_pad(explode('.', $name), 2, '');
        $definition = self::CATALOG[$key] ?? null;

        if (!$definition || !isset($definition['actions'][$action])) {
            return $name;
        }

        $vocabulary = $tenant->vocabulary();
        $words = ['{clients}' => Str::lower($vocabulary['clients'] ?? 'clientes'), '{pros}' => Str::lower($vocabulary['pros'] ?? 'profesionales')];

        $screens = array_map(fn ($screen) => strtr($screen, ['{Clients}' => Str::ucfirst($vocabulary['clients'] ?? 'Clientes'), '{Pros}' => Str::ucfirst($vocabulary['pros'] ?? 'Profesionales')]), self::LAYOUT[$key]['screens'] ?? []);
        $where = $screens ? implode(' y ', $screens) : Str::ucfirst(strtr($definition['label'], $words));

        return Str::ucfirst(strtr($definition['actions'][$action], $words)) . " ({$where})";
    }

    public static function logChange(\Illuminate\Http\Request $request, Tenant $tenant, string $entityType, string $entityId, string $text): void
    {
        if (!Schema::hasTable('audit_events')) {
            return;
        }

        \App\Models\AuditEvent::create([
            'tenant_id'     => $tenant->id,
            'actor_user_id' => $request->user()?->id,
            'action'        => 'access.change',
            'entity_type'   => $entityType,
            'entity_id'     => $entityId,
            'ip_address'    => $request->ip(),
            'user_agent'    => mb_substr((string) $request->userAgent(), 0, 500),
            'after_data'    => ['text' => $text],
        ]);
    }

    public static function history(string $tenantId, string $entityType, string $entityId, int $limit = 50): array
    {
        if (!Schema::hasTable('audit_events')) {
            return [];
        }

        return DB::table('audit_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.actor_user_id')
            ->where('e.tenant_id', $tenantId)->where('e.action', 'access.change')
            ->where('e.entity_type', $entityType)->where('e.entity_id', $entityId)
            ->orderByDesc('e.created_at')->orderByDesc('e.id')->limit($limit)
            ->get(['e.id', 'e.created_at', 'e.after_data', 'u.first_name', 'u.last_name'])
            ->map(fn ($row) => [
                'id'   => $row->id,
                'at'   => \Illuminate\Support\Carbon::parse($row->created_at)->toIso8601String(),
                'by'   => trim("{$row->first_name} {$row->last_name}") ?: 'El sistema',
                'text' => (json_decode((string) $row->after_data, true) ?: [])['text'] ?? '',
            ])->values()->all();
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
