<?php

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class Codes
{
    public const TYPES = [
        'customer'       => ['label' => 'Clientes', 'prefix' => 'CLI', 'digits' => 4, 'table' => 'customers', 'column' => 'code', 'module' => null],
        'appointment'    => ['label' => 'Citas', 'prefix' => 'C', 'digits' => 4, 'table' => 'appointments', 'column' => 'code', 'module' => 'agenda|booking'],
        'sale'           => ['label' => 'Ventas', 'prefix' => 'V', 'digits' => 6, 'sequence' => 'sale', 'module' => 'pos'],
        'quote'          => ['label' => 'Presupuestos', 'prefix' => 'PRE', 'digits' => 5, 'sequence' => 'quote', 'module' => 'quotes'],
        'treatment_plan' => ['label' => 'Planes de tratamiento', 'prefix' => 'PT', 'digits' => 4, 'sequence' => 'treatment_plan', 'module' => 'quotes'],
        'package'        => ['label' => 'Paquetes vendidos', 'prefix' => 'PQ', 'digits' => 4, 'sequence' => 'package', 'module' => 'packages'],
        'product'        => ['label' => 'Productos (SKU)', 'prefix' => 'P', 'digits' => 4, 'sequence' => 'product', 'module' => 'pos|inventory'],
        'transfer'       => ['label' => 'Traslados', 'prefix' => 'TR', 'digits' => 4, 'table' => 'stock_transfers', 'column' => 'code', 'module' => 'inventory'],
        'purchase'       => ['label' => 'Compras', 'prefix' => 'OC', 'digits' => 4, 'table' => 'purchase_orders', 'column' => 'code', 'module' => 'inventory'],
    ];

    public const LEGACY = ['quote' => ['P']];

    private static array $cache = [];

    private static function stored(string $tenantId): array
    {
        if (array_key_exists($tenantId, self::$cache)) {
            return self::$cache[$tenantId];
        }

        $raw = Branding::ready() ? TenantSetting::where('tenant_id', $tenantId)->value('branding') : null;
        $branding = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
        $codes = is_array($branding['codes'] ?? null) ? $branding['codes'] : [];

        return self::$cache[$tenantId] = [
            'prefixes' => is_array($codes['prefixes'] ?? null) ? $codes['prefixes'] : [],
            'history'  => is_array($codes['history'] ?? null) ? $codes['history'] : [],
        ];
    }

    public static function prefix(string $tenantId, string $type): string
    {
        $prefix = self::stored($tenantId)['prefixes'][$type] ?? null;

        return is_string($prefix) && $prefix !== '' ? $prefix : self::TYPES[$type]['prefix'];
    }

    private static function format(string $prefix, int $number, int $digits): string
    {
        return $prefix . '-' . str_pad((string) $number, $digits, '0', STR_PAD_LEFT);
    }

    private static function knownPrefixes(string $tenantId, string $type): array
    {
        return array_values(array_unique(array_merge(
            [self::prefix($tenantId, $type), self::TYPES[$type]['prefix']],
            self::LEGACY[$type] ?? [],
            (array) (self::stored($tenantId)['history'][$type] ?? []),
        )));
    }

    private static function highest(string $tenantId, string $type): int
    {
        $config = self::TYPES[$type];
        $max = 0;

        foreach (self::knownPrefixes($tenantId, $type) as $prefix) {
            $start = mb_strlen($prefix) + 2;
            $value = (int) DB::table($config['table'])
                ->where('tenant_id', $tenantId)
                ->whereRaw("{$config['column']} ~ ?", ['^' . preg_quote($prefix) . '-[0-9]+$'])
                ->selectRaw("COALESCE(MAX(CAST(SUBSTRING({$config['column']} FROM {$start}) AS BIGINT)), 0) AS n")
                ->value('n');
            $max = max($max, $value);
        }

        return $max;
    }

    public static function next(string $tenantId, string $type): string
    {
        $config = self::TYPES[$type];
        $number = isset($config['sequence'])
            ? Sales::nextNumber($tenantId, $config['sequence'])
            : self::highest($tenantId, $type) + 1;

        return self::format(self::prefix($tenantId, $type), $number, $config['digits']);
    }

    public static function preview(string $tenantId, string $type, ?string $prefix = null): string
    {
        $config = self::TYPES[$type];
        $number = isset($config['sequence'])
            ? (int) (DB::table('tenant_sequences')->where('tenant_id', $tenantId)->where('key', $config['sequence'])->value('next_value') ?? 1)
            : self::highest($tenantId, $type) + 1;

        return self::format($prefix ?? self::prefix($tenantId, $type), $number, $config['digits']);
    }

    public static function available(Tenant $tenant): array
    {
        return array_keys(array_filter(self::TYPES, fn ($config) => !$config['module'] || $tenant->hasModule($config['module'])));
    }

    public static function present(Tenant $tenant): array
    {
        return collect(self::available($tenant))->map(fn ($type) => [
            'type'    => $type,
            'label'   => self::TYPES[$type]['label'],
            'prefix'  => self::prefix($tenant->id, $type),
            'default' => self::TYPES[$type]['prefix'],
            'next'    => self::preview($tenant->id, $type),
            'digits'  => self::TYPES[$type]['digits'],
        ])->values()->all();
    }

    public static function save(Request $request, Tenant $tenant, array $prefixes): array
    {
        $available = self::available($tenant);
        $stored = self::stored($tenant->id);
        $current = collect(array_keys(self::TYPES))->mapWithKeys(fn ($type) => [$type => self::prefix($tenant->id, $type)])->all();
        $wanted = $current;
        $errors = [];

        foreach ($prefixes as $type => $value) {
            if (!in_array($type, $available, true)) {
                continue;
            }
            $value = strtoupper(trim(rtrim((string) $value, '-')));
            if ($value === '') {
                $value = self::TYPES[$type]['prefix'];
            }
            if (!preg_match('/^[A-Z0-9]{1,6}$/', $value)) {
                $errors["prefixes.{$type}"] = 'Usa de 1 a 6 letras o números, sin espacios ni tildes (por ejemplo PAC).';
            }
            $wanted[$type] = $value;
        }

        foreach ($wanted as $type => $value) {
            foreach ($wanted as $other => $otherValue) {
                if ($type !== $other && $value === $otherValue && in_array($type, $available, true) && isset($prefixes[$type])) {
                    $errors["prefixes.{$type}"] = '"' . $value . '-" ya se usa en ' . self::TYPES[$other]['label'] . '. Elige otro para que no se confundan.';
                }
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $changed = array_filter($wanted, fn ($value, $type) => $value !== $current[$type], ARRAY_FILTER_USE_BOTH);

        if (!$changed) {
            return self::present($tenant);
        }

        $history = $stored['history'];
        foreach ($changed as $type => $value) {
            $history[$type] = array_values(array_unique(array_merge((array) ($history[$type] ?? []), [$current[$type]])));
        }

        $settings = $tenant->settings ?? new TenantSetting(['tenant_id' => $tenant->id]);
        $branding = Branding::stored($tenant);
        $branding['codes'] = [
            'prefixes' => array_filter($wanted, fn ($value, $type) => $value !== self::TYPES[$type]['prefix'], ARRAY_FILTER_USE_BOTH),
            'history'  => $history,
        ];
        $settings->setAttribute('branding', $branding);
        $settings->save();
        $tenant->setRelation('settings', $settings);
        unset(self::$cache[$tenant->id]);

        AuditEvent::record(
            $request,
            'tenant.code_prefixes_changed',
            $tenant,
            collect($changed)->mapWithKeys(fn ($v, $type) => [self::TYPES[$type]['label'] => $current[$type] . '-'])->all(),
            collect($changed)->mapWithKeys(fn ($v, $type) => [self::TYPES[$type]['label'] => $v . '-'])->all(),
        );

        return self::present($tenant);
    }

    public static function ready(): bool
    {
        return Branding::ready() && Schema::hasTable('tenant_sequences');
    }
}
