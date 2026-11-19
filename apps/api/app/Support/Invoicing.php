<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Billing\InvoiceProvider;
use App\Support\Billing\NubefactInvoiceProvider;
use App\Support\Billing\SimulatedInvoiceProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public const DEFAULT_SERIES = ['boleta' => 'B001', 'factura' => 'F001', 'nota_boleta' => 'BC01', 'nota_factura' => 'FC01'];

    public const SERIES_PATTERNS = ['boleta' => '/^B[A-Z0-9]{3}$/', 'factura' => '/^F[A-Z0-9]{3}$/', 'nota_boleta' => '/^B[A-Z0-9]{3}$/', 'nota_factura' => '/^F[A-Z0-9]{3}$/'];

    public const PROVIDERS = [
        'simulated' => ['label' => 'Simulación', 'class' => null],
        'nubefact'  => ['label' => 'Nubefact', 'class' => NubefactInvoiceProvider::class],
    ];

    public const VOID_DAYS = 7;

    public const MAX_ATTEMPTS = 12;

    private const TICKET_SERIE = 'T001';

    private const TICKET_CREDIT_SERIE = 'TC01';

    private static ?bool $electronic = null;

    private static ?bool $branchSeries = null;

    public static function electronicReady(): bool
    {
        return self::$electronic ??= Schema::hasColumn('invoices', 'send_attempts');
    }

    public static function branchSeriesReady(): bool
    {
        return self::$branchSeries ??= Schema::hasColumn('branches', 'invoice_series');
    }

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

    public static function integration(string $tenantId): ?object
    {
        return DB::table('tenant_integrations')->where('tenant_id', $tenantId)->where('kind', 'invoicing')->first();
    }

    public static function settingsOf(?object $integration): array
    {
        if (!$integration || !$integration->settings) {
            return [];
        }

        return is_string($integration->settings) ? (json_decode($integration->settings, true) ?: []) : (array) $integration->settings;
    }

    public static function companySeries(string $tenantId): array
    {
        $saved = self::settingsOf(self::integration($tenantId))['series'] ?? [];

        return collect(self::DEFAULT_SERIES)->map(fn ($default, $key) => !empty($saved[$key]) ? $saved[$key] : $default)->all();
    }

    public static function branchSeries(?string $branchId): array
    {
        if (!$branchId || !self::branchSeriesReady()) {
            return [];
        }

        $raw = DB::table('branches')->where('id', $branchId)->value('invoice_series');
        $saved = is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;

        return array_filter(array_intersect_key($saved, self::DEFAULT_SERIES));
    }

    public static function seriesFor(string $tenantId, ?string $branchId, string $key): string
    {
        return self::branchSeries($branchId)[$key] ?? self::companySeries($tenantId)[$key];
    }

    public static function provider(Tenant $tenant): InvoiceProvider
    {
        $integration = DB::table('tenant_integrations')
            ->where('tenant_id', $tenant->id)
            ->where('kind', 'invoicing')
            ->where('status', 'active')
            ->first();

        $class = $integration && $integration->mode !== 'simulated' ? (self::PROVIDERS[$integration->provider]['class'] ?? null) : null;

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

    private static function nextNumber(string $tenantId, string $type, string $serie): int
    {
        DB::table('invoice_series')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'doc_type' => $type, 'serie' => $serie, 'next_number' => 1, 'created_at' => now(),
        ]);

        return (int) DB::selectOne(
            'UPDATE invoice_series SET next_number = next_number + 1 WHERE tenant_id = ? AND doc_type = ? AND serie = ? RETURNING next_number - 1 AS number',
            [$tenantId, $type, $serie],
        )->number;
    }

    private static function internal(): array
    {
        return ['provider' => 'internal', 'simulated' => false, 'sunat_status' => 'not_applicable', 'sunat_message' => null, 'external_id' => null];
    }

    private static function waiting(InvoiceProvider $provider): array
    {
        return [
            'provider'      => $provider->key(),
            'simulated'     => !$provider->real(),
            'sunat_status'  => 'pending',
            'sunat_message' => 'Pendiente de envío.',
            'external_id'   => null,
        ];
    }

    public static function issue(Tenant $tenant, object $sale, array $doc, ?User $user): object
    {
        $existing = self::ofSale($sale->id);

        if ($existing) {
            return $existing;
        }

        $type = $doc['doc_type'];
        $serie = $type === self::TICKET ? self::TICKET_SERIE : self::seriesFor($tenant->id, $sale->branch_id, $type);
        $customer = $sale->customer_id ? DB::table('customers')->where('id', $sale->customer_id)->first() : null;
        $customerDocType = $customer && $customer->type_document_id
            ? DB::table('type_documents')->where('id', $customer->type_document_id)->value('name')
            : null;

        $number = self::nextNumber($tenant->id, $type, $serie);
        $taxable = round((float) $sale->total - (float) $sale->tax, 2);

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
            'customer_name'       => $type === self::FACTURA ? $doc['business_name'] : ($customer ? Person::name($customer) : null),
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

        $provider = self::provider($tenant);
        $row += $type === self::TICKET ? self::internal() : self::waiting($provider);

        DB::table('invoices')->insert($row);

        if ($type !== self::TICKET) {
            self::schedule($tenant, $id, $provider);
        }

        Messaging::receipt($tenant, DB::table('sales')->where('id', $sale->id)->first() ?? $sale, $user);

        return DB::table('invoices')->where('id', $id)->first();
    }

    public static function ofSale(string $saleId): ?object
    {
        return DB::table('invoices')->where('sale_id', $saleId)->where('status', 'issued')->where('doc_type', '!=', self::CREDIT_NOTE)->first();
    }

    public static function creditNote(Tenant $tenant, object $sale, float $amount, string $reason, ?User $user, ?string $noteType = null, ?object $origin = null): ?object
    {
        $origin ??= self::ofSale($sale->id);

        if (!$origin) {
            return null;
        }

        $noteType ??= abs($amount - (float) $origin->total) < 0.005 ? 'devolucion_total' : 'disminucion';
        $serie = match ($origin->doc_type) {
            self::BOLETA  => self::seriesFor($tenant->id, $origin->branch_id, 'nota_boleta'),
            self::FACTURA => self::seriesFor($tenant->id, $origin->branch_id, 'nota_factura'),
            default       => self::TICKET_CREDIT_SERIE,
        };

        $number = self::nextNumber($tenant->id, self::CREDIT_NOTE, $serie);
        $tax = (float) $origin->total > 0 ? round((float) $origin->tax * $amount / (float) $origin->total, 2) : 0;

        $id = (string) Str::uuid();
        $row = [
            'id'                   => $id,
            'tenant_id'            => $tenant->id,
            'branch_id'            => $origin->branch_id,
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

        if (self::electronicReady()) {
            $row['note_type'] = $noteType;
        }

        $electronic = $origin->doc_type !== self::TICKET;
        $provider = self::provider($tenant);

        if ($origin->simulated) {
            $provider = new SimulatedInvoiceProvider();
        }

        $row += !$electronic ? self::internal() : self::waiting($provider);

        DB::table('invoices')->insert($row);

        if ($electronic) {
            self::schedule($tenant, $id, $provider);
        }

        return DB::table('invoices')->where('id', $id)->first();
    }

    private static function schedule(Tenant $tenant, string $id, InvoiceProvider $provider): void
    {
        if ($provider->real()) {
            DB::afterCommit(fn () => self::deliver($tenant, $id));
        } else {
            self::deliver($tenant, $id);
        }
    }

    public static function lines(object $invoice): array
    {
        $total = round((float) $invoice->total, 2);
        $tax = round((float) $invoice->tax, 2);
        $items = DB::table('sale_items')->where('sale_id', $invoice->sale_id)->orderBy('position')->get(['item_type', 'description', 'quantity', 'total']);
        $gross = round((float) $items->sum('total'), 2);
        $partial = $invoice->doc_type === self::CREDIT_NOTE && ($invoice->note_type ?? null) === 'disminucion';

        if ($partial || $items->isEmpty() || $gross <= 0) {
            return [[
                'unit' => 'ZZ', 'code' => 'AJUSTE', 'quantity' => 1,
                'description' => $invoice->doc_type === self::CREDIT_NOTE ? 'Devolución: ' . ($invoice->reason ?? 'ajuste') : 'Venta',
                'total' => $total, 'tax' => $tax, 'taxable' => round($total - $tax, 2),
            ]];
        }

        $lines = [];
        $restTotal = $total;
        $restTax = $tax;
        $last = $items->count() - 1;

        foreach ($items->values() as $index => $item) {
            $lineTotal = $index === $last ? $restTotal : round((float) $item->total * $total / $gross, 2);
            $lineTax = $index === $last ? $restTax : ($total > 0 ? round($lineTotal * $tax / $total, 2) : 0);
            $restTotal = round($restTotal - $lineTotal, 2);
            $restTax = round($restTax - $lineTax, 2);
            $quantity = (float) $item->quantity > 0 ? (float) $item->quantity : 1;

            $lines[] = [
                'unit'        => $item->item_type === 'product' ? 'NIU' : 'ZZ',
                'code'        => mb_strtoupper((string) $item->item_type),
                'description' => $item->description,
                'quantity'    => $quantity,
                'total'       => $lineTotal,
                'tax'         => $lineTax,
                'taxable'     => round($lineTotal - $lineTax, 2),
            ];
        }

        return $lines;
    }

    public static function document(Tenant $tenant, object $invoice): array
    {
        $company = DB::table('companies')->where('tenant_id', $tenant->id)->orderBy('created_at')->first();
        $sale = DB::table('sales')->where('id', $invoice->sale_id)->first();
        $origin = $invoice->reference_invoice_id ? DB::table('invoices')->where('id', $invoice->reference_invoice_id)->first() : null;
        $email = $invoice->customer_id ? DB::table('customers')->where('id', $invoice->customer_id)->value('email') : null;

        return (array) $invoice + [
            'tax_rate'       => (float) ($sale->tax_rate ?? 18),
            'customer_email' => $email,
            'issuer'         => ['ruc' => $company->tax_id ?? null, 'legal_name' => $company->legal_name ?? $company->trade_name ?? $tenant->name, 'address' => $company->address ?? null],
            'items'          => DB::table('sale_items')->where('sale_id', $invoice->sale_id)->get(['description', 'quantity', 'unit_price', 'discount', 'total'])->all(),
            'lines'          => self::lines($invoice),
            'reference'      => $origin ? ['doc_type' => $origin->doc_type, 'serie' => $origin->serie, 'number' => $origin->number] : null,
            'note_type'      => $invoice->note_type ?? null,
        ];
    }

    private static function backoff(int $attempts): Carbon
    {
        return now()->addMinutes(min(5 * (2 ** max(0, $attempts - 1)), 360));
    }

    public static function deliver(Tenant $tenant, string $invoiceId): ?object
    {
        $invoice = DB::table('invoices')->where('tenant_id', $tenant->id)->where('id', $invoiceId)->first();

        if (!$invoice || $invoice->doc_type === self::TICKET || $invoice->status !== 'issued' || $invoice->sunat_status === 'accepted') {
            return $invoice;
        }

        $provider = $invoice->provider === 'simulated' ? new SimulatedInvoiceProvider() : self::provider($tenant);

        if ($provider->key() !== $invoice->provider) {
            DB::table('invoices')->where('id', $invoice->id)->update(['sunat_message' => 'Pendiente de envío: vuelve a conectar ' . (self::PROVIDERS[$invoice->provider]['label'] ?? 'tu proveedor de facturación') . ' en Configuración → Integraciones.'] + (self::electronicReady() ? ['next_attempt_at' => null] : []));

            return DB::table('invoices')->where('id', $invoice->id)->first();
        }

        $received = $invoice->external_id !== null && $provider->key() === $invoice->provider;
        $attempts = (int) ($invoice->send_attempts ?? 0) + 1;
        $stop = false;

        try {
            $document = self::document($tenant, $invoice);
            $answer = $received ? $provider->status($document) : $provider->send($document);
            if (!empty($answer['not_found'])) {
                $answer = $provider->send($document);
            }
        } catch (\DomainException $e) {
            $answer = ['status' => 'pending', 'received' => false, 'message' => $e->getMessage()];
            $stop = true;
        } catch (\Throwable $e) {
            report($e);
            $answer = ['status' => 'pending', 'received' => false, 'message' => 'No se pudo conectar con ' . (self::PROVIDERS[$provider->key()]['label'] ?? 'el proveedor') . '. Se volverá a intentar solo.'];
        }

        $status = in_array($answer['status'] ?? '', ['accepted', 'rejected', 'pending'], true) ? $answer['status'] : 'pending';
        $values = [
            'provider'      => $provider->key(),
            'simulated'     => (bool) ($answer['simulated'] ?? !$provider->real()),
            'sunat_status'  => $status,
            'sunat_message' => isset($answer['message']) ? mb_substr((string) $answer['message'], 0, 255) : null,
            'external_id'   => $answer['external_id'] ?? ($received ? $invoice->external_id : null),
        ];

        if (Schema::hasColumn('invoices', 'sent_at')) {
            $values['sent_at'] = now();
        }

        if (self::electronicReady()) {
            $values += array_filter([
                'pdf_url' => isset($answer['pdf_url']) ? mb_substr((string) $answer['pdf_url'], 0, 500) : null,
                'xml_url' => isset($answer['xml_url']) ? mb_substr((string) $answer['xml_url'], 0, 500) : null,
                'cdr_url' => isset($answer['cdr_url']) ? mb_substr((string) $answer['cdr_url'], 0, 500) : null,
            ]);
            $values['send_attempts'] = $status === 'pending' ? $attempts : 0;
            $values['next_attempt_at'] = $status === 'pending' && !$stop && $attempts < self::MAX_ATTEMPTS ? self::backoff($attempts) : null;
        }

        DB::table('invoices')->where('id', $invoice->id)->update($values);

        return DB::table('invoices')->where('id', $invoice->id)->first();
    }

    public static function voidWindowOpen(object $invoice): bool
    {
        $issued = Carbon::parse($invoice->issued_at)->timezone('America/Lima')->startOfDay();

        return now('America/Lima')->startOfDay()->lte($issued->addDays(self::VOID_DAYS));
    }

    public static function void(Tenant $tenant, object $invoice, string $reason, User $user): array
    {
        $base = ['status' => 'voided', 'voided_at' => now(), 'voided_by' => $user->id, 'void_reason' => $reason];
        $electronic = self::electronicReady();
        $local = fn (string $message, string $method = 'local') => $base + ['sunat_message' => $message] + ($electronic ? ['void_method' => $method, 'void_status' => null, 'next_attempt_at' => null] : []);

        if ($invoice->doc_type === self::TICKET) {
            return $local('Anulado. Ticket interno.');
        }

        $provider = self::provider($tenant);

        if ($invoice->simulated || !$provider->real() || $invoice->provider !== $provider->key()) {
            return $local($invoice->simulated ? 'Anulado. Simulación: la baja no se envió a SUNAT.' : 'Anulado: falta comunicar la baja a SUNAT desde tu proveedor.');
        }

        if ($invoice->sunat_status === 'rejected') {
            return $local('Anulado. SUNAT lo había rechazado: no tiene validez y no hace falta darlo de baja.');
        }

        if ($invoice->sunat_status === 'pending' && $invoice->external_id === null) {
            try {
                $check = $provider->status(self::document($tenant, $invoice));
            } catch (\Throwable $e) {
                throw ValidationException::withMessages(['invoice' => 'No se pudo consultar a Nubefact si este comprobante llegó. Inténtalo en unos minutos.']);
            }

            if (!empty($check['not_found'])) {
                return $local('Anulado. Nunca llegó a SUNAT: no hace falta darlo de baja.');
            }

            if (!$check['received']) {
                throw ValidationException::withMessages(['invoice' => $check['message']]);
            }
        }

        if (!$electronic) {
            return $local('Anulado: falta comunicar la baja a SUNAT desde tu proveedor.');
        }

        if (self::voidWindowOpen($invoice)) {
            try {
                $answer = $provider->void(self::document($tenant, $invoice), $reason);
            } catch (\Throwable $e) {
                report($e);
                throw ValidationException::withMessages(['invoice' => 'Nubefact no respondió. El comprobante sigue vigente: inténtalo en unos minutos.']);
            }

            if (in_array($answer['status'], ['error', 'rejected'], true)) {
                throw ValidationException::withMessages(['invoice' => $answer['message'] . ' El comprobante sigue vigente.']);
            }

            return $base + [
                'sunat_message'   => $answer['message'],
                'void_method'     => 'baja',
                'void_status'     => $answer['status'],
                'void_ticket'     => $answer['ticket'] ?? null,
                'send_attempts'   => 0,
                'next_attempt_at' => $answer['status'] === 'pending' ? self::backoff(1) : null,
            ];
        }

        $sale = DB::table('sales')->where('id', $invoice->sale_id)->first();
        $note = self::creditNote($tenant, $sale, (float) $invoice->total, 'Anulación de la operación: ' . $reason, $user, 'anulacion', $invoice);

        return $base + [
            'sunat_message' => 'Anulado con la nota de crédito ' . sprintf('%s-%08d', $note->serie, $note->number) . ': pasaron más de ' . self::VOID_DAYS . ' días para comunicar la baja.',
            'void_method'   => 'credit_note',
            'void_status'   => null,
            'void_note_id'  => $note->id,
        ];
    }

    public static function checkVoid(Tenant $tenant, object $invoice): void
    {
        $provider = self::provider($tenant);
        $attempts = (int) $invoice->send_attempts + 1;

        try {
            $answer = $provider->voidStatus(self::document($tenant, $invoice));
        } catch (\Throwable $e) {
            report($e);
            $answer = ['status' => 'pending', 'message' => 'Baja enviada. No se pudo consultar a Nubefact; se volverá a intentar solo.'];
        }

        $status = $answer['status'] === 'error' ? 'pending' : $answer['status'];

        DB::table('invoices')->where('id', $invoice->id)->update([
            'void_status'     => $status,
            'sunat_message'   => mb_substr((string) $answer['message'], 0, 255),
            'send_attempts'   => $status === 'pending' ? $attempts : 0,
            'next_attempt_at' => $status === 'pending' && $attempts < self::MAX_ATTEMPTS ? self::backoff($attempts) : null,
        ]);
    }

    public static function retryPending(int $limit = 100): array
    {
        if (!self::electronicReady()) {
            return ['sent' => 0, 'voids' => 0];
        }

        $due = fn ($q) => $q->where(fn ($w) => $w->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->where('send_attempts', '<', self::MAX_ATTEMPTS);
        $tenants = [];
        $tenantOf = function (string $id) use (&$tenants) {
            return $tenants[$id] ??= Tenant::with('settings')->find($id);
        };

        $pending = DB::table('invoices')
            ->where('status', 'issued')
            ->where('sunat_status', 'pending')
            ->where('doc_type', '!=', self::TICKET)
            ->where(fn ($q) => $q->whereNotNull('next_attempt_at')->orWhere('send_attempts', 0))
            ->where($due)
            ->orderBy('issued_at')
            ->limit($limit)
            ->get(['id', 'tenant_id']);

        foreach ($pending as $row) {
            if ($tenant = $tenantOf($row->tenant_id)) {
                self::deliver($tenant, $row->id);
            }
        }

        $voids = DB::table('invoices')->where('status', 'voided')->where('void_status', 'pending')->where($due)->limit($limit)->get();

        foreach ($voids as $row) {
            if ($tenant = $tenantOf($row->tenant_id)) {
                self::checkVoid($tenant, $row);
            }
        }

        return ['sent' => $pending->count(), 'voids' => $voids->count()];
    }

    public static function stateLabel(object $invoice): string
    {
        if ($invoice->status === 'voided') {
            return match ($invoice->void_status ?? null) {
                'pending' => 'Anulado: esperando que SUNAT confirme la baja',
                'accepted' => 'Anulado: SUNAT aceptó la baja',
                default   => ($invoice->void_method ?? null) === 'credit_note' ? 'Anulado con nota de crédito' : 'Anulado',
            };
        }

        return match ($invoice->sunat_status) {
            'not_applicable' => 'Interno: no va a SUNAT',
            'accepted'       => $invoice->simulated ? 'Simulado: no se envió a SUNAT' : 'Aceptado por SUNAT',
            'rejected'       => 'Rechazado por SUNAT',
            default          => $invoice->external_id ? 'Esperando respuesta de SUNAT' : 'Pendiente de envío',
        };
    }

    public static function present(?object $invoice): ?array
    {
        if (!$invoice) {
            return null;
        }

        $next = $invoice->next_attempt_at ?? null;

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
            'provider'       => $invoice->provider,
            'sunat_status'   => $invoice->sunat_status,
            'sunat_message'  => $invoice->sunat_message,
            'state_label'    => self::stateLabel($invoice),
            'received'       => $invoice->external_id !== null,
            'next_attempt_at' => $next ? Carbon::parse($next)->toIso8601String() : null,
            'gave_up'        => $invoice->status === 'issued' && $invoice->sunat_status === 'pending' && self::electronicReady() && !$next && (int) ($invoice->send_attempts ?? 0) > 0,
            'pdf_url'        => $invoice->pdf_url ?? null,
            'xml_url'        => $invoice->xml_url ?? null,
            'cdr_url'        => $invoice->cdr_url ?? null,
            'void_method'    => $invoice->void_method ?? null,
            'void_status'    => $invoice->void_status ?? null,
            'reason'         => $invoice->reason ?? null,
            'issued_at'      => Carbon::parse($invoice->issued_at)->toIso8601String(),
        ];
    }
}
