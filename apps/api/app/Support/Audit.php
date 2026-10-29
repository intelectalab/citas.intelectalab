<?php

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\BusinessType;
use App\Models\Customer;
use App\Models\SaaSPlan;
use App\Models\SaasModule;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class Audit
{
    public const AREAS = [
        'companies'      => 'Empresas y planes',
        'business_types' => 'Rubros',
        'platform'       => 'Personalización de la plataforma',
        'support'        => 'Soporte',
        'access'         => 'Ingresos',
        'look'           => 'Personalización de la empresa',
        'team'           => 'Equipo y permisos',
        'data'           => 'Descargas de datos',
        'records'        => 'Fichas',
        'money'          => 'Dinero',
        'settings'       => 'Configuración',
        'catalog'        => 'Catálogo e inventario',
        'customers'      => 'Clientes',
        'agenda'         => 'Citas',
        'marketing'      => 'Marketing',
        'other'          => 'Otras',
    ];

    public const ACTIONS = [
        'tenant.created'                  => ['Creó la empresa', 'companies'],
        'tenant.updated'                  => ['Cambió los datos de la empresa', 'companies'],
        'tenant.deleted'                  => ['Eliminó la empresa', 'companies'],
        'tenant.plan_changed'             => ['Cambió el plan de la empresa', 'companies'],
        'tenant.modules_changed'          => ['Cambió los módulos que la plataforma le apaga', 'companies'],
        'tenant.suspended'                => ['Suspendió la empresa', 'companies'],
        'tenant.reactivated'              => ['Reactivó la empresa', 'companies'],
        'subscription.payment_registered' => ['Registró un pago de la suscripción', 'companies'],
        'subscription.extended'           => ['Extendió la suscripción', 'companies'],
        'subscription.canceled'           => ['Canceló la suscripción', 'companies'],
        'plan.created'                    => ['Creó un plan', 'companies'],
        'plan.updated'                    => ['Cambió un plan', 'companies'],
        'plan.deleted'                    => ['Eliminó un plan', 'companies'],
        'tenant.business_type_changed'    => ['Cambió el rubro de la empresa', 'business_types'],
        'tenant.business_profile_applied' => ['Le aplicó los cambios del rubro', 'business_types'],
        'business_type.created'           => ['Creó un rubro', 'business_types'],
        'business_type.updated'           => ['Cambió un rubro', 'business_types'],
        'business_type.deleted'           => ['Eliminó un rubro', 'business_types'],
        'impersonation.started'           => ['Entró a la empresa como soporte', 'support'],
        'impersonation.ended'             => ['Salió de la sesión de soporte', 'support'],
        'platform.settings_changed'       => ['Cambió la configuración de la plataforma', 'platform'],
        'platform.look_file_changed'      => ['Subió un archivo de la personalización', 'platform'],
        'platform.look_file_removed'      => ['Quitó un archivo de la personalización', 'platform'],
        'tenant.vocabulary_changed'       => ['Cambió el vocabulario', 'look'],
        'tenant.branding_changed'         => ['Cambió la marca y los colores', 'look'],
        'tenant.branding_logo_changed'    => ['Subió un logo', 'look'],
        'tenant.branding_logo_removed'    => ['Quitó un logo', 'look'],
        'tenant.documents_changed'        => ['Cambió el diseño de los documentos', 'look'],
        'tenant.signature_changed'        => ['Subió la firma y sello', 'look'],
        'tenant.signature_removed'        => ['Quitó la firma y sello', 'look'],
        'tenant.code_prefixes_changed'    => ['Cambió las letras de los códigos', 'look'],
        'tenant.portal_texts_changed'     => ['Cambió los textos del portal', 'look'],
        'tenant.privacy_changed'          => ['Cambió la política de privacidad', 'look'],
        'professional.signature_changed'  => ['Subió la firma de un profesional', 'look'],
        'professional.signature_removed'  => ['Quitó la firma de un profesional', 'look'],
        'access.change'                   => ['Cambió permisos', 'team'],
        'data.export'                     => ['Descargó una lista', 'data'],
        'care_record.emergency_access'    => ['Abrió una ficha privada por emergencia', 'records'],
        'auth.login'                      => ['Entró al sistema', 'access'],
        'auth.logout'                     => ['Cerró sesión', 'access'],
        'auth.idle_logout'                => ['Se cerró su sesión por inactividad', 'access'],
        'auth.failed'                     => ['Escribió una contraseña equivocada', 'access'],
        'auth.blocked'                    => ['No pudo entrar: cuenta desactivada o empresa suspendida', 'access'],
    ];

    public const LOOK_SECTIONS = [
        'brand'       => 'marca',
        'landing'     => 'portada',
        'login'       => 'pantalla de ingreso',
        'maintenance' => 'aviso de mantenimiento',
    ];

    private const FIELDS = [
        'name' => 'Nombre', 'slug' => 'Dirección corta', 'status' => 'Estado', 'timezone' => 'Zona horaria',
        'plan_id' => 'Plan', 'business_type_id' => 'Rubro', 'modules_off' => 'Módulos apagados', 'modules' => 'Módulos',
        'owner_email' => 'Correo del dueño', 'as_user_id' => 'Entró como', 'expires_at' => 'Hasta', 'ends_at' => 'Vence',
        'amount' => 'Monto', 'invoice_id' => 'Pago', 'reason' => 'Motivo', 'installed' => 'Copió el catálogo inicial',
        'parts' => 'Partes aplicadas', 'business_profile' => 'Copia del rubro', 'price_monthly' => 'Precio mensual',
        'price_yearly' => 'Precio anual', 'currency' => 'Moneda', 'description' => 'Descripción', 'code_internal' => 'Código interno',
        'limits' => 'Límites', 'features' => 'Características', 'trial_config' => 'Prueba gratis', 'subscription_config' => 'Suscripción',
        'consumption' => 'Consumo', 'support' => 'Soporte', 'presentation_order' => 'Orden en la portada', 'is_public_catalog' => 'En la portada',
        'short_name' => 'Nombre corto', 'color' => 'Color', 'vocabulary' => 'Vocabulario', 'default_rules' => 'Reglas', 'dependents' => 'Dependientes',
        'template' => 'Catálogo inicial', 'is_active' => 'Activo', 'email' => 'Correo', 'logo' => 'Logo', 'firma' => 'Firma',
        'profesional' => 'Profesional', 'archivo' => 'Archivo', 'title' => 'Lista', 'format' => 'Formato', 'rows' => 'Filas',
        'filters' => 'Filtros', 'scope' => 'Qué se descargó', 'limit' => 'Primeras filas', 'columns' => 'Columnas', 'branch' => 'Sede', 'max_users' => 'Usuarios', 'max_professionals' => 'Profesionales',
        'text' => 'Detalle',
        'client' => 'Quien se atiende (singular)', 'clients' => 'Quienes se atienden (plural)', 'pro' => 'Quien atiende (singular)', 'pros' => 'Quienes atienden (plural)',
        'dependent' => 'Acompañante (singular)', 'dependents_word' => 'Acompañantes (plural)', 'client_gender' => 'Se dice el o la (quien se atiende)', 'pro_gender' => 'Se dice el o la (quien atiende)', 'dependent_gender' => 'Se dice el o la (acompañante)',
        'users' => 'Usuarios', 'branches' => 'Sedes', 'professionals' => 'Profesionales', 'whatsapp_month' => 'WhatsApp al mes', 'appointments_month' => 'Citas al mes',
        'max_branches' => 'Sedes', 'max_appointments_month' => 'Citas al mes', 'suspension_reason' => 'Motivo de la suspensión',
    ];

    private const VALUES = [
        'active' => 'Activa', 'inactive' => 'Inactiva', 'suspended' => 'Suspendida', 'canceled' => 'Cancelada', 'cancelled' => 'Cancelada',
        'trialing' => 'En prueba', 'past_due' => 'Con pago vencido', 'expired' => 'Vencida', 'draft' => 'Borrador',
        'xlsx' => 'Excel', 'csv' => 'CSV', 'filtered' => 'Todo lo filtrado', 'selected' => 'Solo lo seleccionado',
        'vocabulary' => 'Vocabulario', 'default_rules' => 'Reglas', 'dependents' => 'Dependientes',
    ];

    private static ?array $columns = null;

    public static function log(Request $request, string $action, array $options = []): ?AuditEvent
    {
        if (!Schema::hasTable('audit_events')) {
            return null;
        }

        $actor = array_key_exists('actor', $options) ? $options['actor'] : $request->user();
        $tenant = $options['tenant'] ?? null;
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;

        $data = [
            'tenant_id'     => $tenantId,
            'actor_user_id' => $actor instanceof User ? $actor->id : null,
            'action'        => $action,
            'entity_type'   => $options['entity_type'] ?? null,
            'entity_id'     => $options['entity_id'] ?? null,
            'ip_address'    => $request->ip(),
            'user_agent'    => mb_substr((string) $request->userAgent(), 0, 500),
            'before_data'   => $options['before'] ?? null,
            'after_data'    => $options['after'] ?? null,
        ];

        $optional = [
            'actor_type'           => $options['actor_type'] ?? self::actorType($actor),
            'actor_customer_id'    => $actor instanceof Customer ? $actor->id : null,
            'actor_name'           => mb_substr($options['actor_name'] ?? self::nameOf($actor) ?? '', 0, 150) ?: null,
            'impersonator_user_id' => array_key_exists('impersonator', $options) ? $options['impersonator'] : self::impersonatorId($request),
            'branch_id'            => $options['branch_id'] ?? ($actor instanceof User ? $actor->branch_id : null),
            'entity_label'         => isset($options['entity_label']) ? mb_substr((string) $options['entity_label'], 0, 200) : null,
        ];

        foreach ($optional as $column => $value) {
            if (self::hasColumn($column)) {
                $data[$column] = $value;
            }
        }

        try {
            return DB::transaction(fn () => AuditEvent::create($data));
        } catch (\Throwable $error) {
            report($error);
            return null;
        }
    }

    public static function changed(array $before, array $after): array
    {
        $keys = array_keys(array_filter($after, fn ($value, $key) => !array_key_exists($key, $before) || $before[$key] != $value, ARRAY_FILTER_USE_BOTH));

        return [array_intersect_key($before, array_flip($keys)), array_intersect_key($after, array_flip($keys))];
    }

    public static function hasColumn(string $column): bool
    {
        if (self::$columns === null) {
            self::$columns = Schema::hasTable('audit_events') ? Schema::getColumnListing('audit_events') : [];
        }

        return in_array($column, self::$columns, true);
    }

    public static function actorType(mixed $actor): string
    {
        return match (true) {
            $actor instanceof Customer               => 'customer',
            $actor instanceof User && !$actor->tenant_id => 'platform',
            $actor instanceof User                   => 'staff',
            default                                  => 'system',
        };
    }

    public static function nameOf(mixed $actor): ?string
    {
        if (!$actor instanceof User && !$actor instanceof Customer) {
            return null;
        }

        return trim("{$actor->first_name} {$actor->last_name}") ?: $actor->email;
    }

    public static function impersonatorId(Request $request): ?string
    {
        $token = $request->user()?->currentAccessToken();

        if (!$token instanceof PersonalAccessToken) {
            return null;
        }

        $prefix = \App\Http\Controllers\Api\ImpersonationController::TOKEN_PREFIX;

        return str_starts_with((string) $token->name, $prefix) ? substr($token->name, strlen($prefix)) : null;
    }

    public static function describe(string $action): array
    {
        $catalog = self::ACTIONS + AuditActions\MoneyActions::ACTIONS + AuditActions\AdminActions::ACTIONS + AuditActions\AgendaActions::ACTIONS + AuditActions\CatalogActions::ACTIONS;

        if (isset($catalog[$action])) {
            return $catalog[$action];
        }

        if (preg_match('/^platform\.look_([a-z]+)_(changed|reset)$/', $action, $match)) {
            $section = self::LOOK_SECTIONS[$match[1]] ?? $match[1];
            return [$match[2] === 'reset' ? "Restableció la {$section} de la plataforma" : "Cambió la {$section} de la plataforma", 'platform'];
        }

        return [Str::ucfirst(str_replace(['.', '_'], ' ', $action)), 'other'];
    }

    public static function device(?string $agent): ?string
    {
        if (!$agent) {
            return null;
        }

        $browser = match (true) {
            str_contains($agent, 'Edg/')     => 'Edge',
            str_contains($agent, 'OPR/')     => 'Opera',
            str_contains($agent, 'Chrome/')  => 'Chrome',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Safari/')  => 'Safari',
            default                          => null,
        };

        $system = match (true) {
            str_contains($agent, 'Android')                                   => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad')      => 'iPhone o iPad',
            str_contains($agent, 'Windows')                                   => 'Windows',
            str_contains($agent, 'Mac OS')                                    => 'Mac',
            str_contains($agent, 'Linux')                                     => 'Linux',
            default                                                           => null,
        };

        if (!$browser && !$system) {
            return 'Otro programa';
        }

        return trim(($browser ?? 'Navegador') . ($system ? " en {$system}" : ''));
    }

    public static function present(iterable $events): array
    {
        $events = collect($events);
        $userIds = $events->flatMap(fn ($e) => [$e->actor_user_id, $e->impersonator_user_id ?? null, $e->after_data['as_user_id'] ?? null, $e->entity_type === 'user' ? $e->entity_id : null])->filter()->unique()->values();
        $roles = \Illuminate\Support\Facades\DB::table('roles')->whereIn('id', $events->where('entity_type', 'role')->pluck('entity_id')->filter()->unique())->pluck('name', 'id');
        $users = User::whereIn('id', $userIds)->get(['id', 'first_name', 'last_name', 'email', 'tenant_id'])->keyBy('id');
        $tenants = Tenant::whereIn('id', $events->pluck('tenant_id')->filter()->unique())->pluck('name', 'id');
        $lookups = self::lookups();

        return $events->map(function ($event) use ($users, $tenants, $lookups, $roles) {
            [$label, $area] = self::describe($event->action);
            $actor = $event->actor_user_id ? $users->get($event->actor_user_id) : null;
            $impersonator = ($event->impersonator_user_id ?? null) ? $users->get($event->impersonator_user_id) : null;
            $type = $event->actor_type ?? ($actor ? self::actorType($actor) : 'system');

            return [
                'id'           => $event->id,
                'at'           => Carbon::parse($event->created_at)->toIso8601String(),
                'action'       => $event->action,
                'label'        => $label,
                'area'         => $area,
                'area_label'   => self::AREAS[$area],
                'actor'        => [
                    'id'   => $actor?->id ?? ($event->actor_customer_id ?? null),
                    'name' => ($actor ? self::nameOf($actor) : null) ?? ($event->actor_name ?? null) ?? ($type === 'system' ? 'El sistema' : 'Cuenta eliminada'),
                    'type' => $type,
                ],
                'impersonator' => $impersonator ? ['id' => $impersonator->id, 'name' => self::nameOf($impersonator)] : null,
                'tenant'       => $event->tenant_id ? ['id' => $event->tenant_id, 'name' => $tenants[$event->tenant_id] ?? 'Empresa eliminada'] : null,
                'entity_label' => ($event->entity_label ?? null) ?? self::entityLabel($event, $tenants, $users, $roles),
                'ip'           => $event->ip_address,
                'device'       => self::device($event->user_agent),
                'changes'      => self::changes($event->before_data, $event->after_data, $lookups + ['users' => $users]),
            ];
        })->values()->all();
    }

    private static function entityLabel($event, $tenants, $users, $roles): ?string
    {
        if ($event->entity_type === 'tenant' && $event->entity_id) {
            return $tenants[$event->entity_id] ?? null;
        }

        if ($event->entity_type === 'user' && $event->entity_id && !str_starts_with($event->action, 'auth.')) {
            return ($user = $users->get($event->entity_id)) ? self::nameOf($user) : null;
        }

        if ($event->entity_type === 'role' && $event->entity_id) {
            return isset($roles[$event->entity_id]) ? "Rol {$roles[$event->entity_id]}" : null;
        }

        return null;
    }

    private static function lookups(): array
    {
        return [
            'plans'   => SaaSPlan::pluck('name', 'id'),
            'types'   => BusinessType::pluck('name', 'id'),
            'modules' => SaasModule::pluck('name', 'key'),
        ];
    }

    public static function changes(?array $before, ?array $after, ?array $lookups = null): array
    {
        $lookups ??= self::lookups() + ['users' => collect()];
        $before = self::flatten($before ?? []);
        $after = self::flatten($after ?? []);
        $rows = [];

        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $from = array_key_exists($key, $before) ? self::format($key, $before[$key], $lookups) : null;
            $to = array_key_exists($key, $after) ? self::format($key, $after[$key], $lookups) : null;

            if ($from === $to) {
                continue;
            }

            $rows[] = ['field' => self::fieldLabel($key), 'from' => $from, 'to' => $to];
        }

        return $rows;
    }

    private static function flatten(array $data, string $prefix = '', int $depth = 0): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $isMap = is_array($value) && $value !== [] && !array_is_list($value);

            if ($isMap && $depth < 1 && !in_array($key, ['business_profile', 'template'], true)) {
                $flat += self::flatten($value, $path, $depth + 1);
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    private static function fieldLabel(string $path): string
    {
        $parts = explode('.', $path);
        $fields = self::FIELDS + AuditActions\MoneyActions::FIELDS + AuditActions\AdminActions::FIELDS + AuditActions\AgendaActions::FIELDS + AuditActions\CatalogActions::FIELDS;
        $labels = array_map(fn ($part) => $fields[$part] ?? Str::ucfirst(str_replace('_', ' ', $part)), $parts);

        return implode(' · ', $labels);
    }

    private static function format(string $path, mixed $value, array $lookups): ?string
    {
        $key = Str::afterLast($path, '.');

        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'Sí' : 'No';
        }

        if ($key === 'plan_id') {
            return $lookups['plans'][$value] ?? 'Plan eliminado';
        }

        if ($key === 'business_type_id') {
            return $lookups['types'][$value] ?? 'Rubro eliminado';
        }

        if ($key === 'as_user_id') {
            return ($user = $lookups['users']->get($value)) ? self::nameOf($user) : 'Cuenta eliminada';
        }

        if (in_array($key, ['modules_off', 'modules'], true) && is_array($value)) {
            return $value === [] ? 'Ninguno' : implode(', ', array_map(fn ($module) => $lookups['modules'][$module] ?? $module, $value));
        }

        if (in_array($key, ['business_profile', 'template'], true)) {
            return 'Actualizada';
        }

        if (is_array($value)) {
            if ($value === []) {
                return 'Ninguno';
            }

            return array_is_list($value) && collect($value)->every(fn ($item) => is_scalar($item))
                ? implode(', ', array_map(fn ($item) => self::VALUES[$item] ?? (string) $item, $value))
                : 'Cambió';
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}.*)?$/', $value)) {
            try {
                $date = Carbon::parse($value)->timezone('America/Lima');
                return str_contains($value, ':') ? $date->format('d/m/Y H:i') : $date->format('d/m/Y');
            } catch (\Throwable) {
                return $value;
            }
        }

        return self::VALUES[$value] ?? (string) $value;
    }
}
