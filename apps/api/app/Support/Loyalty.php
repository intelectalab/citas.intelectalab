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

    public const VIP_MULTIPLIER = 2;

    public const GIFT_CARD_MIN_SALE = 10;

    public const GIFT_CARD_QUICK_AMOUNTS = [50, 100, 150, 200, 300];

    public const REFERRAL_STATUSES = [
        'pending'  => 'Espera su primera atención',
        'rewarded' => 'Bono entregado',
        'void'     => 'Sin bono',
    ];

    public const TIERS = [
        'gold'   => ['label' => 'Oro', 'from' => 3000],
        'silver' => ['label' => 'Plata', 'from' => 1200],
        'bronze' => ['label' => 'Bronce', 'from' => 0],
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
            'vip_multiplier'          => max(1, min(5, round((float) (AppointmentFlow::rule($tenant, 'loyalty.vip_multiplier') ?? self::VIP_MULTIPLIER), 2))),
            'vip_spend'               => (float) AppointmentFlow::rule($tenant, 'crm.vip_spend'),
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

        self::expirePoints($tenantId, $customerId);

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
                ->where('code', 'not like', CustomerCredit::PREFIX . '%')
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

        if (CustomerCredit::isCredit($gc->code)) {
            throw ValidationException::withMessages(['reference' => 'Ese código es un saldo a favor del cliente: cóbralo con «Saldo a favor».']);
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

        $total = (float) ($sale->total ?? 0);
        $tip = (float) ($sale->tip ?? 0);
        $base = $total > 0 && $tip > 0 ? round($realMoneyPaid * max(0, $total - $tip) / $total, 2) : $realMoneyPaid;
        $points = (int) floor(($base + 0.001) / $rules['amount_per_point']);
        $vip = $points > 0 && $rules['vip_multiplier'] > 1 && self::isVip($tenant, $sale->customer_id);

        if ($vip) {
            $points = (int) floor($points * $rules['vip_multiplier']);
        }

        self::settleReferrals($tenant, $sale->customer_id, $actor);

        if ($points <= 0) {
            return 0;
        }

        $multiplier = rtrim(rtrim(number_format($rules['vip_multiplier'], 2, '.', ''), '0'), '.');

        self::recordPoints(
            tenant: $tenant,
            customerId: $sale->customer_id,
            type: 'earn',
            points: $points,
            actor: $actor,
            reason: "Compra {$sale->code}" . ($vip ? " (VIP x{$multiplier})" : ''),
            saleId: $sale->id,
            checkBalance: false,
        );

        return $points;
    }

    public static function isVip(Tenant $tenant, string $customerId): bool
    {
        return Campaigns::customers($tenant, ['group' => 'vip'])->where('c.id', $customerId)->exists();
    }

    public static function tierOf(int $earnedPoints, array $rules): array
    {
        $spent = $earnedPoints * $rules['amount_per_point'];

        foreach (self::TIERS as $key => $tier) {
            if ($spent >= $tier['from']) {
                return ['key' => $key, 'label' => $tier['label']];
            }
        }

        return ['key' => 'bronze', 'label' => 'Bronce'];
    }

    public static function referralsReady(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('customer_referrals');
    }

    public static function hasFirstVisit(string $tenantId, string $customerId): bool
    {
        $attended = DB::table('appointments')
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->where('status', 'completed')
            ->exists();

        return $attended || DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->whereIn('status', ['paid', 'partially_refunded'])
            ->exists();
    }

    public static function settleReferrals(Tenant $tenant, ?string $referredId, ?User $actor = null): int
    {
        if (!$referredId || !self::referralsReady() || !self::rules($tenant)['points_enabled']) {
            return 0;
        }

        $pending = DB::table('customer_referrals')
            ->where('tenant_id', $tenant->id)
            ->where('referred_customer_id', $referredId)
            ->where('status', 'pending')
            ->lockForUpdate()
            ->first();

        if (!$pending || !self::hasFirstVisit($tenant->id, $referredId)) {
            return 0;
        }

        $referrer = DB::table('customers')->where('tenant_id', $tenant->id)->where('id', $pending->referrer_customer_id)->whereNull('deleted_at')->exists();
        $referred = DB::table('customers')->where('id', $referredId)->first(['first_name', 'last_name']);

        if ($referrer && (int) $pending->bonus_points > 0) {
            self::recordPoints(
                tenant: $tenant,
                customerId: $pending->referrer_customer_id,
                type: 'referral',
                points: (int) $pending->bonus_points,
                actor: $actor,
                reason: 'Bono por referido: ' . Person::name($referred),
                checkBalance: false,
            );
        }

        DB::table('customer_referrals')->where('id', $pending->id)->update([
            'status'      => $referrer ? 'rewarded' : 'void',
            'rewarded_at' => now(),
            'updated_at'  => now(),
        ]);

        return $referrer ? (int) $pending->bonus_points : 0;
    }

    public static function settlePendingReferrals(Tenant $tenant): void
    {
        if (!self::referralsReady()) {
            return;
        }

        $ids = DB::table('customer_referrals')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'pending')
            ->limit(200)
            ->pluck('referred_customer_id');

        foreach ($ids as $id) {
            DB::transaction(fn () => self::settleReferrals($tenant, $id));
        }
    }

    public static function afterSaleRefunded(Tenant $tenant, object $sale, ?User $actor = null): void
    {
        if (!self::ready() || ($sale->status ?? null) !== 'refunded') {
            return;
        }

        self::reverseEarned($tenant, $sale, 1.0, $actor);
        self::giveBack($tenant, $sale, 1.0, $actor);
    }

    public static function reverseEarned(Tenant $tenant, object $sale, float $share, ?User $actor = null): int
    {
        if (!self::ready() || empty($sale->customer_id)) {
            return 0;
        }

        $rows = DB::table('loyalty_transactions')
            ->where('tenant_id', $tenant->id)
            ->where('sale_id', $sale->id)
            ->whereIn('type', ['earn', 'reversal'])
            ->get(['type', 'points']);

        $earned = (int) $rows->where('type', 'earn')->sum('points');
        $reversed = -(int) $rows->where('type', 'reversal')->sum('points');

        if ($earned <= 0) {
            return 0;
        }

        $share = max(0.0, min(1.0, $share));
        $target = $share >= 0.999 ? $earned : (int) round($earned * $share);
        $deduct = min($target - $reversed, self::customerPoints($tenant->id, $sale->customer_id));

        if ($deduct <= 0) {
            return 0;
        }

        self::recordPoints(
            tenant: $tenant,
            customerId: $sale->customer_id,
            type: 'reversal',
            points: -$deduct,
            actor: $actor,
            reason: ($share >= 0.999 ? 'Reverso por devolución de venta ' : 'Reverso por devolución parcial de venta ') . $sale->code,
            saleId: $sale->id,
            checkBalance: false,
        );

        return $deduct;
    }

    public static function paidWithLoyalty(string $tenantId, string $saleId): array
    {
        if (!self::ready()) {
            return ['gift_cards' => [], 'points' => 0, 'points_money' => 0.0, 'total' => 0.0];
        }

        $cards = DB::table('gift_card_transactions')
            ->where('tenant_id', $tenantId)
            ->where('sale_id', $saleId)
            ->whereIn('type', ['redeem', 'refund'])
            ->get(['gift_card_id', 'type', 'amount'])
            ->groupBy('gift_card_id')
            ->map(fn ($rows) => round($rows->sum(fn ($r) => $r->type === 'redeem' ? (float) $r->amount : -(float) $r->amount), 2))
            ->filter(fn ($left) => $left > 0.001)
            ->all();

        $redeemed = -(int) DB::table('loyalty_transactions')->where('tenant_id', $tenantId)->where('sale_id', $saleId)->where('type', 'redeem')->sum('points');
        $returned = (int) DB::table('loyalty_transactions')->where('tenant_id', $tenantId)->where('sale_id', $saleId)->where('type', 'adjust')->where('points', '>', 0)->sum('points');
        $pointsMoney = round((float) DB::table('payments')->where('tenant_id', $tenantId)->where('sale_id', $saleId)->where('method', 'points')->where('status', 'confirmed')->sum('amount'), 2);
        $points = max(0, $redeemed - $returned);
        $moneyLeft = $redeemed > 0 ? round($pointsMoney * $points / $redeemed, 2) : 0.0;

        return [
            'gift_cards'   => $cards,
            'points'       => $points,
            'points_money' => $moneyLeft,
            'total'        => round(array_sum($cards) + $moneyLeft, 2),
        ];
    }

    public static function giveBack(Tenant $tenant, object $sale, float $share, ?User $actor = null): float
    {
        $left = self::paidWithLoyalty($tenant->id, $sale->id);
        $share = max(0.0, min(1.0, $share));
        $given = 0.0;

        foreach ($left['gift_cards'] as $cardId => $amount) {
            $back = $share >= 1.0 ? $amount : round($amount * $share, 2);
            $gc = DB::table('gift_cards')->where('id', $cardId)->lockForUpdate()->first();

            if (!$gc || $back <= 0.001) {
                continue;
            }

            DB::table('gift_cards')->where('id', $gc->id)->update([
                'balance'    => round((float) $gc->balance + $back, 2),
                'status'     => $gc->status === 'redeemed' ? 'active' : $gc->status,
                'updated_at' => now(),
            ]);

            DB::table('gift_card_transactions')->insert([
                'id'           => (string) Str::uuid(),
                'tenant_id'    => $tenant->id,
                'gift_card_id' => $gc->id,
                'type'         => 'refund',
                'amount'       => $back,
                'sale_id'      => $sale->id,
                'created_at'   => now(),
            ]);
            $given += $back;
        }

        if ($left['points'] > 0 && !empty($sale->customer_id)) {
            $points = $share >= 1.0 ? $left['points'] : (int) round($left['points'] * $share);

            if ($points > 0) {
                self::recordPoints(
                    tenant: $tenant,
                    customerId: $sale->customer_id,
                    type: 'adjust',
                    points: $points,
                    actor: $actor,
                    reason: "Devolución de los puntos canjeados en la venta {$sale->code}",
                    saleId: $sale->id,
                    checkBalance: false,
                );
                $given += $share >= 1.0 ? $left['points_money'] : round($left['points_money'] * $points / $left['points'], 2);
            }
        }

        return round($given, 2);
    }

    public static function pointLots(string $tenantId, string $customerId): array
    {
        $rows = DB::table('loyalty_transactions')
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'type', 'points', 'expires_at', 'created_at']);

        $now = now();
        $events = [];
        $lots = [];

        foreach ($rows as $row) {
            $at = Carbon::parse($row->created_at);
            $points = (int) $row->points;

            if ($points > 0) {
                $lots[$row->id] = [
                    'points'     => $points,
                    'left'       => 0,
                    'expired'    => 0,
                    'expires_at' => $row->expires_at ? Carbon::parse($row->expires_at) : null,
                    'created_at' => $at,
                ];
                $events[] = [$at, 0, 'add', $row->id, $points];

                if ($lots[$row->id]['expires_at'] && $lots[$row->id]['expires_at']->lte($now)) {
                    $events[] = [$lots[$row->id]['expires_at'], 2, 'expire', $row->id, 0];
                }
            } elseif ($row->type !== 'expire') {
                $events[] = [$at, 1, 'use', $row->id, -$points];
            }
        }

        usort($events, fn ($a, $b) => [$a[0]->getTimestamp(), $a[1]] <=> [$b[0]->getTimestamp(), $b[1]]);
        $expiredTotal = 0;

        foreach ($events as [$at, , $kind, $id, $amount]) {
            if ($kind === 'add') {
                $lots[$id]['left'] = $amount;
                continue;
            }

            if ($kind === 'expire') {
                $lots[$id]['expired'] = $lots[$id]['left'];
                $expiredTotal += $lots[$id]['left'];
                $lots[$id]['left'] = 0;
                continue;
            }

            $alive = array_filter($lots, fn ($lot) => $lot['left'] > 0 && $lot['created_at']->lte($at));
            uasort($alive, fn ($a, $b) => [$a['expires_at'] ? 0 : 1, $a['expires_at']?->getTimestamp() ?? 0, $a['created_at']->getTimestamp()]
                <=> [$b['expires_at'] ? 0 : 1, $b['expires_at']?->getTimestamp() ?? 0, $b['created_at']->getTimestamp()]);

            foreach (array_keys($alive) as $lotId) {
                if ($amount <= 0) {
                    break;
                }
                $take = min($amount, $lots[$lotId]['left']);
                $lots[$lotId]['left'] -= $take;
                $amount -= $take;
            }
        }

        return ['expired_total' => $expiredTotal, 'lots' => $lots];
    }

    public static function expirePoints(string $tenantId, string $customerId): int
    {
        if (!self::ready()) {
            return 0;
        }

        $state = self::pointLots($tenantId, $customerId);

        if ($state['expired_total'] <= 0) {
            return 0;
        }

        $recorded = (int) abs((int) DB::table('loyalty_transactions')->where('tenant_id', $tenantId)->where('customer_id', $customerId)->where('type', 'expire')->sum('points'));
        $current = (int) DB::table('loyalty_transactions')->where('tenant_id', $tenantId)->where('customer_id', $customerId)->sum('points');
        $due = min($state['expired_total'] - $recorded, $current);

        if ($due <= 0) {
            return 0;
        }

        DB::table('loyalty_transactions')->insert([
            'id'            => (string) Str::uuid(),
            'tenant_id'     => $tenantId,
            'customer_id'   => $customerId,
            'type'          => 'expire',
            'points'        => -$due,
            'balance_after' => max(0, $current - $due),
            'sale_id'       => null,
            'reason'        => $due === 1 ? 'Venció 1 punto' : "Vencieron {$due} puntos",
            'expires_at'    => null,
            'actor_user_id' => null,
            'created_at'    => now(),
        ]);

        return $due;
    }

    public static function expirePointsDue(string $tenantId): void
    {
        if (!self::ready()) {
            return;
        }

        $customers = DB::table('loyalty_transactions')
            ->where('tenant_id', $tenantId)
            ->where('points', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->toIso8601String())
            ->distinct()
            ->pluck('customer_id');

        foreach ($customers as $customerId) {
            self::expirePoints($tenantId, $customerId);
        }
    }

    public static function lotStates(string $tenantId, array $customerIds): array
    {
        $states = [];

        foreach (array_unique($customerIds) as $customerId) {
            foreach (self::pointLots($tenantId, $customerId)['lots'] as $id => $lot) {
                $states[$id] = ['left' => $lot['left'], 'expired' => $lot['expired']];
            }
        }

        return $states;
    }

    public static function expiringSoon(string $tenantId, string $customerId, int $limit = 5): array
    {
        return collect(self::pointLots($tenantId, $customerId)['lots'])
            ->filter(fn ($lot) => $lot['left'] > 0 && $lot['expires_at'] && $lot['expires_at']->isFuture())
            ->groupBy(fn ($lot) => $lot['expires_at']->copy()->utc()->toIso8601String())
            ->map(fn ($group, $at) => ['expires_at' => $at, 'points' => (int) $group->sum('left')])
            ->sortBy('expires_at')
            ->take($limit)
            ->values()
            ->all();
    }

    public static function giftCardLine(Tenant $tenant, array $item, string $field, string $currency): array
    {
        if (!self::enabled($tenant) || !self::rules($tenant)['gift_cards_enabled']) {
            throw ValidationException::withMessages([$field => self::ready() ? 'Las gift cards no están activas en tu empresa.' : self::NOT_READY]);
        }

        $details = (array) ($item['details'] ?? []);
        $amount = round((float) ($details['amount'] ?? 0), 2);

        if ($amount < self::GIFT_CARD_MIN_SALE || $amount > 999999) {
            throw ValidationException::withMessages([$field => 'La gift card se vende desde ' . Currency::format(self::GIFT_CARD_MIN_SALE, $currency) . '.']);
        }

        if ((int) ($item['quantity'] ?? 1) > 1) {
            throw ValidationException::withMessages([$field => 'Una gift card se vende de a una. Si quieren dos, agrégala otra vez.']);
        }

        $name = mb_substr(trim((string) ($details['recipient_name'] ?? '')), 0, 150) ?: null;
        $email = mb_substr(trim((string) ($details['recipient_email'] ?? '')), 0, 250) ?: null;

        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([$field => 'Escribe un correo válido para quien recibe la gift card.']);
        }

        return [
            'type'            => 'gift_card',
            'id'              => $item['id'],
            'item_type'       => 'gift_card',
            'reference_id'    => null,
            'description'     => 'Gift card' . ($name ? " para {$name}" : ''),
            'quantity'        => 1,
            'unit_price'      => $amount,
            'professional_id' => null,
            'professional'    => null,
            'appointment_id'  => null,
            'appointment'     => null,
            'no_membership'   => true,
            'details'         => ['amount' => $amount, 'recipient_name' => $name, 'recipient_email' => $email],
        ];
    }

    public static function issueForSale(Tenant $tenant, object $sale, ?User $actor = null): array
    {
        if (!self::ready() || ($sale->status ?? null) !== 'paid') {
            return [];
        }

        $lines = DB::table('sale_items')->where('sale_id', $sale->id)->where('item_type', 'gift_card')->whereNull('reference_id')->get();
        $issued = [];
        $days = self::rules($tenant)['gift_card_validity_days'];

        foreach ($lines as $line) {
            $details = json_decode((string) ($line->details ?? ''), true) ?: [];
            $amount = round((float) ($details['amount'] ?? $line->unit_price), 2);

            if ($amount <= 0) {
                continue;
            }

            $id = (string) Str::uuid();
            $code = self::nextGiftCardCode($tenant->id);
            $expires = $days > 0 ? now()->addDays($days) : null;

            DB::table('gift_cards')->insert([
                'id'                    => $id,
                'tenant_id'             => $tenant->id,
                'code'                  => $code,
                'initial_amount'        => $amount,
                'balance'               => $amount,
                'purchaser_customer_id' => $sale->customer_id,
                'recipient_customer_id' => null,
                'recipient_name'        => $details['recipient_name'] ?? null,
                'recipient_email'       => $details['recipient_email'] ?? null,
                'sale_id'               => $sale->id,
                'status'                => 'active',
                'expires_at'            => $expires,
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);

            DB::table('gift_card_transactions')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'gift_card_id' => $id,
                'type' => 'issue', 'amount' => $amount, 'sale_id' => $sale->id, 'created_at' => now(),
            ]);

            DB::table('sale_items')->where('id', $line->id)->update(['reference_id' => $id]);

            Audit::log(request(), 'gift_card.issued', [
                'tenant' => $tenant->id, 'entity_type' => 'gift_card', 'entity_id' => $id, 'entity_label' => $code, 'branch_id' => $sale->branch_id,
                'after'  => array_filter(['code' => $code, 'amount' => $amount, 'sale' => $sale->code, 'recipient' => $details['recipient_name'] ?? null, 'ends_at' => $expires?->toIso8601String()], fn ($v) => $v !== null),
            ]);

            $issued[] = ['code' => $code, 'amount' => $amount, 'recipient_name' => $details['recipient_name'] ?? null, 'expires_at' => $expires?->toIso8601String()];
        }

        return $issued;
    }

    public static function usedFromSale(string $saleId): float
    {
        if (!self::ready()) {
            return 0.0;
        }

        return round((float) DB::table('gift_cards')
            ->where('sale_id', $saleId)
            ->where('status', '!=', 'cancelled')
            ->whereRaw('balance < initial_amount - 0.001')
            ->sum('initial_amount'), 2);
    }

    public static function cancelUncoveredGiftCards(Tenant $tenant, object $sale, ?User $actor = null): void
    {
        if (!self::ready()) {
            return;
        }

        $unused = DB::table('gift_cards')
            ->where('tenant_id', $tenant->id)
            ->where('sale_id', $sale->id)
            ->where('status', 'active')
            ->whereRaw('balance >= initial_amount - 0.001')
            ->orderByDesc('initial_amount')
            ->lockForUpdate()
            ->get();

        if ($unused->isEmpty()) {
            return;
        }

        $room = round((float) $sale->paid - self::usedFromSale($sale->id), 2);
        $total = round((float) $unused->sum('initial_amount'), 2);

        foreach ($unused as $card) {
            if ($total <= $room + 0.001) {
                break;
            }

            DB::table('gift_cards')->where('id', $card->id)->update(['balance' => 0, 'status' => 'cancelled', 'updated_at' => now()]);
            DB::table('gift_card_transactions')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'gift_card_id' => $card->id,
                'type' => 'cancel', 'amount' => round((float) $card->balance, 2), 'sale_id' => $sale->id, 'created_at' => now(),
            ]);

            Audit::log(request(), 'gift_card.cancelled', [
                'tenant' => $tenant->id, 'entity_type' => 'gift_card', 'entity_id' => $card->id, 'entity_label' => $card->code, 'branch_id' => $sale->branch_id,
                'before' => ['status' => self::GIFT_CARD_STATUSES['active'], 'balance' => round((float) $card->balance, 2)],
                'after'  => ['status' => self::GIFT_CARD_STATUSES['cancelled'], 'balance' => 0, 'reason' => "Se devolvió la venta {$sale->code}"],
            ]);

            $total = round($total - (float) $card->initial_amount, 2);
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
