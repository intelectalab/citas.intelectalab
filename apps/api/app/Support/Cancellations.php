<?php

namespace App\Support;

use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class Cancellations
{
    public const REASONS = [
        'price'       => 'Me sale caro',
        'not_using'   => 'No lo estamos usando',
        'missing'     => 'Le falta algo que necesito',
        'hard'        => 'Es difícil de usar',
        'other_tool'  => 'Me voy a otro sistema',
        'closing'     => 'Cierro o pauso el negocio',
        'other'       => 'Otro motivo',
    ];

    public const OFFER_DEFAULTS = ['enabled' => false, 'percent' => 50, 'months' => 2, 'reasons' => ['price', 'not_using', 'hard']];

    public const NOT_READY = 'Falta actualizar la base de datos para dar de baja la empresa: ejecuta php artisan migrate.';

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Subscriptions::ready() && Schema::hasTable('saas_cancellations') && Schema::hasColumn('subscriptions', 'cancel_at');
    }

    public static function offerConfig(): array
    {
        $stored = PlatformSetting::value('cancel_offer');
        $stored = is_array($stored) ? $stored : [];

        return [
            'enabled' => (bool) ($stored['enabled'] ?? self::OFFER_DEFAULTS['enabled']),
            'percent' => max(5, min(100, (int) ($stored['percent'] ?? self::OFFER_DEFAULTS['percent']))),
            'months'  => max(1, min(12, (int) ($stored['months'] ?? self::OFFER_DEFAULTS['months']))),
            'reasons' => array_values(array_intersect(array_keys(self::REASONS), (array) ($stored['reasons'] ?? self::OFFER_DEFAULTS['reasons']))),
        ];
    }

    public static function saveOffer(array $values): array
    {
        PlatformSetting::put(['cancel_offer' => [
            'enabled' => (bool) ($values['enabled'] ?? false),
            'percent' => (int) $values['percent'],
            'months'  => (int) $values['months'],
            'reasons' => array_values(array_intersect(array_keys(self::REASONS), (array) ($values['reasons'] ?? []))),
        ]]);

        return self::offerConfig();
    }

    public static function offerFor(Subscription $subscription, string $reason): ?array
    {
        $config = self::offerConfig();

        if (!$config['enabled'] || !in_array($reason, $config['reasons'], true) || $subscription->billing_cycle !== 'monthly'
            || $subscription->status === 'trialing' || Subscriptions::price($subscription->plan, 'monthly') <= 0 || (float) $subscription->discount_percent > 0) {
            return null;
        }

        $price = Subscriptions::price($subscription->plan, 'monthly');

        return [
            'percent' => $config['percent'],
            'months'  => $config['months'],
            'price'   => round($price * (100 - $config['percent']) / 100, 2),
            'text'    => "{$config['percent']} % de descuento por {$config['months']} " . ($config['months'] === 1 ? 'mes' : 'meses'),
        ];
    }

    public static function chargeFor(Subscription $subscription, $plan, string $cycle): float
    {
        $price = Subscriptions::price($plan, $cycle);

        if (self::ready() && $cycle === 'monthly' && (int) $subscription->discount_cycles > 0 && (float) $subscription->discount_percent > 0) {
            $price = round($price * (100 - (float) $subscription->discount_percent) / 100, 2);
        }

        $price = PlanCoupons::apply($subscription, $plan instanceof \App\Models\SaaSPlan ? $plan : null, $cycle, $price);

        return round($price + ($price > 0 || Subscriptions::price($plan instanceof \App\Models\SaaSPlan ? $plan : null, $cycle) > 0 ? Addons::total($subscription->tenant_id, $cycle) : 0), 2);
    }

    public static function usedDiscount(Subscription $subscription, string $cycle): array
    {
        if (!self::ready() || $cycle !== 'monthly' || (int) $subscription->discount_cycles <= 0) {
            return [];
        }

        $left = (int) $subscription->discount_cycles - 1;

        return ['discount_cycles' => max(0, $left), 'discount_percent' => $left > 0 ? $subscription->discount_percent : 0];
    }

    public static function scheduled(string $subscriptionId): ?object
    {
        return self::ready() ? DB::table('saas_cancellations')->where('subscription_id', $subscriptionId)->where('status', 'scheduled')->orderByDesc('created_at')->first() : null;
    }

    public static function effectiveDate(Subscription $subscription): Carbon
    {
        $end = Subscriptions::endOf($subscription);

        return $end && $end->isFuture() ? $end : now();
    }

    public static function request(Subscription $subscription, User $user, string $reason, ?string $details, bool $acceptOffer): array
    {
        abort_unless(self::ready(), 503, self::NOT_READY);

        if (!isset(self::REASONS[$reason])) {
            throw ValidationException::withMessages(['reason' => 'Elige por qué te vas.']);
        }

        if ($subscription->status === 'canceled') {
            throw ValidationException::withMessages(['reason' => 'Tu plan ya está cancelado.']);
        }

        if (self::scheduled($subscription->id)) {
            throw ValidationException::withMessages(['reason' => 'Ya pediste la baja. Si cambiaste de idea, usa "Seguir con mi plan".']);
        }

        $offer = self::offerFor($subscription, $reason);

        return DB::transaction(function () use ($subscription, $user, $reason, $details, $acceptOffer, $offer) {
            $row = [
                'tenant_id'       => $subscription->tenant_id,
                'subscription_id' => $subscription->id,
                'reason'          => $reason,
                'details'         => $details ? mb_substr(trim($details), 0, 500) : null,
                'offer'           => $offer['text'] ?? null,
                'requested_by'    => $user->id,
                'created_at'      => now(),
                'updated_at'      => now(),
            ];

            if ($acceptOffer && $offer) {
                DB::table('saas_cancellations')->insert($row + ['status' => 'kept', 'offer_accepted' => true]);
                $subscription->update(['discount_percent' => $offer['percent'], 'discount_cycles' => $offer['months']]);
                Subscriptions::forget($subscription->tenant_id);
                SaasEvents::record($subscription->tenant_id, null, ['meta' => ['reason' => 'offer']]);
                PlanBilling::notify($subscription->tenant_id, 'subscription.offer', 'Te quedas con descuento', "Aplicamos {$offer['text']} a tu plan. Gracias por quedarte.", "offer:{$subscription->id}");

                return ['kept' => true, 'offer' => $offer];
            }

            $effective = self::effectiveDate($subscription);
            DB::table('saas_cancellations')->insert($row + ['status' => 'scheduled', 'effective_at' => $effective]);
            $subscription->update(['cancel_at' => $effective]);

            if ($subscription->external_provider === 'mercadopago' && $subscription->external_subscription_id) {
                try {
                    PlanBilling::cancelCard($subscription);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            $date = $effective->copy()->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
            $text = $effective->lte(now())
                ? 'Tu empresa quedó dada de baja y en solo lectura. Tus datos se conservan: si pagas tu plan desde Mi empresa → Plan, vuelve a tener todo.'
                : "Todo sigue funcionando hasta el {$date}. Después quedará en solo lectura y tus datos se conservan. Puedes seguir con tu plan cuando quieras desde Mi empresa → Plan.";
            PlanBilling::notify($subscription->tenant_id, 'subscription.cancel_requested', 'Pediste la baja de tu empresa', $text, "cancel-req:{$subscription->id}");

            if ($effective->lte(now())) {
                self::finish($subscription->fresh());
            }

            Subscriptions::forget($subscription->tenant_id);
            SaasEvents::record($subscription->tenant_id, 'cancel_scheduled', ['meta' => ['reason' => $reason]]);

            return ['kept' => false, 'effective_at' => $effective];
        });
    }

    public static function resume(Subscription $subscription): void
    {
        abort_unless(self::ready(), 503, self::NOT_READY);
        $row = self::scheduled($subscription->id);

        if (!$row) {
            throw ValidationException::withMessages(['cancel' => $subscription->status === 'canceled' ? 'Tu plan ya terminó: para volver, paga tu plan en esta misma página.' : 'No hay una baja pedida.']);
        }

        DB::table('saas_cancellations')->where('id', $row->id)->update(['status' => 'resumed', 'resumed_at' => now(), 'updated_at' => now()]);
        $subscription->update(['cancel_at' => null]);
        Subscriptions::forget($subscription->tenant_id);
        SaasEvents::record($subscription->tenant_id, 'cancel_resumed');
        PlanBilling::notify($subscription->tenant_id, 'subscription.resumed', 'Sigues con tu plan', 'Anulaste la baja: tu plan sigue como siempre.', "resumed:{$row->id}");
    }

    public static function finish(Subscription $subscription): void
    {
        $subscription->update(['status' => 'canceled', 'canceled_at' => now(), 'cancel_at' => null]);
        DB::table('saas_cancellations')->where('subscription_id', $subscription->id)->where('status', 'scheduled')->update(['status' => 'done', 'updated_at' => now()]);
        Subscriptions::forget($subscription->tenant_id);
        SaasEvents::record($subscription->tenant_id, null, ['meta' => ['reason' => 'cancelled']]);
        PlanBilling::notify($subscription->tenant_id, 'subscription.canceled', 'Tu empresa se dio de baja', 'Tu plan terminó como pediste. Tus datos se conservan: puedes verlos y descargarlos, y si pagas el plan vuelve todo como estaba.', "canceled:{$subscription->id}");
    }

    public static function runDue(): int
    {
        if (!self::ready()) {
            return 0;
        }

        $count = 0;

        foreach (Subscription::whereNotNull('cancel_at')->where('cancel_at', '<=', now())->where('status', '!=', 'canceled')->get() as $subscription) {
            try {
                self::finish($subscription);
                $count++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $count;
    }

    public static function present(Subscription $subscription): ?array
    {
        if (!self::ready()) {
            return null;
        }

        $row = self::scheduled($subscription->id);

        return [
            'scheduled'    => $row ? ['effective_at' => Carbon::parse($row->effective_at)->toIso8601String(), 'reason' => self::REASONS[$row->reason] ?? $row->reason] : null,
            'reasons'      => collect(self::REASONS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'offers'       => collect(array_keys(self::REASONS))->mapWithKeys(fn ($key) => [$key => self::offerFor($subscription, $key)])->filter()->all(),
            'effective_at' => self::effectiveDate($subscription)->toIso8601String(),
            'discount'     => (int) $subscription->discount_cycles > 0 ? ['percent' => (float) $subscription->discount_percent, 'cycles' => (int) $subscription->discount_cycles] : null,
        ];
    }

    public static function forPlatform(int $limit = 200): array
    {
        if (!self::ready()) {
            return [];
        }

        return DB::table('saas_cancellations as c')
            ->join('tenants as t', 't.id', '=', 'c.tenant_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.requested_by')
            ->orderByDesc('c.created_at')
            ->limit($limit)
            ->get(['c.*', 't.name as tenant_name', 'u.first_name', 'u.last_name'])
            ->map(fn ($row) => [
                'id'             => $row->id,
                'tenant'         => ['id' => $row->tenant_id, 'name' => $row->tenant_name],
                'reason'         => self::REASONS[$row->reason] ?? $row->reason,
                'details'        => $row->details,
                'status'         => $row->status,
                'offer'          => $row->offer,
                'offer_accepted' => (bool) $row->offer_accepted,
                'effective_at'   => $row->effective_at ? Carbon::parse($row->effective_at)->toIso8601String() : null,
                'requested_by'   => trim("{$row->first_name} {$row->last_name}") ?: null,
                'created_at'     => Carbon::parse($row->created_at)->toIso8601String(),
            ])
            ->all();
    }
}
