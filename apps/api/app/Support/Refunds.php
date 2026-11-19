<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Refunds
{
    public const REQUESTS_NOT_READY = 'Falta actualizar la base de datos para aprobar devoluciones: ejecuta php artisan migrate.';

    public const REQUEST_LABELS = ['pending' => 'Esperando aprobación', 'approved' => 'Aprobada', 'rejected' => 'Rechazada'];

    public static function requestsReady(): bool
    {
        static $ready = null;

        return $ready ??= \Illuminate\Support\Facades\Schema::hasTable('refund_requests');
    }

    public static function needsApproval(Tenant $tenant, float $amount): bool
    {
        $over = Sales::rules($tenant)['refund_approval_over'];

        if ($over <= 0 || round($amount, 2) <= $over + 0.001) {
            return false;
        }

        abort_unless(self::requestsReady(), 503, self::REQUESTS_NOT_READY);

        return true;
    }

    public static function hasPendingRequest(string $saleId): bool
    {
        return self::requestsReady() && DB::table('refund_requests')->where('sale_id', $saleId)->where('status', 'pending')->exists();
    }

    public static function request(Tenant $tenant, string $saleId, array $data, User $user): string
    {
        $sale = DB::table('sales')->where('tenant_id', $tenant->id)->where('id', $saleId)->lockForUpdate()->first();
        abort_unless($sale, 404);

        $state = self::availability($tenant, $sale);

        if ($state['blocked']) {
            throw ValidationException::withMessages(['amount' => $state['blocked']]);
        }

        $amount = round((float) $data['amount'], 2);

        if ($amount <= 0 || $amount > $state['available']) {
            throw ValidationException::withMessages(['amount' => 'El monto debe ser mayor que cero y no pasar de ' . Currency::format($state['available'], $sale->currency ?? null) . ', que es lo que queda por devolver.']);
        }

        if ($data['method'] === 'gateway' && PaymentLinks::gatewayPaid($sale->id) <= 0) {
            throw ValidationException::withMessages(['method' => 'Esta venta no se pagó por la pasarela.']);
        }

        $id = (string) Str::uuid();
        DB::table('refund_requests')->insert([
            'id' => $id, 'tenant_id' => $tenant->id, 'sale_id' => $sale->id, 'branch_id' => $sale->branch_id,
            'amount' => $amount, 'currency' => $sale->currency ?? Currency::of($tenant), 'method' => $data['method'],
            'cash_session_id' => $data['cash_session_id'] ?? null, 'reason' => mb_substr($data['reason'], 0, 255),
            'status' => 'pending', 'requested_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public static function requestsOf(string $saleId, User $viewer): array
    {
        if (!self::requestsReady()) {
            return [];
        }

        $rows = DB::table('refund_requests')->where('sale_id', $saleId)->orderByDesc('created_at')->get();
        $names = DB::table('users')->whereIn('id', $rows->pluck('requested_by')->merge($rows->pluck('reviewed_by'))->filter()->unique()->values())
            ->get(['id', 'first_name', 'last_name'])->mapWithKeys(fn ($u) => [$u->id => Person::name($u)]);
        $iso = fn ($value) => $value ? Carbon::parse($value)->toIso8601String() : null;

        return $rows->map(fn ($r) => [
            'id'           => $r->id,
            'amount'       => round((float) $r->amount, 2),
            'currency'     => $r->currency,
            'method_label' => (Sales::METHODS + Sales::OTHER_METHODS + ['gateway' => 'Por la pasarela'])[$r->method] ?? $r->method,
            'reason'       => $r->reason,
            'status'       => $r->status,
            'status_label' => self::REQUEST_LABELS[$r->status] ?? $r->status,
            'requested_by' => $names[$r->requested_by] ?? null,
            'requested_at' => $iso($r->created_at),
            'reviewed_by'  => $r->reviewed_by ? ($names[$r->reviewed_by] ?? null) : null,
            'reviewed_at'  => $iso($r->reviewed_at),
            'review_notes' => $r->review_notes,
            'mine'         => $r->requested_by === $viewer->id,
            'can_review'   => $r->status === 'pending' && $r->requested_by !== $viewer->id && $viewer->allows('sales.approve'),
        ])->values()->all();
    }

    public static function notifyRequested(Tenant $tenant, string $requestId, User $actor): void
    {
        try {
            if (!Notifier::available()) {
                return;
            }

            $row = DB::table('refund_requests')->where('id', $requestId)->first();
            $sale = DB::table('sales')->where('id', $row->sale_id)->first(['id', 'code']);
            $body = Person::name($actor) . ' pide devolver ' . Currency::format((float) $row->amount, $row->currency) . " de la venta {$sale->code}: {$row->reason}";

            foreach (Notifier::staffWith($tenant->id, 'sales.approve', $row->branch_id) as $userId) {
                if ($userId !== $actor->id) {
                    Notifier::send($tenant->id, $userId, 'refund.requested', 'Devolución por aprobar', $body, "/sales?venta={$sale->id}", 'sale', $sale->id);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function notifyReviewed(Tenant $tenant, object $row, bool $approved, User $actor, ?string $notes): void
    {
        try {
            if (!Notifier::available() || $row->requested_by === $actor->id) {
                return;
            }

            $code = DB::table('sales')->where('id', $row->sale_id)->value('code');
            $amount = Currency::format((float) $row->amount, $row->currency);
            $title = $approved ? 'Devolución aprobada' : 'Devolución rechazada';
            $body = ($approved ? "Se devolvió {$amount} de la venta {$code}." : "No se aprobó devolver {$amount} de la venta {$code}: {$notes}") . ' La revisó ' . Person::name($actor) . '.';
            Notifier::send($tenant->id, $row->requested_by, $approved ? 'refund.approved' : 'refund.rejected', $title, $body, "/sales?venta={$row->sale_id}", 'sale', $row->sale_id);
        } catch (\Throwable $e) {
            report($e);
        }
    }
    public static function total(string $saleId): float
    {
        return round((float) DB::table('sale_refunds')->where('sale_id', $saleId)->sum('amount'), 2);
    }

    public static function voidBlocked(object $sale): ?string
    {
        return match (true) {
            $sale->status === 'void' => 'Esta venta ya está anulada.',
            (float) $sale->paid > 0 || !in_array($sale->status, ['pending', 'partial'], true)
                => 'Esta venta tiene dinero cobrado: no se anula, se devuelve.',
            default => null,
        };
    }

    public static function void(object $sale, string $reason): void
    {
        if ($blocked = self::voidBlocked($sale)) {
            throw ValidationException::withMessages(['reason' => $blocked]);
        }

        DB::table('sales')->where('id', $sale->id)->update([
            'status' => 'void', 'void_reason' => mb_substr($reason, 0, 255), 'balance' => 0, 'updated_at' => now(),
        ]);
        PaymentLinks::cancelForSale($sale->id, 'La venta se anuló.');

        foreach (DB::table('coupon_redemptions')->where('sale_id', $sale->id)->get() as $redemption) {
            DB::table('coupons')->where('id', $redemption->coupon_id)->where('used_count', '>', 0)->decrement('used_count');
            DB::table('coupon_redemptions')->where('id', $redemption->id)->delete();
        }
    }

    public static function availability(Tenant $tenant, object $sale): array
    {
        $available = in_array($sale->status, ['partial', 'paid', 'partially_refunded'], true) ? round((float) $sale->paid, 2) : 0.0;

        $days = Sales::rules($tenant)['refund_days'];

        $blocked = match (true) {
            $sale->status === 'void'     => 'Una venta anulada no tiene nada que devolver.',
            $sale->status === 'refunded' => 'Esta venta ya se devolvió por completo.',
            $available <= 0              => 'Esta venta no tiene dinero cobrado: si no va a cobrarse, anúlala.',
            $days > 0 && Carbon::parse($sale->created_at)->lt(now()->subDays($days))
                => "Pasó el plazo de {$days} días para devolver esta venta.",
            default => null,
        };

        return ['available' => max(0, $available), 'blocked' => $blocked];
    }

    public static function refund(Tenant $tenant, string $saleId, array $data, User $user): object
    {
        $sale = DB::table('sales')->where('tenant_id', $tenant->id)->where('id', $saleId)->lockForUpdate()->first();
        abort_unless($sale, 404);

        $state = self::availability($tenant, $sale);

        if ($state['blocked']) {
            throw ValidationException::withMessages(['amount' => $state['blocked']]);
        }

        $amount = round((float) $data['amount'], 2);

        if ($amount <= 0 || $amount > $state['available']) {
            throw ValidationException::withMessages(['amount' => 'El monto debe ser mayor que cero y no pasar de ' . Currency::format($state['available'], $sale->currency ?? null) . ', que es lo que queda por devolver.']);
        }

        if (empty($data['from_package'])) {
            $held = Packages::heldInSale($sale->id);
            $free = round(max(0, $state['available'] - $held['amount']), 2);

            if ($held['amount'] > 0 && $amount > $free + 0.001) {
                throw ValidationException::withMessages(['amount' => 'De esta venta, ' . Currency::format(min($held['amount'], $state['available']), $sale->currency ?? null) . " son {$held['label']}, que sigue vigente: eso se devuelve al anularlo, donde se calcula lo que corresponde. Aquí puedes devolver hasta " . Currency::format($free, $sale->currency ?? null) . '.']);
            }
        }

        $usedCards = Loyalty::usedFromSale($sale->id);

        if ($usedCards > 0 && $amount > round(max(0, $state['available'] - $usedCards), 2) + 0.001) {
            $free = round(max(0, $state['available'] - $usedCards), 2);
            throw ValidationException::withMessages(['amount' => 'De esta venta, ' . Currency::format(min($usedCards, $state['available']), $sale->currency ?? null) . ' son gift cards que ya se usaron: eso no se devuelve. Aquí puedes devolver hasta ' . Currency::format($free, $sale->currency ?? null) . '.']);
        }

        $session = null;
        $gateway = null;

        if ($data['method'] === 'gateway') {
            $gateway = PaymentLinks::refundThroughGateway($tenant, $sale, $amount);
        }

        if ($data['method'] === 'cash') {
            $open = Cash::openSessions($tenant->id, $sale->branch_id);
            $wanted = $data['cash_session_id'] ?? null;
            $session = $wanted ? $open->firstWhere('id', $wanted) : ($open->firstWhere('opened_by', $user->id) ?? $open->first());

            if (!$session && Sales::rules($tenant)['require_open_cash']) {
                abort(response()->json(['message' => 'La caja de la sede está cerrada: ábrela para devolver en efectivo, o devuelve por otro medio.', 'code' => 'CASH_CLOSED'], 409));
            }

            if ($session) {
                $inCash = Cash::present(DB::table('cash_sessions')->where('id', $session->id)->first())['expected_amount'];

                if ($amount > $inCash) {
                    throw ValidationException::withMessages(['amount' => 'En la caja solo hay ' . Currency::format($inCash, $sale->currency ?? Currency::of($tenant)) . ' en efectivo.']);
                }
            }
        }

        $paymentId = (string) Str::uuid();

        DB::table('payments')->insert([
            'id' => $paymentId, 'tenant_id' => $tenant->id, 'branch_id' => $sale->branch_id, 'sale_id' => $sale->id,
            'appointment_id' => $sale->appointment_id, 'customer_id' => $sale->customer_id,
            'method' => $data['method'], 'status' => 'refunded', 'amount' => -$amount,
            'external_reference' => $gateway ? mb_substr((string) $gateway['external_id'], 0, 190) : 'Devolución', 'confirmed_at' => now(), 'cash_session_id' => $session?->id,
            'created_by' => $user->id, 'created_at' => now(),
        ] + Currency::column($sale->currency ?? Currency::of($tenant)));

        if ($session) {
            DB::table('cash_movements')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'cash_session_id' => $session->id,
                'type' => 'refund', 'amount' => $amount, 'reference_type' => 'payment', 'reference_id' => $paymentId,
                'description' => $sale->code, 'created_by' => $user->id, 'created_at' => now(),
            ]);
        }

        $paid = max(0, round((float) $sale->paid - $amount, 2));

        if ($sale->status === 'partial') {
            DB::table('sales')->where('id', $sale->id)->update([
                'paid' => $paid, 'balance' => round((float) $sale->total - $paid, 2), 'status' => $paid <= 0.001 ? 'pending' : 'partial', 'updated_at' => now(),
            ]);
            $note = null;
        } else {
            DB::table('sales')->where('id', $sale->id)->update([
                'paid' => $paid, 'status' => $paid <= 0.001 ? 'refunded' : 'partially_refunded', 'updated_at' => now(),
            ]);
            $note = Invoicing::creditNote($tenant, $sale, $amount, $data['reason'], $user);
        }

        $id = (string) Str::uuid();
        DB::table('sale_refunds')->insert([
            'id' => $id, 'tenant_id' => $tenant->id, 'sale_id' => $sale->id, 'amount' => $amount, 'method' => $data['method'],
            'reason' => mb_substr($data['reason'], 0, 255), 'payment_id' => $paymentId, 'invoice_id' => $note?->id,
            'created_by' => $user->id, 'created_at' => now(),
        ]);

        Commissions::forRefund($tenant, DB::table('sale_refunds')->where('id', $id)->first());
        PaymentLinks::afterRefund($sale);
        $fresh = DB::table('sales')->where('id', $sale->id)->first();
        Packages::afterSaleRefunded($tenant, $fresh, $user);
        Memberships::afterSaleRefunded($tenant, $fresh, $user);
        Loyalty::cancelUncoveredGiftCards($tenant, $fresh, $user);

        if ($fresh && $fresh->status === 'refunded') {
            Inventory::returnForSale($tenant, $fresh, $user, $data['reason'] ?? null);
            Loyalty::afterSaleRefunded($tenant, $fresh, $user);
        }

        return $fresh;
    }
}
