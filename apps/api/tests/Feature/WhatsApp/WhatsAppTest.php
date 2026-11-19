<?php

use App\Http\Controllers\Api\MessageController;
use App\Support\Assistant;
use App\Support\Assistant\FakeProvider;
use App\Support\Messaging;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const WA_GRAPH = 'https://graph.facebook.com/*';
const WA_SECRET = 'secreto-de-la-app';
const WA_VERIFY = 'verificar-123';

function waAs(object $test, object $user): object
{
    \Tests\TestCase::forgetCaches();
    $user->unsetRelations();
    \Laravel\Sanctum\Sanctum::actingAs($user, ['*']);

    return $test;
}

function waConnect(object $test, object $co, array $extra = []): void
{
    waAs($test, $co->owner)->putJson('/api/messaging/whatsapp', $extra + [
        'provider' => 'cloud', 'phone_number_id' => '1234567890', 'business_account_id' => '555',
        'token' => 'EAA-token-de-prueba', 'app_secret' => WA_SECRET, 'verify_token' => WA_VERIFY,
    ])->assertOk();
}

function waPhone(object $customer, string $phone = '987 654 321'): void
{
    DB::table('customers')->where('id', $customer->id)->update(['phone' => $phone]);
    $customer->refresh();
}

function waInbound(object $test, object $co, string $from, string $text, string $id = null): \Illuminate\Testing\TestResponse
{
    $payload = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
        'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Lucía WA']]],
        'messages' => [['from' => $from, 'id' => $id ?? 'wamid.IN' . uniqid(), 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]]],
    ]]]]]]);

    return $test->call('POST', "/api/webhooks/whatsapp/{$co->tenant->id}", [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $payload, WA_SECRET),
    ], $payload);
}

function waStatus(object $test, object $co, string $id, string $status): void
{
    $payload = json_encode(['entry' => [['changes' => [['value' => ['statuses' => [['id' => $id, 'status' => $status, 'timestamp' => (string) now()->timestamp]]]]]]]]);
    $test->call('POST', "/api/webhooks/whatsapp/{$co->tenant->id}", [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $payload, WA_SECRET),
    ], $payload)->assertOk();
}

beforeEach(function () {
    $this->co = $this->company();
    $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Manicure');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 60]);
    $this->ana = $this->professional($this->co, 'Ana Torres');
    $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Paredes');
    waPhone($this->clienta);
    $this->sent = [];
    $this->waFail = false;
    $test = $this;
    Http::fake([WA_GRAPH => function (HttpRequest $request) use ($test) {
        if ($test->waFail && $request->method() === 'POST') {
            return Http::response(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token']], 401);
        }

        $test->sent[] = $request->data();
        $sent = $test->sent;

        return $request->method() === 'GET'
            ? Http::response(['display_phone_number' => '+51 999 888 777', 'verified_name' => 'Salón'], 200)
            : Http::response(['messages' => [['id' => 'wamid.OUT' . count($sent)]]], 200);
    }]);
});

it('por defecto los avisos de la cita siguen saliendo solo por correo', function () {
    waConnect($this, $this->co);
    MessageController::saveConsents($this->co->tenant->id, $this->clienta->id, ['whatsapp' => true], 'staff');

    $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant))->assertCreated();

    expect(DB::table('messages')->where('tenant_id', $this->co->tenant->id)->where('kind', 'booking_confirmation')->pluck('channel')->all())->toBe(['email'])
        ->and($this->sent)->toBe([]);
});

