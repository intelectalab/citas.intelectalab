<?php

use App\Support\ApiKeys;
use App\Support\Webhooks;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

const API_HOOK = 'https://sistema.ejemplo.test/avisos';

function apiCompany(object $test): object
{
    return (function () {
        $co = $this->company(['modules_off' => []]);
        $co->service = $this->servicePriced($co->tenant, 80, 'Corte de prueba');
        DB::table('services')->where('id', $co->service->id)->update(['duration_minutes' => 30]);
        $co->pro = $this->professional($co, 'Ana Profesional');
        $co->client = $this->customer($co->tenant, 'Lucía', 'Quispe');

        return $co;
    })->call($test);
}

function apiKey(object $test, object $co, array $scopes = ['appointments' => 'write', 'availability' => 'read', 'customers' => 'write', 'services' => 'read']): string
{
    return (fn () => $this->as($co->owner))->call($test)->postJson('/api/integrations/api/keys', ['name' => 'Página web', 'scopes' => $scopes])->assertCreated()->json('plain');
}

function apiHook(object $test, object $co, array $events): array
{
    return (fn () => $this->as($co->owner))->call($test)->postJson('/api/integrations/api/webhooks', ['url' => API_HOOK, 'events' => $events])->assertCreated()->json();
}

function apiCall(object $test, string $key, string $method, string $url, array $data = [])
{
    return $test->withHeader('Authorization', 'Bearer ' . $key)->json($method, $url, $data);
}

it('la clave se muestra una sola vez, se guarda con hash y registra su último uso', function () {
    $co = apiCompany($this);
    $plain = apiKey($this, $co, ['services' => 'read']);

    expect($plain)->toStartWith('apk_');
    $row = DB::table('api_keys')->where('tenant_id', $co->tenant->id)->first();
    expect($row->key_hash)->toBe(hash('sha256', $plain))
        ->and(json_encode(DB::table('api_keys')->get()))->not->toContain($plain)
        ->and($row->last_used_at)->toBeNull();

    $listed = $this->as($co->owner)->getJson('/api/integrations/api')->assertOk();
    expect($listed->getContent())->not->toContain($plain)
        ->and($listed->json('keys.0.prefix'))->toBe(substr($plain, 0, 12));

    apiCall($this, $plain, 'GET', '/api/v1/services')->assertOk()->assertJsonFragment(['name' => 'Corte de prueba']);
    expect(DB::table('api_keys')->where('id', $row->id)->value('last_used_at'))->not->toBeNull();
});

it('una clave revocada deja de funcionar al instante', function () {
    $co = apiCompany($this);
    $plain = apiKey($this, $co);
    apiCall($this, $plain, 'GET', '/api/v1/services')->assertOk();

    $id = DB::table('api_keys')->where('tenant_id', $co->tenant->id)->value('id');
    $this->as($co->owner)->postJson("/api/integrations/api/keys/{$id}/revoke")->assertOk();

    apiCall($this, $plain, 'GET', '/api/v1/services')->assertStatus(401)->assertJsonPath('code', 'API_KEY_REVOKED');
    apiCall($this, 'apk_inventada', 'GET', '/api/v1/services')->assertStatus(401);
    $this->getJson('/api/v1/services')->assertStatus(401);
});

it('cada clave solo hace lo que sus permisos dicen', function () {
    $co = apiCompany($this);
    $reader = apiKey($this, $co, ['appointments' => 'read']);

    apiCall($this, $reader, 'GET', '/api/v1/appointments')->assertOk();
    apiCall($this, $reader, 'POST', '/api/v1/appointments', [])->assertForbidden();
    apiCall($this, $reader, 'GET', '/api/v1/customers')->assertForbidden();
    apiCall($this, $reader, 'GET', '/api/v1/services')->assertForbidden();

    $this->as($co->owner)->postJson('/api/integrations/api/keys', ['name' => 'Vacía', 'scopes' => ['services' => 'none']])->assertStatus(422);
});

it('limita las solicitudes por minuto de cada clave', function () {
    $co = apiCompany($this);
    $plain = apiKey($this, $co);
    $id = DB::table('api_keys')->where('tenant_id', $co->tenant->id)->value('id');

    foreach (range(1, ApiKeys::PER_MINUTE) as $i) {
        RateLimiter::hit('api-key:' . $id, 60);
    }

    apiCall($this, $plain, 'GET', '/api/v1/services')->assertStatus(429)->assertHeader('Retry-After');
});

it('sin el módulo API y webhooks la clave no funciona', function () {
    $co = apiCompany($this);
    $plain = apiKey($this, $co);
    DB::table('tenant_settings')->where('tenant_id', $co->tenant->id)->update(['modules_off' => json_encode(['api'])]);
    \Tests\TestCase::forgetCaches();

    apiCall($this, $plain, 'GET', '/api/v1/services')->assertForbidden()->assertJsonPath('code', 'MODULE_DISABLED');
    $this->as($co->owner)->getJson('/api/integrations/api')->assertForbidden();
});

