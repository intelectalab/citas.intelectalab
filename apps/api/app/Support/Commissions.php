<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Commissions
{
    public const STATUSES = [
        'calculated' => 'Calculada',
        'approved'   => 'Aprobada',
        'paid'       => 'Pagada',
    ];

    public const SOURCES = [
        'sale'        => 'Venta',
        'package_use' => 'Sesión de paquete',
        'refund'      => 'Devolución',
    ];

    public const NOT_READY = 'Falta actualizar la base de datos para usar comisiones: ejecuta php artisan migrate.';

    private static array $rules = [];

    public static function flush(): void
    {
        self::$rules = [];
    }

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('commission_entries') && Schema::hasTable('commission_rules') && Schema::hasColumn('professionals', 'commission_percent');
    }

    public static function enabled(Tenant $tenant): bool
    {
        return self::ready() && $tenant->hasModule('commissions');
    }

    public static function rules(Tenant $tenant): array
    {
        return [
            'base'           => AppointmentFlow::rule($tenant, 'commission.base') === 'gross' ? 'gross' : 'net',
            'on_products'    => (bool) AppointmentFlow::rule($tenant, 'commission.on_products'),
            'on_package_use' => (bool) AppointmentFlow::rule($tenant, 'commission.on_package_use'),
            'auto_approve'   => (bool) AppointmentFlow::rule($tenant, 'commission.auto_approve'),
        ];
    }

    public static function period(Tenant $tenant, Carbon|string|null $at = null): string
    {
        return Carbon::parse($at ?? now())->setTimezone($tenant->zone())->format('Y-m');
    }

    private static function activeRules(string $tenantId, string $kind): Collection
    {
        return DB::table('commission_rules')->where('tenant_id', $tenantId)->where('kind', $kind)->where('active', true)->get();
    }

    public const LABELS = [
        'exception' => 'Excepción',
        'service'   => 'Comisión del servicio',
        'own'       => 'Su porcentaje',
        'product'   => 'Comisión del producto',
        'products'  => 'Comisión de productos',
    ];

    public static function match(Collection $rules, string $kind, string $professionalId, ?string $referenceId): ?array
    {
        $pick = fn (string $source, ?object $rule) => $rule ? ['source' => $source, 'rule' => $rule] : null;

        if ($kind === 'service') {
            return $pick('exception', $rules->first(fn ($r) => $r->professional_id === $professionalId && $r->service_id === $referenceId))
                ?? $pick('service', $rules->first(fn ($r) => !$r->professional_id && $r->service_id === $referenceId));
        }

        return $pick('product', $rules->first(fn ($r) => $r->product_id && $r->product_id === $referenceId))
            ?? $pick('products', $rules->first(fn ($r) => !$r->product_id));
    }

    public static function explain(Tenant $tenant, string $kind, string $professionalId, ?string $referenceId): ?array
    {
        $key = "{$tenant->id}:{$kind}";
        self::$rules[$key] ??= self::activeRules($tenant->id, $kind);
        $found = self::match(self::$rules[$key], $kind, $professionalId, $referenceId);

        if ($found) {
            return [
                'source'    => $found['source'],
                'percent'   => (float) $found['rule']->percent,
                'fixed'     => (float) $found['rule']->fixed,
                'rule_id'   => $found['rule']->id,
                'rule_name' => self::LABELS[$found['source']],
            ];
        }

        if ($kind !== 'service') {
            return null;
        }

        $percent = (float) DB::table('professionals')->where('id', $professionalId)->value('commission_percent');

        return $percent > 0 ? ['source' => 'own', 'percent' => $percent, 'fixed' => 0.0, 'rule_id' => null, 'rule_name' => self::LABELS['own']] : null;
    }

    public static function preview(Tenant $tenant, string $kind, string $professionalId, string $referenceId): array
    {
        $item = DB::table($kind === 'service' ? 'services' : 'products')->where('tenant_id', $tenant->id)->where('id', $referenceId)->first(['name', 'price']);
        abort_unless($item, 404);
        $professional = DB::table('professionals')->where('tenant_id', $tenant->id)->where('id', $professionalId)->value('display_name');
        abort_unless($professional, 404);

        $rule = self::explain($tenant, $kind, $professionalId, $referenceId);
        $sales = Sales::rules($tenant);
        $price = round((float) $item->price, 2);
        $base = self::rules($tenant)['base'] === 'gross'
            ? $price
            : ($sales['prices_include_tax'] ? self::netOf($price, $sales['tax_rate'], true) : $price);
        $currency = Currency::of($tenant);
        $money = fn (float $v) => Currency::format($v, $currency);

        if (!$rule) {
            return [
                'found'   => false,
                'message' => $kind === 'service'
                    ? "{$professional} no gana comisión en {$item->name}: no tiene porcentaje propio y el servicio no tiene una comisión."
                    : "No gana comisión por {$item->name}: no hay una comisión de productos.",
            ];
        }

        $amount = self::amount($base, $rule, 1);
        $how = match ($rule['source']) {
            'exception' => 'por su excepción en este servicio',
            'service'   => 'por la comisión del servicio, que vale para todos',
            'own'       => 'por su porcentaje propio, porque el servicio no tiene otra comisión',
            'product'   => 'por la comisión de este producto',
            default     => 'por la comisión de productos, que vale para todos',
        };
        $what = implode(' + ', array_filter([$rule['percent'] > 0 ? rtrim(rtrim(number_format($rule['percent'], 2, '.', ''), '0'), '.') . ' %' : null, $rule['fixed'] > 0 ? $money($rule['fixed']) . ($kind === 'service' ? ' por atención' : ' por unidad') : null]));
        $baseText = self::rules($tenant)['base'] === 'gross' ? $money($price) : "{$money($price)} ({$money($base)} sin IGV)";

        return [
            'found'   => true,
            'source'  => $rule['source'],
            'label'   => $rule['rule_name'],
            'percent' => $rule['percent'],
            'fixed'   => $rule['fixed'],
            'amount'  => $amount,
            'message' => "{$professional} gana {$what} en {$item->name}, {$how}. " . ($kind === 'service' ? 'Por una atención de' : 'Por uno de') . " {$baseText} gana {$money($amount)}.",
        ];
    }

    private static function insert(Tenant $tenant, array $row): void
    {
        $status = self::rules($tenant)['auto_approve'] ? 'approved' : 'calculated';

        DB::table('commission_entries')->insertOrIgnore($row + [
            'id'          => (string) Str::uuid(),
            'tenant_id'   => $tenant->id,
            'status'      => $status,
            'approved_at' => $status === 'approved' ? now() : null,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    private static function amount(float $base, array $rule, float $quantity): float
    {
        return round($base * $rule['percent'] / 100 + $rule['fixed'] * $quantity, 2);
    }

    private static function netOf(float $amount, float $taxRate, bool $included): float
    {
        return $included && $taxRate > 0 ? round($amount / (1 + $taxRate / 100), 2) : round($amount, 2);
    }

    public static function forSale(Tenant $tenant, object $sale): int
    {
        return self::guarded(fn () => self::saleEntries($tenant, $sale), 0, 'venta', $sale->id);
    }

    private static function saleEntries(Tenant $tenant, object $sale): int
    {
        if (!self::enabled($tenant) || !in_array($sale->status, ['paid', 'partially_refunded', 'refunded'], true)) {
            return 0;
        }

        $rules = self::rules($tenant);
        $items = DB::table('sale_items')->where('sale_id', $sale->id)->orderBy('position')->get();
        $lines = (float) $items->sum('total');
        $net = max(0, (float) $sale->total - (float) ($sale->tip ?? 0) - (float) $sale->tax);
        $ratio = $lines > 0 ? $net / $lines : 0;
        $earnedAt = $sale->paid_at ?? $sale->created_at;
        $created = 0;

        foreach ($items as $item) {
            if (!$item->professional_id || !in_array($item->item_type, ['service', 'product'], true)) {
                continue;
            }

            if ($item->item_type === 'product' && !$rules['on_products']) {
                continue;
            }

            if (DB::table('commission_entries')->where('source', 'sale')->where('sale_item_id', $item->id)->exists()) {
                continue;
            }

            $rule = self::explain($tenant, $item->item_type, $item->professional_id, $item->reference_id);

            if (!$rule) {
                continue;
            }

            $quantity = (float) $item->quantity;
            $base = $rules['base'] === 'gross' ? round((float) $item->unit_price * $quantity, 2) : round((float) $item->total * $ratio, 2);
            $amount = self::amount($base, $rule, $quantity);

            if ($amount <= 0) {
                continue;
            }

            self::insert($tenant, [
                'branch_id'       => $sale->branch_id,
                'professional_id' => $item->professional_id,
                'source'          => 'sale',
                'sale_id'         => $sale->id,
                'sale_item_id'    => $item->id,
                'kind'            => $item->item_type,
                'description'     => mb_substr(((float) $item->quantity > 1 ? (int) $item->quantity . ' × ' : '') . $item->description, 0, 255),
                'quantity'        => $quantity,
                'base'            => $base,
                'percent'         => $rule['percent'],
                'fixed'           => $rule['fixed'],
                'rule_id'         => $rule['rule_id'],
                'rule_name'       => $rule['rule_name'],
                'amount'          => $amount,
                'currency'        => $sale->currency ?? Currency::of($tenant),
                'period'          => self::period($tenant, $earnedAt),
                'earned_at'       => $earnedAt,
            ]);
            $created++;
        }

        return $created;
    }

    public static function forUsage(Tenant $tenant, ?object $usage): bool
    {
        return $usage ? self::guarded(fn () => self::usageEntry($tenant, $usage), false, 'sesión de paquete', $usage->id) : false;
    }

    private static function usageEntry(Tenant $tenant, object $usage): bool
    {
        if (!self::enabled($tenant) || !$usage->professional_id || $usage->kind !== 'attended' || !self::rules($tenant)['on_package_use']) {
            return false;
        }

        if (DB::table('commission_entries')->where('source', 'package_use')->where('package_usage_id', $usage->id)->exists()) {
            return false;
        }

        $package = DB::table('customer_packages')->where('id', $usage->customer_package_id)->first();

        if (!$package) {
            return false;
        }

        $items = DB::table('customer_package_items')->where('customer_package_id', $package->id)->get();
        $item = $items->firstWhere('service_id', $usage->service_id);
        $listValue = (float) $items->sum(fn ($i) => (int) $i->sessions_total * (float) $i->unit_price);
        $sessions = (int) $items->sum('sessions_total');
        $price = (float) $package->price;

        $value = match (true) {
            $item && $listValue > 0 => $price * (float) $item->unit_price / $listValue,
            $sessions > 0           => $price / $sessions,
            default                 => 0.0,
        };

        $sale = $package->sale_id ? DB::table('sales')->where('id', $package->sale_id)->first() : null;
        $salesRules = Sales::rules($tenant);
        $taxRate = $sale ? (float) $sale->tax_rate : $salesRules['tax_rate'];
        $included = $sale ? (bool) $sale->prices_include_tax : $salesRules['prices_include_tax'];
        $rules = self::rules($tenant);
        $base = $rules['base'] === 'gross' ? round((float) ($item->unit_price ?? $value), 2) : self::netOf($value, $taxRate, $included);
        $rule = self::explain($tenant, 'service', $usage->professional_id, $usage->service_id);

        if (!$rule) {
            return false;
        }

        $amount = self::amount($base, $rule, 1);

        if ($amount <= 0) {
            return false;
        }

        $serviceName = DB::table('services')->where('id', $usage->service_id)->value('name') ?? 'Sesión';
        $branchId = $usage->appointment_id ? DB::table('appointments')->where('id', $usage->appointment_id)->value('branch_id') : ($sale->branch_id ?? null);

        self::insert($tenant, [
            'branch_id'        => $branchId,
            'professional_id'  => $usage->professional_id,
            'source'           => 'package_use',
            'sale_id'          => $sale?->id,
            'package_usage_id' => $usage->id,
            'kind'             => 'service',
            'description'      => mb_substr("{$serviceName} (sesión del paquete " . ($package->code ?? '') . ')', 0, 255),
            'quantity'         => 1,
            'base'             => $base,
            'percent'          => $rule['percent'],
            'fixed'            => $rule['fixed'],
            'rule_id'          => $rule['rule_id'],
            'rule_name'        => $rule['rule_name'],
            'amount'           => $amount,
            'currency'         => $package->currency ?? Currency::of($tenant),
            'period'           => self::period($tenant, $usage->used_at),
            'earned_at'        => $usage->used_at,
        ]);

        return true;
    }

    public static function forRefund(Tenant $tenant, ?object $refund): int
    {
        return $refund ? self::guarded(fn () => self::refundEntries($tenant, $refund), 0, 'devolución', $refund->id) : 0;
    }

    private static function refundEntries(Tenant $tenant, object $refund): int
    {
        if (!self::ready()) {
            return 0;
        }

        $sale = DB::table('sales')->where('id', $refund->sale_id)->first();

        if (!$sale || (float) $sale->total <= 0) {
            return 0;
        }

        $ratio = min(1, (float) $refund->amount / (float) $sale->total);
        $created = 0;

        foreach (DB::table('commission_entries')->where('sale_id', $sale->id)->where('source', 'sale')->get() as $entry) {
            $already = (float) DB::table('commission_entries')->where('source', 'refund')->where('reverses_id', $entry->id)->sum('amount');
            $amount = -round(min((float) $entry->amount * $ratio, (float) $entry->amount + $already), 2);

            if ($amount >= 0) {
                continue;
            }

            self::insert($tenant, [
                'branch_id'       => $entry->branch_id,
                'professional_id' => $entry->professional_id,
                'source'          => 'refund',
                'sale_id'         => $sale->id,
                'sale_item_id'    => $entry->sale_item_id,
                'refund_id'       => $refund->id,
                'reverses_id'     => $entry->id,
                'kind'            => $entry->kind,
                'description'     => mb_substr("Devolución: {$entry->description}", 0, 255),
                'quantity'        => $entry->quantity,
                'base'            => -round((float) $entry->base * $ratio, 2),
                'percent'         => $entry->percent,
                'fixed'           => $entry->fixed,
                'rule_id'         => $entry->rule_id,
                'rule_name'       => $entry->rule_name,
                'amount'          => $amount,
                'currency'        => $entry->currency,
                'period'          => self::period($tenant, $refund->created_at),
                'earned_at'       => $refund->created_at,
            ]);
            $created++;
        }

        return $created;
    }

    private static function guarded(callable $work, mixed $fallback, string $what, string $id): mixed
    {
        try {
            return DB::transaction($work);
        } catch (\Throwable $e) {
            Log::error("No se pudo calcular la comisión de la {$what}", ['id' => $id, 'error' => $e->getMessage()]);

            return $fallback;
        }
    }

    public static function recalculate(Tenant $tenant, string $period): array
    {
        $zone = $tenant->zone();
        $from = Carbon::createFromFormat('Y-m-d', "{$period}-01", $zone)->startOfDay();
        $to = $from->copy()->addMonth();
        $iso = fn (Carbon $d) => $d->copy()->utc()->toIso8601String();

        return DB::transaction(function () use ($tenant, $period, $from, $to, $iso) {
            $removed = DB::table('commission_entries')->where('tenant_id', $tenant->id)->where('period', $period)->where('status', 'calculated')
                ->whereNotIn('id', DB::table('commission_entries')->whereNotNull('reverses_id')
                    ->where(fn ($q) => $q->where('period', '!=', $period)->orWhere('status', '!=', 'calculated'))
                    ->select('reverses_id'))
                ->delete();
            $created = 0;

            $sales = DB::table('sales')->where('tenant_id', $tenant->id)
                ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
                ->whereRaw('coalesce(paid_at, created_at) >= ?', [$iso($from)])
                ->whereRaw('coalesce(paid_at, created_at) < ?', [$iso($to)])
                ->get();

            foreach ($sales as $sale) {
                $created += self::forSale($tenant, $sale);
            }

            $usages = DB::table('package_usages')->where('tenant_id', $tenant->id)
                ->where('used_at', '>=', $iso($from))->where('used_at', '<', $iso($to))->get();

            foreach ($usages as $usage) {
                $created += self::forUsage($tenant, $usage) ? 1 : 0;
            }

            $refunds = DB::table('sale_refunds')->where('tenant_id', $tenant->id)
                ->where('created_at', '>=', $iso($from))->where('created_at', '<', $iso($to))->get();

            foreach ($refunds as $refund) {
                $created += self::forRefund($tenant, $refund);
            }

            return ['removed' => $removed, 'created' => $created];
        });
    }

    public static function scoped(User $user)
    {
        $query = DB::table('commission_entries as e')->where('e.tenant_id', $user->tenant_id);
        $scope = Access::scopeOf($user);

        if ($scope === 'own') {
            $query->where('e.professional_id', DB::table('professionals')->where('tenant_id', $user->tenant_id)->where('user_id', $user->id)->value('id') ?? Access::NO_BRANCH);
        } elseif ($branch = Access::branchLimit($user)) {
            $query->where('e.branch_id', $branch);
        }

        return $query;
    }

    public static function byProfessional(string $tenantId, string $from, string $to, ?string $branchId = null, ?string $professionalId = null): Collection
    {
        if (!self::ready()) {
            return collect();
        }

        return DB::table('commission_entries')->where('tenant_id', $tenantId)
            ->where('earned_at', '>=', $from)->where('earned_at', '<', $to)
            ->when($branchId, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($professionalId, fn ($q, $id) => $q->where('professional_id', $id))
            ->groupBy('professional_id')
            ->selectRaw('professional_id, sum(amount) as total')
            ->pluck('total', 'professional_id')
            ->map(fn ($v) => round((float) $v, 2));
    }
}
