<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Audit;
use App\Support\Currency;
use App\Support\Loyalty;
use App\Support\Person;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LoyaltyController extends Controller
{
    private function tenant(Request $request): Tenant
    {
        abort_unless(Loyalty::ready(), 503, Loyalty::NOT_READY);
        Loyalty::ensurePermissions();

        return $request->user()->tenant;
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();
        Loyalty::expireDue($tenant->id);

        $rules = Loyalty::rules($tenant);
        $iso = fn ($value) => $value ? Carbon::parse($value)->toIso8601String() : null;

        $txStats = DB::table('loyalty_transactions')
            ->where('tenant_id', $tenant->id)
            ->selectRaw("
                customer_id,
                COUNT(*) as movements_count,
                COALESCE(SUM(CASE WHEN points > 0 THEN points ELSE 0 END), 0) as earned,
                COALESCE(SUM(CASE WHEN points < 0 AND type = 'redeem' THEN ABS(points) ELSE 0 END), 0) as redeemed,
                MAX(created_at) as last_movement_at
            ")
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');

        $latestBalances = DB::table('loyalty_transactions as lt')
            ->where('lt.tenant_id', $tenant->id)
            ->whereRaw('lt.created_at = (SELECT MAX(lt2.created_at) FROM loyalty_transactions lt2 WHERE lt2.tenant_id = lt.tenant_id AND lt2.customer_id = lt.customer_id)')
            ->pluck('lt.balance_after', 'lt.customer_id');

        $customers = DB::table('customers')
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(600)
            ->get(['id', 'first_name', 'last_name', 'document_number', 'email', 'phone'])
            ->map(function ($c) use ($txStats, $latestBalances, $rules, $iso) {
                $stat = $txStats[$c->id] ?? null;
                $points = max(0, (int) ($latestBalances[$c->id] ?? 0));
                $earned = (int) ($stat->earned ?? 0);
                $redeemed = (int) ($stat->redeemed ?? 0);
                $movementsCount = (int) ($stat->movements_count ?? 0);

                return [
                    'id'               => $c->id,
                    'name'             => Person::name($c),
                    'document_number'  => $c->document_number,
                    'email'            => $c->email,
                    'phone'            => $c->phone,
                    'points'           => $points,
                    'earned'           => $earned,
                    'redeemed'         => $redeemed,
                    'movements_count'  => $movementsCount,
                    'redeemable_value' => round($points * $rules['point_value'], 2),
                    'can_redeem'       => $points >= $rules['min_redeem_points'],
                    'last_movement_at' => $iso($stat->last_movement_at ?? null),
                ];
            })
            ->sortByDesc(fn ($c) => [$c['points'] > 0 ? 1 : 0, $c['points'], $c['movements_count']])
            ->values();

        $pointMovements = DB::table('loyalty_transactions as lt')
            ->join('customers as c', 'c.id', '=', 'lt.customer_id')
            ->leftJoin('sales as s', 's.id', '=', 'lt.sale_id')
            ->leftJoin('users as u', 'u.id', '=', 'lt.actor_user_id')
            ->where('lt.tenant_id', $tenant->id)
            ->orderByDesc('lt.created_at')
            ->limit(250)
            ->get([
                'lt.id',
                'lt.customer_id',
                'lt.type',
                'lt.points',
                'lt.balance_after',
                'lt.reason',
                'lt.expires_at',
                'lt.created_at',
                'c.first_name as customer_first_name',
                'c.last_name as customer_last_name',
                'c.document_number as customer_document',
                's.code as sale_code',
                'u.first_name as user_first_name',
                'u.last_name as user_last_name',
            ])
            ->map(fn ($m) => [
                'id'                => $m->id,
                'customer_id'       => $m->customer_id,
                'customer_name'     => trim(($m->customer_first_name ?? '') . ' ' . ($m->customer_last_name ?? '')),
                'customer_document' => $m->customer_document,
                'type'              => $m->type,
                'type_label'        => Loyalty::POINT_TYPES[$m->type] ?? $m->type,
                'points'            => (int) $m->points,
                'balance_after'     => (int) $m->balance_after,
                'reason'            => $m->reason,
                'sale_code'         => $m->sale_code,
                'expires_at'        => $iso($m->expires_at),
                'actor'             => trim(($m->user_first_name ?? '') . ' ' . ($m->user_last_name ?? '')) ?: null,
                'created_at'        => $iso($m->created_at),
            ])
            ->values();

        $giftCards = DB::table('gift_cards as gc')
            ->leftJoin('customers as p', 'p.id', '=', 'gc.purchaser_customer_id')
            ->leftJoin('customers as r', 'r.id', '=', 'gc.recipient_customer_id')
            ->leftJoin('sales as s', 's.id', '=', 'gc.sale_id')
            ->where('gc.tenant_id', $tenant->id)
            ->orderByDesc('gc.created_at')
            ->limit(300)
            ->get([
                'gc.*',
                'p.first_name as purchaser_first_name',
                'p.last_name as purchaser_last_name',
                'r.first_name as recipient_first_name',
                'r.last_name as recipient_last_name',
                's.code as sale_code',
            ])
            ->map(function ($gc) use ($iso) {
                $expires = $gc->expires_at ? Carbon::parse($gc->expires_at) : null;
                $daysLeft = $expires ? (int) now()->startOfDay()->diffInDays($expires->copy()->startOfDay(), false) : null;
                $recipientCustomer = trim(($gc->recipient_first_name ?? '') . ' ' . ($gc->recipient_last_name ?? '')) ?: null;
                $purchaserCustomer = trim(($gc->purchaser_first_name ?? '') . ' ' . ($gc->purchaser_last_name ?? '')) ?: null;

                return [
                    'id'                    => $gc->id,
                    'code'                  => $gc->code,
                    'initial_amount'        => round((float) $gc->initial_amount, 2),
                    'balance'               => round((float) $gc->balance, 2),
                    'used_amount'           => max(0, round((float) $gc->initial_amount - (float) $gc->balance, 2)),
                    'status'                => $gc->status,
                    'status_label'          => Loyalty::GIFT_CARD_STATUSES[$gc->status] ?? $gc->status,
                    'purchaser_customer_id' => $gc->purchaser_customer_id,
                    'purchaser_name'        => $purchaserCustomer,
                    'recipient_customer_id' => $gc->recipient_customer_id,
                    'recipient_name'        => $gc->recipient_name ?: $recipientCustomer,
                    'recipient_email'       => $gc->recipient_email,
                    'sale_code'             => $gc->sale_code,
                    'expires_at'            => $iso($gc->expires_at),
                    'days_left'             => $daysLeft,
                    'created_at'            => $iso($gc->created_at),
                ];
            })
            ->values();

        $giftCardMovements = DB::table('gift_card_transactions as gct')
            ->join('gift_cards as gc', 'gc.id', '=', 'gct.gift_card_id')
            ->leftJoin('sales as s', 's.id', '=', 'gct.sale_id')
            ->where('gct.tenant_id', $tenant->id)
            ->orderByDesc('gct.created_at')
            ->limit(250)
            ->get([
                'gct.id',
                'gct.gift_card_id',
                'gct.type',
                'gct.amount',
                'gct.created_at',
                'gc.code as gift_card_code',
                'gc.recipient_name',
                's.code as sale_code',
            ])
            ->map(fn ($m) => [
                'id'             => $m->id,
                'gift_card_id'   => $m->gift_card_id,
                'gift_card_code' => $m->gift_card_code,
                'recipient_name' => $m->recipient_name,
                'type'           => $m->type,
                'type_label'     => Loyalty::GIFT_CARD_TX_TYPES[$m->type] ?? $m->type,
                'amount'         => round((float) $m->amount, 2),
                'sale_code'      => $m->sale_code,
                'created_at'     => $iso($m->created_at),
            ])
            ->values();

        return response()->json([
            'currency'            => Currency::of($tenant),
            'rules'               => $rules,
            'can'                 => [
                'create' => $user->allows('loyalty.create'),
                'edit'   => $user->allows('loyalty.edit'),
                'delete' => $user->allows('loyalty.delete'),
            ],
            'customers'           => $customers,
            'point_movements'     => $pointMovements,
            'gift_cards'          => $giftCards,
            'gift_card_movements' => $giftCardMovements,
        ]);
    }

    private function customerName(?string $customerId): ?string
    {
        $customer = $customerId ? DB::table('customers')->where('id', $customerId)->first(['first_name', 'last_name']) : null;

        return Person::name($customer) ?: null;
    }

    private function auditGiftCard(Request $request, Tenant $tenant, string $action, object $card, array $after, array $before = []): void
    {
        Audit::log($request, $action, [
            'tenant'       => $tenant->id,
            'entity_type'  => 'gift_card',
            'entity_id'    => $card->id,
            'entity_label' => $card->code,
            'before'       => array_filter($before, fn ($value) => $value !== null) ?: null,
            'after'        => array_filter($after, fn ($value) => $value !== null),
        ]);
    }

    public function adjustPoints(Request $request)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();
        $rules = Loyalty::rules($tenant);

        $validated = $request->validate([
            'customer_id' => 'required|uuid',
            'type'        => ['required', Rule::in(['earn', 'redeem', 'adjust', 'referral'])],
            'direction'   => ['nullable', Rule::in(['add', 'subtract'])],
            'points'      => 'required|integer|min:1|max:999999',
            'reason'      => 'required|string|max:255',
        ], [
            'customer_id.required' => 'Elige al cliente.',
            'points.required'      => 'Escribe la cantidad de puntos.',
            'points.min'           => 'La cantidad debe ser al menos 1 punto.',
            'reason.required'      => 'Escribe el motivo del movimiento.',
        ]);

        $customerExists = DB::table('customers')
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->where('id', $validated['customer_id'])
            ->exists();

        if (!$customerExists) {
            throw ValidationException::withMessages(['customer_id' => 'Ese cliente no pertenece a tu empresa.']);
        }

        $absPoints = abs((int) $validated['points']);
        $subtract = $validated['type'] === 'redeem' || ($validated['type'] === 'adjust' && ($validated['direction'] ?? 'add') === 'subtract');
        $delta = $subtract ? -$absPoints : $absPoints;

        DB::transaction(function () use ($tenant, $validated, $delta, $absPoints, $rules, $user) {
            $current = Loyalty::customerPoints($tenant->id, $validated['customer_id']);

            if ($delta < 0 && $current < $absPoints) {
                throw ValidationException::withMessages([
                    'points' => "El cliente tiene {$current} pts disponibles y no alcanza para descontar {$absPoints} pts.",
                ]);
            }

            if ($validated['type'] === 'redeem' && $current < $rules['min_redeem_points']) {
                throw ValidationException::withMessages([
                    'points' => "El mínimo configurado para canjear es de {$rules['min_redeem_points']} puntos.",
                ]);
            }

            $expiresAt = $delta > 0 && $rules['points_validity_days'] > 0
                ? now()->addDays($rules['points_validity_days'])
                : null;

            Loyalty::recordPoints(
                tenant: $tenant,
                customerId: $validated['customer_id'],
                type: $validated['type'],
                points: $delta,
                actor: $user,
                reason: trim($validated['reason']),
                saleId: null,
                expiresAt: $expiresAt,
            );
        });

        $customer = $this->customerName($validated['customer_id']);
        Audit::log($request, 'loyalty.points_adjusted', [
            'tenant'       => $tenant->id,
            'entity_type'  => 'customer',
            'entity_id'    => $validated['customer_id'],
            'entity_label' => $customer,
            'after'        => array_filter([
                'type'     => Loyalty::POINT_TYPES[$validated['type']] ?? $validated['type'],
                'points'   => $delta,
                'reason'   => trim($validated['reason']),
                'customer' => $customer,
            ], fn ($value) => $value !== null),
        ]);

        return $this->index($request);
    }

    public function storeGiftCard(Request $request)
    {
        $tenant = $this->tenant($request);
        $rules = Loyalty::rules($tenant);

        $validated = $request->validate([
            'code'                  => 'nullable|string|max:40',
            'initial_amount'        => 'required|numeric|min:1|max:999999',
            'purchaser_customer_id' => 'nullable|uuid',
            'recipient_customer_id' => 'nullable|uuid',
            'recipient_name'        => 'nullable|string|max:150',
            'recipient_email'       => 'nullable|email|max:250',
            'validity_days'         => 'nullable|integer|min:0|max:3650',
        ], [
            'initial_amount.required' => 'Escribe el monto inicial de la gift card.',
            'initial_amount.min'      => 'El monto inicial debe ser mayor que cero.',
            'recipient_email.email'   => 'Escribe un correo válido para el destinatario.',
        ]);

        $code = isset($validated['code']) && trim($validated['code']) !== ''
            ? mb_strtoupper(trim($validated['code']))
            : Loyalty::nextGiftCardCode($tenant->id);

        $exists = DB::table('gift_cards')
            ->where('tenant_id', $tenant->id)
            ->whereRaw('UPPER(code) = ?', [$code])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['code' => "Ya existe una gift card con el código {$code}."]);
        }

        $recipientName = isset($validated['recipient_name']) ? trim($validated['recipient_name']) : null;
        $recipientEmail = isset($validated['recipient_email']) ? trim($validated['recipient_email']) : null;

        if (!empty($validated['recipient_customer_id'])) {
            $recipient = DB::table('customers')
                ->where('tenant_id', $tenant->id)
                ->where('id', $validated['recipient_customer_id'])
                ->first(['first_name', 'last_name', 'email']);

            if ($recipient) {
                $recipientName = $recipientName ?: Person::name($recipient);
                $recipientEmail = $recipientEmail ?: $recipient->email;
            }
        }

        $validityDays = array_key_exists('validity_days', $validated) && $validated['validity_days'] !== null
            ? (int) $validated['validity_days']
            : $rules['gift_card_validity_days'];

        $expiresAt = $validityDays > 0 ? now()->addDays($validityDays) : null;
        $amount = round((float) $validated['initial_amount'], 2);

        $id = DB::transaction(function () use ($tenant, $code, $amount, $validated, $recipientName, $recipientEmail, $expiresAt) {
            $id = (string) Str::uuid();

            DB::table('gift_cards')->insert([
                'id'                    => $id,
                'tenant_id'             => $tenant->id,
                'code'                  => $code,
                'initial_amount'        => $amount,
                'balance'               => $amount,
                'purchaser_customer_id' => $validated['purchaser_customer_id'] ?? null,
                'recipient_customer_id' => $validated['recipient_customer_id'] ?? null,
                'recipient_name'        => $recipientName ?: null,
                'recipient_email'       => $recipientEmail ?: null,
                'sale_id'               => null,
                'status'                => 'active',
                'expires_at'            => $expiresAt,
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);

            DB::table('gift_card_transactions')->insert([
                'id'           => (string) Str::uuid(),
                'tenant_id'    => $tenant->id,
                'gift_card_id' => $id,
                'type'         => 'issue',
                'amount'       => $amount,
                'sale_id'      => null,
                'created_at'   => now(),
            ]);

            return $id;
        });

        $this->auditGiftCard($request, $tenant, 'gift_card.issued', (object) ['id' => $id, 'code' => $code], [
            'code'       => $code,
            'amount'     => $amount,
            'purchaser'  => $this->customerName($validated['purchaser_customer_id'] ?? null),
            'recipient'  => $recipientName ?: null,
            'ends_at'    => $expiresAt?->toIso8601String(),
        ]);

        return $this->index($request);
    }

    public function redeemGiftCard(Request $request, string $giftCard)
    {
        $tenant = $this->tenant($request);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:999999',
        ], [
            'amount.required' => 'Escribe el monto a descontar.',
            'amount.min'      => 'El monto debe ser mayor que cero.',
        ]);

        [$card, $amount, $newBalance] = DB::transaction(function () use ($tenant, $giftCard, $validated) {
            $card = DB::table('gift_cards')
                ->where('tenant_id', $tenant->id)
                ->where('id', $giftCard)
                ->lockForUpdate()
                ->first();

            abort_unless($card, 404);

            if ($card->status !== 'active') {
                $statusLabel = mb_strtolower(Loyalty::GIFT_CARD_STATUSES[$card->status] ?? $card->status);
                throw ValidationException::withMessages(['amount' => "Esta gift card no está activa ({$statusLabel})."]);
            }

            $amount = round((float) $validated['amount'], 2);
            $balance = round((float) $card->balance, 2);

            if ($amount > $balance + 0.001) {
                throw ValidationException::withMessages([
                    'amount' => 'El monto supera el saldo disponible de ' . Currency::format($balance, Currency::of($tenant)) . '.',
                ]);
            }

            $newBalance = max(0, round($balance - $amount, 2));

            DB::table('gift_cards')->where('id', $card->id)->update([
                'balance'    => $newBalance,
                'status'     => $newBalance <= 0.001 ? 'redeemed' : 'active',
                'updated_at' => now(),
            ]);

            DB::table('gift_card_transactions')->insert([
                'id'           => (string) Str::uuid(),
                'tenant_id'    => $tenant->id,
                'gift_card_id' => $card->id,
                'type'         => 'redeem',
                'amount'       => $amount,
                'sale_id'      => null,
                'created_at'   => now(),
            ]);

            return [$card, $amount, $newBalance];
        });

        $this->auditGiftCard($request, $tenant, 'gift_card.discounted', $card, ['amount' => $amount, 'balance' => $newBalance], ['balance' => round((float) $card->balance, 2)]);

        return $this->index($request);
    }

    public function extendGiftCard(Request $request, string $giftCard)
    {
        $tenant = $this->tenant($request);

        $validated = $request->validate([
            'days' => 'required|integer|min:1|max:1095',
        ], [
            'days.required' => 'Escribe los días a ampliar.',
            'days.min'      => 'Amplía al menos 1 día.',
        ]);

        [$card, $newExpires] = DB::transaction(function () use ($tenant, $giftCard, $validated) {
            $card = DB::table('gift_cards')
                ->where('tenant_id', $tenant->id)
                ->where('id', $giftCard)
                ->lockForUpdate()
                ->first();

            abort_unless($card, 404);

            if ($card->status === 'cancelled') {
                throw ValidationException::withMessages(['days' => 'Una gift card anulada no puede ampliarse.']);
            }

            $base = $card->expires_at && Carbon::parse($card->expires_at)->isFuture()
                ? Carbon::parse($card->expires_at)
                : now();

            $newExpires = $base->copy()->addDays((int) $validated['days']);
            $newStatus = $card->status === 'expired' && (float) $card->balance > 0.001 ? 'active' : $card->status;

            DB::table('gift_cards')->where('id', $card->id)->update([
                'expires_at' => $newExpires,
                'status'     => $newStatus,
                'updated_at' => now(),
            ]);

            DB::table('gift_card_transactions')->insert([
                'id'           => (string) Str::uuid(),
                'tenant_id'    => $tenant->id,
                'gift_card_id' => $card->id,
                'type'         => 'extend',
                'amount'       => round((float) $card->balance, 2),
                'sale_id'      => null,
                'created_at'   => now(),
            ]);

            return [$card, $newExpires];
        });

        $this->auditGiftCard($request, $tenant, 'gift_card.extended', $card, [
            'days' => (int) $validated['days'], 'ends_at' => $newExpires->toIso8601String(),
        ], ['ends_at' => $card->expires_at ? Carbon::parse($card->expires_at)->toIso8601String() : null]);

        return $this->index($request);
    }

    public function cancelGiftCard(Request $request, string $giftCard)
    {
        $tenant = $this->tenant($request);

        [$card, $remaining] = DB::transaction(function () use ($tenant, $giftCard) {
            $card = DB::table('gift_cards')
                ->where('tenant_id', $tenant->id)
                ->where('id', $giftCard)
                ->lockForUpdate()
                ->first();

            abort_unless($card, 404);

            if ($card->status === 'cancelled') {
                throw ValidationException::withMessages(['gift_card' => 'Esta gift card ya está anulada.']);
            }

            $remaining = round((float) $card->balance, 2);

            DB::table('gift_cards')->where('id', $card->id)->update([
                'balance'    => 0,
                'status'     => 'cancelled',
                'updated_at' => now(),
            ]);

            DB::table('gift_card_transactions')->insert([
                'id'           => (string) Str::uuid(),
                'tenant_id'    => $tenant->id,
                'gift_card_id' => $card->id,
                'type'         => 'cancel',
                'amount'       => $remaining,
                'sale_id'      => null,
                'created_at'   => now(),
            ]);

            return [$card, $remaining];
        });

        $this->auditGiftCard($request, $tenant, 'gift_card.cancelled', $card, [
            'status' => Loyalty::GIFT_CARD_STATUSES['cancelled'], 'balance' => 0,
        ], ['status' => Loyalty::GIFT_CARD_STATUSES[$card->status] ?? $card->status, 'balance' => $remaining]);

        return $this->index($request);
    }
}