it('agenda, lista, consulta horarios y cancela citas con la misma lógica de la agenda', function () {
    $co = apiCompany($this);
    $plain = apiKey($this, $co);
    $day = $this->workday($co->tenant)->format('Y-m-d');

    $slots = apiCall($this, $plain, 'GET', '/api/v1/availability?' . http_build_query(['service_id' => $co->service->id, 'branch_id' => $co->branch->id, 'date' => $day]))->assertOk();
    expect($slots->json('professionals'))->not->toBeEmpty();

    $created = apiCall($this, $plain, 'POST', '/api/v1/appointments', [
        'customer_id' => $co->client->id, 'service_id' => $co->service->id, 'professional_id' => $co->pro->id,
        'branch_id' => $co->branch->id, 'starts_at' => "{$day}T10:00", 'video_url' => 'https://meet.ejemplo.test/abc',
    ])->assertCreated();

    $id = $created->json('data.id');
    expect($created->json('data.service.name'))->toBe('Corte de prueba')
        ->and($created->json('data.video_url'))->toBe('https://meet.ejemplo.test/abc')
        ->and(DB::table('appointments')->where('id', $id)->value('source'))->toBe('api');

    apiCall($this, $plain, 'POST', '/api/v1/appointments', [
        'customer_id' => $co->client->id, 'service_id' => $co->service->id, 'professional_id' => $co->pro->id,
        'branch_id' => $co->branch->id, 'starts_at' => "{$day}T10:00",
    ])->assertStatus(409);

    $list = apiCall($this, $plain, 'GET', "/api/v1/appointments?from={$day}&to={$day}")->assertOk();
    expect($list->json('meta.total'))->toBe(1)->and($list->json('data.0.id'))->toBe($id);
    apiCall($this, $plain, 'GET', "/api/v1/appointments/{$id}")->assertOk()->assertJsonPath('data.customer.first_name', 'Lucía');

    apiCall($this, $plain, 'POST', "/api/v1/appointments/{$id}/cancel", ['reason' => 'Lo pidió por la web'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(DB::table('appointments')->where('id', $id)->value('cancel_reason'))->toBe('Lo pidió por la web');
});

it('registra y busca clientes por la API', function () {
    $co = apiCompany($this);
    $plain = apiKey($this, $co);

    apiCall($this, $plain, 'POST', '/api/v1/customers', ['first_name' => 'Rosa', 'last_name' => 'Huamán', 'email' => 'rosa@cliente.test'])->assertCreated()
        ->assertJsonPath('data.first_name', 'Rosa');
    apiCall($this, $plain, 'POST', '/api/v1/customers', ['last_name' => 'Sin nombre'])->assertStatus(422);

    apiCall($this, $plain, 'GET', '/api/v1/customers?search=huaman')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.email', 'rosa@cliente.test');
});

it('envía el aviso firmado cuando se agenda una cita y la firma se puede verificar', function () {
    Http::fake([API_HOOK => Http::response(['ok' => true], 200)]);
    $co = apiCompany($this);
    $hook = apiHook($this, $co, ['appointment.created', 'appointment.cancelled']);

    $this->book($co, $co->client, $co->service, $co->pro, $this->at($co->tenant, '11:00'))->assertCreated();

    Http::assertSent(function (HttpRequest $request) use ($hook) {
        [$t, $v1] = array_map(fn ($part) => explode('=', $part, 2)[1], explode(',', $request->header('X-Webhook-Signature')[0]));
        $expected = hash_hmac('sha256', $t . '.' . $request->body(), $hook['secret']);

        return $request->url() === API_HOOK
            && $request->header('X-Webhook-Event')[0] === 'appointment.created'
            && hash_equals($expected, $v1)
            && $request['data']['customer']['first_name'] === 'Lucía';
    });

    $delivery = DB::table('webhook_deliveries')->where('tenant_id', $co->tenant->id)->first();
    expect($delivery->status)->toBe('sent')->and($delivery->response_code)->toBe(200);
});

it('solo avisa los eventos elegidos', function () {
    Http::fake([API_HOOK => Http::response('', 200)]);
    $co = apiCompany($this);
    apiHook($this, $co, ['appointment.cancelled']);

    $this->book($co, $co->client, $co->service, $co->pro, $this->at($co->tenant, '11:00'))->assertCreated();

    expect(DB::table('webhook_deliveries')->count())->toBe(0);
    Http::assertNothingSent();
});

it('si el sistema que recibe falla, la cita igual se guarda y el aviso se reintenta con más espera', function () {
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;

        return $calls < 3 ? Http::response('caído', 500) : Http::response('', 204);
    });
    $co = apiCompany($this);
    $hook = apiHook($this, $co, ['appointment.created']);

    $this->book($co, $co->client, $co->service, $co->pro, $this->at($co->tenant, '11:00'))->assertCreated();

    $delivery = DB::table('webhook_deliveries')->first();
    expect($delivery->status)->toBe('retrying')->and($delivery->attempts)->toBe(1)->and($delivery->response_code)->toBe(500);
    $first = \Illuminate\Support\Carbon::parse($delivery->next_attempt_at);

    $this->artisan('webhooks:send')->assertSuccessful();
    expect(DB::table('webhook_deliveries')->value('attempts'))->toBe(1);

    $this->travelTo($first->copy()->addSecond());
    $this->artisan('webhooks:send')->assertSuccessful();
    $second = DB::table('webhook_deliveries')->first();
    expect($second->attempts)->toBe(2)->and($second->status)->toBe('retrying')
        ->and(now()->diffInMinutes(\Illuminate\Support\Carbon::parse($second->next_attempt_at)))->toBeGreaterThanOrEqual(4);

    $this->travelTo(\Illuminate\Support\Carbon::parse($second->next_attempt_at)->addSecond());
    $this->artisan('webhooks:send')->assertSuccessful();
    expect(DB::table('webhook_deliveries')->value('status'))->toBe('sent');

    $list = $this->as($co->owner)->getJson("/api/integrations/api/webhooks/{$hook['webhook']['id']}/deliveries")->assertOk();
    expect($list->json('deliveries.0.status'))->toBe('sent')->and($list->json('deliveries.0.attempts'))->toBe(3);
});