it('con la regla WhatsApp la confirmación sale con su plantilla aprobada fuera de las 24 horas', function () {
    waConnect($this, $this->co, ['templates' => ['booking_confirmation' => ['name' => 'cita_agendada', 'language' => 'es']]]);
    $this->setRules($this->co->tenant, ['whatsapp.channel' => 'whatsapp']);
    MessageController::saveConsents($this->co->tenant->id, $this->clienta->id, ['whatsapp' => true], 'staff');

    $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant))->assertCreated();
    Messaging::process($this->co->tenant->id);

    $row = DB::table('messages')->where('tenant_id', $this->co->tenant->id)->where('kind', 'booking_confirmation')->get();
    $post = collect($this->sent)->firstWhere('type', 'template');

    expect($row)->toHaveCount(1)
        ->and($row[0]->channel)->toBe('whatsapp')
        ->and($row[0]->status)->toBe('sent')
        ->and($row[0]->to_address)->toBe('51987654321')
        ->and($post['template']['name'])->toBe('cita_agendada')
        ->and($post['template']['components'][0]['parameters'][0]['text'])->toBe('Lucía')
        ->and(DB::table('whatsapp_messages')->where('message_id', $row[0]->id)->where('direction', 'out')->exists())->toBeTrue();
});

it('sin consentimiento del cliente el aviso cae a correo', function () {
    waConnect($this, $this->co, ['templates' => ['booking_confirmation' => ['name' => 'cita_agendada']]]);
    $this->setRules($this->co->tenant, ['whatsapp.channel' => 'whatsapp']);

    $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant))->assertCreated();

    expect(DB::table('messages')->where('tenant_id', $this->co->tenant->id)->where('kind', 'booking_confirmation')->pluck('channel')->all())->toBe(['email']);
});

it('la regla de consentimiento "salvo quien lo rechazó" deja enviar a quien no dijo nada', function () {
    waConnect($this, $this->co, ['templates' => ['booking_confirmation' => ['name' => 'cita_agendada']]]);
    $this->setRules($this->co->tenant, ['whatsapp.channel' => 'whatsapp', 'whatsapp.consent' => 'unless_revoked']);

    $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant))->assertCreated();

    expect(DB::table('messages')->where('tenant_id', $this->co->tenant->id)->where('kind', 'booking_confirmation')->pluck('channel')->all())->toBe(['whatsapp']);
});

it('fuera de las 24 horas y sin plantilla el aviso sale por correo', function () {
    waConnect($this, $this->co);
    $this->setRules($this->co->tenant, ['whatsapp.channel' => 'whatsapp']);
    MessageController::saveConsents($this->co->tenant->id, $this->clienta->id, ['whatsapp' => true], 'staff');

    $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant))->assertCreated();

    expect(DB::table('messages')->where('tenant_id', $this->co->tenant->id)->where('kind', 'booking_confirmation')->pluck('channel')->all())->toBe(['email']);
});

it('dentro de las 24 horas el aviso sale como texto con el enlace firmado de la cita', function () {
    waConnect($this, $this->co);
    $this->setRules($this->co->tenant, ['whatsapp.channel' => 'whatsapp']);
    MessageController::saveConsents($this->co->tenant->id, $this->clienta->id, ['whatsapp' => true], 'staff');
    waInbound($this, $this->co, '51987654321', 'Hola, quiero una cita')->assertOk();

    $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant))->assertCreated();
    Messaging::process($this->co->tenant->id);

    $post = collect($this->sent)->firstWhere('type', 'text');

    expect($post)->not->toBeNull()
        ->and($post['text']['body'])->toContain('/cita/')
        ->and(DB::table('messages')->where('tenant_id', $this->co->tenant->id)->where('kind', 'booking_confirmation')->value('status'))->toBe('sent');
});

it('si WhatsApp falla, el aviso se manda por correo', function () {
    waConnect($this, $this->co, ['templates' => ['booking_confirmation' => ['name' => 'cita_agendada']]]);
    $this->waFail = true;
    $this->setRules($this->co->tenant, ['whatsapp.channel' => 'whatsapp']);
    MessageController::saveConsents($this->co->tenant->id, $this->clienta->id, ['whatsapp' => true], 'staff');

    $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant))->assertCreated();
    Messaging::process($this->co->tenant->id);

    $rows = DB::table('messages')->where('tenant_id', $this->co->tenant->id)->where('kind', 'booking_confirmation')->get()->keyBy('channel');

    expect($rows['whatsapp']->status)->toBe('failed')
        ->and($rows['whatsapp']->error)->toContain('token')
        ->and($rows)->toHaveKey('email');
});

