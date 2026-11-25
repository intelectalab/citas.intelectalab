<?php

namespace App\Support\Payments;

use App\Support\IntegrationLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class IzipayPaymentProvider implements PaymentProvider
{
    private array $credentials;

    private ?string $tenantId;

    public function __construct(?object $integration)
    {
        $this->credentials = $integration?->credentials ? (json_decode(Crypt::decryptString($integration->credentials), true) ?: []) : [];
        $this->tenantId = $integration?->tenant_id ?? null;
    }

    public function key(): string
    {
        return 'izipay';
    }

    public function simulated(): bool
    {
        return false;
    }

    private function client(): IzipayClient
    {
        $client = IzipayClient::fromCredentials($this->credentials);

        if (!$client) {
            throw ValidationException::withMessages(['link' => 'Faltan el usuario y la contraseña de Izipay en Configuración → Integraciones.']);
        }

        return $client;
    }

    public function checkout(object $link): array
    {
        $result = $this->client()->paymentOrder((float) $link->amount, $link->currency ?? 'PEN', (string) $link->token, $link->email ?? null, (string) ($link->description ?? 'Pago'));
        IntegrationLog::write($link->tenant_id ?? $this->tenantId, 'payments', 'izipay', 'charge', $result['ok'], $result['ok'] ? 'Izipay creó la orden de pago.' : $result['message']);

        if (!$result['ok'] || empty($result['data']['paymentURL'])) {
            throw ValidationException::withMessages(['link' => $result['message'] ?? 'Izipay no creó la orden de pago.']);
        }

        return ['external_id' => (string) $result['data']['paymentOrderId'], 'checkout_url' => (string) $result['data']['paymentURL']];
    }

    public static function event(array $order, string $reference): array
    {
        $status = strtoupper((string) ($order['paymentOrderStatus'] ?? $order['orderStatus'] ?? ''));
        $approved = in_array($status, IzipayClient::PAID, true);
        $amount = isset($order['amount']) ? round(((int) $order['amount']) / 100, 2) : (isset($order['orderDetails']['orderTotalAmount']) ? round(((int) $order['orderDetails']['orderTotalAmount']) / 100, 2) : null);
        $id = (string) ($order['paymentOrderId'] ?? $order['orderDetails']['orderId'] ?? $reference);

        return [
            'event_id'    => 'izipay:' . $id . ':' . $status,
            'type'        => $approved ? 'payment.approved' : (in_array($status, IzipayClient::FAILED, true) ? 'payment.rejected' : 'payment.pending'),
            'reference'   => $reference,
            'external_id' => $id,
            'amount'      => $approved ? $amount : null,
            'payload'     => ['id' => $id, 'status' => $status],
        ];
    }

    public function status(string $externalId): ?array
    {
        $result = $this->client()->getPaymentOrder($externalId);

        if (!$result['ok'] || !$result['data']) {
            return null;
        }

        return self::event($result['data'], $externalId);
    }

    public function refund(object $payment, float $amount): array
    {
        $client = $this->client();
        $orderToken = basename((string) ($payment->payment_url ?? ''));
        $order = $orderToken !== '' ? $client->getOrder($orderToken) : ['ok' => false, 'message' => null];
        $uuid = collect($order['data']['transactions'] ?? [])->first(fn ($t) => in_array($t['status'] ?? '', ['PAID', 'CAPTURED', 'AUTHORISED'], true))['uuid'] ?? null;

        if (!$uuid) {
            return ['ok' => false, 'message' => $order['message'] ?? 'No se encontró el cobro en Izipay.'];
        }

        $result = $client->refund((string) $uuid, $amount, $payment->currency ?? 'PEN');
        IntegrationLog::write($payment->tenant_id, 'payments', 'izipay', 'refund', $result['ok'], $result['ok'] ? 'Devolución aceptada por Izipay.' : $result['message']);

        return [
            'ok'          => $result['ok'],
            'external_id' => $result['ok'] ? (string) ($result['data']['uuid'] ?? $uuid) : null,
            'message'     => $result['ok'] ? 'Izipay devolvió el dinero al cliente.' : $result['message'],
        ];
    }

    public function parse(Request $request): ?array
    {
        $fields = $request->all();

        if (!IzipayClient::signatureValid($fields, (string) ($this->credentials['secret_key'] ?? ''), $this->credentials['webhook_secret'] ?? null)) {
            return null;
        }

        $answer = json_decode((string) $fields['kr-answer'], true);

        return is_array($answer) ? ['topic' => 'order', 'answer' => $answer, 'id' => (string) ($answer['orderDetails']['orderId'] ?? '')] : null;
    }

    public function test(): array
    {
        $result = $this->client()->test();
        IntegrationLog::write($this->tenantId, 'payments', 'izipay', 'test', $result['ok'], $result['ok'] ? 'Izipay aceptó las credenciales.' : $result['message']);

        return ['ok' => $result['ok'], 'message' => $result['ok'] ? 'Izipay respondió: el usuario y la contraseña funcionan.' : ($result['message'] ?? 'Izipay no respondió como se esperaba.')];
    }
}