it('tras el último intento queda como fallido y se puede reenviar', function () {
    $up = false;
    Http::fake(function () use (&$up) {
        if (!$up) {
            throw new \Illuminate\Http\Client\ConnectionException('Could not resolve host');
        }

        return Http::response('', 200);
    });
    $co = apiCompany($this);
    $hook = apiHook($this, $co, ['customer.created']);

    $this->as($co->owner)->postJson('/api/customers', ['first_name' => 'Nueva', 'last_name' => 'Clienta'])->assertCreated();

    for ($i = 0; $i < Webhooks::MAX_ATTEMPTS; $i++) {
        $this->travel(13)->hours();
        $this->artisan('webhooks:send');
    }

    $delivery = DB::table('webhook_deliveries')->first();
    expect($delivery->status)->toBe('failed')->and($delivery->attempts)->toBe(Webhooks::MAX_ATTEMPTS)
        ->and($delivery->error)->toContain('No se pudo conectar');

    $up = true;
    $this->as($co->owner)->postJson("/api/integrations/api/webhooks/{$hook['webhook']['id']}/deliveries/{$delivery->id}/resend")->assertOk()->assertJsonPath('ok', true);
    expect(DB::table('webhook_deliveries')->value('status'))->toBe('sent');
});

it('avisa la venta pagada, el cliente nuevo y los pasos de la cita', function () {
    Http::fake([API_HOOK => Http::response('', 200)]);
    $co = apiCompany($this);
    apiHook($this, $co, array_keys(Webhooks::EVENTS));

    $id = $this->book($co, $co->client, $co->service, $co->pro, $this->at($co->tenant, '11:00'))->assertCreated()->json('id');
    $this->as($co->owner)->postJson("/api/appointments/{$id}/status", ['status' => 'cancelled', 'reason' => 'Viaje'])->assertOk();
    $this->as($co->owner)->postJson('/api/customers', ['first_name' => 'Otra', 'last_name' => 'Persona'])->assertCreated();

    $this->openCash($co);
    $sale = $this->sell($co, [['type' => 'service', 'id' => $co->service->id, 'quantity' => 1]], ['customer_id' => $co->client->id]);
    $this->pay($co, $sale['id'], ['method' => 'cash', 'amount' => 80])->assertSuccessful();

    $events = DB::table('webhook_deliveries')->orderBy('created_at')->pluck('event')->all();
    expect($events)->toContain('appointment.created', 'appointment.cancelled', 'customer.created', 'sale.paid');

    $paid = json_decode(DB::table('webhook_deliveries')->where('event', 'sale.paid')->value('payload'), true);
    expect($paid['data']['id'])->toBe($sale['id'])->and($paid['data']['total'])->toEqual(80);
});

it('la prueba de conexión y la dirección del webhook se validan', function () {
    Http::fake([API_HOOK => Http::response('', 200)]);
    $co = apiCompany($this);
    $hook = apiHook($this, $co, ['appointment.created']);

    $this->as($co->owner)->postJson("/api/integrations/api/webhooks/{$hook['webhook']['id']}/test")->assertOk()->assertJsonPath('ok', true);
    $this->as($co->owner)->postJson('/api/integrations/api/webhooks', ['url' => 'no-es-una-direccion', 'events' => ['appointment.created']])->assertStatus(422);
    $this->as($co->owner)->postJson('/api/integrations/api/webhooks', ['url' => API_HOOK, 'events' => ['otra.cosa']])->assertStatus(422);
});

