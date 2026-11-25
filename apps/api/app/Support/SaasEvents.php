<?php

namespace App\Support;

use App\Models\SaaSPlan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SaasEvents
{
    public const TYPES = [
        'trial'            => 'Empezó la prueba',
        'new'              => 'Empresa nueva que paga',
        'expansion'        => 'Subió de plan',
        'contraction'      => 'Bajó de plan o tiene descuento',
        'churn'            => 'Dejó de pagar o se dio de baja',
        'reactivation'     => 'Volvió a pagar',
        'payment'          => 'Pago del plan',
        'cancel_scheduled' => 'Pidió la baja',
        'cancel_resumed'   => 'Anuló la baja',
    ];

    public const MOVEMENTS = ['new', 'expansion', 'contraction', 'churn', 'reactivation'];

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('saas_events');
    }

    public static function forget(): void
    {
        self::$ready = null;
    }

    public static function mrrOf(?Subscription $subscription): float
    {
        if (!$subscription || !$subscription->plan) {
            return 0.0;
        }

        Subscriptions::forget($subscription->tenant_id);

        return Subscriptions::mrr($subscription);
    }

    public static function reconcile(): int
    {
        if (!self::ready()) {
            return 0;
        }

        $fixed = 0;

        foreach (Rls::bypass(fn () => DB::table('subscriptions')->distinct()->pluck('tenant_id')) as $tenantId) {
            $live = Rls::bypass(fn () => self::mrrOf(Subscriptions::latest($tenantId)));
            $booked = round((float) (self::booked($tenantId)->mrr_after ?? 0), 2);

            if (abs($live - $booked) >= 0.01) {
                self::record($tenantId, null, ['meta' => ['reason' => 'reconcile']]);
                $fixed++;
            }
        }

        return $fixed;
    }

    public static function booked(string $tenantId): ?object
    {
        return Rls::bypass(fn () => DB::table('saas_events')->where('tenant_id', $tenantId)->orderByDesc('occurred_at')->orderByDesc('seq')->first(['mrr_after', 'plan_id']));
    }

    public static function record(string $tenantId, ?string $hint = null, array $extra = []): array
    {
        if (!self::ready()) {
            return [];
        }

        try {
            return Rls::bypass(fn () => DB::transaction(function () use ($tenantId, $hint, $extra) {
                $subscription = Subscriptions::latest($tenantId);
                $last = self::booked($tenantId);
                $before = round((float) ($last->mrr_after ?? 0), 2);
                $after = self::mrrOf($subscription);
                $delta = round($after - $before, 2);
                $created = [];
                $base = [
                    'tenant_id'        => $tenantId,
                    'subscription_id'  => $subscription?->id,
                    'plan_id'          => $subscription?->plan_id,
                    'previous_plan_id' => $last->plan_id ?? null,
                    'currency'         => substr($subscription?->plan?->currency ?? 'PEN', 0, 3),
                    'meta'             => json_encode($extra['meta'] ?? (object) []),
                    'occurred_at'      => $extra['occurred_at'] ?? now(),
                ];

                if ($delta != 0) {
                    $type = match (true) {
                        $before <= 0 && $after > 0 => DB::table('saas_events')->where('tenant_id', $tenantId)->where('type', 'new')->exists() ? 'reactivation' : 'new',
                        $after <= 0                => 'churn',
                        $after > $before           => 'expansion',
                        default                    => 'contraction',
                    };

                    $created[] = self::insert($base + [
                        'type'       => $type,
                        'mrr_before' => $before,
                        'mrr_after'  => $after,
                        'mrr_delta'  => $delta,
                        'amount'     => null,
                    ]);
                }

                if (in_array($hint, ['trial', 'payment', 'cancel_scheduled', 'cancel_resumed'], true)) {
                    $created[] = self::insert($base + [
                        'type'       => $hint,
                        'mrr_before' => $after,
                        'mrr_after'  => $after,
                        'mrr_delta'  => 0,
                        'amount'     => $extra['amount'] ?? null,
                    ]);
                }

                return $created;
            }));
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    public static function payment(Subscription $subscription, SubscriptionInvoice $invoice): array
    {
        return self::record($subscription->tenant_id, 'payment', [
            'amount' => round((float) $invoice->amount, 2),
            'meta'   => ['invoice_id' => $invoice->id, 'reason' => $invoice->billing_reason],
        ]);
    }

    private static function insert(array $row): string
    {
        $id = (string) Str::uuid();
        DB::table('saas_events')->insert($row + ['id' => $id, 'created_at' => now()]);

        return $id;
    }

    public static function bookedMrr(?Carbon $at = null): float
    {
        if (!self::ready()) {
            return 0.0;
        }

        $at ??= now();

        return round((float) Rls::bypass(fn () => DB::selectOne('SELECT coalesce(sum(mrr_after), 0) AS total FROM (SELECT DISTINCT ON (tenant_id) tenant_id, mrr_after FROM saas_events WHERE occurred_at <= ? ORDER BY tenant_id, occurred_at DESC, seq DESC) AS last', [$at])->total), 2);
    }

    public static function trialConversion(int $days = 90): array
    {
        return Rls::bypass(function () use ($days) {
            $trials = DB::table('saas_events')->where('type', 'trial')->where('occurred_at', '>=', now()->subDays($days))->distinct()->pluck('tenant_id');
            $converted = $trials->isEmpty() ? 0 : DB::table('saas_events')->where('type', 'new')->whereIn('tenant_id', $trials)->distinct()->count('tenant_id');

            return ['trials' => $trials->count(), 'converted' => $converted, 'rate' => $trials->count() ? round($converted * 100 / $trials->count(), 1) : null];
        });
    }

    public static function movements(Carbon $from, Carbon $to): array
    {
        $empty = array_fill_keys(self::MOVEMENTS, 0.0);

        if (!self::ready()) {
            return ['by_type' => $empty, 'counts' => array_fill_keys(self::MOVEMENTS, 0), 'net' => 0.0, 'start' => 0.0, 'end' => 0.0, 'balanced' => true, 'nrr' => null, 'trials' => ['trials' => 0, 'converted' => 0, 'rate' => null], 'rows' => []];
        }

        $rows = Rls::bypass(fn () => DB::table('saas_events as e')
            ->join('tenants as t', 't.id', '=', 'e.tenant_id')
            ->leftJoin('saas_plans as p', 'p.id', '=', 'e.plan_id')
            ->leftJoin('saas_plans as pp', 'pp.id', '=', 'e.previous_plan_id')
            ->where('e.occurred_at', '>', $from)->where('e.occurred_at', '<=', $to)
            ->orderByDesc('e.occurred_at')->orderByDesc('e.seq')
            ->get(['e.id', 'e.type', 'e.mrr_before', 'e.mrr_after', 'e.mrr_delta', 'e.amount', 'e.currency', 'e.occurred_at', 't.id as tenant_id', 't.name as tenant', 'p.name as plan', 'pp.name as previous_plan']));

        $byType = $empty;
        $counts = array_fill_keys(self::MOVEMENTS, 0);

        foreach ($rows as $row) {
            if (isset($byType[$row->type])) {
                $byType[$row->type] = round($byType[$row->type] + (float) $row->mrr_delta, 2);
                $counts[$row->type]++;
            }
        }

        $start = self::bookedMrr($from);
        $end = self::bookedMrr($to);
        $net = round(array_sum($byType), 2);
        $nrr = $start > 0 ? round(($start + $byType['expansion'] + $byType['contraction'] + $byType['churn']) / $start * 100, 1) : null;

        return [
            'by_type'  => $byType,
            'counts'   => $counts,
            'net'      => $net,
            'start'    => $start,
            'end'      => $end,
            'balanced' => abs(round($start + $net - $end, 2)) < 0.01,
            'nrr'      => $nrr,
            'trials'   => self::trialConversion(),
            'rows'     => $rows->take(200)->map(fn ($row) => [
                'id'            => $row->id,
                'type'          => $row->type,
                'label'         => self::TYPES[$row->type] ?? $row->type,
                'tenant_id'     => $row->tenant_id,
                'tenant'        => $row->tenant,
                'plan'          => $row->plan,
                'previous_plan' => $row->previous_plan,
                'mrr_delta'     => round((float) $row->mrr_delta, 2),
                'mrr_after'     => round((float) $row->mrr_after, 2),
                'amount'        => $row->amount !== null ? round((float) $row->amount, 2) : null,
                'currency'      => $row->currency,
                'at'            => Carbon::parse($row->occurred_at)->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
