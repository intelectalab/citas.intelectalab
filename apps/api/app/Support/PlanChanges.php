<?php

namespace App\Support;

use App\Models\SaaSPlan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PlanChanges
{
    public const LIMITS = [
        'users'         => ['label' => 'Usuarios', 'fix' => 'desactiva cuentas en Usuarios y roles'],
        'branches'      => ['label' => 'Sedes', 'fix' => 'desactiva sedes en Sucursales'],
        'professionals' => ['label' => 'Profesionales', 'fix' => 'desactiva profesionales en Profesionales'],
    ];

    public static function monthly(?SaaSPlan $plan, string $cycle): float
    {
        $price = Subscriptions::price($plan, $cycle);

        return round($cycle === 'yearly' ? $price / 12 : $price, 2);
    }

    public static function exceeded(Tenant $tenant, SaaSPlan $plan): array
    {
        $usage = $tenant->usage();
        $pros = $tenant->vocabulary()['pros'] ?? 'Profesionales';
        $out = [];
        $limits = Addons::extend($tenant->id, $plan->limits);

        foreach (self::LIMITS as $key => $info) {
            $limit = $limits[$key] ?? null;

            if (is_numeric($limit) && (int) $limit >= 0 && ($usage[$key] ?? 0) > (int) $limit) {
                $out[] = ['key' => $key, 'label' => $key === 'professionals' ? $pros : $info['label'], 'used' => (int) $usage[$key], 'limit' => (int) $limit, 'fix' => $key === 'professionals' ? 'desactiva a alguien en ' . $pros : $info['fix']];
            }
        }

        return $out;
    }

    public const MONTHLY = [
        'appointments_month' => ['label' => 'Citas del mes', 'unit' => 'citas'],
        'whatsapp_month'     => ['label' => 'Mensajes de WhatsApp del mes', 'unit' => 'mensajes de WhatsApp'],
    ];

    public static function monthlyExceeded(Tenant $tenant, SaaSPlan $plan): array
    {
        $usage = $tenant->usage();
        $limits = Addons::extend($tenant->id, $plan->limits);
        $out = [];

        foreach (self::MONTHLY as $key => $info) {
            $limit = $limits[$key] ?? null;

            if (is_numeric($limit) && (int) $limit >= 0 && ($usage[$key] ?? 0) > (int) $limit) {
                $out[] = ['key' => $key, 'label' => $info['label'], 'used' => (int) $usage[$key], 'limit' => (int) $limit, 'fix' => 'espera al próximo mes o elige un plan que alcance'];
            }
        }

        return $out;
    }

    public static function modulesOff(?SaaSPlan $from, SaaSPlan $to): array
    {
        $keys = array_values(array_diff($from?->moduleKeys() ?? [], $to->moduleKeys()));

        if (!$keys) {
            return [];
        }

        $names = Schema::hasTable('saas_modules') ? DB::table('saas_modules')->whereIn('key', $keys)->pluck('name', 'key')->all() : [];

        return array_map(fn ($key) => ['key' => $key, 'name' => $names[$key] ?? $key], $keys);
    }

    public static function preview(Subscription $subscription, SaaSPlan $plan, string $cycle): array
    {
        $tenant = Tenant::find($subscription->tenant_id);
        $current = $subscription->plan;
        $state = Subscriptions::state($subscription->tenant_id)['state'];
        $same = $plan->id === $subscription->plan_id && $cycle === $subscription->billing_cycle;
        $newMonthly = self::monthly($plan, $cycle);
        $curMonthly = self::monthly($current, $subscription->billing_cycle ?: 'monthly');
        $price = Subscriptions::price($plan, $cycle);
        $end = Subscriptions::endOf($subscription);
        $zone = $tenant?->zone() ?? 'America/Lima';
        $day = fn (?Carbon $value) => $value?->copy()->setTimezone($zone)->format('Y-m-d');
        $exceeded = $tenant ? self::exceeded($tenant, $plan) : [];
        $monthly = $tenant && !$same ? self::monthlyExceeded($tenant, $plan) : [];
        $modulesOff = self::modulesOff($current, $plan);
        $paidPeriod = $state === 'active' && $subscription->status === 'active' && $end && $end->isFuture() && $curMonthly > 0;

        $kind = match (true) {
            $same                               => 'same',
            $state === 'trial'                  => 'trial',
            in_array($state, ['grace', 'locked', 'none'], true) || $subscription->status === 'canceled' => 'pay',
            $price <= 0                         => 'free',
            $paidPeriod && $cycle === $subscription->billing_cycle && $newMonthly > $curMonthly => 'upgrade',
            $paidPeriod && $subscription->billing_cycle === 'monthly' && $cycle === 'yearly' && $newMonthly >= $curMonthly * 10 / 12 - 0.01 => 'yearly_now',
            default                             => 'scheduled',
        };

        $today = 0.0;
        $daysLeft = null;
        $daysPeriod = null;
        $credit = 0.0;

        if ($kind === 'upgrade') {
            $periodStart = $cycle === 'yearly' ? $end->copy()->subYear() : $end->copy()->subMonth();
            $daysPeriod = max(1, (int) round($periodStart->diffInSeconds($end) / 86400));
            $daysLeft = max(0, (int) ceil(now()->diffInSeconds($end, false) / 86400));
            $diff = Subscriptions::charge($subscription, $plan, $cycle) - Subscriptions::charge($subscription, $current, $cycle);
            $today = max(0, round($diff * min($daysLeft, $daysPeriod) / $daysPeriod, 2));
        } elseif ($kind === 'yearly_now') {
            $periodStart = $end->copy()->subMonth();
            $daysPeriod = max(1, (int) round($periodStart->diffInSeconds($end) / 86400));
            $daysLeft = max(0, (int) ceil(now()->diffInSeconds($end, false) / 86400));
            $credit = round(Subscriptions::charge($subscription, $current, 'monthly') * min($daysLeft, $daysPeriod) / $daysPeriod, 2);
            $today = max(0, round(Cancellations::chargeFor($subscription, $plan, 'yearly') - $credit, 2));
        } elseif ($kind === 'pay') {
            $today = Cancellations::chargeFor($subscription, $plan, $cycle);
        }

        $nextDate = match ($kind) {
            'trial', 'upgrade', 'scheduled' => $end,
            'yearly_now'                    => now()->addYear(),
            'pay'                           => $cycle === 'yearly' ? now()->addYear() : now()->addMonth(),
            default                         => null,
        };

        $immediateKinds = ['trial', 'pay', 'free'];
        $warnings = [];

        if ($monthly && in_array($kind, $immediateKinds, true)) {
            $exceeded = array_merge($exceeded, $monthly);
        } elseif ($monthly && $kind !== 'upgrade') {
            $warnings = array_map(fn ($row) => "Este mes van {$row['used']} " . self::MONTHLY[$row['key']]['unit'] . " y el plan {$plan->name} incluye {$row['limit']} al mes. Si en tu primer mes con ese plan pasa lo mismo, al llegar al tope " . ($row['key'] === 'whatsapp_month' ? 'los recordatorios salen por correo.' : 'no podrás agendar más citas ese mes.'), $monthly);
        }

        $blocked = $exceeded && !in_array($kind, ['same'], true);
        $immediate = in_array($kind, ['upgrade', 'yearly_now'], true) && PlanBilling::savedCard($subscription) && Schema::hasColumn('plan_payments', 'intent');

        return [
            'kind'          => $kind,
            'plan'          => ['id' => $plan->id, 'name' => $plan->name, 'currency' => $plan->currency ?? 'PEN'],
            'billing_cycle' => $cycle,
            'today'         => in_array($kind, ['upgrade', 'yearly_now'], true) && !$immediate ? 0.0 : $today,
            'prorated'      => $kind === 'upgrade' ? ['amount' => $today, 'days_left' => $daysLeft, 'days_period' => $daysPeriod] : null,
            'credit'        => $kind === 'yearly_now' ? ['amount' => $credit, 'days_left' => $daysLeft, 'days_period' => $daysPeriod, 'total' => $today] : null,
            'warnings'      => $warnings,
            'immediate'     => $immediate,
            'next_charge'   => $price > 0 && $nextDate ? ['amount' => Cancellations::chargeFor($subscription, $plan, $cycle), 'date' => $day($nextDate)] : null,
            'modules_off'   => $modulesOff,
            'exceeded'      => $exceeded,
            'blocked'       => $blocked,
            'message'       => $kind === 'scheduled' && !$blocked && !$paidPeriod ? "El plan {$plan->name} empieza cuando pagues el próximo periodo." : self::message($kind, $plan, $immediate, $blocked, $exceeded),
        ];
    }

    private static function message(string $kind, SaaSPlan $plan, bool $immediate, bool $blocked, array $exceeded): string
    {
        if ($blocked) {
            return "Para pasar al plan {$plan->name} primero ajusta lo que usas: " . implode('; ', array_map(fn ($row) => "{$row['label']}: usas {$row['used']} y el plan permite {$row['limit']} ({$row['fix']})", $exceeded)) . '.';
        }

        return match ($kind) {
            'same'      => "Ya estás en el plan {$plan->name}.",
            'trial'     => "Tu prueba sigue con el plan {$plan->name}. No se cobra nada hasta que lo actives.",
            'pay'       => "Pagas el plan {$plan->name} ahora y empieza de inmediato.",
            'free'      => "Tu empresa pasa al plan {$plan->name}, sin costo.",
            'yearly_now' => $immediate
                ? "Pasas hoy al pago anual del plan {$plan->name}. Descontamos lo que te quedaba del mes ya pagado y tu nuevo año empieza hoy."
                : "Guarda una tarjeta para pasar hoy al pago anual con el descuento de los días que te quedan; si no, el pago anual empieza en el próximo periodo.",
            'upgrade'   => $immediate
                ? "El plan {$plan->name} empieza hoy. Cobramos a tu tarjeta solo la diferencia por los días que faltan."
                : "Guarda una tarjeta para pasar hoy al plan {$plan->name} pagando solo la diferencia; si no, empieza en el próximo periodo.",
            default     => "El plan {$plan->name} empieza en el próximo periodo, cuando termine el que ya pagaste.",
        };
    }

    public static function limitResponse(array $preview)
    {
        return response()->json([
            'message' => $preview['message'],
            'code'    => 'PLAN_LIMIT',
            'limits'  => $preview['exceeded'],
        ], 402);
    }
}
