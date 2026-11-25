<?php

namespace App\Support\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class IzipayClient
{
    public const PAID = ['PAID'];

    public const FAILED = ['EXPIRED', 'CANCELLED', 'REFUSED', 'ABANDONED', 'UNPAID'];

    public function __construct(private string $shopId, private string $password)
    {
    }

    public static function fromCredentials(array $credentials): ?self
    {
        $shop = trim((string) ($credentials['public_key'] ?? ''));
        $password = trim((string) ($credentials['secret_key'] ?? ''));

        return $shop !== '' && $password !== '' ? new self($shop, $password) : null;
    }

    public static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    public static function production(string $password): bool
    {
        return str_starts_with($password, 'prodpassword_');
    }

    public function call(string $path, array $body): array
    {
        $url = rtrim((string) config('payments.izipay.api'), '/') . '/api-payment/V4/' . ltrim($path, '/');

        try {
            $response = Http::withBasicAuth($this->shopId, $this->password)->acceptJson()->asJson()->timeout(25)->post($url, $body);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'message' => 'No se pudo conectar con Izipay. Revisa tu conexión e inténtalo de nuevo.'];
        }

        $data = $response->json();
        $data = is_array($data) ? $data : [];
        $ok = $response->successful() && ($data['status'] ?? null) === 'SUCCESS';

        return [
            'ok'      => $ok,
            'status'  => $response->status(),
            'data'    => $data['answer'] ?? null,
            'message' => $ok ? null : self::errorMessage($response->status(), $data),
        ];
    }

    public static function errorMessage(int $status, array $data): string
    {
        if ($status === 401 || in_array($data['answer']['errorCode'] ?? null, ['INT_902', 'INT_905', 'INT_904'], true)) {
            return 'Izipay no aceptó el usuario o la contraseña: revisa que los copiaste completos del Back Office de Izipay.';
        }

        $detail = $data['answer']['detailedErrorMessage'] ?? $data['answer']['errorMessage'] ?? null;

        return $detail ? 'Izipay: ' . mb_substr((string) $detail, 0, 200) : "Izipay respondió con un error ({$status}).";
    }

    public function test(): array
    {
        $result = $this->call('Charge/SDKTest', ['value' => 'agenda']);

        return ['ok' => $result['ok'] && ($result['data']['value'] ?? null) === 'agenda', 'message' => $result['message']];
    }

    public function paymentOrder(float $amount, string $currency, string $orderId, ?string $email, string $description): array
    {
        return $this->call('Charge/CreatePaymentOrder', array_filter([
            'amount'         => self::cents($amount),
            'currency'       => strtoupper(substr($currency, 0, 3)),
            'orderId'        => $orderId,
            'channelOptions' => ['channelType' => 'URL'],
            'customer'       => $email ? ['email' => $email] : null,
            'merchantComment' => mb_substr($description, 0, 255),
        ], fn ($value) => $value !== null));
    }

    public function getPaymentOrder(string $paymentOrderId): array
    {
        return $this->call('Charge/PaymentOrder/Get', ['paymentOrderId' => $paymentOrderId]);
    }

    public function getOrder(string $orderId): array
    {
        return $this->call('Order/Get', ['orderId' => $orderId]);
    }

    public function refund(string $uuid, float $amount, string $currency): array
    {
        return $this->call('Transaction/CancelOrRefund', ['uuid' => $uuid, 'amount' => self::cents($amount), 'currency' => strtoupper(substr($currency, 0, 3))]);
    }

    public static function signatureValid(array $fields, string $password, ?string $hmacKey): bool
    {
        $answer = (string) ($fields['kr-answer'] ?? '');
        $hash = (string) ($fields['kr-hash'] ?? '');

        if ($answer === '' || $hash === '' || ($fields['kr-hash-algorithm'] ?? 'sha256_hmac') !== 'sha256_hmac') {
            return false;
        }

        $key = ($fields['kr-hash-key'] ?? 'password') === 'password' ? $password : (string) $hmacKey;

        return $key !== '' && hash_equals(hash_hmac('sha256', $answer, $key), $hash);
    }
}
