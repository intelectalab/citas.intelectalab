<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OnlineCash
{
    public const METHODS = ['link', 'deposit', 'gateway'];

    public const NAME = 'En línea';

    public static function payments(string $tenantId, string $currency, ?string $branchId, Carbon $from, Carbon $to)
    {
        return DB::table('payments as p')
            ->where('p.tenant_id', $tenantId)
            ->whereIn('p.method', self::METHODS)
            ->whereIn('p.status', ['confirmed', 'refunded'])
            ->where('p.currency', $currency)
            ->when($branchId, fn ($q, $id) => $q->where('p.branch_id', $id))
            ->whereRaw('coalesce(p.confirmed_at, p.created_at) >= ?', [$from->copy()->utc()->toIso8601String()])
            ->whereRaw('coalesce(p.confirmed_at, p.created_at) < ?', [$to->copy()->utc()->toIso8601String()]);
    }

    public static function totals($query): array
    {
        $row = (clone $query)
            ->selectRaw('coalesce(sum(p.amount) FILTER (WHERE p.amount > 0), 0) AS received, coalesce(sum(-p.amount) FILTER (WHERE p.amount < 0), 0) AS refunded')
            ->selectRaw('count(*) FILTER (WHERE p.amount > 0) AS count')
            ->first();

        $received = round((float) $row->received, 2);
        $refunded = round((float) $row->refunded, 2);

        return ['received' => $received, 'refunded' => $refunded, 'net' => round($received - $refunded, 2), 'count' => (int) $row->count];
    }

    public static function byBranch(Tenant $tenant, ?string $branchId, Carbon $from, Carbon $to): array
    {
        $query = self::payments($tenant->id, Currency::of($tenant), $branchId, $from, $to);
        $rows = (clone $query)
            ->groupBy('p.branch_id')
            ->selectRaw('p.branch_id, coalesce(sum(p.amount) FILTER (WHERE p.amount > 0), 0) AS received, coalesce(sum(-p.amount) FILTER (WHERE p.amount < 0), 0) AS refunded, count(*) FILTER (WHERE p.amount > 0) AS count')
            ->get()
            ->keyBy('branch_id');

        return $rows->map(fn ($row) => [
            'branch_id' => $row->branch_id,
            'received'  => round((float) $row->received, 2),
            'refunded'  => round((float) $row->refunded, 2),
            'net'       => round((float) $row->received - (float) $row->refunded, 2),
            'count'     => (int) $row->count,
        ])->all();
    }

    public static function day(Tenant $tenant, ?string $branchId, string $date): array
    {
        $zone = $tenant->zone();
        $from = Carbon::parse($date, $zone)->startOfDay();
        $to = $from->copy()->addDay();
        $query = self::payments($tenant->id, Currency::of($tenant), $branchId, $from, $to);

        $rows = (clone $query)
            ->leftJoin('sales as s', 's.id', '=', 'p.sale_id')
            ->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
            ->leftJoin('branches as b', 'b.id', '=', 'p.branch_id')
            ->orderByRaw('coalesce(p.confirmed_at, p.created_at) DESC')
            ->limit(500)
            ->get(['p.id', 'p.method', 'p.amount', 'p.provider', 'p.confirmed_at', 'p.created_at', 'p.sale_id', 's.code as sale_code', 'c.first_name', 'c.last_name', 'b.name as branch_name'])
            ->map(fn ($row) => [
                'id'           => $row->id,
                'at'           => Carbon::parse($row->confirmed_at ?? $row->created_at)->toIso8601String(),
                'method'       => $row->method,
                'method_label' => Sales::OTHER_METHODS[$row->method] ?? $row->method,
                'provider'     => $row->provider ? (PaymentLinks::PROVIDERS[$row->provider]['label'] ?? $row->provider) : null,
                'amount'       => round((float) $row->amount, 2),
                'sale_id'      => $row->sale_id,
                'sale_code'    => $row->sale_code,
                'customer'     => $row->first_name !== null ? Person::name($row) : null,
                'branch'       => $row->branch_name,
            ])->values()->all();

        return ['date' => $from->format('Y-m-d'), 'currency' => Currency::of($tenant), 'totals' => self::totals($query), 'payments' => $rows];
    }
}