it('con la regla "ambos" sale por WhatsApp y por correo', function () {
    waConnect($this, $this->co, ['templates' => ['booking_confirmation' => ['name' => 'cita_agendada']]]);
    $this->setRules($this->co->tenant, ['whatsapp.channel' => 'both']);
    MessageController::saveConsents($this->co->tenant->id, $this->clienta->id, ['whatsapp' => true], 'staff');

    $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant))->assertCreated();

    expect(DB::table('messages')->where('tenant_id', $this->co->tenant->id)->where('kind', 'booking_confirmation')->pluck('channel')->sort()->values()->all())->toBe(['email', 'whatsapp']);
});

it('verifica el webhook con la clave de verificación', function () {
    waConnect($this, $this->co);
    $url = "/api/webhooks/whatsapp/{$this->co->tenant->id}";

    $this->get("{$url}?hub.mode=subscribe&hub.verify_token=" . WA_VERIFY . '&hub.challenge=12345')->assertOk()->assertSee('12345');
    $this->get("{$url}?hub.mode=subscribe&hub.verify_token=otra&hub.challenge=12345")->assertForbidden();
});

it('rechaza avisos sin la firma de Meta', function () {
    waConnect($this, $this->co);
    $payload = json_encode(['entry' => []]);

    $this->call('POST', "/api/webhooks/whatsapp/{$this->co->tenant->id}", [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload)->assertForbidden();
    $this->call('POST', "/api/webhooks/whatsapp/{$this->co->tenant->id}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=malo'], $payload)->assertForbidden();
});

it('guarda la conversación, la une al cliente por su celular y actualiza los estados', function () {
    waConnect($this, $this->co);
    waInbound($this, $this->co, '51987654321', 'Hola')->assertOk()->assertJsonPath('messages', 1);
    waInbound($this, $this->co, '51987654321', 'Hola', 'wamid.REPETIDO')->assertOk();
    waInbound($this, $this->co, '51987654321', 'Hola', 'wamid.REPETIDO')->assertJsonPath('messages', 0);

    $conversation = DB::table('whatsapp_conversations')->where('tenant_id', $this->co->tenant->id)->first();
    expect($conversation->customer_id)->toBe($this->clienta->id)->and($conversation->unread)->toBe(2);

    $this->as($this->co->owner)->postJson("/api/whatsapp/conversations/{$conversation->id}/reply", ['body' => 'Hola Lucía, ¿qué día te acomoda?'])
        ->assertOk()->assertJsonPath('messages.2.status', 'sent');

    $out = DB::table('whatsapp_messages')->where('conversation_id', $conversation->id)->where('direction', 'out')->first();
    waStatus($this, $this->co, $out->provider_message_id, 'delivered');
    waStatus($this, $this->co, $out->provider_message_id, 'read');
    waStatus($this, $this->co, $out->provider_message_id, 'delivered');

    $out = DB::table('whatsapp_messages')->where('id', $out->id)->first();
    expect($out->status)->toBe('read')->and($out->delivered_at)->not->toBeNull()->and($out->read_at)->not->toBeNull();

    $this->getJson('/api/whatsapp/conversations')->assertOk()->assertJsonPath('conversations.0.customer.id', $this->clienta->id);
});

it('responder pide su propio permiso y solo se puede dentro de las 24 horas', function () {
    waConnect($this, $this->co);
    waInbound($this, $this->co, '51987654321', 'Hola')->assertOk();
    $conversation = DB::table('whatsapp_conversations')->where('tenant_id', $this->co->tenant->id)->first();

    $caja = $this->staff($this->co, 'cashier');
    $this->as($caja)->getJson("/api/whatsapp/conversations/{$conversation->id}")->assertOk()->assertJsonPath('can.reply', false);
    $this->as($caja)->postJson("/api/whatsapp/conversations/{$conversation->id}/reply", ['body' => 'Hola'])->assertForbidden();

    $recepcion = $this->staff($this->co, 'reception');
    $this->as($recepcion)->postJson("/api/whatsapp/conversations/{$conversation->id}/reply", ['body' => 'Hola'])->assertOk();

    DB::table('whatsapp_conversations')->where('id', $conversation->id)->update(['last_inbound_at' => now()->subHours(25)]);
    $this->as($recepcion)->postJson("/api/whatsapp/conversations/{$conversation->id}/reply", ['body' => 'Hola'])->assertStatus(422);
});

it('el asistente está apagado por defecto', function () {
    waConnect($this, $this->co);
    Assistant::$provider = new FakeProvider();

    waInbound($this, $this->co, '51987654321', 'Hola')->assertOk();

    expect(DB::table('assistant_logs')->count())->toBe(0)->and(FakeProvider::$received)->toBe([]);
});

it('con el asistente encendido responde y deja registro para medirlo', function () {
    waConnect($this, $this->co);
    $this->setRules($this->co->tenant, ['assistant.enabled' => true]);
    Assistant::$provider = new FakeProvider();
    FakeProvider::$answers = [['action' => 'reply', 'text' => 'Atendemos de 9 a 7.']];

    waInbound($this, $this->co, '51987654321', '¿Qué horario tienen?')->assertOk();

    $log = DB::table('assistant_logs')->first();
    expect($log->action)->toBe('reply')->and($log->provider)->toBe('fake')->and($log->question)->toBe('¿Qué horario tienen?')
        ->and(DB::table('whatsapp_messages')->where('by_assistant', true)->value('body'))->toBe('Atendemos de 9 a 7.')
        ->and(FakeProvider::$received[0]['customer']['id'])->toBe($this->clienta->id);
});

it('el asistente nunca responde una consulta de salud: la deriva a una persona y avisa al personal', function () {
    waConnect($this, $this->co);
    $this->setRules($this->co->tenant, ['assistant.enabled' => true]);
    Assistant::$provider = new FakeProvider();
    $recepcion = $this->staff($this->co, 'reception');

    waInbound($this, $this->co, '51987654321', '¿Qué medicamento tomo para el DOLOR?')->assertOk();

    $conversation = DB::table('whatsapp_conversations')->where('tenant_id', $this->co->tenant->id)->first();
    expect($conversation->status)->toBe('needs_human')
        ->and(FakeProvider::$received)->toBe([])
        ->and(DB::table('assistant_logs')->value('action'))->toBe('blocked')
        ->and(DB::table('user_notifications')->where('user_id', $recepcion->id)->where('type', 'whatsapp_handoff')->exists())->toBeTrue()
        ->and(DB::table('whatsapp_messages')->where('by_assistant', true)->value('body'))->toBe(Assistant::HANDOFF_TEXT);

    waInbound($this, $this->co, '51987654321', 'Hola?')->assertOk();
    expect(DB::table('assistant_logs')->count())->toBe(1);

    $this->as($recepcion)->postJson("/api/whatsapp/conversations/{$conversation->id}/resolve")->assertOk()->assertJsonPath('conversation.status', 'open');
});

it('el asistente puede pasar la conversación a una persona', function () {
    waConnect($this, $this->co);
    $this->setRules($this->co->tenant, ['assistant.enabled' => true]);
    Assistant::$provider = new FakeProvider();
    FakeProvider::$answers = [['action' => 'handoff', 'reason' => 'Pidió hablar con alguien.']];

    waInbound($this, $this->co, '51987654321', 'Quiero hablar con una persona')->assertOk();

    expect(DB::table('whatsapp_conversations')->value('handoff_reason'))->toBe('Pidió hablar con alguien.')
        ->and(DB::table('assistant_logs')->value('action'))->toBe('handoff');
});

it('el asistente reserva por chat con el flujo de reserva en línea', function () {
    waConnect($this, $this->co);
    $this->setRules($this->co->tenant, ['assistant.enabled' => true]);
    Assistant::$provider = new FakeProvider();
    FakeProvider::$answers = [['action' => 'book', 'text' => 'Listo, tu cita quedó agendada.', 'booking' => [
        'service_id' => $this->servicio->id, 'branch_id' => $this->co->branch->id, 'professional_id' => $this->ana->id, 'starts_at' => $this->at($this->co->tenant, '11:00'),
    ]]];

    waInbound($this, $this->co, '51987654321', 'Quiero cita el jueves a las 11')->assertOk();

    expect(DB::table('assistant_logs')->value('action'))->toBe('booking')
        ->and(DB::table('appointments')->where('customer_id', $this->clienta->id)->exists())->toBeTrue();
});

it('guarda las llaves cifradas, pide el token y prueba la conexión con Meta', function () {
    $this->as($this->co->owner)->putJson('/api/messaging/whatsapp', ['provider' => 'cloud', 'phone_number_id' => '1234567890', 'app_secret' => WA_SECRET])
        ->assertStatus(422)->assertJsonValidationErrors('token');

    waConnect($this, $this->co);
    $raw = DB::table('tenant_integrations')->where('tenant_id', $this->co->tenant->id)->where('kind', 'whatsapp')->value('credentials');
    expect($raw)->not->toContain('EAA-token-de-prueba');

    $this->postJson('/api/messaging/whatsapp/test')->assertOk()->assertJsonPath('ok', true)->assertJsonPath('status.state', 'ok')
        ->assertJsonPath('config.token_saved', '••••••••ueba');

    $caja = $this->staff($this->co, 'cashier');
    $this->as($caja)->putJson('/api/messaging/whatsapp', ['provider' => 'simulated'])->assertForbidden();
});

it('el modo simulado no llama a Meta y marca los mensajes como simulados', function () {
    $this->as($this->co->owner)->putJson('/api/messaging/whatsapp', ['provider' => 'simulated'])->assertOk()->assertJsonPath('status.state', 'simulated');
    $this->setRules($this->co->tenant, ['whatsapp.channel' => 'whatsapp']);
    MessageController::saveConsents($this->co->tenant->id, $this->clienta->id, ['whatsapp' => true], 'staff');
    $payload = json_encode(['entry' => [['changes' => [['value' => ['messages' => [['from' => '51987654321', 'id' => 'wamid.S1', 'type' => 'text', 'text' => ['body' => 'Hola']]]]]]]]]);
    $this->call('POST', "/api/webhooks/whatsapp/{$this->co->tenant->id}", [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload)->assertOk();

    $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant))->assertCreated();
    Messaging::process($this->co->tenant->id);

    expect(DB::table('messages')->where('tenant_id', $this->co->tenant->id)->where('kind', 'booking_confirmation')->value('status'))->toBe('simulated')
        ->and($this->sent)->toBe([]);
});

it('la empresa elige el canal, el consentimiento y el asistente en Configuración', function () {
    $this->as($this->co->owner)->getJson('/api/messaging/whatsapp')->assertOk()
        ->assertJsonPath('rules.channel', 'email')->assertJsonPath('rules.assistant_enabled', false)->assertJsonPath('assistant.provider', 'none');

    $this->putJson('/api/messaging/whatsapp/rules', ['channel' => 'both', 'consent' => 'unless_revoked', 'assistant_enabled' => true, 'blocked_words' => 'receta, dosis'])
        ->assertOk()->assertJsonPath('rules.channel', 'both')->assertJsonPath('rules.assistant_enabled', true);

    expect(DB::table('audit_events')->where('tenant_id', $this->co->tenant->id)->where('entity_label', 'WhatsApp y asistente')->exists())->toBeTrue();
});

it('no deja ver ni responder conversaciones de otra empresa', function () {
    waConnect($this, $this->co);
    waInbound($this, $this->co, '51987654321', 'Hola')->assertOk();
    $conversation = DB::table('whatsapp_conversations')->where('tenant_id', $this->co->tenant->id)->value('id');
    $otra = $this->company();

    $this->as($otra->owner)->getJson("/api/whatsapp/conversations/{$conversation}")->assertNotFound();
    $this->as($otra->owner)->postJson("/api/whatsapp/conversations/{$conversation}/reply", ['body' => 'Hola'])->assertNotFound();
    $this->as($otra->owner)->getJson('/api/whatsapp/conversations')->assertOk()->assertJsonCount(0, 'conversations');
});
