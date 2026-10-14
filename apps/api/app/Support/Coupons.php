<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Coupons
{
    public const PERCENT = 'percent';
    public const AMOUNT = 'amount';

    public static function state(object $coupon): string
    {
        $now = now();

        return match (true) {
            $coupon->status !== 'active' => 'inactive',
            $coupon->ends_at && Carbon::parse($coupon->ends_at)->lt($now) => 'expired',
            $coupon->max_uses !== null && $coupon->used_count >= $coupon->max_uses => 'used_up',
            $coupon->starts_at && Carbon::parse($coupon->starts_at)->gt($now) => 'scheduled',
            default => 'valid',
        };
    }

    public static function check(string $tenantId, string $code, float $gross): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['coupon_code' => $message]);

        $coupon = DB::table('coupons')->where('tenant_id', $tenantId)->whereRaw('upper(code) = ?', [mb_strtoupper(trim($code))])->first();

        if (!$coupon) {
            $fail('No existe un cupón con ese código.');
        }

        match (self::state($coupon)) {
            'inactive'  => $fail('Ese cupón está desactivado.'),
            'expired'   => $fail('Ese cupón ya venció.'),
            'used_up'   => $fail('Ese cupón ya agotó sus usos.'),
            'scheduled' => $fail('Ese cupón todavía no empieza a regir.'),
            default     => null,
        };

        if ($gross < (float) $coupon->min_purchase) {
            $fail('Ese cupón pide una compra mínima de S/ ' . number_format((float) $coupon->min_purchase, 2) . '.');
        }

        $discount = $coupon->discount_type === self::PERCENT
            ? round($gross * (float) $coupon->discount_value / 100, 2)
            : round(min($gross, (float) $coupon->discount_value), 2);

        if ($discount >= $gross) {
            $fail('Ese cupón cubre todo el monto: no quedaría nada que cobrar.');
        }

        return ['coupon' => $coupon, 'discount' => $discount];
    }

    public static function redeem(object $coupon, string $saleId, ?string $customerId, float $discount): void
    {
        DB::table('coupon_redemptions')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $coupon->tenant_id, 'coupon_id' => $coupon->id,
            'sale_id' => $saleId, 'customer_id' => $customerId, 'discount_amount' => $discount, 'created_at' => now(),
        ]);

        DB::table('coupons')->where('id', $coupon->id)->increment('used_count');
    }

    public static function ofSale(string $saleId): ?array
    {
        $row = DB::table('coupon_redemptions as r')
            ->join('coupons as c', 'c.id', '=', 'r.coupon_id')
            ->where('r.sale_id', $saleId)
            ->first(['c.code', 'c.description', 'c.discount_type', 'c.discount_value', 'r.discount_amount']);

        return $row ? self::summary($row, (float) $row->discount_amount) : null;
    }

    public static function summary(object $coupon, float $discount): array
    {
        return [
            'code'        => $coupon->code,
            'description' => $coupon->description,
            'benefit'     => $coupon->discount_type === self::PERCENT ? rtrim(rtrim(number_format((float) $coupon->discount_value, 2), '0'), '.') . ' %' : 'S/ ' . number_format((float) $coupon->discount_value, 2),
            'discount'    => round($discount, 2),
        ];
    }
}
