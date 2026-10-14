<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Cash;
use App\Support\Invoicing;
use App\Support\Refunds;
use App\Support\Sales;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Support\Access;

/**
 * Ventas y pagos de la empresa: consultarlos, anular una venta sin dinero
 * cobrado y devolver una que ya lo tiene. Como Caja, lo ve quien no es solo
 * profesional; anular y devolver son del administrador.
 */
class SalesController extends Controller
{
    private const MAX_ROWS = 500;

    private function tenant(Request $request): Tenant
    {
        $user = $request->user();
        abort_unless($user->tenant, 403);
        abort_unless(Sales::ready(), 503, Sales::NOT_READY);
        // Quién ve las ventas lo decide el permiso sales.view de cada ruta.

        return $user->tenant->loadMissing(['settings', 'activeSubscription.plan']);
    }

    private function assertAdmin(User $user, string $what): void
    {
        abort_unless($user->allows('sales.delete'), 403, "Tu cuenta no tiene permiso para {$what}.");
    }

    private function find(Tenant $tenant, string $id): object
    {
        $sale = DB::table('sales')->where('tenant_id', $tenant->id)->where('id', $id)->first();
        abort_unless($sale, 404);
        // Quien trabaja solo con su sede no abre ventas de otra.
        Access::assertBranch(request()->user(), $sale->branch_id);

        return $sale;
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);
        $iso = fn ($value) => $value ? Carbon::parse($value)->toIso8601String() : null;

        $sales = DB::table('sales as s')
            ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
            ->leftJoin('branches as b', 'b.id', '=', 's.branch_id')
            ->leftJoin('appointments as a', 'a.id', '=', 's.appointment_id')
            ->where('s.tenant_id', $tenant->id)
            ->when(Access::branchLimit($request->user()), fn ($q, $id) => $q->where('s.branch_id', $id))
            ->orderByDesc('s.created_at')
            ->limit(self::MAX_ROWS)
            ->get(['s.*', 'c.first_name', 'c.last_name', 'b.name as branch_name', 'a.code as appointment_code']);

        $ids = $sales->pluck('id');
        $items = DB::table('sale_items')->whereIn('sale_id', $ids)->get(['sale_id', 'description'])->groupBy('sale_id');
        $invoices = DB::table('invoices')->whereIn('sale_id', $ids)->where('status', 'issued')->where('doc_type', '!=', Invoicing::CREDIT_NOTE)->get()->keyBy('sale_id');
        $refunds = DB::table('sale_refunds')->whereIn('sale_id', $ids)->groupBy('sale_id')->selectRaw('sale_id, sum(amount) as total')->pluck('total', 'sale_id');

