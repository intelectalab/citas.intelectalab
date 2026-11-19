<?php

namespace App\Support;

use App\Models\PlatformSetting;
use App\Models\SaaSPlan;
use App\Models\Status;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Subscriptions
{
    public const LOCKED = 'Tu empresa está en solo lectura porque el plan no está pagado: puedes ver y exportar tus datos, pero no agendar, vender ni cambiar nada. El administrador puede pagarlo en Mi empresa → Plan.';

    public const CANCELED = 'Tu plan está cancelado. Tus datos se conservan: puedes verlos y exportarlos, y si el administrador paga el plan en Mi empresa → Plan, tu empresa vuelve a tener todo.';

    public const PORTAL_LOCKED = 'La reserva en línea no está disponible por ahora. Comunícate con la empresa.';

    public const LOCKED_STATES = ['locked', 'none'];

    private static array $states = [];

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('plan_payments') && Schema::hasColumn('subscriptions', 'requires_card');
    }

    public static function graceDays(): int
    {
        return Dunning::config()['readonly_day'];
    }

    public static function graceFor(Subscription $subscription): int
    {
        return self::graceDays() + Dunning::extraDays($subscription);
    }

    public static function graceSql(string $column = 'ends_at'): string
    {
        $extra = Dunning::ready() ? ' + coalesce(grace_extra_days, 0)' : '';

        return "{$column} + make_interval(days => " . self::graceDays() . "{$extra}) > now()";
    }

    public static function periodFor(Subscription $subscription, string $cycle, ?Carbon $paidAt = null): array
    {
        $paidAt ??= now();
        $base = self::endOf($subscription);
        $current = in_array($subscription->status, ['active', 'trialing'], true) && $base && $base->gt($paidAt->copy()->subDays(self::graceFor($subscription)));
        $start = $current ? $base->copy() : $paidAt->copy();

        return [$start, $cycle === 'yearly' ? $start->copy()->addYear() : $start->copy()->addMonth()];
    }

    public static function latest(string $tenantId): ?Subscription
    {
        return Subscription::with('plan')->where('tenant_id', $tenantId)->latest('starts_at')->first();
    }

    public static function price(?SaaSPlan $plan, string $cycle): float
    {
        return $plan ? round((float) ($cycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly), 2) : 0.0;
    }

    public static function endOf(Subscription $subscription): ?Carbon
    {
        $end = $subscription->status === 'trialing' ? ($subscription->trial_ends_at ?? $subscription->ends_at) : $subscription->ends_at;

        return $end ? Carbon::parse($end) : null;
    }

    public static function forget(?string $tenantId = null): void
    {
        $tenantId ? (self::$states[$tenantId] = null) : (self::$states = []);
    }

    public static function state(Tenant|string $tenant): array
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;

        return self::$states[$tenantId] ??= self::compute($tenantId);
    }

    private static function compute(string $tenantId): array
    {
        $subscription = self::latest($tenantId);

        if (!$subscription) {
            return ['state' => 'none', 'ends_at' => null, 'grace_ends_at' => null];
        }

        $end = self::endOf($subscription);
        $graceEnd = $end?->copy()->addDays(self::graceFor($subscription));
        $base = ['ends_at' => $end, 'grace_ends_at' => $graceEnd];

        if ($subscription->status === 'canceled') {
            return ['state' => 'locked', 'reason' => 'canceled'] + $base;
        }

        if (self::price($subscription->plan, $subscription->billing_cycle) <= 0 && $subscription->status === 'active') {
            return ['state' => 'active', 'free' => true] + $base;
        }

        if (!$end || $end->isFuture()) {
            return ['state' => $subscription->status === 'trialing' ? 'trial' : 'active'] + $base;
        }

        return ['state' => $graceEnd->isFuture() ? 'grace' : 'locked'] + $base;
    }

    public static function lockedFor(Tenant|string $tenant): bool
    {
        return in_array(self::state($tenant)['state'], self::LOCKED_STATES, true);
    }

    public static function lockMessage(Tenant|string $tenant): string
    {
        return (self::state($tenant)['reason'] ?? null) === 'canceled' ? self::CANCELED : self::LOCKED;
    }

    public static function recordPayment(Subscription $subscription, array $values): SubscriptionInvoice
    {
        $plan = SaaSPlan::findOrFail($values['plan_id'] ?? $subscription->plan_id);
        $cycle = $values['billing_cycle'] ?? $subscription->billing_cycle;
        $paidAt = $values['paid_at'] ?? now();
        $wasTrial = $subscription->status === 'trialing';
        [$periodStart, $periodEnd] = self::periodFor($subscription, $cycle, Carbon::parse($paidAt));

        $paidStatusId = Status::whereHas('module', fn ($q) => $q->where('name', 'payments'))
            ->whereNull('tenant_id')
            ->where('slug', 'paid')
            ->value('id');

        abort_unless($paidStatusId, 500, 'Falta el estado "Pagado" del módulo de pagos.');

        $invoice = DB::transaction(function () use ($subscription, $plan, $cycle, $values, $paidAt, $periodStart, $periodEnd, $paidStatusId, $wasTrial) {
            $invoice = SubscriptionInvoice::create([
                'subscription_id'     => $subscription->id,
                'tenant_id'           => $subscription->tenant_id,
                'status_id'           => $paidStatusId,
                'amount'              => $values['amount'] ?? Cancellations::chargeFor($subscription, $plan, $cycle),
                'currency'            => substr($plan->currency ?? 'PEN', 0, 3),
                'billing_reason'      => $values['reason'] ?? ($wasTrial ? 'subscription_create' : 'manual'),
                'invoice_date'        => $paidAt,
                'paid_at'             => $paidAt,
                'period_start'        => $periodStart,
                'period_end'          => $periodEnd,
                'external_invoice_id' => $values['reference'] ?? null,
                'notes'               => $values['notes'] ?? null,
            ]);

            $update = [
                'plan_id'       => $plan->id,
                'status'        => 'active',
                'billing_cycle' => $cycle,
                'starts_at'     => $subscription->status === 'active' ? $subscription->starts_at : $periodStart,
                'ends_at'       => $periodEnd,
                'trial_ends_at' => null,
                'canceled_at'   => null,
            ];

            if (self::ready()) {
                $update += ['last_charge_failed_at' => null];

                if (Dunning::ready()) {
                    $update += ['grace_extra_days' => 0];
                }

                if ($subscription->pending_plan_id === $plan->id) {
                    $update += ['pending_plan_id' => null, 'pending_billing_cycle' => null];
                }
            }

            if (Cancellations::ready()) {
                $update += ['cancel_at' => null] + Cancellations::usedDiscount($subscription, $cycle);
                DB::table('saas_cancellations')->where('subscription_id', $subscription->id)->where('status', 'scheduled')
                    ->update(['status' => 'resumed', 'resumed_at' => now(), 'updated_at' => now()]);
            }

            PlanCoupons::consume($subscription, $plan, $cycle);
            $subscription->update($update);

            return $invoice;
        });

        self::forget($subscription->tenant_id);
        Referrals::onPaid($subscription, $invoice);
        WebLeads::onPaid($subscription);

        return $invoice;
    }

    public static function present(Tenant $tenant, ?User $user = null): array
    {
        $subscription = self::latest($tenant->id);
        $state = self::state($tenant);
        $plan = $subscription?->plan;
        $zone = $tenant->zone();
        $day = fn ($value) => $value ? Carbon::parse($value)->setTimezone($zone)->format('Y-m-d') : null;
        $pending = $subscription && self::ready() && $subscription->pending_plan_id ? SaaSPlan::find($subscription->pending_plan_id) : null;
        $overdue = $subscription && in_array($state['state'], ['grace', 'locked'], true) && ($state['reason'] ?? null) !== 'canceled';
        $lastFailure = $overdue ? DB::table('plan_payments')->where('subscription_id', $subscription->id)->where('kind', 'charge')->where('status', 'failed')->orderByDesc('created_at')->value('message') : null;

        return [
            'state'         => $state['state'],
            'reason'        => $state['reason'] ?? null,
            'free'          => (bool) ($state['free'] ?? false),
            'ends_at'       => $day($state['ends_at']),
            'grace_ends_at' => $day($state['grace_ends_at']),
            'grace_days'    => self::graceDays(),
            'trial_days_left' => $state['state'] === 'trial' && $state['ends_at'] ? max(0, (int) ceil(now()->diffInSeconds($state['ends_at'], false) / 86400)) : null,
            'overdue'       => $overdue ? [
                'day'          => Dunning::dayOf($subscription),
                'readonly_on'  => $day(Dunning::readOnlyOn($subscription)),
                'cancel_on'    => $day(Dunning::cancelOn($subscription)),
                'last_failure' => $lastFailure,
            ] : null,
            'message'       => in_array($state['state'], self::LOCKED_STATES, true) ? self::lockMessage($tenant) : null,
            'can_pay'       => (bool) ($user?->isTenantAdmin()),
            'online'        => PlanBilling::enabled(),
            'provider'      => PlanBilling::active() ?? (PlanBilling::simulated() ? 'simulated' : null),
            'methods'       => [
                'card'     => PlanBilling::cardEnabled(),
                'transfer' => PlanTransfers::enabled(),
                'transfer_cycles' => PlanTransfers::CYCLES,
            ],
            'review'        => $subscription && ($pendingTransfer = PlanTransfers::pendingFor($subscription->id)) ? PlanTransfers::present($pendingTransfer) : null,
            'rejected'      => $subscription && ($rejectedTransfer = PlanTransfers::lastRejected($subscription->id)) ? PlanTransfers::present($rejectedTransfer) : null,
            'simulated'     => PlanBilling::simulated(),
            'cancellation'  => $subscription && $user?->isTenantAdmin() ? Cancellations::present($subscription) : null,
            'charge'        => $subscription ? Cancellations::chargeFor($subscription, $plan, $subscription->billing_cycle) : 0,
            'coupon'        => PlanCoupons::present($subscription),
            'coupons'       => PlanCoupons::ready(),
            'plan'          => $plan ? ['id' => $plan->id, 'name' => $plan->name, 'currency' => $plan->currency ?? 'PEN'] : null,
            'billing_cycle' => $subscription?->billing_cycle,
            'price'         => $subscription ? self::price($plan, $subscription->billing_cycle) : 0,
            'next_period'   => $subscription ? array_map($day, self::periodFor($subscription, $subscription->pending_billing_cycle ?? $subscription->billing_cycle)) : null,
            'pending_plan'  => $pending ? ['id' => $pending->id, 'name' => $pending->name, 'billing_cycle' => $subscription->pending_billing_cycle, 'price' => self::price($pending, $subscription->pending_billing_cycle ?? 'monthly')] : null,
            'card'          => $subscription && self::ready() ? [
                'status'        => $subscription->card_status,
                'saved'         => PlanBilling::savedCard($subscription),
                'provider'      => $subscription->external_provider,
                'brand'         => $subscription->card_brand,
                'last4'         => $subscription->card_last4,
                'authorized_at' => $day($subscription->card_authorized_at),
                'failed_at'     => $day($subscription->last_charge_failed_at),
            ] : null,
        ];
    }
}
