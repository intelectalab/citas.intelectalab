<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Currency
{
    public const DEFAULT = 'PEN';

    public const NOT_READY = 'Falta actualizar la base de datos: ejecuta php artisan migrate.';

    private static ?bool $ready = null;

    private static ?Collection $all = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('currencies') && Schema::hasColumn('sales', 'currency') && Schema::hasTable('currency_changes');
    }

    public static function all(): Collection
    {
        if (!self::ready()) {
            return collect([(object) ['code' => self::DEFAULT, 'name' => 'Sol peruano', 'symbol' => 'S/', 'decimals' => 2, 'is_active' => true]]);
        }

        return self::$all ??= DB::table('currencies')->orderBy('sort_order')->orderBy('code')->get();
    }

    public static function forget(): void
    {
        self::$all = null;
    }

    public static function find(?string $code): ?object
    {
        return self::all()->firstWhere('code', $code);
    }

    public static function present(?string $code): array
    {
        $currency = self::find($code) ?? self::find(self::DEFAULT) ?? self::all()->first();

        return [
            'code'     => $currency->code,
            'name'     => $currency->name,
            'symbol'   => $currency->symbol,
            'decimals' => (int) $currency->decimals,
        ];
    }

    public static function catalog(): array
    {
        return self::all()->map(fn ($c) => self::present($c->code) + ['is_active' => (bool) $c->is_active])->values()->all();
    }

    public static function of(Tenant $tenant): string
    {
        $code = (string) ($tenant->rules()['currency.code'] ?? self::DEFAULT);

        return self::find($code) ? $code : self::DEFAULT;
    }

    public static function column(Tenant|string $currency): array
    {
        if (!self::ready()) {
            return [];
        }

        return ['currency' => $currency instanceof Tenant ? self::of($currency) : $currency];
    }

    public static function format(float $amount, ?string $code): string
    {
        $currency = self::present($code);

        return $currency['symbol'] . ' ' . number_format($amount, $currency['decimals']);
    }

    public static function blockers(Tenant $tenant): array
    {
        $open = DB::table('cash_sessions as s')
            ->join('cash_registers as r', 'r.id', '=', 's.cash_register_id')
            ->join('branches as b', 'b.id', '=', 's.branch_id')
            ->where('s.tenant_id', $tenant->id)->where('s.status', 'open')
            ->get(['r.name as register', 'b.name as branch']);
        $pending = DB::table('sales')->where('tenant_id', $tenant->id)->whereIn('status', ['pending', 'partial'])->where('balance', '>', 0)->count();

        $reasons = [];

        if ($open->isNotEmpty()) {
            $names = $open->take(3)->map(fn ($s) => "{$s->register} de {$s->branch}")->implode(', ');
            $reasons[] = ($open->count() === 1 ? "La {$names} está abierta: ciérrala antes" : "Hay {$open->count()} cajas abiertas ({$names}): ciérralas antes")
                . ', para que cada turno quede en una sola moneda.';
        }

        if ($pending > 0) {
            $reasons[] = ($pending === 1 ? 'Hay una venta a medio cobrar' : "Hay {$pending} ventas a medio cobrar")
                . ': termina de cobrarlas o anúlalas antes, para que cada venta quede en una sola moneda.';
        }

        return $reasons;
    }

    public static function setTenantCurrency(Tenant $tenant, string $code, ?User $user): void
    {
        $from = self::of($tenant);

        if ($from === $code) {
            return;
        }

        DB::transaction(function () use ($tenant, $code, $from, $user) {
            $setting = TenantSetting::firstOrCreate(['tenant_id' => $tenant->id]);
            $setting->rules = array_merge($setting->rules ?? [], ['currency.code' => $code]);
            $setting->save();

            DB::table('currency_changes')->insert([
                'id'            => (string) Str::uuid(),
                'tenant_id'     => $tenant->id,
                'kind'          => 'setting',
                'from_currency' => $from,
                'to_currency'   => $code,
                'created_by'    => $user?->id,
                'created_at'    => now(),
            ]);
        });

        $tenant->unsetRelation('settings');
    }

    public static function recordsByCurrency(Tenant $tenant): array
    {
        $count = fn (string $table) => DB::table($table)->where('tenant_id', $tenant->id)->groupBy('currency')
            ->selectRaw('currency, count(*) as total')->pluck('total', 'currency')->map(fn ($n) => (int) $n);
        $sales = $count('sales');
        $appointments = $count('appointments');

        return $sales->keys()->merge($appointments->keys())->unique()->values()
            ->map(fn ($code) => self::present($code) + ['sales' => $sales[$code] ?? 0, 'appointments' => $appointments[$code] ?? 0])
            ->all();
    }

    private static function range(Tenant $tenant, ?string $startsOn, ?string $endsOn): array
    {
        $timezone = $tenant->zone();

        return [
            $startsOn ? Carbon::parse($startsOn, $timezone)->startOfDay()->toIso8601String() : null,
            $endsOn ? Carbon::parse($endsOn, $timezone)->addDay()->startOfDay()->toIso8601String() : null,
        ];
    }

    public static function scope(Tenant $tenant, string $from, ?string $startsOn, ?string $endsOn): array
    {
        [$start, $end] = self::range($tenant, $startsOn, $endsOn);
        $between = fn ($query, string $column) => $query
            ->when($start, fn ($q) => $q->where($column, '>=', $start))
            ->when($end, fn ($q) => $q->where($column, '<', $end));

        $withInvoice = DB::table('invoices')->where('tenant_id', $tenant->id)->where('status', 'issued')->whereNotNull('sale_id')->distinct()->pluck('sale_id')->all();

        $inRange = $between(DB::table('sales')->where('tenant_id', $tenant->id)->where('currency', $from), 'created_at')->pluck('id')->all();
        $excluded = array_values(array_intersect($inRange, $withInvoice));
        $sales = array_values(array_diff($inRange, $withInvoice));

        $lockedAppointments = DB::table('sale_items')->whereIn('sale_id', $withInvoice)->whereNotNull('appointment_id')->pluck('appointment_id')->all();
        $appointments = $between(DB::table('appointments')->where('tenant_id', $tenant->id)->where('currency', $from), 'starts_at')
            ->whereNotIn('id', $lockedAppointments)->pluck('id')->all();

        $payments = DB::table('payments')->whereIn('sale_id', $sales)->pluck('id')->all();

        $sessions = $between(DB::table('cash_sessions')->where('tenant_id', $tenant->id)->where('currency', $from), 'opened_at')
            ->whereNotExists(fn ($q) => $q->from('payments as p')->whereColumn('p.cash_session_id', 'cash_sessions.id')
                ->where(fn ($w) => $w->whereIn('p.sale_id', $withInvoice)->orWhere('p.currency', '!=', $from)))
            ->pluck('id')->all();

        return compact('sales', 'excluded', 'appointments', 'payments', 'sessions');
    }

    public static function counts(array $scope): array
    {
        return [
            'sales'        => count($scope['sales']),
            'payments'     => count($scope['payments']),
            'appointments' => count($scope['appointments']),
            'sessions'     => count($scope['sessions']),
            'excluded'     => count($scope['excluded']),
        ];
    }

    public static function apply(Tenant $tenant, string $kind, string $from, string $to, ?float $factor, ?string $startsOn, ?string $endsOn, User $user): array
    {
        return DB::transaction(function () use ($tenant, $kind, $from, $to, $factor, $startsOn, $endsOn, $user) {
            $scope = self::scope($tenant, $from, $startsOn, $endsOn);
            $decimals = (int) self::present($to)['decimals'];
            $convert = $kind === 'convert';
            $factor = sprintf('%.8F', (float) $factor);
            $scaled = fn (array $columns) => collect($columns)
                ->mapWithKeys(fn ($column) => [$column => DB::raw("round(coalesce({$column}, 0) * {$factor}, {$decimals})")])
                ->all();
            $nullable = fn (array $columns) => collect($columns)
                ->mapWithKeys(fn ($column) => [$column => DB::raw("case when {$column} is null then null else round({$column} * {$factor}, {$decimals}) end")])
                ->all();

            if ($scope['sales']) {
                DB::table('sales')->whereIn('id', $scope['sales'])->update(['currency' => $to, 'updated_at' => now()]
                    + ($convert ? $scaled(['subtotal', 'discount', 'tax', 'tip', 'total', 'paid']) + ['converted_from' => $from, 'exchange_rate' => (float) $factor] : []));

                if ($convert) {
                    DB::table('sales')->whereIn('id', $scope['sales'])->update(['balance' => DB::raw('greatest(total - paid, 0)')]);
                    DB::table('sales')->whereIn('id', $scope['sales'])->where('status', 'paid')->update(['paid' => DB::raw('total'), 'balance' => 0]);
                    DB::table('sale_items')->whereIn('sale_id', $scope['sales'])->update($scaled(['unit_price', 'discount', 'total']));
                    DB::table('sale_refunds')->whereIn('sale_id', $scope['sales'])->update($scaled(['amount']));
                }
            }

            if ($scope['payments']) {
                DB::table('payments')->whereIn('id', $scope['payments'])->update(['currency' => $to] + ($convert ? $scaled(['amount', 'change_amount']) : []));
            }

            if ($scope['appointments']) {
                DB::table('appointments')->whereIn('id', $scope['appointments'])->update(['currency' => $to] + ($convert ? $scaled(['price']) : []));
            }

            if ($scope['sessions']) {
                DB::table('cash_sessions')->whereIn('id', $scope['sessions'])->update(['currency' => $to]
                    + ($convert ? $scaled(['opening_amount']) + $nullable(['expected_amount', 'closing_amount', 'difference']) : []));

                if ($convert) {
                    DB::table('cash_movements')->whereIn('cash_session_id', $scope['sessions'])->update($scaled(['amount']));
                }
            }

            $counts = self::counts($scope);

            DB::table('currency_changes')->insert([
                'id'            => (string) Str::uuid(),
                'tenant_id'     => $tenant->id,
                'kind'          => $convert ? 'convert' : 'relabel',
                'from_currency' => $from,
                'to_currency'   => $to,
                'exchange_rate' => $convert ? (float) $factor : null,
                'starts_on'     => $startsOn,
                'ends_on'       => $endsOn,
                'counts'        => json_encode($counts),
                'excluded'      => $counts['excluded'],
                'created_by'    => $user->id,
                'created_at'    => now(),
            ]);

            return $counts;
        });
    }

    public static function history(Tenant $tenant, int $limit = 6): array
    {
        return DB::table('currency_changes as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.created_by')
            ->where('c.tenant_id', $tenant->id)
            ->orderByDesc('c.created_at')
            ->limit($limit)
            ->get(['c.*', 'u.first_name', 'u.last_name'])
            ->map(fn ($c) => [
                'id'            => $c->id,
                'kind'          => $c->kind,
                'from'          => self::present($c->from_currency),
                'to'            => self::present($c->to_currency),
                'exchange_rate' => $c->exchange_rate !== null ? (float) $c->exchange_rate : null,
                'starts_on'     => $c->starts_on,
                'ends_on'       => $c->ends_on,
                'counts'        => json_decode($c->counts, true) ?: [],
                'excluded'      => (int) $c->excluded,
                'by'            => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) ?: null,
                'created_at'    => Carbon::parse($c->created_at)->toIso8601String(),
            ])->all();
    }
}
