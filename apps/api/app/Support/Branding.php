<?php

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class Branding
{
    public const NOT_READY = 'Falta actualizar la base de datos para personalizar la marca: ejecuta php artisan migrate.';

    public const DEFAULTS = ['primary' => '#022340', 'accent' => '#3F768C', 'menu' => '#0B192C'];

    public const VARIANTS = ['light' => 'logo', 'dark' => 'logo-dark'];

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasColumn('tenant_settings', 'branding');
    }

    public static function stored(Tenant $tenant): array
    {
        $raw = $tenant->settings?->getAttribute('branding');

        return is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
    }

    private static function hex(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) ? strtoupper($value) : null;
    }

    public static function present(Tenant $tenant): array
    {
        $stored = self::ready() ? self::stored($tenant) : [];

        return [
            'primary'       => self::hex($tenant->settings?->brand_color),
            'accent'        => self::hex($stored['accent'] ?? null),
            'menu'          => self::hex($stored['menu'] ?? null),
            'short_name'    => isset($stored['short_name']) && trim((string) $stored['short_name']) !== '' ? (string) $stored['short_name'] : null,
            'logo_url'      => self::logoPath($tenant->id, 'light') ? $tenant->settings?->logo_url : null,
            'logo_dark_url' => self::logoPath($tenant->id, 'dark') ? ($stored['logo_dark_url'] ?? null) : null,
        ];
    }

    public static function primary(Tenant $tenant): ?string
    {
        return self::hex($tenant->settings?->brand_color);
    }

    public static function save(Request $request, Tenant $tenant, array $values): array
    {
        $before = self::present($tenant);
        $settings = $tenant->settings ?? new TenantSetting(['tenant_id' => $tenant->id]);
        $stored = self::stored($tenant);

        $settings->brand_color = self::hex($values['primary'] ?? null);
        $stored['accent'] = self::hex($values['accent'] ?? null);
        $stored['menu'] = self::hex($values['menu'] ?? null);
        $short = trim((string) ($values['short_name'] ?? ''));
        $stored['short_name'] = $short !== '' ? mb_strtoupper(mb_substr($short, 0, 6)) : null;
        $settings->setAttribute('branding', $stored);
        $settings->save();
        $tenant->setRelation('settings', $settings);

        $after = self::present($tenant);
        $keys = ['primary', 'accent', 'menu', 'short_name'];
        $changed = array_filter($keys, fn ($key) => ($before[$key] ?? null) !== ($after[$key] ?? null));

        if ($changed) {
            AuditEvent::record(
                $request,
                'tenant.branding_changed',
                $tenant,
                array_intersect_key($before, array_flip($changed)),
                array_intersect_key($after, array_flip($changed)),
            );
        }

        return $after;
    }

    public static function logoPath(string $tenantId, string $variant = 'light'): ?string
    {
        $prefix = (self::VARIANTS[$variant] ?? 'logo') . '.';

        foreach (Storage::disk('local')->files("branding/{$tenantId}") as $file) {
            if (str_starts_with(basename($file), $prefix)) {
                return $file;
            }
        }

        return null;
    }

    public static function storeLogo(Request $request, Tenant $tenant, UploadedFile $file, string $variant = 'light'): void
    {
        self::deleteFile($tenant->id, $variant);
        $name = self::VARIANTS[$variant];
        $extension = strtolower($file->extension() ?: 'png');
        Storage::disk('local')->putFileAs("branding/{$tenant->id}", $file, "{$name}.{$extension}");

        $settings = $tenant->settings ?? new TenantSetting(['tenant_id' => $tenant->id]);
        $url = rtrim((string) config('app.url'), '/') . "/api/branding/{$tenant->id}/{$name}?v=" . now()->timestamp;

        if ($variant === 'light') {
            $settings->logo_url = $url;
        } else {
            $settings->setAttribute('branding', ['logo_dark_url' => $url] + self::stored($tenant));
        }

        $settings->save();
        $tenant->setRelation('settings', $settings);
        AuditEvent::record($request, 'tenant.branding_logo_changed', $tenant, null, ['logo' => $variant === 'light' ? 'Logo para fondo claro' : 'Logo para fondo oscuro']);
    }

    public static function removeLogo(Request $request, Tenant $tenant, string $variant = 'light'): void
    {
        self::deleteFile($tenant->id, $variant);
        $settings = $tenant->settings;

        if (!$settings) {
            return;
        }

        if ($variant === 'light') {
            $settings->logo_url = null;
        } else {
            $stored = self::stored($tenant);
            unset($stored['logo_dark_url']);
            $settings->setAttribute('branding', $stored);
        }

        $settings->save();
        AuditEvent::record($request, 'tenant.branding_logo_removed', $tenant, ['logo' => $variant === 'light' ? 'Logo para fondo claro' : 'Logo para fondo oscuro'], null);
    }

    private static function deleteFile(string $tenantId, string $variant): void
    {
        while ($path = self::logoPath($tenantId, $variant)) {
            Storage::disk('local')->delete($path);
        }
    }
}
