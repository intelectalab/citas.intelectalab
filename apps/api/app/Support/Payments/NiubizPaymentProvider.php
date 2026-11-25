<?php

namespace App\Support\Payments;

use App\Support\IntegrationLog;
use App\Support\PaymentLinks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NiubizPaymentProvider implements PaymentProvider
{
    private array $credentials;

    private ?string $mode;

    private ?string $tenantId;

    public function __construct(?object $integration)
    {
        $this->credentials = $integration?->credentials ? (json_decode(Crypt::decryptString($integration->credentials), true) ?: []) : [];
        $this->mode = $integration?->mode ?? null;
        $this->tenantId = $integration?->tenant_id ?? null;
    }

    public function key(): string
    {
        return 'niubiz';
    }

    public function simulated(): bool
    {
        return false;
    }

    public function client(): NiubizClient
    {
        $client = NiubizClient::fromCredentials($this->credentials, $this->mode);

        if (!$client) {
            throw ValidationException::withMessages(['link' => 'Faltan el código de comercio, el usuario o la contraseña de Niubiz en Configuración → Integraciones.']);
        }

        return $client;
    }

    public static function purchaseNumber(): string
    {
        return now()->format('ymdHis') . random_int(10, 99);
    }

    public static function formUrl(string $token): string
    {
        return rtrim((string) config('app.url'), '/') . '/api/pay/' . $token . '/niubiz';
    }

    public function checkout(object $link): array
    {
        $this->client();

        return ['external_id' => mb_substr(self::purchaseNumber(), 0, 12), 'checkout_url' => self::formUrl($link->token)];
    }

    public function form(object $link, string $ip, ?string $email, string $title, ?string $logo): string
    {
        $client = $this->client();
        $session = $client->session((float) $link->amount, $ip, $email, (string) ($link->customer_id ?? $link->id));

        if (!$session['ok'] || empty($session['data']['sessionKey'])) {
            IntegrationLog::write($link->tenant_id, 'payments', 'niubiz', 'charge', false, $session['message'] ?? 'Niubiz no abrió la sesión de pago.');

            throw ValidationException::withMessages(['link' => $session['message'] ?? 'Niubiz no abrió el formulario de pago. Inténtalo de nuevo.']);
        }

        $attributes = [
            'src'                    => $client->script(),
            'data-sessiontoken'      => (string) $session['data']['sessionKey'],
            'data-channel'           => 'web',
            'data-merchantid'        => $client->merchantId(),
            'data-purchasenumber'    => (string) $link->external_id,
            'data-amount'            => number_format(NiubizClient::amount((float) $link->amount), 2, '.', ''),
            'data-expirationminutes' => '20',
            'data-timeouturl'        => PaymentLinks::url($link->token),
            'data-merchantname'      => mb_substr($title, 0, 25),
        ];

        if ($logo && str_starts_with($logo, 'https://')) {
            $attributes['data-merchantlogo'] = $logo;
        }

        $tag = collect($attributes)->map(fn ($value, $name) => $name . '="' . e($value) . '"')->implode(' ');
        $action = e(self::formUrl($link->token));
        $back = e(PaymentLinks::url($link->token));
        $heading = e($title);

        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Pagar con Niubiz</title></head>'
            . '<body style="margin:0;padding:32px 16px;font-family:Arial,Helvetica,sans-serif;background:#f1f5f9;color:#0f172a;text-align:center">'
            . '<h1 style="font-size:18px">' . $heading . '</h1><p style="font-size:14px;color:#475569">Pulsa el botón para escribir tu tarjeta en el formulario seguro de Niubiz.</p>'
            . '<form action="' . $action . '" method="post"><script ' . $tag . '></script></form>'
            . '<p style="margin-top:24px;font-size:13px"><a href="' . $back . '" style="color:#475569">Volver sin pagar</a></p></body></html>';
    }

    public function authorize(object $link, string $transactionToken): array
    {
        $result = $this->client()->authorize($transactionToken, (string) $link->external_id, (float) $link->amount, $link->currency ?? 'PEN');

        if (($result['status'] ?? null) === 0) {
            IntegrationLog::write($link->tenant_id, 'payments', 'niubiz', 'charge', false, 'Se cortó la conexión con Niubiz al confirmar el pago; se revisará su estado.');

            throw new \RuntimeException('No se pudo conectar con Niubiz al confirmar el pago.');
        }
        $approved = NiubizClient::approved($result);
        $map = $result['data']['dataMap'] ?? $result['data']['data'] ?? [];
        $transaction = $map['TRANSACTION_ID'] ?? $result['data']['order']['transactionId'] ?? null;

        IntegrationLog::write($link->tenant_id, 'payments', 'niubiz', 'charge', $approved, $approved ? 'Pago aprobado por Niubiz.' : ($result['message'] ?? 'Niubiz rechazó el pago.'));

        return [
            'event_id'    => 'niubiz:' . ($transaction ?? $link->external_id . ':' . now()->format('YmdHisu')),
            'type'        => $approved ? 'payment.approved' : 'payment.rejected',
            'reference'   => (string) $link->token,
            'external_id' => $transaction ? (string) $transaction : null,
            'amount'      => $approved ? (float) ($result['data']['order']['amount'] ?? $link->amount) : null,
            'payload'     => ['action_code' => $map['ACTION_CODE'] ?? null, 'status' => $map['STATUS'] ?? null, 'description' => $map['ACTION_DESCRIPTION'] ?? null, 'message' => $result['message'] ?? null],
            'message'     => $approved ? null : ($result['message'] ?? 'Niubiz rechazó el pago.'),
        ];
    }

    public function status(string $externalId): ?array
    {
        return null;
    }

    public function refund(object $payment, float $amount): array
    {
        $transaction = (string) ($payment->external_reference ?? '');
        $ruc = (string) DB::table('companies')->where('tenant_id', $payment->tenant_id)->orderBy('created_at')->value('tax_id');

        if ($transaction === '' || !preg_match('/^\d{11}$/', $ruc)) {
            return ['ok' => false, 'message' => $transaction === '' ? 'No se encontró el cobro en Niubiz.' : 'Para devolver por Niubiz, tu empresa necesita su RUC en Configuración → Empresa.'];
        }

        $result = $this->client()->refund($transaction, $amount, $ruc, substr((string) $payment->id, 0, 20));
        IntegrationLog::write($payment->tenant_id, 'payments', 'niubiz', 'refund', $result['ok'], $result['ok'] ? 'Devolución aceptada por Niubiz.' : $result['message']);

        return [
            'ok'          => $result['ok'],
            'external_id' => $result['ok'] ? (string) ($result['data']['data']['CODIGODEVOLUCION'] ?? $transaction) : null,
            'message'     => $result['ok'] ? 'Niubiz devolvió el dinero al cliente.' : $result['message'],
        ];
    }

    public function parse(Request $request): ?array
    {
        return null;
    }

    public function test(): array
    {
        $result = $this->client()->accessToken(true);
        IntegrationLog::write($this->tenantId, 'payments', 'niubiz', 'test', $result['ok'], $result['ok'] ? 'Niubiz aceptó las credenciales.' : $result['message']);

        return ['ok' => $result['ok'], 'message' => $result['ok'] ? 'Niubiz respondió: el usuario y la contraseña de la integración funcionan.' : $result['message']];
    }
}
