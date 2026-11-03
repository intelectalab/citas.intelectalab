<?php

namespace App\Support\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class MercadoPagoClient
{
    public const SITE_CURRENCIES = [
        'MPE' => 'PEN', 'MLA' => 'ARS', 'MLB' => 'BRL', 'MLC' => 'CLP', 'MCO' => 'COP', 'MLM' => 'MXN', 'MLU' => 'UYU',
    ];

    public function __construct(private string $token)
    {
    }

    public static function fromCredentials(array $credentials): ?self
    {
        $token = trim((string) ($credentials['secret_key'] ?? ''));

        return $token !== '' ? new self($token) : null;
    }

    public function call(string $method, string $path, array $body = [], ?string $idempotency = null): array
    {
        $request = Http::withToken($this->token)
            ->acceptJson()
            ->timeout(20)
            ->when($idempotency, fn ($http) => $http->withHeaders(['X-Idempotency-Key' => $idempotency]));

        try {
            $response = $method === 'get'
                ? $request->get(rtrim((string) config('payments.mercadopago.api'), '/') . $path, $body)
                : $request->{$method}(rtrim((string) config('payments.mercadopago.api'), '/') . $path, $body ?: (object) []);
        } catch (ConnectionException $e) {
            $certificate = str_contains($e->getMessage(), 'cURL error 60') || str_contains($e->getMessage(), 'cURL error 77');

            return ['ok' => false, 'status' => 0, 'data' => null, 'message' => $certificate
                ? 'Este equipo no pudo verificar el certificado de seguridad de Mercado Pago (a PHP le falta la lista de certificados). En el servidor publicado esto no pasa.'
                : 'No se pudo conectar con Mercado Pago. Revisa tu conexión e inténtalo de nuevo.'];
        }

        $data = $response->json();

        return [
            'ok'      => $response->successful(),
            'status'  => $response->status(),
            'data'    => is_array($data) ? $data : null,
            'message' => $response->successful() ? null : self::errorMessage($response->status(), is_array($data) ? $data : []),
        ];
    }

    public static function errorMessage(int $status, array $data): string
    {
        if ($status === 401 || $status === 403) {
            return 'Mercado Pago no aceptó el Access Token: revisa que lo copiaste completo y que es de la misma aplicación.';
        }

        $detail = $data['errors'][0]['message'] ?? $data['message'] ?? $data['error'] ?? null;

        return 'Mercado Pago respondió con un error' . ($detail ? ': ' . mb_substr((string) $detail, 0, 160) : " ({$status}).");
    }

    public function account(): array
    {
        $result = $this->call('get', '/users/me');

        if (!$result['ok']) {
            return ['ok' => false, 'message' => $result['message']];
        }

        $site = (string) ($result['data']['site_id'] ?? '');

        return [
            'ok'       => true,
            'site'     => $site,
            'currency' => self::SITE_CURRENCIES[$site] ?? null,
            'name'     => $result['data']['nickname'] ?? null,
            'email'    => $result['data']['email'] ?? null,
        ];
    }

    public static function signatureValid(Request $request, ?string $secret): bool
    {
        if (!$secret) {
            return true;
        }

        $parts = [];
        foreach (explode(',', (string) $request->header('x-signature')) as $piece) {
            [$name, $value] = array_pad(explode('=', trim($piece), 2), 2, '');
            $parts[trim($name)] = trim($value);
        }

        if (empty($parts['ts']) || empty($parts['v1'])) {
            return false;
        }

        $id = self::dataId($request);
        $requestId = (string) $request->header('x-request-id');
        $manifest = ($id !== '' ? 'id:' . (preg_match('/^[A-Za-z0-9]+$/', $id) ? strtolower($id) : $id) . ';' : '')
            . ($requestId !== '' ? "request-id:{$requestId};" : '')
            . "ts:{$parts['ts']};";

        return hash_equals(hash_hmac('sha256', $manifest, $secret), $parts['v1']);
    }

    public static function dataId(Request $request): string
    {
        $raw = [];
        foreach (explode('&', (string) $request->getQueryString()) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $raw[urldecode($name)] = urldecode($value);
        }

        return (string) ($raw['data.id'] ?? $raw['id'] ?? $request->input('data.id') ?? $request->input('id') ?? '');
    }

    public static function topic(Request $request): string
    {
        return (string) ($request->query('type') ?? $request->query('topic') ?? $request->input('type') ?? $request->input('topic') ?? '');
    }

    public static function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
