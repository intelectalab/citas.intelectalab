<?php

namespace App\Support;

use App\Support\Payments\CulqiClient;
use App\Support\Payments\MercadoPagoClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class GatewayWebhooks
{
    public const PROVIDERS = ['culqi', 'mercadopago', 'izipay'];

    public const MAX_ATTEMPTS = 5;

    public const PROVIDER_LABELS = ['culqi' => 'Culqi', 'mercadopago' => 'Mercado Pago', 'izipay' => 'Izipay'];

    public const STATUS_LABELS = [
        'received'  => 'Recibido',
        'processed' => 'Procesado',
        'ignored'   => 'Sin cambios',
        'duplicate' => 'Repetido',
        'failed'    => 'Falló: se reintentará',
        'gave_up'   => 'Falló: revisar',
        'rejected'  => 'Rechazado',
    ];

    public const HEADERS = ['x-signature', 'x-request-id', 'kr-hash', 'kr-hash-algorithm', 'kr-hash-key', 'kr-answer-type', 'content-type'];

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('payment_webhooks');
    }

    public static function tracks(string $provider, ?string $owner): bool
    {
        return self::ready() && in_array($provider, self::PROVIDERS, true)
            && ($owner === 'platform' || ($owner && Str::isUuid($owner)));
    }

    public static function eventKey(string $provider, Request $request): string
    {
        $body = (array) $request->all();

        $key = match ($provider) {
            'culqi' => (function () use ($body) {
                $object = CulqiClient::eventObject($body);
                $event = (string) ($body['id'] ?? '');

                return str_starts_with($event, 'evt_') ? $event : trim(($body['type'] ?? '') . ':' . ($object['id'] ?? ''), ':');
            })(),
            'mercadopago' => trim(implode(':', array_filter([
                (string) ($body['id'] ?? ''),
                (string) ($body['action'] ?? ''),
                MercadoPagoClient::topic($request),
                MercadoPagoClient::dataId($request),
            ])), ':'),
            'izipay' => (function () use ($body) {
                $answer = is_string($body['kr-answer'] ?? null) ? (json_decode($body['kr-answer'], true) ?: []) : [];

                return trim(($answer['orderDetails']['orderId'] ?? '') . ':' . ($answer['transactions'][0]['uuid'] ?? '') . ':' . ($answer['orderStatus'] ?? ''), ':');
            })(),
            default => '',
        };

        return mb_substr($key !== '' ? $key : 'sha:' . hash('sha256', (string) $request->getContent() . '|' . (string) $request->getQueryString()), 0, 190);
    }

    private static function reference(string $provider, Request $request): ?string
    {
        $value = match ($provider) {
            'culqi'       => (string) (CulqiClient::eventObject((array) $request->all())['id'] ?? ''),
            'mercadopago' => MercadoPagoClient::dataId($request),
            default       => '',
        };

        return $value !== '' ? mb_substr($value, 0, 120) : null;
    }

    private static function topic(string $provider, Request $request): ?string
    {
        $value = match ($provider) {
            'culqi'       => (string) ($request->input('type') ?? ''),
            'mercadopago' => trim(MercadoPagoClient::topic($request) . ' ' . (string) ($request->input('action') ?? '')),
            'izipay'      => (string) ($request->input('kr-answer-type') ?? 'IPN'),
            default       => '',
        };

        return $value !== '' ? mb_substr($value, 0, 80) : null;
    }

    public static function receive(Request $request, string $provider, string $owner, callable $handler)
    {
        $tenantId = $owner === 'platform' ? null : $owner;

        if ($tenantId && !Rls::bypass(fn () => DB::table('tenants')->where('id', $tenantId)->exists())) {
            return $handler($request);
        }

        $key = self::eventKey($provider, $request);
        $row = Rls::bypass(fn () => DB::table('payment_webhooks')->where('provider', $provider)->where('owner_key', $owner)->where('event_key', $key)->first());

        if ($row && $row->status === 'processed') {
            Rls::bypass(fn () => DB::table('payment_webhooks')->where('id', $row->id)->update(['repeats' => DB::raw('repeats + 1'), 'updated_at' => now()]));

            return response()->json(['status' => 'duplicate', 'message' => 'Este aviso ya se había recibido y procesado: no se registra dos veces.']);
        }

        if (!$row) {
            $id = (string) Str::uuid();
            Rls::bypass(fn () => DB::table('payment_webhooks')->insertOrIgnore([
                'id'         => $id,
                'tenant_id'  => $tenantId,
                'owner_key'  => $owner,
                'provider'   => $provider,
                'event_key'  => $key,
                'topic'      => self::topic($provider, $request),
                'reference'  => self::reference($provider, $request),
                'status'     => 'received',
                'payload'    => json_encode((array) $request->all(), JSON_UNESCAPED_UNICODE),
                'query'      => json_encode((array) $request->query(), JSON_UNESCAPED_UNICODE),
                'headers'    => json_encode(collect(self::HEADERS)->mapWithKeys(fn ($h) => [$h => $request->header($h)])->filter()->all()),
                'created_at' => now(),
                'updated_at' => now(),
            ]));
            $row = Rls::bypass(fn () => DB::table('payment_webhooks')->where('provider', $provider)->where('owner_key', $owner)->where('event_key', $key)->first());
        } else {
            Rls::bypass(fn () => DB::table('payment_webhooks')->where('id', $row->id)->update(['repeats' => DB::raw('repeats + 1'), 'updated_at' => now()]));
        }

        return self::run($row, $request, $handler);
    }

    public static function run(object $row, Request $request, callable $handler)
    {
        $attempts = (int) $row->attempts + 1;

        try {
            $response = $handler($request);
        } catch (HttpExceptionInterface $e) {
            $status = $e->getStatusCode() === 400 ? 'rejected' : 'ignored';
            self::mark($row, $status, $attempts, $e->getMessage() ?: ($e->getStatusCode() === 404 ? 'La pasarela no está conectada o no se encontró a quién va el aviso.' : 'Aviso no válido.'));

            throw $e;
        } catch (\Throwable $e) {
            report($e);
            $final = $attempts >= self::MAX_ATTEMPTS;
            self::mark($row, $final ? 'gave_up' : 'failed', $attempts, 'Error al procesar el aviso: ' . mb_substr($e->getMessage(), 0, 300), $final ? null : now()->addMinutes(5 * $attempts));

            return response()->json(['status' => 'failed', 'message' => 'No se pudo procesar el aviso: se volverá a intentar.'], 500);
        }

        $data = $response instanceof JsonResponse ? (array) $response->getData(true) : [];
        $code = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;
        $result = (string) ($data['status'] ?? '');

        $status = match (true) {
            $code === 400                                         => 'rejected',
            $code >= 500                                          => 'failed',
            $result === 'ignored'                                  => 'ignored',
            $result === 'duplicate'                               => 'duplicate',
            default                                               => 'processed',
        };

        $message = (string) ($data['message'] ?? '') ?: match ($status) {
            'processed' => 'Aviso procesado.',
            'ignored'   => 'Aviso recibido sin cambios en ningún pago.',
            'rejected'  => 'La firma del aviso no coincide: no se tomó en cuenta.',
            'duplicate' => 'Este pago ya estaba registrado.',
            default     => 'No se pudo procesar el aviso.',
        };

        self::mark($row, $status, $attempts, $message, $status === 'failed' && $attempts < self::MAX_ATTEMPTS ? now()->addMinutes(5 * $attempts) : null);

        return $response;
    }

    private static function mark(object $row, string $status, int $attempts, ?string $message, ?Carbon $retry = null): void
    {
        Rls::bypass(fn () => DB::table('payment_webhooks')->where('id', $row->id)->update([
            'status'        => $status,
            'attempts'      => $attempts,
            'message'       => $message ? mb_substr($message, 0, 500) : null,
            'next_retry_at' => $retry,
            'processed_at'  => in_array($status, ['processed', 'ignored', 'duplicate'], true) ? now() : null,
            'updated_at'    => now(),
        ]));

        if ($row->tenant_id && $row->provider !== 'izipay' && in_array($status, ['processed', 'failed', 'gave_up', 'rejected'], true)) {
            IntegrationLog::write($row->tenant_id, 'payments', $row->provider, 'webhook', $status === 'processed', ($message ?: self::STATUS_LABELS[$status]));
        }

        if (!$row->tenant_id && in_array($status, ['failed', 'gave_up', 'rejected'], true)) {
            IntegrationLog::write(null, 'payments', $row->provider, 'webhook', false, ($message ?: self::STATUS_LABELS[$status]));
        }
    }

    public static function rebuild(object $row): Request
    {
        $decode = fn ($value) => is_string($value) ? (json_decode($value, true) ?: []) : (array) $value;
        $payload = $decode($row->payload);
        $query = $decode($row->query);
        $headers = $decode($row->headers);
        $server = ['CONTENT_TYPE' => $headers['content-type'] ?? 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $uri = '/api/webhooks/payments/' . $row->provider . '/' . $row->owner_key . ($query ? '?' . http_build_query($query) : '');
        $json = str_contains((string) $server['CONTENT_TYPE'], 'json');
        $request = Request::create($uri, 'POST', $json ? [] : $payload, [], [], $server, $json ? json_encode($payload) : null);

        if ($json) {
            $request->setJson(new \Symfony\Component\HttpFoundation\InputBag($payload));
        }

        return $request;
    }

    public static function retry(object $row, callable $handler): array
    {
        try {
            self::run($row, self::rebuild($row), $handler);
        } catch (HttpExceptionInterface $e) {
        }

        $fresh = Rls::bypass(fn () => DB::table('payment_webhooks')->where('id', $row->id)->first());

        return self::present($fresh);
    }

    public static function due(int $limit = 100): \Illuminate\Support\Collection
    {
        if (!self::ready()) {
            return collect();
        }

        return Rls::bypass(fn () => DB::table('payment_webhooks')->where('status', 'failed')->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(fn ($q) => $q->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))
            ->orderBy('created_at')->limit($limit)->get());
    }

    public static function list(string $owner, int $limit = 100): array
    {
        if (!self::ready()) {
            return [];
        }

        return Rls::bypass(fn () => DB::table('payment_webhooks')->where('owner_key', $owner)->orderByDesc('created_at')->limit($limit)->get())
            ->map(fn ($row) => self::present($row))->values()->all();
    }

    public static function find(string $owner, string $id): ?object
    {
        if (!self::ready() || !Str::isUuid($id)) {
            return null;
        }

        return Rls::bypass(fn () => DB::table('payment_webhooks')->where('owner_key', $owner)->where('id', $id)->first());
    }

    public static function present(object $row): array
    {
        return [
            'id'             => $row->id,
            'provider'       => $row->provider,
            'provider_label' => self::PROVIDER_LABELS[$row->provider] ?? $row->provider,
            'topic'          => $row->topic,
            'reference'      => $row->reference,
            'status'         => $row->status,
            'status_label'   => self::STATUS_LABELS[$row->status] ?? $row->status,
            'message'        => $row->message,
            'attempts'       => (int) $row->attempts,
            'repeats'        => (int) $row->repeats,
            'can_retry'      => in_array($row->status, ['failed', 'gave_up', 'ignored'], true),
            'next_retry_at'  => $row->next_retry_at ? Carbon::parse($row->next_retry_at)->toIso8601String() : null,
            'at'             => Carbon::parse($row->created_at)->toIso8601String(),
        ];
    }
}
