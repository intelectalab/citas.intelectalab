<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ApiKeys
{
    public const NOT_READY = 'Falta actualizar la base de datos para usar la API y los webhooks: ejecuta php artisan migrate.';

    public const PER_MINUTE = 120;

    public const RESOURCES = [
        'appointments' => ['label' => 'Citas', 'levels' => ['read', 'write'], 'hint' => 'Ver las citas; con escritura, también agendarlas y cancelarlas.'],
        'availability' => ['label' => 'Horarios libres', 'levels' => ['read'], 'hint' => 'Consultar qué horarios hay libres para un servicio.'],
        'customers'    => ['label' => 'Clientes', 'levels' => ['read', 'write'], 'hint' => 'Ver los clientes; con escritura, también registrarlos.'],
        'services'     => ['label' => 'Servicios, sedes y profesionales', 'levels' => ['read'], 'hint' => 'Ver el catálogo de servicios, las sedes y el equipo.'],
    ];

    public const LEVELS = ['none' => 'Sin acceso', 'read' => 'Solo lectura', 'write' => 'Lectura y escritura'];

    private const PREFIX = 'apk_';

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('api_keys') && Schema::hasTable('webhook_endpoints') && Schema::hasTable('webhook_deliveries') && Schema::hasTable('calendar_feeds');
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function generate(): string
    {
        return self::PREFIX . Str::random(40);
    }

    public static function cleanScopes(array $scopes): array
    {
        $clean = [];

        foreach (self::RESOURCES as $resource => $definition) {
            $level = $scopes[$resource] ?? 'none';

            if (in_array($level, $definition['levels'], true)) {
                $clean[$resource] = $level;
            }
        }

        return $clean;
    }

    public static function allows(object $key, string $resource, string $level): bool
    {
        $scopes = is_string($key->scopes) ? (json_decode($key->scopes, true) ?: []) : (array) $key->scopes;
        $granted = $scopes[$resource] ?? null;

        return $granted === 'write' || ($level === 'read' && $granted === 'read');
    }

    public static function find(?string $plain): ?object
    {
        if (!$plain || !str_starts_with($plain, self::PREFIX) || strlen($plain) > 100 || !self::ready()) {
            return null;
        }

        return DB::table('api_keys')->where('key_hash', self::hash($plain))->first();
    }

    public static function present(object $key, array $users = []): array
    {
        $scopes = is_string($key->scopes) ? (json_decode($key->scopes, true) ?: []) : (array) $key->scopes;

        return [
            'id'           => $key->id,
            'name'         => $key->name,
            'prefix'       => $key->prefix,
            'scopes'       => (object) $scopes,
            'created_by'   => $users[$key->created_by] ?? null,
            'created_at'   => $key->created_at ? \Illuminate\Support\Carbon::parse($key->created_at)->toIso8601String() : null,
            'last_used_at' => $key->last_used_at ? \Illuminate\Support\Carbon::parse($key->last_used_at)->toIso8601String() : null,
            'last_used_ip' => $key->last_used_ip,
            'revoked_at'   => $key->revoked_at ? \Illuminate\Support\Carbon::parse($key->revoked_at)->toIso8601String() : null,
            'revoked_by'   => $users[$key->revoked_by] ?? null,
        ];
    }
}
