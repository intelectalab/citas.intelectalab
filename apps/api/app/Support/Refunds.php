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

        $session = null;

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
            'external_reference' => 'Devolución', 'confirmed_at' => now(), 'cash_session_id' => $session?->id,
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

        return DB::table('sales')->where('id', $sale->id)->first();
    }
}
