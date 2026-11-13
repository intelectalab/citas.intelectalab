<?php

namespace App\Support\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class CulqiClient
{
    public function __construct(private string $secret)
    {
    }

    public static function fromCredentials(array $credentials): ?self
    {
        $secret = trim((string) ($credentials['secret_key'] ?? ''));

        return $secret !== '' ? new self($secret) : null;
    }

    public static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    public function call(string $method, string $path, array $body = []): array
    {
        $request = Http::withToken($this->secret)->acceptJson()->timeout(25);
        $url = rtrim((string) config('payments.culqi.api'), '/') . $path;

        try {
            $response = $method === 'get' ? $request->get($url, $body) : $request->{$method}($url, $body ?: (object) []);
        } catch (ConnectionException $e) {
            $certificate = str_contains($e->getMessage(), 'cURL error 60') || str_contains($e->getMessage(), 'cURL error 77');

            return ['ok' => false, 'status' => 0, 'data' => null, 'message' => $certificate
                ? 'Este equipo no pudo verificar el certificado de seguridad de Culqi (a PHP le falta la lista de certificados). En el servidor publicado esto no pasa.'
                : 'No se pudo conectar con Culqi. Revisa tu conexión e inténtalo de nuevo.'];
        }

        $data = $response->json();
        $data = is_array($data) ? $data : null;

        return [
            'ok'      => $response->successful(),
            'status'  => $response->status(),
            'data'    => $data,
            'message' => $response->successful() ? null : self::errorMessage($response->status(), $data ?? []),
        ];
    }

    public static function errorMessage(int $status, array $data): string
    {
        if ($status === 401 || $status === 403) {
            return 'Culqi no aceptó la llave secreta: revisa que la copiaste completa y que es del mismo comercio.';
        }

        $detail = $data['user_message'] ?? $data['merchant_message'] ?? $data['message'] ?? null;

        return $detail ? mb_substr((string) $detail, 0, 200) : "Culqi respondió con un error ({$status}).";
    }

    public static function declined(array $result): bool
    {
        return !$result['ok'] && $result['status'] >= 400 && $result['status'] < 500
            && in_array($result['data']['type'] ?? null, ['card_error', 'parameter_error'], true);
    }

    public static function unavailable(array $result): bool
    {
        return !$result['ok'] && ($result['status'] === 0 || $result['status'] >= 500);
    }

    public static function chargeApproved(array $result): bool
    {
        $data = $result['data'] ?? [];

        return $result['ok'] && ($data['object'] ?? null) === 'charge'
            && ($data['outcome']['type'] ?? 'venta_exitosa') === 'venta_exitosa';
    }

    public static function needsAuthentication(array $result): bool
    {
        return ($result['data']['action_code'] ?? null) === 'REVIEW';
    }

    public static function cardInfo(array $card): array
    {
        $source = $card['source'] ?? [];
        $number = (string) ($source['card_number'] ?? '');
        $last4 = $source['last_four'] ?? ($number !== '' ? substr(preg_replace('/\D/', '', $number), -4) : null);
        $brand = $source['iin']['card_brand'] ?? $source['card_brand'] ?? null;

        return ['brand' => $brand ? mb_strtolower((string) $brand) : null, 'last4' => $last4 ? (string) $last4 : null];
    }

    public function account(): array
    {
        $result = $this->call('get', '/charges', ['limit' => 1]);

        return $result['ok']
            ? ['ok' => true, 'message' => null]
            : ['ok' => false, 'message' => $result['message']];
    }

    public function customer(array $values): array
    {
        $created = $this->call('post', '/customers', $values);

        if ($created['ok'] && !empty($created['data']['id'])) {
            return ['ok' => true, 'id' => (string) $created['data']['id']];
        }

        $found = $this->call('get', '/customers', ['email' => $values['email']]);
        $id = $found['data']['data'][0]['id'] ?? null;

        return $id ? ['ok' => true, 'id' => (string) $id] : ['ok' => false, 'message' => $created['message'] ?? 'Culqi no registró al cliente.', 'result' => $created];
    }

    public function saveCard(string $customerId, string $token): array
    {
        return $this->call('post', '/cards', ['customer_id' => $customerId, 'token_id' => $token]);
    }

    public function deleteCard(string $cardId): array
    {
        return $this->call('delete', '/cards/' . rawurlencode($cardId));
    }

    public function charge(float $amount, string $currency, string $email, string $source, string $description, array $metadata = []): array
    {
        return $this->call('post', '/charges', array_filter([
            'amount'        => self::cents($amount),
            'currency_code' => strtoupper(substr($currency, 0, 3)),
            'email'         => $email,
            'source_id'     => $source,
            'description'   => mb_substr($description, 0, 80),
            'metadata'      => $metadata ?: null,
        ], fn ($value) => $value !== null));
    }

    public function getCharge(string $id): array
    {
        return $this->call('get', '/charges/' . rawurlencode($id));
    }

    public function refund(string $chargeId, float $amount, string $reason = 'solicitud_comprador'): array
    {
        return $this->call('post', '/refunds', ['amount' => self::cents($amount), 'charge_id' => $chargeId, 'reason' => $reason]);
    }

    public static function eventObject(array $payload): array
    {
        $data = $payload['data'] ?? $payload;

        if (is_string($data)) {
            $decoded = json_decode($data, true);
            $data = is_array($decoded) ? $decoded : [];
        }

        return is_array($data) ? $data : [];
    }
}
