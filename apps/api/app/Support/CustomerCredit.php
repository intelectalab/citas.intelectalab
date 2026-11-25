<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerCredit
{
    public const PREFIX = 'SAF-';

    public const NOT_READY = 'Falta actualizar la base de datos para guardar el adelanto como saldo a favor: ejecuta php artisan migrate.';

    public static function ready(): bool
    {
        static $ready = null;

        if ($ready !== null) {
            return $ready;
        }

        if (!Schema::hasTable('gift_cards') || !Schema::hasTable('gift_card_transactions') || !Schema::hasColumn('sales', 'deposit_outcome')) {
            return $ready = false;
        }

        $check = DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'sales_deposit_outcome_check'");

        return $ready = !$check || str_contains((string) $check->def, 'credited');
    }

    public static function isCredit(?string $code): bool
    {
        return $code !== null && str_starts_with(mb_strtoupper($code), self::PREFIX);
    }

    public static function query(string $tenantId, string $customerId)
    {
        return DB::table('gift_cards')
            ->where('gift_cards.tenant_id', $tenantId)
            ->where('gift_cards.code', 'like', self::PREFIX . '%')
            ->where('gift_cards.status', 'active')
            ->where('gift_cards.balance', '>', 0)
            ->where('gift_cards.recipient_customer_id', $customerId);
    }

    public static function balance(string $tenantId, ?string $customerId): float
    {
        if (!$customerId || !self::ready()) {
            return 0.0;
        }

        return round((float) self::query($tenantId, $customerId)->sum('gift_cards.balance'), 2);
    }

    public static function summary(string $tenantId, ?string $customerId): array
    {
        if (!$customerId || !self::ready()) {
            return ['balance' => 0.0, 'items' => []];
        }

        $items = self::query($tenantId, $customerId)
            ->leftJoin('sales as s', 's.id', '=', 'gift_cards.sale_id')
            ->orderBy('gift_cards.created_at')
            ->get(['gift_cards.code', 'gift_cards.balance', 'gift_cards.initial_amount', 'gift_cards.created_at', 's.code as sale_code'])
            ->map(fn ($row) => [
                'code'       => $row->code,
                'balance'    => round((float) $row->balance, 2),
                'initial'    => round((float) $row->initial_amount, 2),
                'sale_code'  => $row->sale_code,
                'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            ])->values()->all();

        return ['balance' => round(array_sum(array_column($items, 'balance')), 2), 'items' => $items];
    }

    public static function fromDeposit(Tenant $tenant, object $sale, Appointment $appointment, float $deposit): void
    {
        $rules = Sales::rules($tenant);
        $totals = Sales::totals($rules, $deposit);
        $appointment->loadMissing('service');

        DB::table('coupon_redemptions')->where('sale_id', $sale->id)->get()->each(function ($redemption) {
            DB::table('coupons')->where('id', $redemption->coupon_id)->where('used_count', '>', 0)->decrement('used_count');
            DB::table('coupon_redemptions')->where('id', $redemption->id)->delete();
        });

        DB::table('sale_items')->where('sale_id', $sale->id)->delete();
        DB::table('sale_items')->insert([
            'id'              => (string) Str::uuid(),
            'tenant_id'       => $tenant->id,
            'sale_id'         => $sale->id,
            'item_type'       => 'deposit',
            'reference_id'    => $appointment->service_id,
            'description'     => mb_substr('Saldo a favor: adelanto de ' . ($appointment->service?->name ?? 'servicio') . " (cita {$appointment->code}, canceló a tiempo)", 0, 255),
            'quantity'        => 1,
            'unit_price'      => $deposit,
            'discount'        => 0,
            'total'           => $deposit,
            'professional_id' => $appointment->professional_id,
            'appointment_id'  => $appointment->id,
        ]);

        DB::table('sales')->where('id', $sale->id)->update([
            'subtotal'        => $totals['subtotal'],
            'discount'        => 0,
            'tax'             => $totals['tax'],
            'total'           => $deposit,
            'paid'            => $deposit,
            'balance'         => 0,
            'status'          => 'paid',
            'paid_at'         => now(),
            'deposit_outcome' => 'credited',
            'updated_at'      => now(),
        ]);

        $id = (string) Str::uuid();
        $code = self::nextCode($tenant->id);

        DB::table('gift_cards')->insert([
            'id'                    => $id,
            'tenant_id'             => $tenant->id,
            'code'                  => $code,
            'initial_amount'        => $deposit,
            'balance'               => $deposit,
            'purchaser_customer_id' => $sale->customer_id,
            'recipient_customer_id' => $sale->customer_id,
            'sale_id'               => $sale->id,
            'status'                => 'active',
            'expires_at'            => null,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        DB::table('gift_card_transactions')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'gift_card_id' => $id,
            'type' => 'issue', 'amount' => $deposit, 'sale_id' => $sale->id, 'created_at' => now(),
        ]);

        Audit::log(request(), 'customer_credit.created', [
            'tenant' => $tenant->id, 'entity_type' => 'sale', 'entity_id' => $sale->id, 'entity_label' => $sale->code, 'branch_id' => $sale->branch_id,
            'after'  => ['amount' => $deposit, 'code' => $code, 'appointment' => $appointment->code],
        ]);
    }

    public static function pay(Tenant $tenant, object $sale, float $amount): string
    {
        if (!self::ready()) {
            throw ValidationException::withMessages(['method' => self::NOT_READY]);
        }

        if (!$sale->customer_id) {
            throw ValidationException::withMessages(['method' => 'Esta venta no tiene cliente: el saldo a favor solo se usa en las compras del mismo cliente.']);
        }

        $cards = self::query($tenant->id, $sale->customer_id)->orderBy('created_at')->lockForUpdate()->get();
        $available = round((float) $cards->sum('balance'), 2);

        if ($amount > $available + 0.001) {
            throw ValidationException::withMessages([
                'amount' => $available > 0
                    ? 'El cliente tiene ' . Currency::format($available, $sale->currency ?? Currency::of($tenant)) . ' de saldo a favor.'
                    : 'El cliente no tiene saldo a favor.',
            ]);
        }

        $left = round($amount, 2);
        $used = [];

        foreach ($cards as $card) {
            if ($left <= 0.001) {
                break;
            }

            $take = round(min($left, (float) $card->balance), 2);
            $rest = round((float) $card->balance - $take, 2);

            DB::table('gift_cards')->where('id', $card->id)->update([
                'balance'    => max(0, $rest),
                'status'     => $rest <= 0.001 ? 'redeemed' : 'active',
                'updated_at' => now(),
            ]);

            DB::table('gift_card_transactions')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'gift_card_id' => $card->id,
                'type' => 'redeem', 'amount' => $take, 'sale_id' => $sale->id, 'created_at' => now(),
            ]);

            $left = round($left - $take, 2);
            $used[] = $card->code;
        }

        return implode(', ', $used);
    }

    public static function usedOn(string $saleId): bool
    {
        return DB::table('gift_card_transactions as t')
            ->join('gift_cards as g', 'g.id', '=', 't.gift_card_id')
            ->where('t.sale_id', $saleId)
            ->where('t.type', 'redeem')
            ->where('g.code', 'like', self::PREFIX . '%')
            ->exists();
    }

    private static function nextCode(string $tenantId): string
    {
        do {
            $code = self::PREFIX . Str::upper(Str::random(4)) . '-' . random_int(1000, 9999);
        } while (DB::table('gift_cards')->where('tenant_id', $tenantId)->where('code', $code)->exists());

        return $code;
    }
}
