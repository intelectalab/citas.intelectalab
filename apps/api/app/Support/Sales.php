<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Sales
{
    public const METHODS = [
        'cash'     => 'Efectivo',
        'card'     => 'Tarjeta',
        'transfer' => 'Transferencia',
        'yape'     => 'Yape',
        'plin'     => 'Plin',
    ];

    public const OTHER_METHODS = [
        'link'      => 'Link de pago',
        'deposit'   => 'Adelanto en línea',
        'gateway'   => 'Devolución por la pasarela',
        'gift_card' => 'Gift card',
        'points'    => 'Puntos',
    ];

    public const LOYALTY_METHODS = ['gift_card', 'points'];

    public const NEEDS_REFERENCE = ['card', 'transfer', 'yape', 'plin'];

    public const STATUS_LABELS = [
        'pending' => 'Por cobrar', 'partial' => 'Pago parcial', 'paid' => 'Pagada',
        'void' => 'Anulada', 'partially_refunded' => 'Devuelta en parte', 'refunded' => 'Devuelta',
    ];

    public const NOT_READY = 'Falta actualizar la base de datos para usar ventas y caja: ejecuta php artisan migrate.';

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('cash_registers') && Schema::hasTable('invoices') && Schema::hasTable('sale_refunds') && Schema::hasColumn('sales', 'code');
    }

    public static function rules(Tenant $tenant): array
    {
        return [
            'prices_include_tax' => (bool) AppointmentFlow::rule($tenant, 'pos.prices_include_tax'),
            'tax_rate'           => max(0, min(100, (float) AppointmentFlow::rule($tenant, 'pos.tax_rate'))),
            'require_open_cash'  => (bool) AppointmentFlow::rule($tenant, 'pos.require_open_cash'),
            'partial_payments'   => (bool) AppointmentFlow::rule($tenant, 'pos.partial_payments'),
            'cash_tolerance'     => max(0, (float) AppointmentFlow::rule($tenant, 'cash.tolerance')),
            'auto_register'      => (bool) AppointmentFlow::rule($tenant, 'cash.auto_register'),
            'refund_days'        => max(0, (int) AppointmentFlow::rule($tenant, 'pos.refund_days')),
            'pay_before_service' => (bool) AppointmentFlow::rule($tenant, 'pos.pay_before_service'),
            'arrive_minutes'     => max(0, min(240, (int) AppointmentFlow::rule($tenant, 'pos.arrive_minutes'))),
        ];
    }

    public static function totals(array $rules, float $gross, float $discount = 0): array
    {
        $net = max(0, round($gross - $discount, 2));
        $rate = $rules['tax_rate'] / 100;

        $tax = $rules['prices_include_tax'] ? round($net - $net / (1 + $rate), 2) : round($net * $rate, 2);

        return [
            'subtotal' => round($gross, 2),
            'discount' => round($discount, 2),
            'tax'      => $tax,
            'total'    => $rules['prices_include_tax'] ? $net : round($net + $tax, 2),
        ];
    }

    public static function nextNumber(string $tenantId, string $key): int
    {
        return (int) DB::selectOne(
            'INSERT INTO tenant_sequences (tenant_id, key, next_value) VALUES (?, ?, 2)
             ON CONFLICT (tenant_id, key) DO UPDATE SET next_value = tenant_sequences.next_value + 1
             RETURNING next_value - 1 AS value',
            [$tenantId, $key],
        )->value;
    }

    public static function mustPayFirst(Tenant $tenant): bool
    {
        return $tenant->hasModule('pos') && self::ready() && (bool) AppointmentFlow::rule($tenant, 'pos.pay_before_service');
    }

    public static function holding(string $appointmentId)
    {
        return DB::table('sale_items')->where('appointment_id', $appointmentId)->select('sale_id');
    }

    public static function appointmentPaid(string $tenantId, string $appointmentId): bool
    {
        if (Packages::ready() && DB::table('appointments')->where('id', $appointmentId)->whereNotNull('customer_package_id')->exists()) {
            return true;
        }

        return DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', self::holding($appointmentId))
            ->whereIn('status', ['paid', 'partially_refunded'])
            ->exists();
    }

    public static function ofAppointment(string $tenantId, string $appointmentId): ?object
    {
        return DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', self::holding($appointmentId))
            ->where('status', '!=', 'void')
            ->orderByDesc('created_at')
            ->first();
    }

    public static function createForAppointment(Tenant $tenant, Appointment $appointment, ?User $user, ?string $couponCode = null): object
    {
        $rules = self::rules($tenant);
        $applied = $couponCode ? Coupons::check($tenant->id, $couponCode, (float) $appointment->price, Currency::of($tenant)) : null;
        $totals = self::totals($rules, (float) $appointment->price, $applied['discount'] ?? 0);
        $id = (string) Str::uuid();

        DB::table('sales')->insert([
            'id'                 => $id,
            'tenant_id'          => $tenant->id,
            'branch_id'          => $appointment->branch_id,
            'customer_id'        => $appointment->customer_id,
            'appointment_id'     => $appointment->id,
            'code'               => Codes::next($tenant->id, 'sale'),
            'status'             => 'pending',
            'subtotal'           => $totals['subtotal'],
            'discount'           => $totals['discount'],
            'tax'                => $totals['tax'],
            'total'              => $totals['total'],
            'paid'               => 0,
            'balance'            => $totals['total'],
            'tax_rate'           => $rules['tax_rate'],
            'prices_include_tax' => $rules['prices_include_tax'],
            'created_by'         => $user?->id,
            'created_at'         => now(),
            'updated_at'         => now(),
        ] + Currency::column($tenant));

        DB::table('sale_items')->insert([
            'id'              => (string) Str::uuid(),
            'tenant_id'       => $tenant->id,
            'sale_id'         => $id,
            'item_type'       => 'service',
            'reference_id'    => $appointment->service_id,
            'description'     => $appointment->service?->name ?? 'Servicio',
            'quantity'        => 1,
            'unit_price'      => $appointment->price,
            'discount'        => 0,
            'total'           => $appointment->price,
            'professional_id' => $appointment->professional_id,
            'appointment_id'  => $appointment->id,
        ]);

        if ($applied) {
            Coupons::redeem($applied['coupon'], $id, $appointment->customer_id, $applied['discount']);
        }

        return DB::table('sales')->where('id', $id)->first();
    }

    public static function pay(Tenant $tenant, string $saleId, array $data, array $doc, User $user): array
    {
        $rules = self::rules($tenant);
        $key = $data['idempotency_key'] ?? null;

        $sale = DB::table('sales')->where('tenant_id', $tenant->id)->where('id', $saleId)->lockForUpdate()->first();
        abort_unless($sale, 404);

        if ($key && DB::table('payments')->where('tenant_id', $tenant->id)->where('idempotency_key', $key)->exists()) {
            return ['sale' => $sale, 'duplicate' => true, 'change' => 0.0];
        }

        if (!in_array($sale->status, ['pending', 'partial'], true)) {
            throw ValidationException::withMessages(['amount' => 'Esta venta ya no admite pagos: está ' . mb_strtolower(self::STATUS_LABELS[$sale->status] ?? $sale->status) . '.']);
        }

        $method = $data['method'];
        $amount = round((float) $data['amount'], 2);
        $balance = round((float) $sale->balance, 2);

        if ($amount <= 0 || $amount > $balance) {
            throw ValidationException::withMessages(['amount' => 'El monto debe ser mayor que cero y no pasar el saldo de ' . Currency::format($balance, $sale->currency ?? null) . '.']);
        }

        if (!$rules['partial_payments'] && $amount < $balance && !in_array($method, self::LOYALTY_METHODS, true) && !Quotes::isQuoteSale($sale)) {
            throw ValidationException::withMessages(['amount' => 'Tu empresa no admite pagos parciales: cobra el total de ' . Currency::format($balance, $sale->currency ?? null) . '.']);
        }

        if ($sale->branch_id && round((float) $sale->paid + $amount, 2) >= round((float) $sale->total - 0.001, 2)) {
            Inventory::assertStockForItems(
                $tenant,
                $sale->branch_id,
                DB::table('sale_items')->where('sale_id', $sale->id)->get(['item_type', 'reference_id', 'quantity']),
                'amount',
            );
        }

        $reference = trim((string) ($data['reference'] ?? '')) ?: null;

        if (in_array($method, self::NEEDS_REFERENCE, true) && !$reference) {
            throw ValidationException::withMessages(['reference' => 'Escribe el número de operación o voucher.']);
        }

        if ($method === 'gift_card') {
            $reference = Loyalty::payWithGiftCard($tenant, $sale, $amount, $reference, $user);
        } elseif ($method === 'points') {
            $reference = Loyalty::payWithPoints($tenant, $sale, $amount, $user);
        }

        $session = null;
        $change = 0.0;

        if ($method === 'cash') {
            $open = Cash::openSessions($tenant->id, $sale->branch_id);
            $wanted = $data['cash_session_id'] ?? null;
            $session = $wanted ? $open->firstWhere('id', $wanted) : ($open->firstWhere('opened_by', $user->id) ?? $open->first());

            if ($wanted && !$session) {
                throw ValidationException::withMessages(['cash_session_id' => 'Esa caja ya no está abierta. Elige otra.']);
            }

            if (!$session && $rules['require_open_cash']) {
                abort(response()->json(['message' => 'La caja de la sede está cerrada: ábrela antes de cobrar en efectivo.', 'code' => 'CASH_CLOSED'], 409));
            }

            $received = isset($data['received']) && $data['received'] !== '' && $data['received'] !== null ? round((float) $data['received'], 2) : $amount;

            if ($received < $amount) {
                throw ValidationException::withMessages(['received' => 'Lo recibido no alcanza para el monto a cobrar.']);
            }

            $change = round($received - $amount, 2);
        }

        $paymentId = (string) Str::uuid();

        DB::table('payments')->insert([
            'id'                 => $paymentId,
            'tenant_id'          => $tenant->id,
            'branch_id'          => $sale->branch_id,
            'sale_id'            => $sale->id,
            'appointment_id'     => $sale->appointment_id,
            'customer_id'        => $sale->customer_id,
            'method'             => $method,
            'status'             => 'confirmed',
            'amount'             => $amount,
            'change_amount'      => $change,
            'external_reference' => $reference ? mb_substr($reference, 0, 190) : null,
            'confirmed_at'       => now(),
            'cash_session_id'    => $session?->id,
            'idempotency_key'    => $key,
            'created_by'         => $user->id,
            'created_at'         => now(),
        ] + Currency::column($sale->currency ?? Currency::of($tenant)));

        if ($session) {
            DB::table('cash_movements')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'cash_session_id' => $session->id,
                'type' => 'sale', 'amount' => $amount, 'reference_type' => 'payment', 'reference_id' => $paymentId,
                'description' => $sale->code, 'created_by' => $user->id, 'created_at' => now(),
            ]);
        }

        $paid = round((float) $sale->paid + $amount, 2);
        $newBalance = round((float) $sale->total - $paid, 2);
        $isPaid = $newBalance <= 0.001;

        DB::table('sales')->where('id', $sale->id)->update([
            'paid'       => $paid,
            'balance'    => max(0, $newBalance),
            'status'     => $isPaid ? 'paid' : 'partial',
            'paid_at'    => $isPaid ? now() : null,
            'updated_at' => now(),
        ]);

        $sale = DB::table('sales')->where('id', $sale->id)->first();
        self::auditPayment($tenant->id, $sale, $method, $amount, $reference);
        PaymentLinks::cancelForSale($sale->id, 'Se cobró en la sede.');
        $packages = [];

        if ($isPaid) {
            Invoicing::issue($tenant, $sale, $doc, $user);
            $packages = Packages::issueForSale($tenant, $sale, $user);
            Inventory::deductForSale($tenant, $sale, $user);
            Loyalty::earnForSale($tenant, $sale, $user);
            Commissions::forSale($tenant, $sale);
        }

        return ['sale' => $sale, 'duplicate' => false, 'change' => $change, 'packages' => $packages];
    }

    public static function auditPayment(string $tenantId, object $sale, string $method, float $amount, ?string $reference = null, array $options = []): void
    {
        $customer = $sale->customer_id ? DB::table('customers')->where('id', $sale->customer_id)->first(['first_name', 'last_name']) : null;

        Audit::log(request(), 'payment.registered', $options + [
            'tenant'       => $tenantId,
            'entity_type'  => 'sale',
            'entity_id'    => $sale->id,
            'entity_label' => $sale->code,
            'branch_id'    => $sale->branch_id,
            'after'        => array_filter([
                'amount'    => $amount,
                'method'    => (self::METHODS + self::OTHER_METHODS)[$method] ?? $method,
                'customer'  => Person::name($customer) ?: null,
                'reference' => $reference,
                'balance'   => round((float) $sale->balance, 2),
            ], fn ($value) => $value !== null),
        ]);
    }

    public static function label(object $sale): string
    {
        if (($sale->deposit_outcome ?? null) === 'refund_due' && $sale->status !== 'void') {
            return 'Adelanto por devolver';
        }

        return self::STATUS_LABELS[$sale->status] ?? $sale->status;
    }

    public static function present(object $sale): array
    {
        $labels = self::METHODS + self::OTHER_METHODS;
        $iso = fn ($value) => $value ? Carbon::parse($value)->toIso8601String() : null;

        $payments = DB::table('payments as p')
            ->leftJoin('users as u', 'u.id', '=', 'p.created_by')
            ->leftJoin('cash_sessions as s', 's.id', '=', 'p.cash_session_id')
            ->leftJoin('cash_registers as r', 'r.id', '=', 's.cash_register_id')
            ->where('p.sale_id', $sale->id)
            ->orderBy('p.created_at')
            ->get(['p.id', 'p.method', 'p.status', 'p.amount', 'p.change_amount', 'p.external_reference', 'p.created_at', 'u.first_name', 'u.last_name', 'r.name as register_name']);

        return [
            'id'                 => $sale->id,
            'code'               => $sale->code,
            'status'             => $sale->status,
            'status_label'       => self::label($sale),
            'subtotal'           => round((float) $sale->subtotal, 2),
            'discount'           => round((float) $sale->discount, 2),
            'tax'                => round((float) $sale->tax, 2),
            'total'              => round((float) $sale->total, 2),
            'paid'               => round((float) $sale->paid, 2),
            'balance'            => round((float) $sale->balance, 2),
            'tax_rate'           => (float) $sale->tax_rate,
            'prices_include_tax' => (bool) $sale->prices_include_tax,
            'currency'           => $sale->currency ?? Currency::DEFAULT,
            'created_at'         => $iso($sale->created_at),
            'paid_at'            => $iso($sale->paid_at),
            'items'              => DB::table('sale_items')->where('sale_id', $sale->id)->when(Schema::hasColumn('sale_items', 'position'), fn ($q) => $q->orderBy('position'))->get(['id', 'description', 'quantity', 'unit_price', 'discount', 'total'])
                ->map(fn ($i) => ['id' => $i->id, 'description' => $i->description, 'quantity' => (float) $i->quantity, 'unit_price' => round((float) $i->unit_price, 2), 'discount' => round((float) $i->discount, 2), 'total' => round((float) $i->total, 2)])
                ->values(),
            'payments'           => $payments->map(fn ($p) => [
                'id'           => $p->id,
                'method'       => $p->method,
                'method_label' => $labels[$p->method] ?? $p->method,
                'status'       => $p->status,
                'amount'       => round((float) $p->amount, 2),
                'change'       => round((float) $p->change_amount, 2),
                'reference'    => $p->external_reference,
                'register'     => $p->register_name,
                'by'           => \App\Support\Person::name($p) ?: null,
                'at'           => $iso($p->created_at),
            ])->values(),
            'coupon'             => Coupons::ofSale($sale->id, $sale->currency ?? null),
            'invoice'            => Invoicing::present(Invoicing::ofSale($sale->id)),
            'refunded'           => Refunds::total($sale->id),
            'deposit'            => PaymentLinks::ready() ? PaymentLinks::depositPaid($sale->id) : 0.0,
            'deposit_outcome'    => $sale->deposit_outcome ?? null,
        ];
    }
}
