<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Loyalty
{
    public const NOT_READY = 'Falta actualizar la base de datos para usar puntos y gift cards: ejecuta php artisan migrate.';

    public const POINT_TYPES = [
        'earn'     => 'Acumulación por compra',
        'redeem'   => 'Canje en compra',
        'adjust'   => 'Ajuste manual',
        'referral' => 'Bono por referido',
        'expire'   => 'Vencimiento de puntos',
        'reversal' => 'Reverso por devolución',
    ];

    public const GIFT_CARD_STATUSES = [
        'active'    => 'Vigente',
        'redeemed'  => 'Agotada',
        'expired'   => 'Vencida',
        'cancelled' => 'Anulada',
    ];

    public const GIFT_CARD_TX_TYPES = [
        'issue'  => 'Emisión',
        'redeem' => 'Uso / canje',
        'refund' => 'Devolución a favor',
        'extend' => 'Ampliación de vigencia',
        'cancel' => 'Anulación',
    ];

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('loyalty_transactions')
            && Schema::hasTable('gift_cards')
            && Schema::hasTable('gift_card_transactions');
    }

    public static function enabled(Tenant $tenant): bool
    {
        return $tenant->hasModule('loyalty') && self::ready();
    }

    public static function rules(Tenant $tenant): array
    {
        return [
            'points_enabled'          => (bool) AppointmentFlow::rule($tenant, 'loyalty.points_enabled'),
            'amount_per_point'        => max(0.01, round((float) AppointmentFlow::rule($tenant, 'loyalty.amount_per_point'), 2)),
            'point_value'             => max(0.01, round((float) AppointmentFlow::rule($tenant, 'loyalty.point_value'), 2)),
            'min_redeem_points'       => max(1, (int) AppointmentFlow::rule($tenant, 'loyalty.min_redeem_points')),
            'points_validity_days'    => max(0, (int) AppointmentFlow::rule($tenant, 'loyalty.points_validity_days')),
            'gift_cards_enabled'      => (bool) AppointmentFlow::rule($tenant, 'loyalty.gift_cards_enabled'),
            'gift_card_validity_days' => max(0, (int) AppointmentFlow::rule($tenant, 'loyalty.gift_card_validity_days')),
        ];
    }

    public static function ensurePermissions(): void
    {
        static $synced = false;
        if ($synced || !Access::ready()) {
            return;
        }
        $synced = true;

        if (DB::table('permissions')->where('name', 'loyalty.view')->exists()) {
            return;
        }

        $ids = Access::permissionIds();

        foreach (['manager', 'reception', 'cashier'] as $slug) {
            $names = array_values(array_intersect(
                Access::factoryPermissions($slug),
                array_filter(array_keys($ids), fn ($name) => str_starts_with($name, 'loyalty.')),
            ));

            if (!$names) {
                continue;
            }

            $roles = DB::table('roles')->where('slug', $slug)->whereNotNull('permissions_seeded_at')->pluck('id');
            foreach ($roles as $roleId) {
                DB::table('role_permissions')->insertOrIgnore(
                    array_map(fn ($name) => ['role_id' => $roleId, 'permission_id' => $ids[$name]], $names),
                );
            }
        }
    }

    public static function expireDue(string $tenantId): void
    {
        if (!self::ready()) {
            return;
        }

        DB::table('gift_cards')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now()->toIso8601String())
            ->update(['status' => 'expired', 'updated_at' => now()]);
    }

    public static function customerPoints(string $tenantId, string $customerId): int
    {
        if (!self::ready()) {
            return 0;
        }

        return max(0, (int) DB::table('loyalty_transactions')
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->sum('points'));
    }

    public static function recordPoints(
        Tenant $tenant,
        string $customerId,
        string $type,
        int $points,
        ?User $actor = null,
        ?string $reason = null,
        ?string $saleId = null,
        ?Carbon $expiresAt = null,
        bool $checkBalance = true,
        string $errorField = 'points',
    ): object {
        if ($points === 0) {
            throw ValidationException::withMessages([$errorField => 'La cantidad de puntos debe ser distinta de cero.']);
        }

        $customer = DB::table('customers')
            ->where('tenant_id', $tenant->id)
            ->where('id', $customerId)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first(['id', 'first_name', 'last_name']);

        if (!$customer) {
            throw ValidationException::withMessages(['customer_id' => 'El cliente seleccionado no existe.']);
        }

        $current = self::customerPoints($tenant->id, $customerId);
        $after = $current + $points;

        if ($points < 0 && $checkBalance && $after < 0) {
            throw ValidationException::withMessages([
                $errorField => "El cliente tiene {$current} puntos disponibles y no alcanzan para descontar " . abs($points) . ' puntos.',
            ]);
        }

        $after = max(0, $after);

        if ($points > 0 && !$expiresAt) {
            $days = self::rules($tenant)['points_validity_days'];
            if ($days > 0) {
                $expiresAt = now($tenant->zone())->addDays($days)->endOfDay()->utc();
            }
        }

        $id = (string) Str::uuid();

        DB::table('loyalty_transactions')->insert([
            'id'            => $id,
            'tenant_id'     => $tenant->id,
            'customer_id'   => $customerId,
            'type'          => $type,
            'points'        => $points,
            'balance_after' => $after,
            'sale_id'       => $saleId,
            'reason'        => $reason ? mb_substr(trim($reason), 0, 255) : null,
            'expires_at'    => $expiresAt?->toIso8601String(),
            'actor_user_id' => $actor?->id,
            'created_at'    => now(),
        ]);

        return (object) [
            'id'             => $id,
            'customer_id'    => $customerId,
            'type'           => $type,
            'points'         => $points,
            'balance_before' => $current,
            'balance_after'  => $after,
        ];
    }

    public static function chargeContext(Tenant $tenant, ?object $customer, float $balanceToPay): ?array
    {
        if (!self::enabled($tenant)) {
            return null;
        }

        self::expireDue($tenant->id);
        $rules = self::rules($tenant);
        $customerId = $customer->id ?? null;

        $pointsBalance = $customerId ? self::customerPoints($tenant->id, $customerId) : 0;
        $pointValue = $rules['point_value'];
        $minRedeem = $rules['min_redeem_points'];
        $redeemableAmount = round($pointsBalance * $pointValue, 2);
        $maxAmountWithPoints = round(min(max(0, $balanceToPay), $redeemableAmount), 2);
        $canRedeemPoints = $rules['points_enabled']
            && $customerId !== null
            && $pointsBalance >= $minRedeem
            && $maxAmountWithPoints > 0;

        $earnPreview = $rules['points_enabled'] && $customerId && $rules['amount_per_point'] > 0
            ? (int) floor(($balanceToPay + 0.001) / $rules['amount_per_point'])
            : 0;

        $giftCards = [];
        if ($rules['gift_cards_enabled'] && $customerId) {
            $giftCards = DB::table('gift_cards')
                ->where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->where('balance', '>', 0)
                ->where(fn ($q) => $q->where('recipient_customer_id', $customerId)->orWhere('purchaser_customer_id', $customerId))
                ->orderBy('expires_at')
                ->orderByDesc('balance')
                ->get(['id', 'code', 'balance', 'initial_amount', 'expires_at'])
                ->map(fn ($gc) => [
                    'id'             => $gc->id,
                    'code'           => $gc->code,
                    'balance'        => round((float) $gc->balance, 2),
                    'initial_amount' => round((float) $gc->initial_amount, 2),
                    'expires_at'     => $gc->expires_at ? Carbon::parse($gc->expires_at)->toIso8601String() : null,
                ])->values()->all();
        }

        return [
            'points_enabled'         => $rules['points_enabled'],
            'gift_cards_enabled'     => $rules['gift_cards_enabled'],
            'points_balance'         => $pointsBalance,
            'point_value'            => $pointValue,
            'amount_per_point'       => $rules['amount_per_point'],
            'min_redeem_points'      => $minRedeem,
            'redeemable_amount'      => $redeemableAmount,
            'max_amount_with_points' => $maxAmountWithPoints,
            'can_redeem_points'      => $canRedeemPoints,
            'earn_preview'           => $earnPreview,
            'customer_gift_cards'    => $giftCards,
        ];
    }

    public static function payWithGiftCard(Tenant $tenant, object $sale, float $amount, ?string $rawCode, User $actor): string
    {
        if (!self::enabled($tenant) || !self::rules($tenant)['gift_cards_enabled']) {
            throw ValidationException::withMessages(['method' => 'Las gift cards no están activas en tu empresa.']);
        }

        self::expireDue($tenant->id);
        $code = mb_strtoupper(trim((string) $rawCode));

        if ($code === '') {
            throw ValidationException::withMessages(['reference' => 'Escribe el código de la gift card.']);
        }

        $gc = DB::table('gift_cards')
            ->where('tenant_id', $tenant->id)
            ->whereRaw('UPPER(code) = ?', [$code])
            ->lockForUpdate()
            ->first();

        if (!$gc) {
            throw ValidationException::withMessages(['reference' => "No existe ninguna gift card con el código {$code}."]);
        }

        if ($gc->status !== 'active') {
            $label = mb_strtolower(self::GIFT_CARD_STATUSES[$gc->status] ?? $gc->status);
            throw ValidationException::withMessages(['reference' => "La gift card {$gc->code} ya no está disponible ({$label})."]);
        }

        $avail = round((float) $gc->balance, 2);
        if ($amount > $avail + 0.001) {
            $formatted = Currency::format($avail, $sale->currency ?? Currency::of($tenant));
            throw ValidationException::withMessages([
                'amount' => "La gift card {$gc->code} tiene un saldo disponible de {$formatted}.",
            ]);
        }

        $newBalance = max(0, round($avail - $amount, 2));

        DB::table('gift_cards')->where('id', $gc->id)->update([
            'balance'    => $newBalance,
            'status'     => $newBalance <= 0.001 ? 'redeemed' : 'active',
            'updated_at' => now(),
        ]);

        DB::table('gift_card_transactions')->insert([
            'id'           => (string) Str::uuid(),
            'tenant_id'    => $tenant->id,
            'gift_card_id' => $gc->id,
            'type'         => 'redeem',
            'amount'       => round($amount, 2),
            'sale_id'      => $sale->id,
            'created_at'   => now(),
        ]);

        return $gc->code;
    }

    public static function payWithPoints(Tenant $tenant, object $sale, float $amount, User $actor): string
    {
        $rules = self::rules($tenant);

        if (!self::enabled($tenant) || !$rules['points_enabled']) {
            throw ValidationException::withMessages(['method' => 'El canje de puntos no está activo en tu empresa.']);
        }

        if (empty($sale->customer_id)) {
            throw ValidationException::withMessages(['method' => 'Para pagar con puntos la venta debe tener un cliente asignado.']);
        }

        $balancePoints = self::customerPoints($tenant->id, $sale->customer_id);
        if ($balancePoints < $rules['min_redeem_points']) {
            throw ValidationException::withMessages([
                'method' => "Se necesitan al menos {$rules['min_redeem_points']} puntos para canjear (el cliente tiene {$balancePoints}).",
            ]);
        }

        $pointsNeeded = (int) ceil(round($amount / $rules['point_value'], 4));
        if ($pointsNeeded <= 0) {
            throw ValidationException::withMessages(['amount' => 'El monto a canjear es inválido.']);
        }

        if ($pointsNeeded > $balancePoints) {
            $maxMoney = Currency::format(round($balancePoints * $rules['point_value'], 2), $sale->currency ?? Currency::of($tenant));
            throw ValidationException::withMessages([
                'amount' => "Al cliente le alcanzan sus {$balancePoints} puntos para cubrir hasta {$maxMoney}.",
            ]);
        }

        self::recordPoints(
            tenant: $tenant,
            customerId: $sale->customer_id,
            type: 'redeem',
            points: -$pointsNeeded,
            actor: $actor,
            reason: "Canje en venta {$sale->code}",
            saleId: $sale->id,
            checkBalance: true,
            errorField: 'amount',
        );

        return "{$pointsNeeded} pts";
    }

    public static function earnForSale(Tenant $tenant, object $sale, ?User $actor = null): int
    {
        $rules = self::rules($tenant);

        if (
            !self::enabled($tenant)
            || !$rules['points_enabled']
            || ($sale->status ?? null) !== 'paid'
            || empty($sale->customer_id)
            || $rules['amount_per_point'] <= 0
        ) {
            return 0;
        }

        $already = DB::table('loyalty_transactions')
            ->where('tenant_id', $tenant->id)
            ->where('sale_id', $sale->id)
            ->where('type', 'earn')
            ->exists();

        if ($already) {
            return 0;
        }

        $realMoneyPaid = (float) DB::table('payments')
            ->where('tenant_id', $tenant->id)
            ->where('sale_id', $sale->id)
            ->where('status', 'confirmed')
            ->whereNotIn('method', ['points', 'gift_card'])
            ->sum('amount');

        $points = (int) floor(($realMoneyPaid + 0.001) / $rules['amount_per_point']);

        if ($points <= 0) {
            return 0;
        }

        self::recordPoints(
            tenant: $tenant,
            customerId: $sale->customer_id,
            type: 'earn',
            points: $points,
            actor: $actor,
            reason: "Compra {$sale->code}",
            saleId: $sale->id,
            checkBalance: false,
        );

        return $points;
    }

    public static function afterSaleRefunded(Tenant $tenant, object $sale, ?User $actor = null): void
    {
        if (!self::ready() || ($sale->status ?? null) !== 'refunded') {
            return;
        }

        if (!empty($sale->customer_id)) {
            $alreadyReversed = DB::table('loyalty_transactions')
                ->where('tenant_id', $tenant->id)
                ->where('sale_id', $sale->id)
                ->where('type', 'reversal')
                ->exists();

            if (!$alreadyReversed) {
                $earned = (int) DB::table('loyalty_transactions')
                    ->where('tenant_id', $tenant->id)
                    ->where('sale_id', $sale->id)
                    ->where('type', 'earn')
                    ->sum('points');

                if ($earned > 0) {
                    $current = self::customerPoints($tenant->id, $sale->customer_id);
                    $deduct = min($current, $earned);
                    if ($deduct > 0) {
                        self::recordPoints(
                            tenant: $tenant,
                            customerId: $sale->customer_id,
                            type: 'reversal',
                            points: -$deduct,
                            actor: $actor,
                            reason: "Reverso por devolución de venta {$sale->code}",
                            saleId: $sale->id,
                            checkBalance: false,
                        );
                    }
                }
            }
        }

        $gcRedemptions = DB::table('gift_card_transactions')
            ->where('tenant_id', $tenant->id)
            ->where('sale_id', $sale->id)
            ->where('type', 'redeem')
            ->get();

        foreach ($gcRedemptions as $tx) {
            $alreadyRefunded = DB::table('gift_card_transactions')
                ->where('tenant_id', $tenant->id)
                ->where('gift_card_id', $tx->gift_card_id)
                ->where('sale_id', $sale->id)
                ->where('type', 'refund')
                ->exists();

            if ($alreadyRefunded) {
                continue;
            }

            $gc = DB::table('gift_cards')->where('id', $tx->gift_card_id)->lockForUpdate()->first();
            if (!$gc) {
                continue;
            }

            $refundAmt = round((float) $tx->amount, 2);
            $newBalance = round((float) $gc->balance + $refundAmt, 2);

            DB::table('gift_cards')->where('id', $gc->id)->update([
                'balance'    => $newBalance,
                'status'     => $gc->status === 'redeemed' ? 'active' : $gc->status,
                'updated_at' => now(),
            ]);

            DB::table('gift_card_transactions')->insert([
                'id'           => (string) Str::uuid(),
                'tenant_id'    => $tenant->id,
                'gift_card_id' => $gc->id,
                'type'         => 'refund',
                'amount'       => $refundAmt,
                'sale_id'      => $sale->id,
                'created_at'   => now(),
            ]);
        }
    }

    public static function nextGiftCardCode(string $tenantId): string
    {
        do {
            $code = 'GC-' . Str::upper(Str::random(4)) . '-' . random_int(1000, 9999);
        } while (DB::table('gift_cards')->where('tenant_id', $tenantId)->where('code', $code)->exists());

        return $code;
    }
}
