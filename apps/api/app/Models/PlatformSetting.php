<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlatformSetting extends Model
{
    protected $table = 'platform_settings';

    protected $primaryKey = 'key';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    protected $casts = ['value' => 'array'];

    public const DEFAULTS = [
        'product_name'          => 'CoreERP',
        'support_email'         => null,
        'accent_color'          => '#10B981',
        'usage_warning_percent' => 70,
        'usage_danger_percent'  => 90,
        'allow_tenant_signup'   => true,
        'impersonation_hours'   => 2,
        'grace_days'            => 7,
    ];

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
