<?php

namespace App\Support\Payments;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class CulqiPaymentProvider implements PaymentProvider
{
    private array $credentials;

    public function __construct(?object $integration)
    {
        $this->credentials = $integration?->credentials ? (json_decode(Crypt::decryptString($integration->credentials), true) ?: []) : [];
    }

    public function key(): string
    {
        return 'culqi';
    }

    public function simulated(): bool
    {
        return false;
    }

    public function publicKey(): string
    {
        return (string) ($this->credentials['public_key'] ?? '');
    }

    private function client(): CulqiClient
    {
        $client = CulqiClient::fromCredentials($this->credentials);

        if (!$client) {
            throw ValidationException::withMessages(['link' => 'Falta la llave secreta de Culqi en Configuración → Integraciones.']);
        }

        return $client;
    }

    public function checkout(object $link): array
    {
        return ['external_id' => $link->external_id ?? null, 'checkout_url' => \App\Support\PaymentLinks::url($link->token)];
    }

    public function charge(object $link, string $token, ?string $email): array
    {
        return $this->client()->charge(
            (float) $link->amount,
            $link->currency ?? 'PEN',
            $email ?: 'cliente@example.com',
            $token,
            (string) ($link->description ?? 'Pago'),
            ['link' => (string) $link->token],
        );
    }

    public static function event(array $result, string $fallbackReference): array
    {
        $data = $result['data'] ?? [];
        $approved = CulqiClient::chargeApproved($result);

        return [
            'event_id'    => 'culqi:' . ($data['id'] ?? $fallbackReference . ':' . now()->format('YmdHisu')),
            'type'        => $approved ? 'payment.approved' : 'payment.rejected',
            'reference'   => (string) ($data['id'] ?? $fallbackReference),
            'external_id' => $data['id'] ?? null,
            'amount'      => isset($data['amount']) ? round(((int) $data['amount']) / 100, 2) : null,
            'payload'     => ['id' => $data['id'] ?? null, 'outcome' => $data['outcome'] ?? null, 'message' => $result['message'] ?? null],
        ];
    }

    public function status(string $externalId): ?array
    {
        if (!str_starts_with($externalId, 'chr_')) {
            return null;
        }

        $result = $this->client()->getCharge($externalId);

        return $result['ok'] && CulqiClient::chargeApproved($result) ? self::event($result, $externalId) : null;
    }

    public function refund(object $payment, float $amount): array
    {
        $chargeId = (string) ($payment->external_reference ?? '');

        if (!str_starts_with($chargeId, 'chr_')) {
            return ['ok' => false, 'message' => 'No se encontró el cobro en Culqi.'];
        }

        $result = $this->client()->refund($chargeId, $amount);

        return [
            'ok'          => $result['ok'],
            'external_id' => $result['ok'] ? (string) ($result['data']['id'] ?? $chargeId) : null,
            'message'     => $result['ok'] ? 'Culqi devolvió el dinero al cliente.' : $result['message'],
        ];
    }

    public function parse(Request $request): ?array
    {
        $object = CulqiClient::eventObject($request->all());
        $chargeId = (string) ($object['id'] ?? '');

        return str_starts_with($chargeId, 'chr_') ? ['topic' => 'charge', 'id' => $chargeId] : null;
    }

    public function test(): array
    {
        $result = $this->client()->account();

        return ['ok' => $result['ok'], 'message' => $result['ok'] ? 'Culqi respondió: la llave secreta funciona.' : $result['message']];
    }
}
