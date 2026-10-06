<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Configuración de la plataforma (del SaaS, no de una empresa). Una fila por
 * clave. Solo la cambia el administrador SaaS.
 */
class PlatformSetting extends Model
{
    protected $table = 'platform_settings';

    protected $primaryKey = 'key';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    protected $casts = ['value' => 'array'];

    // Valores cuando una clave aún no se ha guardado.
    public const DEFAULTS = [
        'product_name'          => 'CoreERP',
        'support_email'         => null,
        // Color de lo que está "encendido" o "bien": interruptores y anillos de uso.
        'accent_color'          => '#10B981',
        // Desde qué porcentaje del límite el uso se pinta de aviso y de alerta.
        'usage_warning_percent' => 70,
        'usage_danger_percent'  => 90,
        // Si cualquiera puede registrar una empresa desde /register.
        'allow_tenant_signup'   => true,
        // Cuántas horas dura la sesión al entrar al panel de una empresa.
        'impersonation_hours'   => 2,
    ];

    // Lo que puede leer cualquiera, sin sesión: marca y apariencia.
    public const PUBLIC_KEYS = [
        'product_name', 'support_email', 'accent_color',
        'usage_warning_percent', 'usage_danger_percent', 'allow_tenant_signup',
    ];

    private static ?array $resolved = null;

    public static function values(): array
    {
        if (static::$resolved !== null) {
            return static::$resolved;
        }

        try {
            $stored = static::query()->pluck('value', 'key')->all();
        } catch (\Throwable $e) {
            // La tabla puede no existir todavía si el esquema no se ha reseteado.
            Log::warning('platform_settings no disponible; se usan los valores por defecto.');
            $stored = [];
        }

        return static::$resolved = array_merge(static::DEFAULTS, array_intersect_key($stored, static::DEFAULTS));
    }

    public static function value(string $key): mixed
    {
        return static::values()[$key] ?? null;
    }

    public static function put(array $values): array
    {
        // Todo o nada: si una clave falla no queda la configuración a medias.
        DB::transaction(function () use ($values) {
            foreach (array_intersect_key($values, static::DEFAULTS) as $key => $value) {
                static::updateOrCreate(['key' => $key], ['value' => $value]);
            }
        });

        static::$resolved = null;

        return static::values();
    }

    public static function publicValues(): array
    {
        return array_intersect_key(static::values(), array_flip(static::PUBLIC_KEYS));
    }
}
