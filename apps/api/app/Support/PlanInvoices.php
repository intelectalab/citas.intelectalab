<?php

namespace App\Support;

use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Support\Billing\InvoiceProvider;
use App\Support\Billing\NubefactInvoiceProvider;
use App\Support\Billing\SimulatedInvoiceProvider;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PlanInvoices
{
    public const NOT_READY = 'Falta actualizar la base de datos para emitir la boleta o factura del plan: ejecuta php artisan migrate.';

    public const SERIES = ['boleta' => 'B001', 'factura' => 'F001'];

    public const TAX_RATE = 18;

    public const MAX_ATTEMPTS = 12;

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasColumn('subscription_invoices', 'doc_type') && Schema::hasTable('saas_invoice_series') && Schema::hasColumn('tenants', 'billing');
    }

    public static function forget(): void
    {
        self::$ready = null;
    }

    public static function integration(): ?object
    {
        return Schema::hasTable('platform_integrations') ? DB::table('platform_integrations')->where('kind', 'invoicing')->first() : null;
    }

    public static function config(): array
    {
        $row = self::integration();
        $settings = $row ? (json_decode($row->settings ?? '{}', true) ?: []) : [];

        return [
            'provider'   => $row && $row->status === 'active' && $row->mode !== 'simulated' ? $row->provider : 'simulated',
            'connected'  => (bool) ($row && $row->status === 'active' && $row->mode !== 'simulated' && $row->credentials),
            'ruc'        => $settings['ruc'] ?? null,
            'legal_name' => $settings['legal_name'] ?? null,
            'address'    => $settings['address'] ?? null,
            'series'     => array_merge(self::SERIES, array_intersect_key($settings['series'] ?? [], self::SERIES)),
        ];
    }

    public static function save(array $values): array
    {
        $current = self::integration();
        $settings = [
            'ruc'        => $values['ruc'] ?? null,
            'legal_name' => $values['legal_name'] ?? null,
            'address'    => $values['address'] ?? null,
            'series'     => array_merge(self::SERIES, array_filter(array_intersect_key($values['series'] ?? [], self::SERIES))),
        ];
        $mode = ($values['mode'] ?? 'simulated') === 'live' ? 'live' : 'simulated';
        $credentials = $current?->credentials;

        if (!empty($values['route']) || !empty($values['token'])) {
            $old = $credentials ? (json_decode(Crypt::decryptString($credentials), true) ?: []) : [];
            $credentials = Crypt::encryptString(json_encode(['route' => $values['route'] ?? $old['route'] ?? '', 'token' => $values['token'] ?? $old['token'] ?? '']));
        }

        if ($mode === 'live' && !$credentials) {
            throw ValidationException::withMessages(['token' => 'Para emitir de verdad escribe la ruta y el token de Nubefact.']);
        }

        $row = ['provider' => 'nubefact', 'mode' => $mode, 'credentials' => $credentials, 'settings' => json_encode($settings), 'status' => 'active', 'updated_at' => now()];

        $current
            ? DB::table('platform_integrations')->where('id', $current->id)->update($row)
            : DB::table('platform_integrations')->insert($row + ['kind' => 'invoicing', 'created_at' => now()]);

        return self::config();
    }

    public static function provider(): InvoiceProvider
    {
        $row = self::integration();

        return $row && $row->status === 'active' && $row->mode !== 'simulated' && $row->credentials ? new NubefactInvoiceProvider($row) : new SimulatedInvoiceProvider();
    }

    public static function billingOf(Tenant $tenant): array
    {
        $saved = self::ready() ? (is_array($tenant->billing) ? $tenant->billing : (json_decode((string) $tenant->billing, true) ?: [])) : [];
        $company = DB::table('companies')->where('tenant_id', $tenant->id)->orderBy('created_at')->first();
        $taxId = preg_replace('/\D/', '', (string) ($company->tax_id ?? ''));
        $type = in_array($saved['doc_type'] ?? null, ['boleta', 'factura'], true) ? $saved['doc_type'] : 'boleta';

        return [
            'doc_type'      => $type,
            'ruc'           => $saved['ruc'] ?? (strlen($taxId) === 11 ? $taxId : null),
            'business_name' => $saved['business_name'] ?? ($company->legal_name ?? $company->trade_name ?? $tenant->name),
            'address'       => $saved['address'] ?? ($company->address ?? null),
            'dni'           => $saved['dni'] ?? (strlen($taxId) === 8 ? $taxId : null),
            'email'         => $saved['email'] ?? null,
            'saved'         => !empty($saved),
        ];
    }

    public static function saveBilling(Tenant $tenant, array $input): array
    {
        abort_unless(self::ready(), 503, self::NOT_READY);
        $type = $input['doc_type'] ?? 'boleta';
        $ruc = preg_replace('/\D/', '', (string) ($input['ruc'] ?? ''));
        $name = trim((string) ($input['business_name'] ?? ''));

        if ($type === 'factura') {
            if (!preg_match('/^(10|15|17|20)\d{9}$/', $ruc)) {
                throw ValidationException::withMessages(['ruc' => 'La factura necesita un RUC válido de 11 dígitos.']);
            }
            if ($name === '') {
                throw ValidationException::withMessages(['business_name' => 'Escribe la razón social para la factura.']);
            }
        }

        $dni = preg_replace('/\D/', '', (string) ($input['dni'] ?? ''));

        if ($dni !== '' && strlen($dni) !== 8) {
            throw ValidationException::withMessages(['dni' => 'El DNI tiene 8 dígitos.']);
        }

        $tenant->forceFill(['billing' => array_filter([
            'doc_type'      => $type,
            'ruc'           => $type === 'factura' ? $ruc : null,
            'business_name' => $name !== '' ? mb_substr($name, 0, 200) : null,
            'address'       => isset($input['address']) && trim($input['address']) !== '' ? mb_substr(trim($input['address']), 0, 255) : null,
            'dni'           => $dni !== '' ? $dni : null,
            'email'         => !empty($input['email']) ? mb_strtolower(trim($input['email'])) : null,
        ], fn ($value) => $value !== null)])->save();

        return self::billingOf($tenant->fresh());
    }

    private static function nextNumber(string $type, string $serie): int
    {
        DB::table('saas_invoice_series')->insertOrIgnore(['doc_type' => $type, 'serie' => $serie, 'next_number' => 1, 'created_at' => now()]);

        return (int) DB::selectOne('UPDATE saas_invoice_series SET next_number = next_number + 1 WHERE doc_type = ? AND serie = ? RETURNING next_number - 1 AS number', [$type, $serie])->number;
    }

    public static function issue(SubscriptionInvoice $invoice): ?SubscriptionInvoice
    {
        if (!self::ready() || (float) $invoice->amount <= 0) {
            return null;
        }

        return Rls::bypass(function () use ($invoice) {
            $invoice = $invoice->fresh();

            if ($invoice->doc_type) {
                return $invoice;
            }

            $tenant = Tenant::find($invoice->tenant_id);
            $billing = self::billingOf($tenant);
            $type = $billing['doc_type'] === 'factura' && $billing['ruc'] ? 'factura' : 'boleta';
            $serie = self::config()['series'][$type];
            $total = round((float) $invoice->amount, 2);
            $tax = round($total - $total / (1 + self::TAX_RATE / 100), 2);
            $provider = self::provider();

            $issued = DB::transaction(function () use ($invoice, $type, $serie, $billing, $tax, $provider) {
                $invoice = SubscriptionInvoice::whereKey($invoice->id)->lockForUpdate()->first();

                if (!$invoice || $invoice->doc_type) {
                    return false;
                }

                $invoice->forceFill([
                    'doc_type'            => $type,
                    'serie'               => $serie,
                    'number'              => self::nextNumber($type, $serie),
                    'customer_doc_type'   => $type === 'factura' ? 'RUC' : ($billing['dni'] ? 'DNI' : null),
                    'customer_doc_number' => $type === 'factura' ? $billing['ruc'] : $billing['dni'],
                    'customer_name'       => mb_substr((string) $billing['business_name'], 0, 200),
                    'customer_address'    => $billing['address'] ? mb_substr($billing['address'], 0, 255) : null,
                    'tax'                 => $tax,
                    'provider'            => $provider->key(),
                    'simulated'           => !$provider->real(),
                    'sunat_status'        => 'pending',
                    'sunat_message'       => 'Pendiente de envío.',
                    'issued_at'           => now(),
                ])->save();

                return true;
            });

            return $issued ? self::deliver($invoice->fresh()) : $invoice->fresh();
        });
    }

    public static function document(SubscriptionInvoice $invoice): array
    {
        $invoice->loadMissing('subscription.plan');
        $config = self::config();
        $tenant = Tenant::find($invoice->tenant_id);
        $total = round((float) $invoice->amount, 2);
        $tax = round((float) $invoice->tax, 2);
        $plan = $invoice->subscription?->plan;
        $cycle = $invoice->subscription?->billing_cycle === 'yearly' ? 'anual' : 'mensual';
        $email = $tenant ? (self::billingOf($tenant)['email'] ?? DB::table('users')->where('tenant_id', $tenant->id)->where('is_owner', true)->value('email')) : null;

        return [
            'doc_type'            => $invoice->doc_type,
            'serie'               => $invoice->serie,
            'number'              => $invoice->number,
            'currency'            => $invoice->currency ?: 'PEN',
            'customer_doc_type'   => $invoice->customer_doc_type,
            'customer_doc_number' => $invoice->customer_doc_number,
            'customer_name'       => $invoice->customer_name,
            'customer_address'    => $invoice->customer_address,
            'customer_email'      => $email,
            'issued_at'           => $invoice->issued_at ?? now(),
            'total'               => $total,
            'tax'                 => $tax,
            'taxable'             => round($total - $tax, 2),
            'tax_rate'            => self::TAX_RATE,
            'issuer'              => ['ruc' => $config['ruc'], 'legal_name' => $config['legal_name'] ?? PlatformMail::productName(), 'address' => $config['address']],
            'lines'               => [[
                'unit'        => 'ZZ',
                'code'        => 'PLAN',
                'quantity'    => 1,
                'description' => 'Suscripción ' . PlatformMail::productName() . ($plan ? " · plan {$plan->name} ({$cycle})" : ''),
                'total'       => $total,
                'tax'         => $tax,
                'taxable'     => round($total - $tax, 2),
            ]],
        ];
    }

    public static function deliver(SubscriptionInvoice $invoice): SubscriptionInvoice
    {
        if (!$invoice->doc_type || $invoice->sunat_status === 'accepted') {
            return $invoice;
        }

        $provider = $invoice->provider === 'simulated' ? new SimulatedInvoiceProvider() : self::provider();
        $attempts = (int) $invoice->send_attempts + 1;
        $stop = false;

        try {
            $document = self::document($invoice);
            $answer = $invoice->external_doc_id ? $provider->status($document) : $provider->send($document);

            if (!empty($answer['not_found'])) {
                $answer = $provider->send($document);
            }
        } catch (\DomainException $e) {
            $answer = ['status' => 'pending', 'message' => $e->getMessage()];
            $stop = true;
        } catch (\Throwable $e) {
            report($e);
            $answer = ['status' => 'pending', 'message' => 'No se pudo conectar con el proveedor de facturación. Se volverá a intentar solo.'];
        }

        $status = in_array($answer['status'] ?? '', ['accepted', 'rejected', 'pending'], true) ? $answer['status'] : 'pending';

        Rls::bypass(fn () => $invoice->forceFill([
            'provider'        => $provider->key(),
            'simulated'       => (bool) ($answer['simulated'] ?? !$provider->real()),
            'sunat_status'    => $status,
            'sunat_message'   => isset($answer['message']) ? mb_substr((string) $answer['message'], 0, 255) : null,
            'external_doc_id' => $answer['external_id'] ?? $invoice->external_doc_id,
            'doc_pdf_url'     => isset($answer['pdf_url']) ? mb_substr((string) $answer['pdf_url'], 0, 500) : $invoice->doc_pdf_url,
            'send_attempts'   => $status === 'pending' ? $attempts : 0,
            'next_attempt_at' => $status === 'pending' && !$stop && $attempts < self::MAX_ATTEMPTS ? now()->addMinutes(min(5 * (2 ** max(0, $attempts - 1)), 360)) : null,
        ])->save());

        return $invoice->fresh();
    }

    public static function retryPending(int $limit = 100): int
    {
        if (!self::ready()) {
            return 0;
        }

        $done = 0;

        Rls::bypass(function () use ($limit, &$done) {
            SubscriptionInvoice::where('sunat_status', 'pending')->whereNotNull('next_attempt_at')->where('next_attempt_at', '<=', now())
                ->orderBy('next_attempt_at')->limit($limit)->get()
                ->each(function (SubscriptionInvoice $invoice) use (&$done) {
                    self::deliver($invoice);
                    $done++;
                });
        });

        return $done;
    }

    public static function label(SubscriptionInvoice $invoice): ?string
    {
        return $invoice->doc_type ? (($invoice->doc_type === 'factura' ? 'Factura' : 'Boleta') . " {$invoice->serie}-" . str_pad((string) $invoice->number, 8, '0', STR_PAD_LEFT)) : null;
    }

    public static function stateLabel(SubscriptionInvoice $invoice): ?string
    {
        if (!$invoice->doc_type) {
            return null;
        }

        if ($invoice->simulated) {
            return 'Simulado: no se envió a SUNAT';
        }

        return match ($invoice->sunat_status) {
            'accepted' => 'Aceptado por SUNAT',
            'rejected' => 'Rechazado por SUNAT',
            default    => 'En envío a SUNAT',
        };
    }

    public static function present(SubscriptionInvoice $invoice): ?array
    {
        if (!self::ready() || !$invoice->doc_type) {
            return null;
        }

        return [
            'label'     => self::label($invoice),
            'doc_type'  => $invoice->doc_type,
            'status'    => $invoice->sunat_status,
            'state'     => self::stateLabel($invoice),
            'message'   => $invoice->sunat_message,
            'simulated' => (bool) $invoice->simulated,
            'pdf_url'   => $invoice->doc_pdf_url,
        ];
    }
}
