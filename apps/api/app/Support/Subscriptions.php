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

    public const CARD_NEEDED = 'Para empezar tu prueba gratis falta autorizar tu tarjeta en Mercado Pago. No se cobra nada hasta que termine la prueba. El administrador lo hace en Mi empresa → Plan.';

    public const PORTAL_LOCKED = 'La reserva en línea no está disponible por ahora. Comunícate con la empresa.';

    public const LOCKED_STATES = ['locked', 'card_needed', 'none'];

    private static array $states = [];

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('plan_payments') && Schema::hasColumn('subscriptions', 'requires_card');
    }

    public static function graceDays(): int
    {
        return max(0, (int) (PlatformSetting::value('grace_days') ?? 7));
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
        $graceEnd = $end?->copy()->addDays(self::graceDays());
        $base = ['ends_at' => $end, 'grace_ends_at' => $graceEnd];

        if ($subscription->status === 'canceled') {
            return ['state' => 'locked', 'reason' => 'canceled'] + $base;
        }

        if (self::price($subscription->plan, $subscription->billing_cycle) <= 0 && $subscription->status === 'active') {
            return ['state' => 'active', 'free' => true] + $base;
        }

        if (self::ready() && $subscription->status === 'trialing' && $subscription->requires_card
            && $subscription->card_status !== 'authorized' && PlanBilling::enabled()) {
            return ['state' => 'card_needed'] + $base;
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
        return self::state($tenant)['state'] === 'card_needed' ? self::CARD_NEEDED : self::LOCKED;
    }

    public static function recordPayment(Subscription $subscription, array $values): SubscriptionInvoice
    {
        $plan = SaaSPlan::findOrFail($values['plan_id'] ?? $subscription->plan_id);
        $cycle = $values['billing_cycle'] ?? $subscription->billing_cycle;
        $paidAt = $values['paid_at'] ?? now();
        $wasTrial = $subscription->status === 'trialing';
        $base = self::endOf($subscription);
        $current = in_array($subscription->status, ['active', 'trialing'], true) && $base && $base->gt(now()->subDays(self::graceDays()));
        $periodStart = $current ? $base->copy() : Carbon::parse($paidAt);
        $periodEnd = $cycle === 'yearly' ? $periodStart->copy()->addYear() : $periodStart->copy()->addMonth();

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
                'amount'              => $values['amount'] ?? self::price($plan, $cycle),
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

                if ($subscription->pending_plan_id === $plan->id) {
                    $update += ['pending_plan_id' => null, 'pending_billing_cycle' => null];
                }
            }

            $subscription->update($update);

            return $invoice;
        });

        self::forget($subscription->tenant_id);

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

        return [
            'state'         => $state['state'],
            'free'          => (bool) ($state['free'] ?? false),
            'ends_at'       => $day($state['ends_at']),
            'grace_ends_at' => $day($state['grace_ends_at']),
            'grace_days'    => self::graceDays(),
            'message'       => in_array($state['state'], self::LOCKED_STATES, true) ? self::lockMessage($tenant) : null,
            'can_pay'       => (bool) ($user?->isTenantAdmin()),
            'online'        => PlanBilling::enabled(),
            'simulated'     => PlanBilling::simulated(),
            'plan'          => $plan ? ['id' => $plan->id, 'name' => $plan->name, 'currency' => $plan->currency ?? 'PEN'] : null,
            'billing_cycle' => $subscription?->billing_cycle,
            'price'         => $subscription ? self::price($plan, $subscription->billing_cycle) : 0,
            'pending_plan'  => $pending ? ['id' => $pending->id, 'name' => $pending->name, 'billing_cycle' => $subscription->pending_billing_cycle, 'price' => self::price($pending, $subscription->pending_billing_cycle ?? 'monthly')] : null,
            'card'          => $subscription && self::ready() ? [
                'required'      => (bool) $subscription->requires_card,
                'status'        => $subscription->card_status,
                'brand'         => $subscription->card_brand,
                'last4'         => $subscription->card_last4,
                'authorized_at' => $day($subscription->card_authorized_at),
                'failed_at'     => $day($subscription->last_charge_failed_at),
            ] : null,
        ];
    }
}
