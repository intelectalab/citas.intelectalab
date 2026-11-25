<?php

namespace App\Support;

use App\Models\PlatformSetting;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PlanReceipts
{
    public const REASONS = [
        'subscription_create' => 'Primer pago',
        'subscription_cycle'  => 'Cobro a la tarjeta',
        'online'              => 'Pago en línea',
        'manual'              => 'Registrado por la plataforma',
        'transfer'            => 'Transferencia',
        'subscription_upgrade' => 'Cambio a un plan mayor',
    ];

    public static function number(SubscriptionInvoice $invoice): string
    {
        return 'CP-' . strtoupper(substr(str_replace('-', '', $invoice->id), 0, 8));
    }

    public static function fileName(SubscriptionInvoice $invoice): string
    {
        return 'Constancia ' . self::number($invoice) . '.pdf';
    }

    private static function logo(): ?string
    {
        $path = PlatformLook::filePath('logo');

        if (!$path || !is_file($path) || filesize($path) > 2 * 1024 * 1024) {
            return null;
        }

        $mime = mime_content_type($path) ?: 'image/png';

        return in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true) ? "data:{$mime};base64," . base64_encode((string) file_get_contents($path)) : null;
    }

    public static function pdf(SubscriptionInvoice $invoice): string
    {
        $invoice->loadMissing('subscription.plan');
        $tenant = Tenant::find($invoice->tenant_id);
        $zone = $tenant?->zone() ?? config('app.timezone');
        $company = DB::table('companies')->where('tenant_id', $invoice->tenant_id)->orderBy('created_at')->first();
        $date = fn ($value) => $value ? Carbon::parse($value)->setTimezone($zone)->locale('es')->isoFormat('D [de] MMMM [de] YYYY') : '—';
        $product = PlatformMail::productName();
        $support = PlatformSetting::value('support_email');
        $accent = PlatformSetting::value('accent_color') ?: '#10B981';
        $logo = self::logo();
        $plan = $invoice->subscription?->plan;
        $cycle = $invoice->subscription?->billing_cycle === 'yearly' ? 'anual' : 'mensual';

        $rows = [
            'Empresa'          => $company->legal_name ?? $company->trade_name ?? $tenant?->name,
            'RUC o documento'  => $company->tax_id ?? null,
            'Plan'             => $plan ? "{$plan->name} ({$cycle})" : null,
            'Periodo pagado'   => $invoice->period_start && $invoice->period_end ? 'Del ' . $date($invoice->period_start) . ' al ' . $date($invoice->period_end) : null,
            'Fecha de pago'    => $date($invoice->paid_at ?? $invoice->invoice_date),
            'Medio'            => trim(($method = self::REASONS[$invoice->billing_reason] ?? 'Pago') . ($invoice->notes && trim($invoice->notes) !== $method ? " · {$invoice->notes}" : '')),
            'Referencia'       => $invoice->external_invoice_id,
            'Comprobante'      => PlanInvoices::ready() && $invoice->doc_type ? PlanInvoices::label($invoice) . ' · ' . PlanInvoices::stateLabel($invoice) : null,
        ];

        $list = collect($rows)->filter()->map(fn ($value, $label) => '<tr><th>' . e($label) . '</th><td>' . e($value) . '</td></tr>')->implode('');
        $amount = e(Currency::format((float) $invoice->amount, $invoice->currency));
        $head = $logo ? '<img src="' . $logo . '" style="max-height:44px;max-width:180px">' : '<span class="brand">' . e($product) . '</span>';

        $html = '<html><head><meta charset="utf-8"><style>
            body{font-family:"DejaVu Sans",sans-serif;color:#0f172a;font-size:11px;margin:28px}
            .top{border-bottom:3px solid ' . e($accent) . ';padding-bottom:12px;margin-bottom:18px}
            .brand{font-size:20px;font-weight:bold;color:' . e($accent) . '}
            h1{font-size:17px;margin:0 0 4px}
            .muted{color:#64748b}
            .amount{font-size:26px;font-weight:bold;margin:14px 0}
            table{width:100%;border-collapse:collapse;margin-top:8px}
            th{text-align:left;color:#64748b;font-weight:normal;padding:7px 0;width:34%;border-bottom:1px solid #e2e8f0}
            td{padding:7px 0;border-bottom:1px solid #e2e8f0}
            .note{margin-top:22px;font-size:9px;color:#64748b}
        </style></head><body>
            <div class="top"><table style="margin:0"><tr><td style="border:0">' . $head . '</td><td style="border:0;text-align:right" class="muted">' . collect([$logo ? e($product) : null, $support ? e($support) : null])->filter()->implode('<br>') . '</td></tr></table></div>
            <h1>Constancia de pago</h1>
            <div class="muted">N.º ' . e(self::number($invoice)) . ' · emitida el ' . $date(now()) . '</div>
            <div class="amount">' . $amount . '</div>
            <table>' . $list . '</table>
            <p class="note">Esta constancia confirma que ' . e($product) . ' recibió el pago de tu plan. ' . (PlanInvoices::ready() && $invoice->doc_type ? 'El comprobante electrónico de este pago es la ' . e(lcfirst((string) PlanInvoices::label($invoice))) . '.' : 'No es una boleta ni una factura electrónica.') . '</p>
        </body></html>';

        return Documents::pdf($html);
    }
}