        return response()->json([
            'can_manage' => $request->user()->allows('sales.delete'),
            // Si la empresa no cobra antes de atender, puede haber citas atendidas que nadie cobró.
            'unbilled'   => Sales::mustPayFirst($tenant) ? null : $this->unbilled($tenant),
            'sales'      => $sales->map(fn ($s) => [
                'id'               => $s->id,
                'code'             => $s->code,
                'status'           => $s->status,
                'status_label'     => Sales::STATUS_LABELS[$s->status] ?? $s->status,
                'created_at'       => $iso($s->created_at),
                'branch_id'        => $s->branch_id,
                'branch_name'      => $s->branch_name,
                'customer'         => $s->customer_id ? ['id' => $s->customer_id, 'name' => trim("{$s->first_name} {$s->last_name}")] : null,
                'appointment_code' => $s->appointment_code,
                'detail'           => ($items[$s->id] ?? collect())->pluck('description')->implode(', '),
                'total'            => round((float) $s->total, 2),
                'paid'             => round((float) $s->paid, 2),
                'balance'          => round((float) $s->balance, 2),
                'refunded'         => round((float) ($refunds[$s->id] ?? 0), 2),
                'invoice_number'   => isset($invoices[$s->id]) ? sprintf('%s-%08d', $invoices[$s->id]->serie, $invoices[$s->id]->number) : null,
                'invoice_label'    => isset($invoices[$s->id]) ? (Invoicing::LABELS[$invoices[$s->id]->doc_type] ?? null) : null,
            ])->values(),
        ]);
    }

    /**
     * Citas atendidas con precio y sin venta: se atendieron y nadie las cobró.
     */
    private function unbilled(Tenant $tenant): array
    {
        return DB::table('appointments as a')
            ->leftJoin('customers as c', 'c.id', '=', 'a.customer_id')
            ->leftJoin('services as sv', 'sv.id', '=', 'a.service_id')
            ->leftJoin('branches as b', 'b.id', '=', 'a.branch_id')
            ->where('a.tenant_id', $tenant->id)
            ->when(Access::branchLimit(request()->user()), fn ($q, $id) => $q->where('a.branch_id', $id))
            ->where('a.status', \App\Support\AppointmentFlow::COMPLETED)
            ->where('a.price', '>', 0)
            ->whereNotExists(fn ($q) => $q->from('sales as s')->whereColumn('s.appointment_id', 'a.id')->where('s.status', '!=', 'void'))
            ->orderByDesc('a.starts_at')
            ->limit(self::MAX_ROWS)
            ->get(['a.id', 'a.code', 'a.starts_at', 'a.price', 'a.branch_id', 'a.customer_id', 'c.first_name', 'c.last_name', 'sv.name as service_name', 'b.name as branch_name'])
            ->map(fn ($a) => [
                'appointment_id'   => $a->id,
                'appointment_code' => $a->code,
                'at'               => Carbon::parse($a->starts_at)->toIso8601String(),
                'branch_id'        => $a->branch_id,
                'branch_name'      => $a->branch_name,
                'customer'         => $a->customer_id ? ['id' => $a->customer_id, 'name' => trim("{$a->first_name} {$a->last_name}")] : null,
                'detail'           => $a->service_name,
                'total'            => round((float) $a->price, 2),
            ])
            ->values()
            ->all();
    }

    private function detail(Tenant $tenant, object $sale, User $user): array
    {
        $iso = fn ($value) => $value ? Carbon::parse($value)->toIso8601String() : null;
        $customer = $sale->customer_id ? DB::table('customers')->where('id', $sale->customer_id)->first(['id', 'first_name', 'last_name']) : null;
        $company = DB::table('companies')->where('tenant_id', $tenant->id)->orderBy('created_at')->first();
        $refund = Refunds::availability($tenant, $sale);
        $notes = DB::table('invoices')->where('sale_id', $sale->id)->where('doc_type', Invoicing::CREDIT_NOTE)->orderBy('created_at')->get()->keyBy('id');

        $refunds = DB::table('sale_refunds as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.created_by')
            ->where('r.sale_id', $sale->id)
            ->orderByDesc('r.created_at')
            ->get(['r.*', 'u.first_name', 'u.last_name']);

        return Sales::present($sale) + [
            'branch'           => ['id' => $sale->branch_id, 'name' => DB::table('branches')->where('id', $sale->branch_id)->value('name')],
            'customer'         => $customer ? ['id' => $customer->id, 'name' => trim("{$customer->first_name} {$customer->last_name}")] : null,
            'appointment'      => $sale->appointment_id ? ['id' => $sale->appointment_id, 'code' => DB::table('appointments')->where('id', $sale->appointment_id)->value('code')] : null,
            'void_reason'      => $sale->void_reason,
            'issuer'           => [
                'name'    => $company->legal_name ?? $company->trade_name ?? $tenant->name,
                'tax_id'  => $company->tax_id ?? null,
                'address' => $company->address ?? null,
            ],
            'refunds'          => $refunds->map(fn ($r) => [
                'id'           => $r->id,
                'amount'       => round((float) $r->amount, 2),
                'method_label' => (Sales::METHODS + Sales::OTHER_METHODS)[$r->method] ?? $r->method,
                'reason'       => $r->reason,
                'by'           => trim("{$r->first_name} {$r->last_name}") ?: null,
                'at'           => $iso($r->created_at),
                'credit_note'  => $r->invoice_id && isset($notes[$r->invoice_id]) ? Invoicing::present($notes[$r->invoice_id]) : null,
            ])->values(),
            'can_manage'       => $user->allows('sales.delete'),
            'void_blocked'     => Refunds::voidBlocked($sale),
            'refund_available' => $refund['available'],
            'refund_blocked'   => $refund['blocked'],
            'refund_methods'   => collect(Sales::METHODS)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'cash_sessions'    => Cash::openSessions($tenant->id, $sale->branch_id)->map(fn ($s) => ['id' => $s->id, 'register_name' => $s->register_name])->values(),
        ];
    }

    public function show(Request $request, string $sale)
    {
        $tenant = $this->tenant($request);

        return response()->json($this->detail($tenant, $this->find($tenant, $sale), $request->user()));
    }

    public function void(Request $request, string $sale)
    {
        $tenant = $this->tenant($request);
        $this->assertAdmin($request->user(), 'anular una venta');

        $validated = $request->validate(['reason' => 'required|string|max:255'], ['reason.required' => 'Escribe por qué se anula la venta.']);

        DB::transaction(function () use ($tenant, $sale, $validated) {
            $row = DB::table('sales')->where('tenant_id', $tenant->id)->where('id', $sale)->lockForUpdate()->first();
            abort_unless($row, 404);
            Access::assertBranch(request()->user(), $row->branch_id, true);
            Refunds::void($row, trim($validated['reason']));
        });

        return response()->json($this->detail($tenant, $this->find($tenant, $sale), $request->user()));
    }

    public function refund(Request $request, string $sale)
    {
        $tenant = $this->tenant($request);
        $this->assertAdmin($request->user(), 'devolver una venta');

        $validated = $request->validate([
            'amount'          => 'required|numeric|min:0.01|max:9999999',
            'method'          => ['required', Rule::in(array_keys(Sales::METHODS))],
            'reason'          => 'required|string|max:255',
            'cash_session_id' => 'nullable|uuid',
        ], [
            'amount.required' => 'Escribe cuánto se devuelve.',
            'amount.numeric'  => 'Escribe el monto como un número.',
            'amount.min'      => 'El monto debe ser mayor que cero.',
            'method.required' => 'Elige por qué medio se devuelve el dinero.',
            'reason.required' => 'Escribe por qué se devuelve.',
        ]);

        $validated['reason'] = trim($validated['reason']);
        $this->find($tenant, $sale);

        DB::transaction(fn () => Refunds::refund($tenant, $sale, $validated, $request->user()));

        return response()->json($this->detail($tenant, $this->find($tenant, $sale), $request->user()));
    }

    /**
     * Todos los pagos y devoluciones, del más reciente al más antiguo.
     */
    public function payments(Request $request)
    {
        $tenant = $this->tenant($request);
        $labels = Sales::METHODS + Sales::OTHER_METHODS;

        $payments = DB::table('payments as p')
            ->leftJoin('sales as s', 's.id', '=', 'p.sale_id')
            ->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
            ->leftJoin('branches as b', 'b.id', '=', 'p.branch_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.created_by')
            ->when(Access::branchLimit($request->user()), fn ($q, $id) => $q->where('p.branch_id', $id))
            ->leftJoin('cash_sessions as cs', 'cs.id', '=', 'p.cash_session_id')
            ->leftJoin('cash_registers as r', 'r.id', '=', 'cs.cash_register_id')
            ->where('p.tenant_id', $tenant->id)
            ->orderByDesc('p.created_at')
            ->limit(self::MAX_ROWS)
            ->get(['p.*', 's.code as sale_code', 'c.first_name', 'c.last_name', 'b.name as branch_name', 'u.first_name as user_first', 'u.last_name as user_last', 'r.name as register_name']);

        return response()->json([
            'methods'  => collect($labels)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'payments' => $payments->map(fn ($p) => [
                'id'           => $p->id,
                'at'           => Carbon::parse($p->created_at)->toIso8601String(),
                'method'       => $p->method,
                'method_label' => $labels[$p->method] ?? $p->method,
                // Un monto negativo es una devolución.
                'kind'         => (float) $p->amount < 0 ? 'refund' : 'payment',
                'status'       => $p->status,
                'amount'       => round((float) $p->amount, 2),
                'reference'    => (float) $p->amount < 0 ? null : $p->external_reference,
                'sale_id'      => $p->sale_id,
                'sale_code'    => $p->sale_code,
                'customer'     => $p->customer_id ? ['id' => $p->customer_id, 'name' => trim("{$p->first_name} {$p->last_name}")] : null,
                'branch_id'    => $p->branch_id,
                'branch_name'  => $p->branch_name,
                'register'     => $p->register_name,
                'by'           => trim("{$p->user_first} {$p->user_last}") ?: null,
            ])->values(),
        ]);
    }
}
