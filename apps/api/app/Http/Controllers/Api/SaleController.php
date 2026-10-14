<?php

namespace App\Http\Controllers\Api;

use App\Models\Appointment;
use App\Models\Tenant;
use App\Support\AppointmentFlow;
use App\Support\Cash;
use App\Support\Coupons;
use App\Support\Invoicing;
use App\Support\Sales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Support\Currency;

class SaleController extends AppointmentController
{
    protected function assertReady(): void
    {
        abort_unless(Sales::ready(), 503, Sales::NOT_READY);
    }

    private function blocked(Tenant $tenant, Appointment $appointment): ?string
    {
        if (in_array($appointment->status, AppointmentFlow::RELEASED, true)) {
            return 'Una cita ' . mb_strtolower(AppointmentFlow::name($tenant, $appointment->status)) . ' no se cobra.';
        }

        if ((float) $appointment->price <= 0) {
            return 'Esta cita no tiene un monto por cobrar: su servicio se agendó con precio cero.';
        }

        $currency = Currency::of($tenant);

        if (Currency::ready() && ($appointment->currency ?? $currency) !== $currency) {
            $from = Currency::present($appointment->currency);
            $to = Currency::present($currency);

            return "El precio de esta cita está en {$from['symbol']} y tu empresa ahora cobra en {$to['symbol']}. Pásala a {$to['symbol']} en Configuración → Moneda → \"Cambiar registros anteriores\" para poder cobrarla.";
        }

        return null;
    }

    private function payload(Tenant $tenant, Appointment $appointment, ?string $couponCode = null): array
    {
        $sale = Sales::ofAppointment($tenant->id, $appointment->id);
        $applied = !$sale && $couponCode ? Coupons::check($tenant->id, $couponCode, (float) $appointment->price, Currency::of($tenant)) : null;

        return $this->chargeContext($tenant, $sale, $appointment->branch_id, $appointment->customer, $appointment->service?->name, (float) $appointment->price, $applied, $sale ? null : $this->blocked($tenant, $appointment));
    }

    protected function chargeContext(Tenant $tenant, ?object $sale, string $branchId, ?object $customer, ?string $item, float $gross, ?array $applied, ?string $blocked): array
    {
        $rules = Sales::rules($tenant);
        $company = DB::table('companies')->where('tenant_id', $tenant->id)->orderBy('created_at')->first();
        $currency = $sale->currency ?? Currency::of($tenant);

        return [
            'sale'             => $sale ? Sales::present($sale) : null,
            'preview'          => Sales::totals($rules, $gross, $applied['discount'] ?? 0) + ['tax_rate' => $rules['tax_rate'], 'prices_include_tax' => $rules['prices_include_tax'], 'currency' => $currency],
            'coupon'           => $applied ? Coupons::summary($applied['coupon'], $applied['discount'], $currency) : null,
            'blocked'          => $blocked,
            'item'             => $item,
            'methods'          => collect(Sales::METHODS)->map(fn ($label, $key) => ['value' => $key, 'label' => $label, 'needs_reference' => in_array($key, Sales::NEEDS_REFERENCE, true)])->values(),
            'partial_payments' => $rules['partial_payments'],
            'cash'             => [
                'required' => $rules['require_open_cash'],
                'sessions' => Cash::openSessions($tenant->id, $branchId)->map(fn ($s) => ['id' => $s->id, 'register_name' => $s->register_name])->values(),
            ],
            'doc_types'        => collect(Invoicing::types($tenant))->map(fn ($type) => ['value' => $type, 'label' => Invoicing::LABELS[$type]])->values(),
            'default_doc_type' => Invoicing::defaultType($tenant),
            'customer'         => $customer ? [
                'name'            => trim("{$customer->first_name} {$customer->last_name}"),
                'document_number' => $customer->document_number,
            ] : null,
            'issuer'           => [
                'name'    => $company->legal_name ?? $company->trade_name ?? $tenant->name,
                'tax_id'  => $company->tax_id ?? null,
                'address' => $company->address ?? null,
            ],
        ];
    }

    public function forAppointment(Request $request, string $appointment)
    {
        $this->assertReady();
        $appointment = $this->find($request, $appointment);

        $coupon = $request->validate(['coupon' => 'nullable|string|max:40'])['coupon'] ?? null;

        return response()->json($this->payload($this->tenant($request), $appointment, $coupon));
    }

    public function charge(Request $request, string $appointment)
    {
        $this->assertReady();
        $appointment = $this->find($request, $appointment);
        $tenant = $this->tenant($request);
        $user = $request->user();

        $validated = $request->validate([
            'method'          => ['required', Rule::in(array_keys(Sales::METHODS))],
            'amount'          => 'required|numeric|min:0.01|max:9999999',
            'received'        => 'nullable|numeric|min:0|max:9999999',
            'reference'       => 'nullable|string|max:190',
            'cash_session_id' => 'nullable|uuid',
            'idempotency_key' => 'nullable|string|max:190',
            'coupon_code'     => 'nullable|string|max:40',
            'doc_type'        => 'nullable|string|max:20',
            'ruc'             => 'nullable|string|max:11',
            'business_name'   => 'nullable|string|max:255',
            'address'         => 'nullable|string|max:255',
        ], [
            'method.required' => 'Elige el medio de pago.',
            'method.in'       => 'Ese medio de pago no está disponible.',
            'amount.required' => 'Escribe el monto a cobrar.',
            'amount.numeric'  => 'Escribe el monto como un número.',
            'amount.min'      => 'El monto debe ser mayor que cero.',
            'received.numeric' => 'Escribe lo recibido como un número.',
        ]);

        $doc = Invoicing::validated($tenant, $validated);

        $result = DB::transaction(function () use ($tenant, $appointment, $validated, $doc, $user) {
            Appointment::where('id', $appointment->id)->lockForUpdate()->first();
            $sale = Sales::ofAppointment($tenant->id, $appointment->id);

            if (!$sale) {
                abort_if($reason = $this->blocked($tenant, $appointment), 422, $reason ?? '');
                $sale = Sales::createForAppointment($tenant, $appointment, $user, $validated['coupon_code'] ?? null);
            }

            return Sales::pay($tenant, $sale->id, $validated, $doc, $user);
        });

        return response()->json($this->payload($tenant, $appointment) + ['change' => $result['change'], 'duplicate' => $result['duplicate']]);
    }

    public function issueInvoice(Request $request, string $appointment)
    {
        $this->assertReady();
        $appointment = $this->find($request, $appointment);
        $tenant = $this->tenant($request);

        $sale = Sales::ofAppointment($tenant->id, $appointment->id);
        abort_unless($sale, 404);
        abort_unless($sale->status === 'paid', 422, 'El comprobante se emite cuando la venta está pagada.');

        $doc = Invoicing::validated($tenant, $request->only(['doc_type', 'ruc', 'business_name', 'address']));
        DB::transaction(fn () => Invoicing::issue($tenant, $sale, $doc, $request->user()));

        return response()->json($this->payload($tenant, $appointment));
    }
}
