<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Invoicing;
use App\Support\Sales;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    private const MAX_ROWS = 500;

    private function tenant(Request $request): Tenant
    {
        $user = $request->user();
        abort_unless($user->tenant, 403);
        abort_unless(Sales::ready(), 503, Sales::NOT_READY);

        return $user->tenant->loadMissing(['settings', 'activeSubscription.plan']);
    }

    private function find(Request $request, Tenant $tenant, string $id): object
    {
        $invoice = DB::table('invoices')->where('tenant_id', $tenant->id)->where('id', $id)->first();
        abort_unless($invoice, 404);
        Access::assertBranch($request->user(), $invoice->branch_id);

        return $invoice;
    }

    private static function voidable(): bool
    {
        return Schema::hasColumn('invoices', 'voided_at');
    }

    private function row(object $invoice, array $extra = []): array
    {
        $iso = fn ($value) => $value ? Carbon::parse($value)->toIso8601String() : null;

        return Invoicing::present($invoice) + [
            'sale_id'     => $invoice->sale_id,
            'branch_id'   => $invoice->branch_id,
            'voided_at'   => $iso($invoice->voided_at ?? null),
            'void_reason' => $invoice->void_reason ?? null,
            'can_resend'  => $invoice->status === 'issued' && $invoice->doc_type !== Invoicing::TICKET && in_array($invoice->sunat_status, ['pending', 'rejected'], true),
            'can_void'    => self::voidable() && $invoice->status === 'issued' && $invoice->doc_type !== Invoicing::CREDIT_NOTE,
            'void_plan'   => !$invoice->simulated && $invoice->doc_type !== Invoicing::TICKET && $invoice->sunat_status !== 'rejected' && Invoicing::electronicReady()
                ? (Invoicing::voidWindowOpen($invoice) ? 'baja' : 'credit_note')
                : 'local',
        ] + $extra;
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);

        $rows = DB::table('invoices as i')
            ->leftJoin('sales as s', 's.id', '=', 'i.sale_id')
            ->leftJoin('branches as b', 'b.id', '=', 'i.branch_id')
            ->leftJoin('invoices as r', 'r.id', '=', 'i.reference_invoice_id')
            ->where('i.tenant_id', $tenant->id)
            ->when(Access::branchLimit($request->user()), fn ($q, $id) => $q->where('i.branch_id', $id))
            ->orderByDesc('i.issued_at')
            ->limit(self::MAX_ROWS)
            ->get(['i.*', 's.code as sale_code', 'b.name as branch_name', 'r.serie as ref_serie', 'r.number as ref_number']);

        $refunded = DB::table('sale_refunds')->whereIn('sale_id', $rows->pluck('sale_id')->unique())->pluck('sale_id')->flip();

        return response()->json([
            'can_manage' => $request->user()->allows('sales.delete'),
            'can_issue'  => $request->user()->allows('sales.create'),
            'voidable'   => self::voidable(),
            'provider'   => Invoicing::provider($tenant)->key(),
            'void_days'  => Invoicing::VOID_DAYS,
            'not_ready'  => Invoicing::electronicReady() ? null : 'Falta actualizar la base de datos para la facturación electrónica (php artisan migrate): mientras tanto no se guardan los enlaces del PDF ni se reintenta solo.',
            'invoices'   => $rows->map(fn ($i) => $this->row($i, [
                'sale_code'   => $i->sale_code,
                'branch_name' => $i->branch_name,
                'reference'   => $i->ref_serie ? sprintf('%s-%08d', $i->ref_serie, $i->ref_number) : null,
                'has_refund'  => $refunded->has($i->sale_id),
            ]))->values(),
        ]);
    }

    public function resend(Request $request, string $invoice)
    {
        $tenant = $this->tenant($request);
        $invoice = $this->find($request, $tenant, $invoice);

        if ($invoice->status !== 'issued' || $invoice->doc_type === Invoicing::TICKET || !in_array($invoice->sunat_status, ['pending', 'rejected'], true)) {
            return response()->json(['message' => 'Este comprobante no necesita reenviarse.', 'code' => 'NOT_RESENDABLE'], 422);
        }

        if (Invoicing::electronicReady()) {
            DB::table('invoices')->where('id', $invoice->id)->update(['send_attempts' => 0]);
        }

        Invoicing::deliver($tenant, $invoice->id);

        return response()->json($this->row(DB::table('invoices')->where('id', $invoice->id)->first()));
    }

    public function void(Request $request, string $invoice)
    {
        $tenant = $this->tenant($request);
        abort_unless(self::voidable(), 503, 'Falta actualizar la base de datos para anular comprobantes (php artisan migrate).');
        $validated = $request->validate(['reason' => 'required|string|min:5|max:255'], [
            'reason.required' => 'Escribe por qué se anula el comprobante.',
            'reason.min'      => 'Escribe por qué se anula el comprobante.',
        ]);

        return DB::transaction(function () use ($request, $tenant, $invoice, $validated) {
            $invoice = DB::table('invoices')->where('tenant_id', $tenant->id)->where('id', $invoice)->lockForUpdate()->first();
            abort_unless($invoice, 404);
            Access::assertBranch($request->user(), $invoice->branch_id);

            if ($invoice->status === 'voided') {
                throw ValidationException::withMessages(['invoice' => 'Este comprobante ya está anulado.']);
            }

            if ($invoice->doc_type === Invoicing::CREDIT_NOTE) {
                throw ValidationException::withMessages(['invoice' => 'Una nota de crédito no se anula desde aquí.']);
            }

            if (DB::table('sale_refunds')->where('sale_id', $invoice->sale_id)->exists()) {
                throw ValidationException::withMessages(['invoice' => 'La venta de este comprobante tiene devoluciones con nota de crédito. Para corregirlo, comunícate con tu contador.']);
            }

            $values = Invoicing::void($tenant, $invoice, $validated['reason'], $request->user());
            DB::table('invoices')->where('id', $invoice->id)->update($values);

            Audit::log($request, 'invoice.voided', [
                'tenant'       => $tenant->id,
                'entity_type'  => 'invoice',
                'entity_id'    => $invoice->id,
                'entity_label' => sprintf('%s %s-%08d', Invoicing::LABELS[$invoice->doc_type] ?? $invoice->doc_type, $invoice->serie, $invoice->number),
                'branch_id'    => $invoice->branch_id,
                'after'        => ['status' => 'Anulado', 'reason' => $validated['reason']] + (isset($values['void_method']) ? ['void_method' => ['baja' => 'Comunicación de baja a SUNAT', 'credit_note' => 'Nota de crédito', 'local' => 'Solo en el sistema'][$values['void_method']]] : []),
            ]);

            return response()->json($this->row(DB::table('invoices')->where('id', $invoice->id)->first()));
        });
    }
}
