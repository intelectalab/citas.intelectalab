<?php

namespace App\Support;

use App\Models\Tenant;

class Phones
{
    public const DEFAULT_PREFIX = '51';

    private const BY_CURRENCY = [
        'PEN' => '51', 'COP' => '57', 'MXN' => '52', 'CLP' => '56', 'ARS' => '54', 'BOB' => '591',
        'BRL' => '55', 'UYU' => '598', 'PYG' => '595', 'GTQ' => '502', 'CRC' => '506', 'DOP' => '1',
    ];

    private const PREFIXES = ['591', '593', '595', '598', '502', '503', '504', '505', '506', '507', '51', '52', '54', '55', '56', '57', '58', '34', '1'];

    public static function prefixFor(?Tenant $tenant): string
    {
        if (!$tenant) {
            return self::DEFAULT_PREFIX;
        }

        try {
            return self::BY_CURRENCY[Currency::of($tenant)] ?? self::DEFAULT_PREFIX;
        } catch (\Throwable) {
            return self::DEFAULT_PREFIX;
        }
    }

    public static function prefixForTenantId(?string $tenantId): string
    {
        return self::prefixFor($tenantId ? Tenant::find($tenantId) : null);
    }

    public static function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    public static function normalize(?string $value, string $prefix = self::DEFAULT_PREFIX): ?string
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        $digits = self::digits($raw);

        if (strlen($digits) < 6 || strlen($digits) > 15) {
            return null;
        }

        if (str_starts_with($raw, '+')) {
            return '+' . $digits;
        }

        if (str_starts_with($digits, '00')) {
            return '+' . substr($digits, 2);
        }

        $local = ltrim($digits, '0');

        if (str_starts_with($local, $prefix) && strlen($local) >= strlen($prefix) + 9) {
            return '+' . $local;
        }

        return '+' . $prefix . $local;
    }

    public static function isValid(?string $value): bool
    {
        $digits = self::digits($value);

        return strlen($digits) >= 6 && strlen($digits) <= 15;
    }

    public static function display(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (!str_starts_with($value, '+')) {
            return $value;
        }

        $digits = substr($value, 1);
        $prefix = collect(self::PREFIXES)->first(fn ($p) => str_starts_with($digits, $p) && strlen($digits) - strlen($p) >= 6) ?? substr($digits, 0, 2);

        return '+' . $prefix . ' ' . trim(chunk_split(substr($digits, strlen($prefix)), 3, ' '));
    }

    public static function searchVariants(string $term, string $prefix = self::DEFAULT_PREFIX): array
    {
        $digits = self::digits($term);

        if (strlen($digits) < 4) {
            return [];
        }

        $variants = [$digits];
        $trimmed = ltrim($digits, '0');

        if (str_starts_with($trimmed, $prefix) && strlen($trimmed) > strlen($prefix) + 5) {
            $variants[] = substr($trimmed, strlen($prefix));
        }

        return array_values(array_unique($variants));
    }
}
