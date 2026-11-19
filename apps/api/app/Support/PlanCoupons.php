<?php

namespace App\Support;

use App\Models\SaaSPlan;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PlanCoupons
{
    public const NOT_READY = 'Falta actualizar la base de datos para usar cupones de planes. Pide que se ejecute la actualización (php artisan migrate).';

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('saas_coupon_redemptions');
    }

    public static function normalize(?string $code): string
    {
        return mb_strtoupper(trim((string) $code));
    }

    public static function find(string $code): ?object
    {
        return self::ready() ? DB::table('saas_coupons')->where('code', self::normalize($code))->first() : null;
    }

    public static function problem(?object $coupon, ?string $planId = null): ?string
    {
        if (!$coupon) {
            return 'Ese cupón no existe. Revisa que esté bien escrito.';
        }

        if (!$coupon->active) {
            return 'Ese cupón ya no está activo.';
        }

        if ($coupon->valid_until && Carbon::parse($coupon->valid_until, 'America/Lima')->endOfDay()->isPast()) {
            return 'Ese cupón venció el ' . Carbon::parse($coupon->valid_until)->locale('es')->isoFormat('D [de] MMMM [de] YYYY') . '.';
        }

        if ($coupon->max_uses !== null && (int) $coupon->used >= (int) $coupon->max_uses) {
            return 'Ese cupón ya se usó todas las veces que permitía.';
        }

        $plans = self::planIds($coupon);

        if ($planId && $plans && !in_array($planId, $plans, true)) {
            $names = DB::table('saas_plans')->whereIn('id', $plans)->orderBy('presentation_order')->pluck('name')->implode(', ');

            return "Ese cupón vale solo para el plan {$names}.";
        }

        return null;
    }

    public static function check(string $code, ?string $planId = null, string $field = 'coupon'): object
    {
        abort_unless(self::ready(), 503, self::NOT_READY);
        $coupon = self::find($code);

        if ($message = self::problem($coupon, $planId)) {
            throw ValidationException::withMessages([$field => $message]);
        }

        return $coupon;
    }

    public static function planIds(object $coupon): array
    {
        $ids = is_string($coupon->plan_ids ?? null) ? json_decode($coupon->plan_ids, true) : ($coupon->plan_ids ?? []);

        return array_values(array_filter((array) $ids));
    }

    public static function text(object $coupon): string
    {
        $value = $coupon->percent_off !== null
            ? rtrim(rtrim(number_format((float) $coupon->percent_off, 2, '.', ''), '0'), '.') . ' %'
            : 'S/ ' . number_format((float) $coupon->amount_off, 2, '.', '');
        $months = (int) $coupon->months;

        return "{$value} de descuento por {$months} " . ($months === 1 ? 'mes' : 'meses');
    }

    public static function redeem(Subscription $subscription, string $code, string $source = 'signup'): object
    {
        abort_unless(self::ready(), 503, self::NOT_READY);

        return DB::transaction(function () use ($subscription, $code, $source) {
            $coupon = DB::table('saas_coupons')->where('code', self::normalize($code))->lockForUpdate()->first();

            if ($message = self::problem($coupon, $subscription->plan_id)) {
                throw ValidationException::withMessages(['coupon' => $message]);
            }

            if (self::active($subscription)) {
                throw ValidationException::withMessages(['coupon' => 'Tu plan ya tiene un cupón en uso. Puedes usar otro cuando ese termine.']);
            }

            if (DB::table('saas_coupon_redemptions')->where('coupon_id', $coupon->id)->where('tenant_id', $subscription->tenant_id)->exists()) {
                throw ValidationException::withMessages(['coupon' => 'Tu empresa ya usó este cupón.']);
            }

            DB::table('saas_coupons')->where('id', $coupon->id)->update(['used' => DB::raw('used + 1'), 'updated_at' => now()]);

            $id = (string) \Illuminate\Support\Str::uuid();
            DB::table('saas_coupon_redemptions')->insert([
                'id'              => $id,
                'coupon_id'       => $coupon->id,
                'tenant_id'       => $subscription->tenant_id,
                'subscription_id' => $subscription->id,
                'code'            => $coupon->code,
                'percent_off'     => $coupon->percent_off,
                'amount_off'      => $coupon->amount_off,
                'plan_ids'        => json_encode(self::planIds($coupon)),
                'cycles_left'     => (int) $coupon->months,
                'source'          => $source,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            return DB::table('saas_coupon_redemptions')->where('id', $id)->first();
        });
    }

    public static function active(Subscription $subscription): ?object
    {
        if (!self::ready()) {
            return null;
        }

        return DB::table('saas_coupon_redemptions')->where('subscription_id', $subscription->id)->where('cycles_left', '>', 0)->orderByDesc('created_at')->first();
    }

    private static function appliesTo(object $redemption, ?SaaSPlan $plan): bool
    {
        $plans = self::planIds($redemption);

        return !$plans || ($plan && in_array($plan->id, $plans, true));
    }

    public static function discount(object $redemption, float $price, string $cycle): float
    {
        $months = $cycle === 'yearly' ? min(12, (int) $redemption->cycles_left) : 1;

        if ($redemption->percent_off !== null) {
            $off = $price * (float) $redemption->percent_off / 100;
            $off = $cycle === 'yearly' ? $off * $months / 12 : $off;
        } else {
            $off = (float) $redemption->amount_off * $months;
        }

        return round(min($price, max(0, $off)), 2);
    }

    public static function apply(Subscription $subscription, ?SaaSPlan $plan, string $cycle, float $price): float
    {
        $redemption = self::active($subscription);

        if (!$redemption || $price <= 0 || !self::appliesTo($redemption, $plan)) {
            return $price;
        }

        return round($price - self::discount($redemption, $price, $cycle), 2);
    }

    public static function consume(Subscription $subscription, ?SaaSPlan $plan, string $cycle): void
    {
        $redemption = self::active($subscription);

        if (!$redemption || !self::appliesTo($redemption, $plan) || Subscriptions::price($plan, $cycle) <= 0) {
            return;
        }

        $used = $cycle === 'yearly' ? (int) $redemption->cycles_left : 1;

        DB::table('saas_coupon_redemptions')->where('id', $redemption->id)->update([
            'cycles_left' => max(0, (int) $redemption->cycles_left - $used),
            'cycles_used' => (int) $redemption->cycles_used + $used,
            'updated_at'  => now(),
        ]);
    }

    public static function present(?Subscription $subscription): ?array
    {
        $redemption = $subscription ? self::active($subscription) : null;

        if (!$redemption) {
            return null;
        }

        return [
            'code'        => $redemption->code,
            'text'        => self::text((object) ['percent_off' => $redemption->percent_off, 'amount_off' => $redemption->amount_off, 'months' => $redemption->cycles_left]),
            'cycles_left' => (int) $redemption->cycles_left,
        ];
    }
}