it('recepción no ve ni cambia las claves ni los webhooks', function () {
    $co = apiCompany($this);
    $reception = $this->staff($co, 'reception');

    $this->as($reception)->getJson('/api/integrations/api')->assertForbidden();
    $this->as($reception)->postJson('/api/integrations/api/keys', ['name' => 'X', 'scopes' => ['services' => 'read']])->assertForbidden();
});

it('el enlace de calendario muestra las citas del profesional sin datos del cliente, y se puede regenerar', function () {
    $co = apiCompany($this);
    $this->book($co, $co->client, $co->service, $co->pro, $this->at($co->tenant, '11:00'))->assertCreated();

    $feed = $this->as($co->owner)->postJson('/api/integrations/api/calendars', ['professional_id' => $co->pro->id])->assertCreated()->json('feed');
    $path = parse_url($feed['url'], PHP_URL_PATH);

    $ics = $this->get($path)->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->getContent();
    expect($ics)->toContain('BEGIN:VCALENDAR', 'BEGIN:VEVENT', 'SUMMARY:Corte de prueba')->not->toContain('Lucía')->not->toContain('Quispe');

    $this->as($co->owner)->putJson("/api/integrations/api/calendars/{$feed['id']}", ['detail' => 'first_name'])->assertOk();
    expect($this->get($path)->getContent())->toContain('Lucía')->not->toContain('Quispe');

    $new = $this->as($co->owner)->postJson('/api/integrations/api/calendars', ['professional_id' => $co->pro->id])->assertOk()->json('feed');
    expect($new['url'])->not->toBe($feed['url']);
    $this->get($path)->assertNotFound();
    $this->get(parse_url($new['url'], PHP_URL_PATH))->assertOk();
});

it('el enlace de la sede junta las citas de todo su equipo', function () {
    $co = apiCompany($this);
    $otra = $this->professional($co, 'Beto Profesional');
    $this->book($co, $co->client, $co->service, $co->pro, $this->at($co->tenant, '11:00'))->assertCreated();
    $this->book($co, $this->customer($co->tenant, 'Mario', 'Vega'), $co->service, $otra, $this->at($co->tenant, '11:00'))->assertCreated();

    $feed = $this->as($co->owner)->postJson('/api/integrations/api/calendars', ['branch_id' => $co->branch->id])->assertCreated()->json('feed');
    $ics = $this->get(parse_url($feed['url'], PHP_URL_PATH))->getContent();

    expect(substr_count($ics, 'BEGIN:VEVENT'))->toBe(2)->and($ics)->toContain('Ana Profesional', 'Beto Profesional');
});

it('el enlace de videollamada sale en la cita, el portal, el enlace de la cita y el correo', function () {
    $co = apiCompany($this);
    $this->as($co->owner)->putJson("/api/services/{$co->service->id}", [
        'name' => 'Corte de prueba', 'price' => 80, 'duration_minutes' => 30, 'is_virtual' => true, 'video_url' => 'https://meet.ejemplo.test/sala', 'status_id' => $co->service->status_id,
    ])->assertSuccessful();
    expect(DB::table('services')->where('id', $co->service->id)->value('video_url'))->toBe('https://meet.ejemplo.test/sala');

    $id = $this->book($co, $co->client, $co->service, $co->pro, $this->at($co->tenant, '11:00'))->assertCreated()->assertJsonPath('video_url', 'https://meet.ejemplo.test/sala')->json('id');

    $this->as($co->owner)->putJson("/api/appointments/{$id}", ['notes' => null, 'video_url' => 'https://meet.ejemplo.test/propia'])->assertOk()->assertJsonPath('video_url', 'https://meet.ejemplo.test/propia');

    $this->as($co->client)->getJson('/api/portal/overview')->assertOk()->assertJsonFragment(['video_url' => 'https://meet.ejemplo.test/propia']);

    $token = \App\Support\AppointmentLinks::token(\App\Models\Appointment::find($id));
    $this->getJson("/api/public/appointment/{$token}")->assertOk()->assertJsonPath('appointment.video_url', 'https://meet.ejemplo.test/propia');

    $vars = \App\Support\Messaging::appointmentVars($co->tenant, \App\Models\Appointment::find($id));
    expect($vars['Videollamada'])->toBe('https://meet.ejemplo.test/propia');

    $body = DB::table('messages')->where('tenant_id', $co->tenant->id)->where('kind', 'booking_confirmation')->value('body');
    expect($body)->toContain('https://meet.ejemplo.test/sala');
});
