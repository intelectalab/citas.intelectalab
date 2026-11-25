<?php

namespace App\Support\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class NiubizClient
{
    public const APPROVED_CODES = ['000', '010'];

    public function __construct(private string $merchantId, private string $username, private string $password, private bool $production)
    {
    }

    public static function fromCredentials(array $credentials, ?string $mode): ?self
    {
        $merchant = trim((string) ($credentials['public_key'] ?? ''));
        $user = trim((string) ($credentials['username'] ?? ''));
        $password = (string) ($credentials['secret_key'] ?? '');

        return $merchant !== '' && $user !== '' && $password !== '' ? new self($merchant, $user, $password, $mode === 'production') : null;
    }

    public function merchantId(): string
    {
        return $this->merchantId;
    }

    public function production(): bool
    {
        return $this->production;
    }

    public function base(): string
    {
        return rtrim((string) config($this->production ? 'payments.niubiz.api' : 'payments.niubiz.sandbox_api'), '/');
    }

    public function refundBase(): string
    {
        return rtrim((string) config($this->production ? 'payments.niubiz.api' : 'payments.niubiz.refund_sandbox_api'), '/');
    }

    public function script(): string
    {
        return (string) config($this->production ? 'payments.niubiz.script' : 'payments.niubiz.sandbox_script');
    }

    private static function unreachable(ConnectionException $e): array
    {
        return ['ok' => false, 'status' => 0, 'data' => null, 'message' => 'No se pudo conectar con Niubiz. Revisa tu conexión e inténtalo de nuevo.'];
    }

    public function accessToken(bool $fresh = false): array
    {
        $key = 'niubiz-token:' . sha1($this->merchantId . '|' . $this->username . '|' . ($this->production ? 'p' : 's'));

        if (!$fresh && ($cached = Cache::get($key))) {
            return ['ok' => true, 'token' => $cached];
        }

        try {
            $response = Http::withBasicAuth($this->username, $this->password)->timeout(20)->get($this->base() . '/api.security/v1/security');
        } catch (ConnectionException $e) {
            return self::unreachable($e);
        }

        $token = trim((string) $response->body());

        if (!$response->successful() || $token === '' || str_starts_with($token, '{')) {
            return ['ok' => false, 'status' => $response->status(), 'message' => $response->status() === 401
                ? 'Niubiz no aceptó el usuario o la contraseña de la integración. Revísalos en el correo de bienvenida de Niubiz.'
                : "Niubiz respondió con un error ({$response->status()})."];
        }

        Cache::put($key, $token, now()->addMinutes(50));

        return ['ok' => true, 'token' => $token];
    }

    private function call(string $url, array $body): array
    {
        $token = $this->accessToken();

        if (!$token['ok']) {
            return ['ok' => false, 'status' => $token['status'] ?? 0, 'data' => null, 'message' => $token['message']];
        }

        try {
            $response = Http::withHeaders(['Authorization' => $token['token']])->acceptJson()->asJson()->timeout(30)->post($url, $body);
        } catch (ConnectionException $e) {
            return self::unreachable($e);
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
        if ($status === 401) {
            return 'Niubiz no aceptó las credenciales de la integración.';
        }

        $detail = $data['data']['ACTION_DESCRIPTION'] ?? $data['errorMessage'] ?? null;

        return $detail ? 'Niubiz: ' . mb_substr((string) $detail, 0, 200) : "Niubiz respondió con un error ({$status}).";
    }

    public static function amount(float $amount): float
    {
        return round($amount, 2);
    }

    public function session(float $amount, string $ip, ?string $email, string $customerId): array
    {
        return $this->call($this->base() . '/api.ecommerce/v2/ecommerce/token/session/' . rawurlencode($this->merchantId), [
            'channel'   => 'web',
            'amount'    => self::amount($amount),
            'antifraud' => [
                'clientIp'         => $ip,
                'merchantDefineData' => array_filter([
                    'MDD4'  => $email,
                    'MDD32' => mb_substr($customerId, 0, 30),
                    'MDD75' => 'Registrado',
                    'MDD77' => '1',
                ]),
            ],
        ]);
    }

    public function authorize(string $tokenId, string $purchaseNumber, float $amount, string $currency): array
    {
        return $this->call($this->base() . '/api.authorization/v3/authorization/ecommerce/' . rawurlencode($this->merchantId), [
            'channel'     => 'web',
            'captureType' => 'manual',
            'countable'   => true,
            'order'       => [
                'tokenId'        => $tokenId,
                'purchaseNumber' => $purchaseNumber,
                'amount'         => self::amount($amount),
                'currency'       => strtoupper(substr($currency, 0, 3)),
            ],
        ]);
    }

    public static function approved(array $result): bool
    {
        $map = $result['data']['dataMap'] ?? [];

        return $result['ok'] && ($map['STATUS'] ?? null) === 'Authorized' && in_array((string) ($map['ACTION_CODE'] ?? ''), self::APPROVED_CODES, true);
    }

    public function refund(string $transactionId, float $amount, string $ruc, string $reference): array
    {
        $result = $this->call($this->refundBase() . '/api.refund/v1/refund/' . rawurlencode($this->merchantId) . '/' . rawurlencode($transactionId), [
            'ruc'                 => $ruc,
            'comment'             => 'Devolución solicitada por el comercio',
            'externalReferenceId' => mb_substr($reference, 0, 20),
            'amount'              => self::amount($amount),
        ]);

        $ok = $result['ok'] && (int) ($result['data']['errorCode'] ?? 1) === 0 && (string) ($result['data']['data']['CODERROR'] ?? '100') === '100';

        return ['ok' => $ok, 'data' => $result['data'], 'message' => $ok ? null : ($result['message'] ?? ('Niubiz: ' . ($result['data']['data']['DSCERROR'] ?? $result['data']['errorMessage'] ?? 'no aceptó la devolución.')))];
    }
}
