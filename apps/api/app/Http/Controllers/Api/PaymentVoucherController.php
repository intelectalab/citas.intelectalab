<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\CustomerTransfers;
use App\Support\PaymentLinks;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentVoucherController extends Controller
{
    private function ready(Request $request): void
    {
        abort_unless($request->user()->tenant_id, 403);
        abort_unless(CustomerTransfers::ready(), 503, CustomerTransfers::NOT_READY);
    }

    public function index(Request $request)
    {
        $this->ready($request);
        $status = $request->query('status', 'review');

        $rows = CustomerTransfers::scoped($request->user())
            ->when(in_array($status, ['review', 'approved', 'rejected'], true), fn ($q) => $q->where('v.status', $status))
            ->orderByDesc('v.created_at')
            ->limit(300)
            ->get(['v.*']);

        return response()->json([
            'vouchers' => $rows->map(fn ($row) => CustomerTransfers::present($row))->values(),
            'counts'   => CustomerTransfers::scoped($request->user())->selectRaw('v.status, count(*) AS total')->groupBy('v.status')->pluck('total', 'status'),
            'enabled'  => CustomerTransfers::enabled($request->user()->tenant_id),
        ]);
    }

    public function approve(Request $request, string $voucher)
    {
        $this->ready($request);
        $row = CustomerTransfers::approve($voucher, $request->user());

        Audit::log($request, 'payment_voucher.approved', [
            'tenant'       => $row->tenant_id,
            'branch'       => $row->branch_id,
            'entity_type'  => 'payment_voucher',
            'entity_id'    => $row->id,
            'entity_label' => "{$row->account_label} · {$row->operation_number}",
        ]);

        return response()->json(['voucher' => CustomerTransfers::present($row), 'message' => 'Comprobante aprobado: el pago quedó registrado.']);
    }

    public function reject(Request $request, string $voucher)
    {
        $this->ready($request);
        $validated = $request->validate(['reason' => 'required|string|min:5|max:255'], [
            'reason.required' => 'Escribe por qué lo rechazas: el cliente lo verá.',
            'reason.min'      => 'Explica un poco más por qué lo rechazas.',
        ]);
        $row = CustomerTransfers::reject($voucher, $request->user(), trim($validated['reason']));

        Audit::log($request, 'payment_voucher.rejected', [
            'tenant'       => $row->tenant_id,
            'branch'       => $row->branch_id,
            'entity_type'  => 'payment_voucher',
            'entity_id'    => $row->id,
            'entity_label' => "{$row->account_label} · {$row->operation_number}",
            'after'        => ['reason' => $row->reason],
        ]);

        return response()->json(['voucher' => CustomerTransfers::present($row), 'message' => 'Comprobante rechazado: el cliente verá el motivo y podrá volver a pagar.']);
    }

    public function file(Request $request, string $voucher)
    {
        $this->ready($request);
        $row = CustomerTransfers::scoped($request->user())->where('v.id', $voucher)->first(['v.*']);
        abort_unless($row, 404);

        return CustomerTransfers::file($row);
    }

    public function settings(Request $request)
    {
        abort_unless($request->user()->allows('settings.view'), 403, 'Tu cuenta no tiene permiso para ver la configuración de la empresa.');
        $this->ready($request);

        return response()->json(['transfer' => CustomerTransfers::config($request->user()->tenant_id), 'gateway' => PaymentLinks::provider($request->user()->tenant_id)?->key()]);
    }

    public function saveSettings(Request $request)
    {
        abort_unless($request->user()->allows('settings.edit'), 403, 'Tu cuenta no tiene permiso para cambiar la configuración de la empresa.');
        $this->ready($request);

        $validated = $request->validate([
            'enabled'                   => 'boolean',
            'accounts'                  => 'array|max:6',
            'accounts.*.id'             => 'nullable|string|max:20',
            'accounts.*.bank'           => 'required|string|max:60',
            'accounts.*.holder'         => 'required|string|max:120',
            'accounts.*.account_number' => 'required|string|max:40',
            'accounts.*.cci'            => 'nullable|string|max:40',
            'yape_phone'                => ['nullable', 'string', 'max:20', 'regex:/^[0-9 +]{6,20}$/'],
            'plin_phone'                => ['nullable', 'string', 'max:20', 'regex:/^[0-9 +]{6,20}$/'],
            'hold_hours'                => 'nullable|integer|min:1|max:168',
            'review_text'               => 'nullable|string|max:60',
        ], [
            'accounts.*.bank.required'           => 'Escribe el banco de cada cuenta.',
            'accounts.*.holder.required'         => 'Escribe el titular de cada cuenta.',
            'accounts.*.account_number.required' => 'Escribe el número de cada cuenta.',
            'yape_phone.regex'                   => 'El número de Yape solo lleva dígitos.',
            'plin_phone.regex'                   => 'El número de Plin solo lleva dígitos.',
            'hold_hours.max'                     => 'El horario se puede apartar como mucho 7 días (168 horas).',
        ]);

        $tenantId = $request->user()->tenant_id;
        $before = CustomerTransfers::config($tenantId);
        $config = CustomerTransfers::save($tenantId, $validated);

        Audit::log($request, 'settings.changed', [
            'tenant'       => $tenantId,
            'entity_type'  => 'settings',
            'entity_label' => 'Transferencia y comprobantes',
            'before'       => ['transfer_enabled' => $before['enabled'] ? 'Sí' : 'No', 'transfer_accounts' => count($before['accounts']), 'hold_hours' => $before['hold_hours']],
            'after'        => ['transfer_enabled' => $config['enabled'] ? 'Sí' : 'No', 'transfer_accounts' => count($config['accounts']), 'hold_hours' => $config['hold_hours']],
        ]);

        return response()->json(['transfer' => $config, 'message' => 'Listo: se guardó cómo te pagan por transferencia.']);
    }

    public function submit(Request $request, string $token)
    {
        abort_unless(CustomerTransfers::ready(), 404);

        $validated = $request->validate([
            'method_id'        => 'required|string|max:20',
            'operation_number' => 'required|string|min:3|max:60',
            'paid_on'          => ['required', 'date_format:Y-m-d', 'before_or_equal:' . now()->addDay()->format('Y-m-d'), 'after_or_equal:' . now()->subDays(15)->format('Y-m-d')],
            'voucher'          => 'required|string',
        ], [
            'method_id.required'        => 'Elige a dónde pagaste.',
            'operation_number.required' => 'Escribe el número de operación que sale en tu comprobante.',
            'paid_on.required'          => 'Elige el día en que pagaste.',
            'paid_on.before_or_equal'   => 'La fecha de pago no puede ser futura.',
            'paid_on.after_or_equal'    => 'Ese pago es muy antiguo: revisa la fecha.',
            'voucher.required'          => 'Sube la foto o el PDF del comprobante.',
        ]);

        CustomerTransfers::submit($token, $validated);

        return response()->json(['link' => PaymentLinks::publicView($token), 'message' => 'Recibimos tu comprobante. La empresa lo revisará y te avisará.']);
    }
}
