<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Webhooks
{
    public const EVENTS = [
        'appointment.created'     => 'Cita creada',
        'appointment.confirmed'   => 'Cita confirmada',
        'appointment.rescheduled' => 'Cita reprogramada',
        'appointment.cancelled'   => 'Cita cancelada',
        'appointment.completed'   => 'Cita atendida',
        'appointment.no_show'     => 'No asistió a la cita',
        'sale.paid'               => 'Venta pagada',
        'customer.created'        => 'Cliente nuevo',
    ];

    public const TEST_EVENT = 'webhook.test';

    public const MAX_ATTEMPTS = 6;

    public const BACKOFF_MINUTES = [1, 5, 30, 120, 720];

    public const STATUS = ['pending' => 'Por enviar', 'retrying' => 'Reintentando', 'sent' => 'Entregado', 'failed' => 'Falló'];

    public static function listen(): void
    {
        Customer::created(fn (Customer $customer) => self::customerCreated($customer));
    }

    public static function newSecret(): string
    {
        return 'whsec_' . Str::random(40);
    }

    public static function secretOf(object $endpoint): string
    {
        try {
            return Crypt::decryptString($endpoint->secret);
        } catch (\Throwable) {
            return (string) $endpoint->secret;
        }
    }

    public static function sign(string $body, string $secret, int $timestamp): string
    {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    public static function appointmentStep(Appointment $appointment, ?string $from, string $to): void
    {
        $event = match (true) {
            $from === null                                         => 'appointment.created',
            $from === $to                                          => 'appointment.rescheduled',
            $to === AppointmentFlow::CONFIRMED                     => 'appointment.confirmed',
            $to === AppointmentFlow::CANCELLED                     => 'appointment.cancelled',
            $to === AppointmentFlow::COMPLETED                     => 'appointment.completed',
            $to === AppointmentFlow::NO_SHOW                       => 'appointment.no_show',
            default                                                => null,
        };

        if ($event) {
            self::dispatch($appointment->tenant_id, $event, fn () => self::appointmentData($appointment, $from));
        }
    }

    public static function salePaid(string $tenantId, object $sale): void
    {
        self::dispatch($tenantId, 'sale.paid', fn () => [
            'id'             => $sale->id,
            'code'           => $sale->code ?? null,
            'status'         => $sale->status ?? 'paid',
            'total'          => round((float) ($sale->total ?? 0), 2),
            'paid'           => round((float) ($sale->paid ?? 0), 2),
            'currency'       => $sale->currency ?? null,
            'customer_id'    => $sale->customer_id ?? null,
            'appointment_id' => $sale->appointment_id ?? null,
            'branch_id'      => $sale->branch_id ?? null,
            'paid_at'        => !empty($sale->paid_at) ? Carbon::parse($sale->paid_at)->toIso8601String() : now()->toIso8601String(),
        ]);
    }

    public static function customerCreated(Customer $customer): void
    {
        if ($customer->tenant_id) {
            self::dispatch($customer->tenant_id, 'customer.created', fn () => self::customerData($customer));
        }
    }

    public static function customerData(Customer $customer): array
    {
        return [
            'id'              => $customer->id,
            'code'            => $customer->code,
            'first_name'      => $customer->first_name,
            'last_name'       => $customer->last_name,
            'email'           => $customer->email,
            'phone'           => $customer->phone,
            'document_number' => $customer->document_number,
            'created_at'      => $customer->created_at?->toIso8601String(),
        ];
    }

    public static function appointmentData(Appointment $appointment, ?string $from = null): array
    {
        $appointment->loadMissing(['customer', 'service', 'professional', 'branch']);

        return [
            'id'              => $appointment->id,
            'code'            => $appointment->code,
            'status'          => $appointment->status,
            'previous_status' => $from,
            'starts_at'       => $appointment->starts_at?->toIso8601String(),
            'ends_at'         => $appointment->ends_at?->toIso8601String(),
            'source'          => $appointment->source,
            'price'           => round((float) $appointment->price, 2),
            'currency'        => $appointment->currency,
            'notes'           => $appointment->notes,
            'video_url'       => VideoLinks::for($appointment),
            'cancel_reason'   => $appointment->cancel_reason,
            'branch'          => $appointment->branch ? ['id' => $appointment->branch->id, 'name' => $appointment->branch->name] : null,
            'service'         => $appointment->service ? ['id' => $appointment->service->id, 'name' => $appointment->service->name] : null,
            'professional'    => $appointment->professional ? ['id' => $appointment->professional->id, 'name' => $appointment->professional->display_name] : null,
            'customer'        => $appointment->customer ? self::customerData($appointment->customer) : null,
        ];
    }

    public static function dispatch(string $tenantId, string $event, callable $data): int
    {
        try {
            if (!ApiKeys::ready()) {
                return 0;
            }

            $endpoints = DB::table('webhook_endpoints')->where('tenant_id', $tenantId)->where('active', true)
                ->whereRaw('events @> ?::jsonb', [json_encode([$event])])
                ->pluck('id');

            if ($endpoints->isEmpty() || !Tenant::find($tenantId)?->hasModule('api')) {
                return 0;
            }

            $eventId = (string) Str::uuid();
            $payload = json_encode(['id' => $eventId, 'event' => $event, 'created_at' => now()->toIso8601String(), 'data' => $data()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            DB::transaction(fn () => DB::table('webhook_deliveries')->insert($endpoints->map(fn ($id) => [
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'webhook_id' => $id, 'event_id' => $eventId, 'event' => $event,
                'payload' => $payload, 'status' => 'pending', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
            ])->all()));

            self::kick($tenantId);

            return $endpoints->count();
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    public static function kick(string $tenantId): void
    {
        $attributes = request()->attributes;
        $pending = $attributes->get('webhooks_kick', []);

        if (isset($pending[$tenantId])) {
            return;
        }

        $attributes->set('webhooks_kick', $pending + [$tenantId => true]);
        app()->terminating(fn () => Rls::within($tenantId, true, fn () => self::process($tenantId)));
    }

    public static function process(?string $tenantId = null, int $limit = 50): array
    {
        $done = ['sent' => 0, 'failed' => 0];

        if (!ApiKeys::ready()) {
            return $done;
        }

        $due = DB::table('webhook_deliveries')
            ->when($tenantId, fn ($q, $id) => $q->where('tenant_id', $id))
            ->whereIn('status', ['pending', 'retrying'])
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()->toIso8601String()))
            ->orderBy('created_at')->limit($limit)->get();

        foreach ($due as $delivery) {
            $ok = self::deliver($delivery);

            if ($ok !== null) {
                $done[$ok ? 'sent' : 'failed']++;
            }
        }

        return $done;
    }

    public static function deliver(object $delivery): ?bool
    {
        $claimed = DB::table('webhook_deliveries')->where('id', $delivery->id)->where('attempts', $delivery->attempts)->whereIn('status', ['pending', 'retrying'])
            ->update(['attempts' => $delivery->attempts + 1, 'updated_at' => now()]);

        if (!$claimed) {
            return null;
        }

        $attempts = (int) $delivery->attempts + 1;
        $endpoint = DB::table('webhook_endpoints')->where('id', $delivery->webhook_id)->first();

        if (!$endpoint || (!$endpoint->active && $delivery->event !== self::TEST_EVENT)) {
            DB::table('webhook_deliveries')->where('id', $delivery->id)->update(['status' => 'failed', 'error' => 'El webhook está pausado: no se envió.', 'next_attempt_at' => null, 'updated_at' => now()]);

            return false;
        }

        $body = is_string($delivery->payload) ? $delivery->payload : json_encode($delivery->payload);
        $timestamp = now()->timestamp;
        $started = microtime(true);
        $code = null;
        $response = null;
        $error = null;

        try {
            $result = Http::timeout(10)->connectTimeout(5)->withoutRedirecting()
                ->withHeaders([
                    'User-Agent'          => 'Agenda-Webhooks/1.0',
                    'X-Webhook-Event'     => $delivery->event,
                    'X-Webhook-Id'        => $delivery->event_id,
                    'X-Webhook-Delivery'  => $delivery->id,
                    'X-Webhook-Timestamp' => (string) $timestamp,
                    'X-Webhook-Signature' => self::sign($body, self::secretOf($endpoint), $timestamp),
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->url);

            $code = $result->status();
            $response = mb_substr((string) $result->body(), 0, 1000);
            $ok = $result->successful();

            if (!$ok) {
                $error = "El sistema que recibe respondió con el código {$code}.";
            }
        } catch (\Throwable $e) {
            $ok = false;
            $error = str_contains($e->getMessage(), 'timed out') || str_contains($e->getMessage(), 'timeout')
                ? 'El sistema que recibe tardó más de 10 segundos en responder.'
                : 'No se pudo conectar con la dirección del webhook. Revisa que esté bien escrita y que esté en línea.';
        }

        $final = $attempts >= self::MAX_ATTEMPTS;

        DB::table('webhook_deliveries')->where('id', $delivery->id)->update([
            'status'          => $ok ? 'sent' : ($final ? 'failed' : 'retrying'),
            'response_code'   => $code,
            'response_body'   => $response,
            'error'           => $ok ? null : mb_substr((string) $error, 0, 500),
            'duration_ms'     => (int) round((microtime(true) - $started) * 1000),
            'delivered_at'    => $ok ? now() : null,
            'next_attempt_at' => $ok || $final ? null : now()->addMinutes(self::BACKOFF_MINUTES[min($attempts, count(self::BACKOFF_MINUTES)) - 1]),
            'updated_at'      => now(),
        ]);

        return $ok;
    }

    public static function resend(object $delivery): ?bool
    {
        DB::table('webhook_deliveries')->where('id', $delivery->id)->update(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => null, 'updated_at' => now()]);

        return self::deliver(DB::table('webhook_deliveries')->where('id', $delivery->id)->first());
    }

    public static function test(object $endpoint): ?bool
    {
        $eventId = (string) Str::uuid();
        $id = (string) Str::uuid();

        DB::table('webhook_deliveries')->insert([
            'id' => $id, 'tenant_id' => $endpoint->tenant_id, 'webhook_id' => $endpoint->id, 'event_id' => $eventId, 'event' => self::TEST_EVENT,
            'payload' => json_encode(['id' => $eventId, 'event' => self::TEST_EVENT, 'created_at' => now()->toIso8601String(), 'data' => ['message' => 'Prueba de conexión']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'pending', 'attempts' => self::MAX_ATTEMPTS - 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return self::deliver(DB::table('webhook_deliveries')->where('id', $id)->first());
    }

    public static function urlProblem(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'Escribe una dirección completa, que empiece con https://';
        }

        if (!app()->isProduction()) {
            return null;
        }

        if ($scheme !== 'https') {
            return 'La dirección debe empezar con https:// para que los datos viajen protegidos.';
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'Esa dirección es de una red interna: usa la dirección pública del sistema que recibirá los avisos.';
            }
        }

        return $host === 'localhost' ? 'Esa dirección es de una red interna: usa la dirección pública del sistema que recibirá los avisos.' : null;
    }

    public static function presentEndpoint(object $endpoint, array $stats = []): array
    {
        return [
            'id'          => $endpoint->id,
            'url'         => $endpoint->url,
            'description' => $endpoint->description,
            'events'      => is_string($endpoint->events) ? (json_decode($endpoint->events, true) ?: []) : (array) $endpoint->events,
            'active'      => (bool) $endpoint->active,
            'created_at'  => Carbon::parse($endpoint->created_at)->toIso8601String(),
            'last'        => $stats[$endpoint->id] ?? null,
        ];
    }

    public static function presentDelivery(object $delivery): array
    {
        return [
            'id'              => $delivery->id,
            'event'           => $delivery->event,
            'event_label'     => self::EVENTS[$delivery->event] ?? ($delivery->event === self::TEST_EVENT ? 'Prueba de conexión' : $delivery->event),
            'event_id'        => $delivery->event_id,
            'status'          => $delivery->status,
            'status_label'    => self::STATUS[$delivery->status] ?? $delivery->status,
            'attempts'        => (int) $delivery->attempts,
            'response_code'   => $delivery->response_code !== null ? (int) $delivery->response_code : null,
            'response_body'   => $delivery->response_body,
            'error'           => $delivery->error,
            'duration_ms'     => $delivery->duration_ms !== null ? (int) $delivery->duration_ms : null,
            'payload'         => is_string($delivery->payload) ? json_decode($delivery->payload, true) : $delivery->payload,
            'next_attempt_at' => $delivery->next_attempt_at ? Carbon::parse($delivery->next_attempt_at)->toIso8601String() : null,
            'delivered_at'    => $delivery->delivered_at ? Carbon::parse($delivery->delivered_at)->toIso8601String() : null,
            'created_at'      => Carbon::parse($delivery->created_at)->toIso8601String(),
        ];
    }
}
