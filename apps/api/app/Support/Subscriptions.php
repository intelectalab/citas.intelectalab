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

    public const NO_PERMISSION = 'Para ver los pagos, pagar o cambiar el plan necesitas el permiso «Plan y pagos de la empresa». Pídeselo al dueño de tu empresa en Usuarios y roles.';

    private static array $states = [];

    public static function manages(?User $user): bool
    {
        return (bool) ($user && $user->tenant_id && !$user->isCustomer() && Access::allows($user, 'admin.plan'));
    }

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

    public static function charge(?Subscription $subscription, ?SaaSPlan $plan = null, ?string $cycle = null): float
    {
        $plan ??= $subscription?->plan;

        return $subscription && $plan ? Cancellations::chargeFor($subscription, $plan, $cycle ?? ($subscription->billing_cycle ?: 'monthly')) : 0.0;
    }

    public static function mrr(?Subscription $subscription, ?array $state = null): float
    {
        if (!$subscription || !$subscription->plan || $subscription->status === 'canceled') {
            return 0.0;
        }

        $state ??= self::state($subscription->tenant_id);

        if (!in_array($state['state'], ['active', 'grace'], true)) {
            return 0.0;
        }

        $charge = self::charge($subscription);

        return round(($subscription->billing_cycle ?: 'monthly') === 'yearly' ? $charge / 12 : $charge, 2);
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
            ] + self::listAmount($subscription, $plan, $cycle));

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
        self::afterPaid($subscription, $invoice);

        return $invoice;
    }

    public static function afterPaid(Subscription $subscription, SubscriptionInvoice $invoice): void
    {
        SaasEvents::payment($subscription, $invoice);

        try {
            PlanInvoices::issue($invoice);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function listAmount(Subscription $subscription, SaaSPlan $plan, string $cycle): array
    {
        static $ready = null;
        $ready ??= Schema::hasColumn('subscription_invoices', 'list_amount');

        return $ready ? ['list_amount' => round(self::price($plan, $cycle) + (self::price($plan, $cycle) > 0 ? Addons::total($subscription->tenant_id, $cycle) : 0), 2)] : [];
    }

    public static function recordYearlyNow(Subscription $subscription, SaaSPlan $plan, float $amount, array $values = []): SubscriptionInvoice
    {
        $paidStatusId = Status::whereHas('module', fn ($q) => $q->where('name', 'payments'))->whereNull('tenant_id')->where('slug', 'paid')->value('id');
        abort_unless($paidStatusId, 500, 'Falta el estado "Pagado" del módulo de pagos.');
        $previous = $subscription->plan;

        $invoice = DB::transaction(function () use ($subscription, $plan, $amount, $values, $paidStatusId, $previous) {
            $invoice = SubscriptionInvoice::create([
                'subscription_id'     => $subscription->id,
                'tenant_id'           => $subscription->tenant_id,
                'status_id'           => $paidStatusId,
                'amount'              => $amount,
                'currency'            => substr($plan->currency ?? 'PEN', 0, 3),
                'billing_reason'      => 'cycle_change',
                'invoice_date'        => now(),
                'paid_at'             => now(),
                'period_start'        => now(),
                'period_end'          => now()->addYear(),
                'external_invoice_id' => $values['reference'] ?? null,
                'notes'               => mb_substr('Paso a pago anual' . ($previous && $previous->id !== $plan->id ? ' (de ' . $previous->name . " a {$plan->name})" : " del plan {$plan->name}") . ', con descuento de los días que quedaban del mes', 0, 255),
            ] + self::listAmount($subscription, $plan, 'yearly'));

            PlanCoupons::consume($subscription, $plan, 'yearly');
            $subscription->update(['plan_id' => $plan->id, 'billing_cycle' => 'yearly', 'status' => 'active', 'ends_at' => now()->addYear()] + (self::ready() ? ['pending_plan_id' => null, 'pending_billing_cycle' => null, 'last_charge_failed_at' => null] : []));

            return $invoice;
        });

        self::forget($subscription->tenant_id);
        self::afterPaid($subscription->fresh('plan'), $invoice);

        return $invoice;
    }

    public static function recordUpgrade(Subscription $subscription, SaaSPlan $plan, string $cycle, float $amount, array $values = []): SubscriptionInvoice
    {
        $paidStatusId = Status::whereHas('module', fn ($q) => $q->where('name', 'payments'))->whereNull('tenant_id')->where('slug', 'paid')->value('id');
        abort_unless($paidStatusId, 500, 'Falta el estado "Pagado" del módulo de pagos.');
        $previous = $subscription->plan;

        $invoice = DB::transaction(function () use ($subscription, $plan, $cycle, $amount, $values, $paidStatusId, $previous) {
            $invoice = SubscriptionInvoice::create([
                'subscription_id'     => $subscription->id,
                'tenant_id'           => $subscription->tenant_id,
                'status_id'           => $paidStatusId,
                'amount'              => $amount,
                'currency'            => substr($plan->currency ?? 'PEN', 0, 3),
                'billing_reason'      => 'subscription_upgrade',
                'invoice_date'        => now(),
                'paid_at'             => now(),
                'period_start'        => now(),
                'period_end'          => $subscription->ends_at,
                'external_invoice_id' => $values['reference'] ?? null,
                'notes'               => mb_substr('Cambio de ' . ($previous?->name ?? 'plan') . " a {$plan->name} por los días que faltan", 0, 255),
            ]);

            $subscription->update(['plan_id' => $plan->id, 'billing_cycle' => $cycle] + (self::ready() ? ['pending_plan_id' => null, 'pending_billing_cycle' => null] : []));

            return $invoice;
        });

        self::forget($subscription->tenant_id);
        self::afterPaid($subscription->fresh('plan'), $invoice);

        return $invoice;
    }

    public const USAGE_WARNING = 80;

    public static function notices(Tenant $tenant, Subscription $subscription, string $state, ?SaaSPlan $pending, ?User $user): array
    {
        if (!in_array($state, ['active', 'trial'], true)) {
            return [];
        }

        $admin = self::manages($user);
        $notices = [];
        $scheduled = Cancellations::scheduled($subscription->id);

        if ($scheduled) {
            $notices[] = ['kind' => 'cancel', 'text' => 'Se pidió la baja de tu empresa: todo funciona hasta el ' . Carbon::parse($scheduled->effective_at)->setTimezone($tenant->zone())->locale('es')->isoFormat('D [de] MMMM') . ' y después queda en solo lectura.' . ($admin ? ' Puedes anularla en Mi empresa → Plan.' : ''), 'action' => $admin ? 'Seguir con mi plan' : null];
        }

        if ($pending) {
            $notices[] = ['kind' => 'change', 'text' => "Desde el próximo periodo tu empresa pasa al plan {$pending->name}." . ($admin ? ' Puedes anular el cambio en Mi empresa → Plan.' : ''), 'action' => $admin ? 'Ver el cambio' : null];
        }

        $limits = Addons::extend($tenant->id, $subscription->plan?->limits);
        $labels = ['appointments_month' => 'las citas', 'whatsapp_month' => 'los mensajes de WhatsApp'];
        $used = null;

        foreach ($labels as $key => $label) {
            $limit = $limits[$key] ?? null;

            if (!is_numeric($limit) || (int) $limit <= 0) {
                continue;
            }

            $used ??= $tenant->usage();
            $percent = (int) floor(($used[$key] ?? 0) * 100 / (int) $limit);

            if ($percent >= self::USAGE_WARNING) {
                $full = $percent >= 100;
                $count = (int) ($used[$key] ?? 0) . ' de ' . (int) $limit;
                $text = $full
                    ? "Este mes tu empresa llegó al tope de {$label} que incluye su plan ({$count})." . ($key === 'whatsapp_month' ? ' Los recordatorios que falten salen por correo.' : '')
                    : "Este mes tu empresa ya usó el {$percent} % de {$label} que incluye su plan ({$count}).";
                $notices[] = ['kind' => 'usage', 'level' => $full ? 'full' : 'warning', 'key' => $key, 'percent' => $percent, 'text' => $text . ($admin ? ' Si te quedas corto, sube de plan.' : ' Avísale al administrador de tu empresa.'), 'action' => $admin ? 'Ver planes' : null];
            }
        }

        return $notices;
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

        $data = [
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
                'amount'       => self::manages($user) ? self::charge($subscription, $pending ?? $plan, $subscription->pending_billing_cycle ?? $subscription->billing_cycle) : null,
                'days_to_readonly' => ($readonlyOn = Dunning::readOnlyOn($subscription)) && $readonlyOn->isFuture() ? max(0, (int) ceil(now()->diffInSeconds($readonlyOn, false) / 86400)) : 0,
                'since'        => $day(self::endOf($subscription)),
            ] : null,
            'message'       => in_array($state['state'], self::LOCKED_STATES, true) ? self::lockMessage($tenant) : null,
            'can_pay'       => self::manages($user),
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
            'cancellation'  => $subscription && self::manages($user) ? Cancellations::present($subscription) : null,
            'addons'        => self::manages($user) ? Addons::present($tenant, $subscription) : null,
            'charge'        => self::charge($subscription),
            'coupon'        => PlanCoupons::present($subscription),
            'coupons'       => PlanCoupons::ready(),
            'plan'          => $plan ? ['id' => $plan->id, 'name' => $plan->name, 'currency' => $plan->currency ?? 'PEN'] : null,
            'billing_cycle' => $subscription?->billing_cycle,
            'price'         => $subscription ? self::price($plan, $subscription->billing_cycle) : 0,
            'next_period'   => $subscription ? array_map($day, self::periodFor($subscription, $subscription->pending_billing_cycle ?? $subscription->billing_cycle)) : null,
            'notices'       => $subscription ? self::notices($tenant, $subscription, $state['state'], $pending, $user) : [],
            'pending_plan'  => $pending ?['id' => $pending->id, 'name' => $pending->name, 'billing_cycle' => $subscription->pending_billing_cycle, 'price' => self::charge($subscription, $pending, $subscription->pending_billing_cycle ?? 'monthly'), 'list_price' => self::price($pending, $subscription->pending_billing_cycle ?? 'monthly')] : null,
            'card'          => $subscription && self::ready() ? [
                'status'        => $subscription->card_status,
                'saved'         => PlanBilling::savedCard($subscription),
                'provider'      => $subscription->external_provider,
                'brand'         => $subscription->card_brand,
                'last4'         => $subscription->card_last4,
                'authorized_at' => $day($subscription->card_authorized_at),
                'failed_at'     => $day($subscription->last_charge_failed_at),
                'expiry'        => CardExpiry::forSubscription($subscription),
            ] : null,
        ];

        return self::manages($user) ? $data : array_merge($data, ['card' => null, 'charge' => null, 'coupon' => null, 'review' => null, 'rejected' => null]);
    }
}
