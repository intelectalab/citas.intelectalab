<?php

namespace App\Support\Payments;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MercadoPagoPaymentProvider implements PaymentProvider
{
    public const PAID = ['processed'];

    public const FAILED = ['failed', 'canceled', 'cancelled', 'expired'];

    private array $credentials;

    private array $settings;

    public function __construct(?object $integration)
    {
        $this->credentials = $integration?->credentials ? (json_decode(Crypt::decryptString($integration->credentials), true) ?: []) : [];
        $this->settings = $integration?->settings ? (is_string($integration->settings) ? (json_decode($integration->settings, true) ?: []) : (array) $integration->settings) : [];
    }

    public function key(): string
    {
        return 'mercadopago';
    }

    public function simulated(): bool
    {
        return false;
    }

    private function client(): MercadoPagoClient
    {
        $client = MercadoPagoClient::fromCredentials($this->credentials);

        if (!$client) {
            throw ValidationException::withMessages(['link' => 'Falta el Access Token de Mercado Pago en Configuración → Integraciones.']);
        }

        return $client;
    }

    public function currency(): ?string
    {
        return $this->settings['currency'] ?? null;
    }

    public function secret(): ?string
    {
        $secret = trim((string) ($this->credentials['webhook_secret'] ?? ''));

        return $secret !== '' ? $secret : null;
    }

    public function order(array $values): array
    {
        $currency = $this->currency();

        if ($currency && $values['currency'] && strtoupper($values['currency']) !== $currency) {
            throw ValidationException::withMessages(['link' => "La cuenta de Mercado Pago cobra en {$currency} y este cobro está en {$values['currency']}: no se puede cobrar en línea."]);
        }

        $body = [
            'type'               => 'online',
            'processing_mode'    => 'manual',
            'total_amount'       => MercadoPagoClient::money((float) $values['amount']),
            'external_reference' => $values['reference'],
            'description'        => mb_substr($values['description'], 0, 250),
            'items'              => [[
                'title'      => mb_substr($values['description'], 0, 250),
                'unit_price' => MercadoPagoClient::money((float) $values['amount']),
                'quantity'   => 1,
            ]],
            'config'             => ['online' => [
                'success_url' => $values['return_url'],
                'failure_url' => $values['return_url'],
                'pending_url' => $values['return_url'],
                'auto_return' => 'all',
            ]],
        ];

        if (!empty($values['email'])) {
            $body['payer'] = ['email' => $values['email']];
        }

        $result = $this->client()->call('post', '/v1/orders', $body, $values['idempotency'] ?? (string) Str::uuid());

        if (!$result['ok'] || empty($result['data']['id'])) {
            throw ValidationException::withMessages(['link' => $result['message'] ?? 'Mercado Pago no creó el cobro.']);
        }

        return ['external_id' => (string) $result['data']['id'], 'checkout_url' => $result['data']['checkout_url'] ?? $result['data']['init_point'] ?? null];
    }

    public function checkout(object $link): array
    {
        return $this->order([
            'amount'      => (float) $link->amount,
            'currency'    => $link->currency ?? null,
            'reference'   => $link->token,
            'description' => $link->description,
            'email'       => $link->email ?? null,
            'return_url'  => \App\Support\PaymentLinks::url($link->token) . '?volver=1',
            'idempotency' => 'link:' . $link->id . ':' . ($link->attempt ?? 0),
        ]);
    }

    public function status(string $externalId): ?array
    {
        $result = $this->client()->call('get', '/v1/orders/' . rawurlencode($externalId));

        if (!$result['ok'] || !$result['data']) {
            return null;
        }

        return self::event($result['data']);
    }

    public static function event(array $order): array
    {
        $status = (string) ($order['status'] ?? '');
        $total = (float) ($order['total_amount'] ?? 0);
        $paid = (float) ($order['total_paid_amount'] ?? 0);
        $approved = in_array($status, self::PAID, true) && $paid + 0.001 >= $total;

        return [
            'event_id'    => 'order:' . ($order['id'] ?? '') . ':' . $status . ':' . MercadoPagoClient::money($paid),
            'type'        => $approved ? 'payment.approved' : (in_array($status, self::FAILED, true) ? 'payment.rejected' : 'payment.pending'),
            'reference'   => (string) ($order['external_reference'] ?? ''),
            'external_id' => (string) ($order['id'] ?? ''),
            'amount'      => $approved ? $paid : null,
            'payload'     => ['id' => $order['id'] ?? null, 'status' => $status, 'status_detail' => $order['status_detail'] ?? null, 'total_amount' => $order['total_amount'] ?? null, 'total_paid_amount' => $order['total_paid_amount'] ?? null],
        ];
    }

    public function refund(object $payment, float $amount): array
    {
        $orderId = (string) ($payment->external_reference ?? '');

        if ($orderId === '') {
            return ['ok' => false, 'message' => 'No se encontró el cobro en Mercado Pago.'];
        }

        $client = $this->client();
        $order = $client->call('get', '/v1/orders/' . rawurlencode($orderId));

        if (!$order['ok']) {
            return ['ok' => false, 'message' => $order['message']];
        }

        $paid = (float) ($order['data']['total_paid_amount'] ?? 0);
        $transaction = collect($order['data']['transactions']['payments'] ?? [])->first();
        $body = abs($paid - $amount) < 0.01 || !$transaction
            ? []
            : ['transactions' => [['id' => $transaction['id'], 'amount' => MercadoPagoClient::money($amount)]]];

        $result = $client->call('post', '/v1/orders/' . rawurlencode($orderId) . '/refund', $body, (string) Str::uuid());

        return [
            'ok'          => $result['ok'],
            'external_id' => $result['ok'] ? (string) ($result['data']['transactions']['refunds'][0]['id'] ?? $orderId) : null,
            'message'     => $result['ok'] ? 'Mercado Pago devolvió el dinero al cliente.' : $result['message'],
        ];
    }

    public function parse(Request $request): ?array
    {
        if (!MercadoPagoClient::signatureValid($request, $this->secret())) {
            return null;
        }

        return ['topic' => MercadoPagoClient::topic($request), 'id' => MercadoPagoClient::dataId($request)];
    }

    public function test(): array
    {
        $account = $this->client()->account();

        if (!$account['ok']) {
            return ['ok' => false, 'message' => $account['message']];
        }

        return [
            'ok'       => true,
            'currency' => $account['currency'],
            'message'  => 'Mercado Pago respondió: la cuenta ' . ($account['name'] ?? '') . ' está conectada' . ($account['currency'] ? " y cobra en {$account['currency']}." : '.'),
        ];
    }
}
