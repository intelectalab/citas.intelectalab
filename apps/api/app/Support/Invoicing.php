<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Billing\InvoiceProvider;
use App\Support\Billing\SimulatedInvoiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Invoicing
{
    public const TICKET = 'ticket';
    public const BOLETA = 'boleta';
    public const FACTURA = 'factura';
    public const CREDIT_NOTE = 'nota_credito';

    public const LABELS = [
        self::TICKET  => 'Comprobante interno',
        self::BOLETA  => 'Boleta',
        self::FACTURA => 'Factura',
        self::CREDIT_NOTE => 'Nota de crédito',
    ];

    private const SERIES = [self::TICKET => 'T001', self::BOLETA => 'B001', self::FACTURA => 'F001'];

    private const CREDIT_SERIES = [self::TICKET => 'TC01', self::BOLETA => 'BC01', self::FACTURA => 'FC01'];

    private const PROVIDERS = [];

    public static function types(Tenant $tenant): array
    {
        return $tenant->hasModule('invoicing') ? [self::BOLETA, self::FACTURA] : [self::TICKET];
    }

    public static function defaultType(Tenant $tenant): string
    {
        $types = self::types($tenant);
        $preferred = (string) AppointmentFlow::rule($tenant, 'invoice.default_type');

        return in_array($preferred, $types, true) ? $preferred : $types[0];
    }

    public static function provider(Tenant $tenant): InvoiceProvider
    {
        $integration = DB::table('tenant_integrations')
            ->where('tenant_id', $tenant->id)
            ->where('kind', 'invoicing')
            ->where('status', 'active')
            ->first();

        $class = $integration && $integration->mode !== 'simulated' ? (self::PROVIDERS[$integration->provider] ?? null) : null;

        return $class ? new $class($integration) : new SimulatedInvoiceProvider();
    }

    public static function validated(Tenant $tenant, array $input): array
    {
        $type = $input['doc_type'] ?? self::defaultType($tenant);

        if (!in_array($type, self::types($tenant), true)) {
            throw ValidationException::withMessages(['doc_type' => 'Tu empresa no emite ese tipo de comprobante.']);
        }

        $ruc = trim((string) ($input['ruc'] ?? ''));
        $name = trim((string) ($input['business_name'] ?? ''));

        if ($type === self::FACTURA) {
            if (!preg_match('/^(10|15|17|20)\d{9}$/', $ruc)) {
                throw ValidationException::withMessages(['ruc' => 'La factura necesita un RUC válido de 11 dígitos.']);
            }
            if ($name === '') {
                throw ValidationException::withMessages(['business_name' => 'Escribe la razón social para la factura.']);
            }
        }

        return [
            'doc_type'      => $type,
            'ruc'           => $type === self::FACTURA ? $ruc : null,
            'business_name' => $type === self::FACTURA ? $name : null,
            'address'       => $type === self::FACTURA ? (trim((string) ($input['address'] ?? '')) ?: null) : null,
        ];
    }

    public static function issue(Tenant $tenant, object $sale, array $doc, ?User $user): object
    {
        $existing = self::ofSale($sale->id);

        if ($existing) {
            return $existing;
        }

        $type = $doc['doc_type'];
        $serie = self::SERIES[$type];
        $customer = $sale->customer_id ? DB::table('customers')->where('id', $sale->customer_id)->first() : null;
        $customerDocType = $customer && $customer->type_document_id
            ? DB::table('type_documents')->where('id', $customer->type_document_id)->value('name')
            : null;

        DB::table('invoice_series')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'doc_type' => $type, 'serie' => $serie, 'next_number' => 1, 'created_at' => now(),
        ]);
        $number = DB::selectOne(
            'UPDATE invoice_series SET next_number = next_number + 1 WHERE tenant_id = ? AND doc_type = ? AND serie = ? RETURNING next_number - 1 AS number',
            [$tenant->id, $type, $serie],
        )->number;

        $taxable = round((float) $sale->total - (float) $sale->tax, 2);
        $company = DB::table('companies')->where('tenant_id', $tenant->id)->orderBy('created_at')->first();

        $id = (string) Str::uuid();
        $row = [
            'id'                  => $id,
            'tenant_id'           => $tenant->id,
            'branch_id'           => $sale->branch_id,
            'sale_id'             => $sale->id,
            'customer_id'         => $sale->customer_id,
            'doc_type'            => $type,
            'serie'               => $serie,
            'number'              => $number,
            'customer_doc_type'   => $type === self::FACTURA ? 'RUC' : $customerDocType,
            'customer_doc_number' => $type === self::FACTURA ? $doc['ruc'] : ($customer->document_number ?? null),
            'customer_name'       => $type === self::FACTURA ? $doc['business_name'] : ($customer ? \App\Support\Person::name($customer) : null),
            'customer_address'    => $doc['address'] ?? null,
            'taxable'             => $taxable,
            'tax'                 => $sale->tax,
            'total'               => $sale->total,
            'status'              => 'issued',
        ] + Currency::column($sale->currency ?? Currency::of($tenant)) + [
            'issued_by'           => $user?->id,
            'issued_at'           => now(),
            'created_at'          => now(),
        ];

        if ($type === self::TICKET) {
            $row += ['provider' => 'internal', 'simulated' => false, 'sunat_status' => 'not_applicable', 'sunat_message' => null, 'external_id' => null];
        } else {
            $provider = self::provider($tenant);

            try {
                $answer = $provider->send($row + [
                    'issuer' => ['ruc' => $company->tax_id ?? null, 'legal_name' => $company->legal_name ?? $company->trade_name ?? $tenant->name, 'address' => $company->address ?? null],
                    'items'  => DB::table('sale_items')->where('sale_id', $sale->id)->get(['description', 'quantity', 'unit_price', 'discount', 'total'])->all(),
                ]);
            } catch (\Throwable $e) {
                report($e);
                $answer = ['status' => 'pending', 'message' => 'El proveedor de facturación no respondió. Queda pendiente de envío.', 'external_id' => null, 'simulated' => false];
            }

            $row += [
                'provider'      => $provider->key(),
                'simulated'     => (bool) ($answer['simulated'] ?? false),
                'sunat_status'  => in_array($answer['status'] ?? '', ['accepted', 'rejected', 'pending'], true) ? $answer['status'] : 'pending',
                'sunat_message' => isset($answer['message']) ? mb_substr((string) $answer['message'], 0, 255) : null,
                'external_id'   => $answer['external_id'] ?? null,
            ];
        }

        DB::table('invoices')->insert($row);
        Messaging::receipt($tenant, DB::table('sales')->where('id', $sale->id)->first() ?? $sale, $user);

        return DB::table('invoices')->where('id', $id)->first();
    }

    public static function ofSale(string $saleId): ?object
    {
        return DB::table('invoices')->where('sale_id', $saleId)->where('status', 'issued')->where('doc_type', '!=', self::CREDIT_NOTE)->first();
    }

    public static function creditNote(Tenant $tenant, object $sale, float $amount, string $reason, ?User $user): ?object
    {
        $origin = self::ofSale($sale->id);

        if (!$origin) {
            return null;
        }

        $serie = self::CREDIT_SERIES[$origin->doc_type] ?? 'NC01';

        DB::table('invoice_series')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'doc_type' => self::CREDIT_NOTE, 'serie' => $serie, 'next_number' => 1, 'created_at' => now(),
        ]);
        $number = DB::selectOne(
            'UPDATE invoice_series SET next_number = next_number + 1 WHERE tenant_id = ? AND doc_type = ? AND serie = ? RETURNING next_number - 1 AS number',
            [$tenant->id, self::CREDIT_NOTE, $serie],
        )->number;

        $tax = (float) $sale->total > 0 ? round((float) $sale->tax * $amount / (float) $sale->total, 2) : 0;

        $id = (string) Str::uuid();
        $row = [
            'id'                   => $id,
            'tenant_id'            => $tenant->id,
            'branch_id'            => $sale->branch_id,
            'sale_id'              => $sale->id,
            'customer_id'          => $sale->customer_id,
            'doc_type'             => self::CREDIT_NOTE,
            'serie'                => $serie,
            'number'               => $number,
            'customer_doc_type'    => $origin->customer_doc_type,
            'customer_doc_number'  => $origin->customer_doc_number,
            'customer_name'        => $origin->customer_name,
            'customer_address'     => $origin->customer_address,
            'taxable'              => round($amount - $tax, 2),
            'tax'                  => $tax,
            'total'                => round($amount, 2),
            'status'               => 'issued',
        ] + Currency::column($origin->currency ?? $sale->currency ?? Currency::of($tenant)) + [
            'reference_invoice_id' => $origin->id,
            'reason'               => mb_substr($reason, 0, 255),
            'issued_by'            => $user?->id,
            'issued_at'            => now(),
            'created_at'           => now(),
        ];

        if ($origin->doc_type === self::TICKET) {
            $row += ['provider' => 'internal', 'simulated' => false, 'sunat_status' => 'not_applicable', 'sunat_message' => null, 'external_id' => null];
        } else {
            $provider = self::provider($tenant);

            try {
                $answer = $provider->send($row + ['reference' => sprintf('%s-%08d', $origin->serie, $origin->number)]);
            } catch (\Throwable $e) {
                report($e);
                $answer = ['status' => 'pending', 'message' => 'El proveedor de facturación no respondió. Queda pendiente de envío.', 'external_id' => null, 'simulated' => false];
            }

            $row += [
                'provider'      => $provider->key(),
                'simulated'     => (bool) ($answer['simulated'] ?? false),
                'sunat_status'  => in_array($answer['status'] ?? '', ['accepted', 'rejected', 'pending'], true) ? $answer['status'] : 'pending',
                'sunat_message' => isset($answer['message']) ? mb_substr((string) $answer['message'], 0, 255) : null,
                'external_id'   => $answer['external_id'] ?? null,
            ];
        }

        DB::table('invoices')->insert($row);

        return DB::table('invoices')->where('id', $id)->first();
    }

    public static function present(?object $invoice): ?array
    {
        if (!$invoice) {
            return null;
        }

        return [
            'id'             => $invoice->id,
            'doc_type'       => $invoice->doc_type,
            'doc_type_label' => self::LABELS[$invoice->doc_type] ?? $invoice->doc_type,
            'number'         => sprintf('%s-%08d', $invoice->serie, $invoice->number),
            'customer_doc_type'   => $invoice->customer_doc_type,
            'customer_doc_number' => $invoice->customer_doc_number,
            'customer_name'  => $invoice->customer_name,
            'customer_address' => $invoice->customer_address,
            'taxable'        => $invoice->taxable,
            'tax'            => $invoice->tax,
            'total'          => $invoice->total,
            'currency'       => $invoice->currency ?? Currency::DEFAULT,
            'status'         => $invoice->status,
            'simulated'      => (bool) $invoice->simulated,
            'sunat_status'   => $invoice->sunat_status,
            'sunat_message'  => $invoice->sunat_message,
            'reason'         => $invoice->reason ?? null,
            'issued_at'      => \Illuminate\Support\Carbon::parse($invoice->issued_at)->toIso8601String(),
        ];
    }
}
