<?php

use App\Http\Controllers\Api\MessageController;
use App\Models\User;
use App\Support\Assistant;
use App\Support\Assistant\FakeProvider;
use App\Support\Assistant\NoneProvider;
use App\Support\CardExpiry;
use App\Support\Messaging;
use App\Support\Subscriptions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function ilcConnect(object $test, object $co, array $extra = []): void
{
    \Tests\TestCase::forgetCaches();
    $co->owner->unsetRelations();
    \Laravel\Sanctum\Sanctum::actingAs($co->owner, ['*']);
    $test->putJson('/api/messaging/whatsapp', $extra + [
        'provider' => 'cloud', 'phone_number_id' => '1234567890', 'business_account_id' => '555',
        'token' => 'EAA-token-de-prueba', 'app_secret' => 'secreto-ilc', 'verify_token' => 'verificar-ilc',
    ])->assertOk();
}

function ilcHook(object $test, object $co, array $change): \Illuminate\Testing\TestResponse
{
    $payload = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [$change]]]]);

    return $test->call('POST', "/api/webhooks/whatsapp/{$co->tenant->id}", [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $payload, 'secreto-ilc'),
    ], $payload);
}

function ilcAdmin(): User
{
    return User::forceCreate([
        'id' => (string) Str::uuid(),
        'first_name' => 'Admin', 'last_name' => 'Plataforma', 'email' => 'ilc.' . Str::lower(Str::random(6)) . '@plataforma.test',
        'password_hash' => bcrypt('clave-de-prueba-123'), 'status' => 'active', 'tenant_id' => null,
    ]);
}

function ilcPlatformCulqi(): void
{
    DB::table('platform_integrations')->insert([
        'id' => (string) Str::uuid(), 'kind' => 'payments', 'provider' => 'culqi', 'mode' => 'sandbox',
        'credentials' => Crypt::encryptString(json_encode(['public_key' => 'pk_test_plataforma', 'secret_key' => 'sk_test_plataforma'])),
        'settings' => json_encode(['currency' => 'PEN']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    \Tests\TestCase::forgetCaches();
}

afterEach(function () {
    Assistant::$provider = null;
    FakeProvider::$answers = [];
    FakeProvider::$received = [];
});

describe('plantillas de WhatsApp aprobadas por Meta', function () {
    beforeEach(function () {
        config(['services.whatsapp.simulate' => false]);
        $this->co = $this->company();
        $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Manicure');
        DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 60]);
        $this->ana = $this->professional($this->co, 'Ana Torres');
        $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Paredes');
        DB::table('customers')->where('id', $this->clienta->id)->update(['phone' => '987654321']);
        $this->clienta->refresh();
        $this->meta = [
            ['id' => '11', 'name' => 'cita_agendada', 'language' => 'es', 'status' => 'PENDING', 'category' => 'UTILITY', 'components' => [['type' => 'BODY', 'text' => 'Hola {{1}}, tu cita de {{2}} quedó para el {{3}}.']]],
            ['id' => '12', 'name' => 'promo_rechazada', 'language' => 'es', 'status' => 'REJECTED', 'rejected_reason' => 'INVALID_FORMAT', 'category' => 'MARKETING', 'components' => [['type' => 'BODY', 'text' => 'Oferta']]],
        ];
        $this->posts = [];
        $test = $this;
        Http::fake(['https://graph.facebook.com/*' => function (HttpRequest $request) use ($test) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/message_templates')) {
                return Http::response(['data' => $test->meta]);
            }

            if ($request->method() === 'POST' && str_contains($request->url(), '/message_templates')) {
                $test->posts[] = $request->data();

                return Http::response(['id' => '99', 'status' => 'PENDING', 'category' => 'UTILITY']);
            }

            $test->posts[] = $request->data();

            return Http::response(['messages' => [['id' => 'wamid.OUT' . count($test->posts)]]]);
        }]);
    });

    it('trae de Meta todas las plantillas con su estado y deja elegir solo las aprobadas', function () {
        ilcConnect($this, $this->co);
        $res = $this->getJson('/api/messaging/whatsapp/templates')->assertOk()->json();

        expect($res['ok'])->toBeTrue()
            ->and($res['templates'])->toBe([])
            ->and(collect($res['catalog'])->pluck('status_label', 'name')->all())->toBe(['cita_agendada' => 'En revisión', 'promo_rechazada' => 'Rechazada'])
            ->and(collect($res['catalog'])->firstWhere('name', 'promo_rechazada')['reason'])->toBe('INVALID_FORMAT')
            ->and(DB::table('whatsapp_templates')->where('tenant_id', $this->co->tenant->id)->count())->toBe(2);

        $this->meta[0]['status'] = 'APPROVED';
        $this->meta = [$this->meta[0]];
        $res = $this->getJson('/api/messaging/whatsapp/templates')->assertOk()->json();

        expect(collect($res['templates'])->pluck('name')->all())->toBe(['cita_agendada'])
            ->and($res['templates'][0]['params'])->toBe(3)
            ->and(DB::table('whatsapp_templates')->where('name', 'promo_rechazada')->value('status'))->toBe('DELETED')
            ->and($this->getJson('/api/messaging/whatsapp')->json('meta_templates.verifiable'))->toBeTrue();
    });

    it('envía una plantilla nueva a revisión con sus ejemplos y revisa cómo está escrita', function () {
        ilcConnect($this, $this->co);

        $this->postJson('/api/messaging/whatsapp/templates', ['name' => 'Recordatorio Cita', 'category' => 'UTILITY', 'body' => 'Hola {{1}}, te esperamos.'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/messaging/whatsapp/templates', ['name' => 'recordatorio', 'category' => 'UTILITY', 'body' => 'Hola {{1}}, tu cita es el {{3}}.', 'examples' => ['Lucía', 'x', 'y']])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->postJson('/api/messaging/whatsapp/templates', ['name' => 'recordatorio', 'category' => 'UTILITY', 'body' => 'Hola {{1}}, tu cita es el {{2}} a tiempo.', 'examples' => ['Lucía']])->assertStatus(422)->assertJsonValidationErrors('examples');

        $res = $this->postJson('/api/messaging/whatsapp/templates', ['name' => 'recordatorio', 'language' => 'es', 'category' => 'UTILITY', 'body' => 'Hola {{1}}, tu cita es el {{2}}. Te esperamos.', 'examples' => ['Lucía', 'lunes 10:00']])->assertOk();

        expect($res->json('template.status'))->toBe('PENDING')
            ->and($this->posts[0]['name'])->toBe('recordatorio')
            ->and($this->posts[0]['category'])->toBe('UTILITY')
            ->and($this->posts[0]['components'][0]['example']['body_text'][0])->toBe(['Lucía', 'lunes 10:00'])
            ->and(DB::table('integration_logs')->where('tenant_id', $this->co->tenant->id)->where('action', 'templates')->where('ok', true)->exists())->toBeTrue();
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/555/message_templates'));

        $this->postJson('/api/messaging/whatsapp/templates', ['name' => 'recordatorio', 'language' => 'es', 'category' => 'UTILITY', 'body' => 'Hola {{1}} otra vez.', 'examples' => ['Lucía']])->assertStatus(422)->assertJsonValidationErrors('name');
    });

    it('el aviso de Meta cambia el estado de la plantilla', function () {
        ilcConnect($this, $this->co);
        $this->getJson('/api/messaging/whatsapp/templates')->assertOk();

        ilcHook($this, $this->co, ['field' => 'message_template_status_update', 'value' => ['event' => 'APPROVED', 'message_template_id' => 11, 'message_template_name' => 'cita_agendada', 'message_template_language' => 'es', 'reason' => 'NONE']])->assertOk();

        expect(DB::table('whatsapp_templates')->where('name', 'cita_agendada')->value('status'))->toBe('APPROVED');
    });

    it('fuera de las 24 horas una plantilla en revisión no se usa: el aviso sale por correo; aprobada, sale por WhatsApp', function () {
        ilcConnect($this, $this->co, ['templates' => ['booking_confirmation' => ['name' => 'cita_agendada', 'language' => 'es']]]);
        $this->setRules($this->co->tenant, ['whatsapp.channel' => 'whatsapp']);
        MessageController::saveConsents($this->co->tenant->id, $this->clienta->id, ['whatsapp' => true], 'staff');

        $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant))->assertCreated();
        Messaging::process($this->co->tenant->id);

        expect(DB::table('messages')->where('tenant_id', $this->co->tenant->id)->where('kind', 'booking_confirmation')->pluck('channel')->all())->toBe(['email'])
            ->and(collect($this->posts)->where('type', 'template'))->toHaveCount(0);

        DB::table('whatsapp_templates')->where('name', 'cita_agendada')->update(['status' => 'APPROVED']);
        $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant, '12:00'))->assertCreated();
        Messaging::process($this->co->tenant->id);

        $wa = DB::table('messages')->where('tenant_id', $this->co->tenant->id)->where('kind', 'booking_confirmation')->where('channel', 'whatsapp')->first();
        expect($wa->status)->toBe('sent')
            ->and(collect($this->posts)->firstWhere('type', 'template')['template']['name'])->toBe('cita_agendada');
    });

    it('si Meta pausa la plantilla, un aviso ya encolado con ella no se envía', function () {
        ilcConnect($this, $this->co, ['templates' => ['booking_confirmation' => ['name' => 'cita_agendada', 'language' => 'es']]]);
        $this->meta[0]['status'] = 'APPROVED';
        $this->getJson('/api/messaging/whatsapp/templates')->assertOk();
        $this->setRules($this->co->tenant, ['whatsapp.channel' => 'whatsapp']);
        MessageController::saveConsents($this->co->tenant->id, $this->clienta->id, ['whatsapp' => true], 'staff');
        DB::table('messages')->delete();

        DB::table('whatsapp_templates')->where('name', 'cita_agendada')->update(['status' => 'PAUSED']);
        $this->meta[0]['status'] = 'PAUSED';
        \App\Support\WhatsApp::store($this->co->tenant, ['customer' => $this->clienta, 'kind' => 'booking_confirmation', 'subject' => 'Cita', 'body' => 'Hola', 'dedupe_key' => null, 'user' => null, 'vars' => [], 'template' => ['name' => 'cita_agendada', 'language' => 'es']]);
        Messaging::process($this->co->tenant->id);

        $wa = DB::table('messages')->where('tenant_id', $this->co->tenant->id)->where('kind', 'booking_confirmation')->where('channel', 'whatsapp')->first();
        expect($wa->status)->toBe('not_sent')
            ->and($wa->error)->toContain('no está aprobada por Meta')
            ->and(collect($this->posts)->where('type', 'template'))->toHaveCount(0);
    });

    it('sin el identificador de la cuenta no se puede comprobar: se manda y Meta decide', function () {
        ilcConnect($this, $this->co, ['business_account_id' => null, 'templates' => ['booking_confirmation' => ['name' => 'cita_agendada', 'language' => 'es']]]);
        $this->setRules($this->co->tenant, ['whatsapp.channel' => 'whatsapp']);
        MessageController::saveConsents($this->co->tenant->id, $this->clienta->id, ['whatsapp' => true], 'staff');

        $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant))->assertCreated();
        Messaging::process($this->co->tenant->id);

        expect(collect($this->posts)->firstWhere('type', 'template')['template']['name'])->toBe('cita_agendada')
            ->and($this->getJson('/api/messaging/whatsapp')->json('meta_templates.verifiable'))->toBeFalse();
    });

    it('con 360dialog también lee y envía plantillas a revisión', function () {
        Http::fake([
            'https://waba-v2.360dialog.io/message_templates*' => Http::response(['waba_templates' => [['name' => 'aviso_pago', 'language' => 'es', 'status' => 'approved', 'category' => 'UTILITY', 'components' => [['type' => 'BODY', 'text' => 'Pagaste {{1}}.']]]]]),
            'https://waba-v2.360dialog.io/v1/configs/templates' => Http::response(['id' => 'd360-1', 'status' => 'submitted']),
        ]);
        ilcConnect($this, $this->co, ['provider' => '360dialog', 'token' => 'D360-clave', 'phone_number_id' => null, 'business_account_id' => null]);

        $res = $this->getJson('/api/messaging/whatsapp/templates')->assertOk()->json();
        expect(collect($res['templates'])->pluck('name')->all())->toBe(['aviso_pago']);

        $this->postJson('/api/messaging/whatsapp/templates', ['name' => 'nuevo_aviso', 'category' => 'UTILITY', 'body' => 'Hola, tu pedido está listo.'])->assertOk()->assertJsonPath('template.status', 'PENDING');
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === 'https://waba-v2.360dialog.io/v1/configs/templates' && $r->hasHeader('D360-API-KEY', 'D360-clave'));
    });
});

describe('recepcionista con IA: simulador, pasar a una persona y tablero', function () {
    beforeEach(function () {
        $this->co = $this->company();
        $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Manicure');
        DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 60, 'online_booking' => true, 'requires_deposit' => false]);
        $this->ana = $this->professional($this->co, 'Ana Torres');
        $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Paredes');
        DB::table('customers')->where('id', $this->clienta->id)->update(['phone' => '987654321']);
        $this->dia = $this->workday($this->co->tenant)->format('Y-m-d');
        $this->as($this->co->owner)->putJson('/api/messaging/whatsapp', ['provider' => 'simulated'])->assertOk();
        $this->setRules($this->co->tenant, ['assistant.enabled' => true]);

        $this->inbound = function (string $text) {
            $payload = json_encode(['entry' => [['changes' => [['value' => [
                'contacts' => [['wa_id' => '51987654321', 'profile' => ['name' => 'Lucía']]],
                'messages' => [['from' => '51987654321', 'id' => 'wamid.' . Str::random(10), 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]]],
            ]]]]]]);

            return $this->call('POST', "/api/webhooks/whatsapp/{$this->co->tenant->id}", [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload)->assertOk();
        };
    });

    it('el simulador responde sin guardar nada ni tocar WhatsApp', function () {
        $this->as($this->co->owner);
        Assistant::$provider = new FakeProvider();
        FakeProvider::$answers = [['action' => 'reply', 'text' => 'Hola Lucía, el manicure cuesta S/ 80.']];

        $res = $this->postJson('/api/assistant/simulate', [
            'customer_id' => $this->clienta->id,
            'history'     => [['from' => 'customer', 'text' => 'Hola'], ['from' => 'assistant', 'text' => '¡Hola! ¿En qué te ayudo?']],
            'text'        => '¿Cuánto cuesta el manicure?',
        ])->assertOk();

        expect($res->json('action'))->toBe('reply')
            ->and($res->json('reply'))->toBe('Hola Lucía, el manicure cuesta S/ 80.')
            ->and(FakeProvider::$received[0]['history'])->toHaveCount(3)
            ->and(FakeProvider::$received[0]['customer']['name'])->toBe('Lucía Paredes')
            ->and(DB::table('whatsapp_messages')->count())->toBe(0)
            ->and(DB::table('assistant_logs')->count())->toBe(0);
    });

    it('en el simulador Claude «reserva» con horas reales, pero no se crea la cita', function () {
        DB::table('tenant_integrations')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'kind' => 'ai', 'provider' => 'anthropic', 'mode' => 'production', 'status' => 'active',
            'credentials' => Crypt::encryptString(json_encode(['api_key' => 'sk-ant-prueba'])), 'settings' => json_encode(['model' => null]),
        ]);
        $test = $this;
        $calls = [];
        $reply = fn (array $content, string $stop = 'end_turn') => Http::response(['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'content' => $content, 'stop_reason' => $stop]);
        Http::fake(['https://api.anthropic.com/v1/messages' => function (HttpRequest $request) use ($test, &$calls, $reply) {
            $calls[] = $request->data();

            return count($calls) === 1
                ? $reply([['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'reservar_cita', 'input' => ['service_id' => $test->servicio->id, 'branch_id' => $test->co->branch->id, 'starts_at' => $test->dia . 'T10:00']]], 'tool_use')
                : $reply([['type' => 'text', 'text' => 'Listo, tu cita quedó para las 10:00.']]);
        }]);

        $before = DB::table('appointments')->where('tenant_id', $this->co->tenant->id)->count();
        $res = $this->as($this->co->owner)->postJson('/api/assistant/simulate', ['customer_id' => $this->clienta->id, 'text' => 'Sí, resérvame a las 10'])->assertOk();
        $tool = json_decode($calls[1]['messages'][array_key_last($calls[1]['messages'])]['content'][0]['content'], true);

        expect($res->json('reply'))->toBe('Listo, tu cita quedó para las 10:00.')
            ->and($res->json('done.0.label'))->toBe('Reservaría la cita')
            ->and($tool['ok'])->toBeTrue()->and($tool['simulado'])->toBeTrue()
            ->and(DB::table('appointments')->where('tenant_id', $this->co->tenant->id)->count())->toBe($before);
    });

    it('el simulador bloquea los temas de salud sin llamar al proveedor y avisa si falta la clave', function () {
        Assistant::$provider = new FakeProvider();
        $this->as($this->co->owner)->postJson('/api/assistant/simulate', ['text' => 'Tengo fiebre, ¿qué tomo?'])->assertOk()->assertJsonPath('action', 'blocked');
        expect(FakeProvider::$received)->toBe([]);

        Assistant::$provider = new NoneProvider();
        $this->as($this->co->owner)->postJson('/api/assistant/simulate', ['text' => 'Hola'])->assertOk()->assertJsonPath('action', 'none')
            ->assertJsonPath('reason', fn ($r) => str_contains($r, 'clave de inteligencia artificial'));
    });

    it('si la conversación no se resuelve en los minutos de la regla, pasa a una persona con un resumen', function () {
        $this->setRules($this->co->tenant, ['assistant.enabled' => true, 'assistant.handoff_minutes' => 10]);
        Assistant::$provider = new FakeProvider();
        FakeProvider::$answers = [['action' => 'reply', 'text' => '¿Qué servicio quieres?'], ['action' => 'reply', 'text' => '¿Y para qué día?']];

        ($this->inbound)('Hola, quiero una cita');
        $this->travel(6)->minutes();
        ($this->inbound)('Manicure');
        $this->travel(6)->minutes();
        ($this->inbound)('No sé, el jueves o el viernes');

        $conversation = DB::table('whatsapp_conversations')->where('tenant_id', $this->co->tenant->id)->first();
        expect(FakeProvider::$received)->toHaveCount(2)
            ->and($conversation->status)->toBe('needs_human')
            ->and($conversation->handoff_reason)->toContain('10 minutos')
            ->and($conversation->handoff_summary)->toContain('«Manicure»')
            ->and(DB::table('whatsapp_messages')->where('by_assistant', true)->orderByDesc('created_at')->value('body'))->toBe(Assistant::HANDOFF_TEXT);

        $this->getJson("/api/whatsapp/conversations/{$conversation->id}")->assertOk()->assertJsonPath('conversation.handoff_summary', $conversation->handoff_summary);
    });

    it('si nadie le responde al cliente en esos minutos, la revisión automática la pasa a una persona', function () {
        $this->setRules($this->co->tenant, ['assistant.enabled' => true, 'assistant.handoff_minutes' => 5]);
        Assistant::$provider = new NoneProvider();

        ($this->inbound)('Hola, ¿hay alguien?');
        $this->artisan('integrations:watch')->assertSuccessful();
        expect(DB::table('whatsapp_conversations')->value('status'))->toBe('open');

        $this->travel(6)->minutes();
        $this->artisan('integrations:watch')->assertSuccessful();

        $conversation = DB::table('whatsapp_conversations')->first();
        expect($conversation->status)->toBe('needs_human')
            ->and($conversation->handoff_reason)->toContain('5 minutos')
            ->and(DB::table('user_notifications')->where('type', 'whatsapp_handoff')->exists())->toBeTrue();
    });

    it('la regla de minutos se guarda con sus límites', function () {
        $rules = $this->as($this->co->owner)->getJson('/api/messaging/whatsapp')->json('rules');
        expect($rules['assistant_handoff_minutes'])->toBe(10);

        $this->putJson('/api/messaging/whatsapp/rules', ['assistant_handoff_minutes' => 0] + $rules)->assertStatus(422);
        $this->putJson('/api/messaging/whatsapp/rules', ['assistant_handoff_minutes' => 25] + $rules)->assertOk()->assertJsonPath('rules.assistant_handoff_minutes', 25);
    });

    it('el tablero cuenta lo que hizo y lista cada conversación con su resultado', function () {
        Assistant::$provider = new FakeProvider();
        FakeProvider::$answers = [['action' => 'reply', 'text' => 'Abrimos de 9 a 7.'], ['action' => 'handoff', 'reason' => 'Pide hablar con una persona']];
        ($this->inbound)('¿A qué hora abren?');
        $first = DB::table('whatsapp_conversations')->first();
        $log = DB::table('assistant_logs')->first();
        DB::table('assistant_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'conversation_id' => $first->id, 'provider' => 'fake', 'action' => 'booking', 'reason' => 'Cita C-1', 'created_at' => now()]);

        $res = $this->as($this->co->owner)->getJson('/api/assistant/board')->assertOk();
        $kpis = collect($res->json('kpis'))->pluck('value', 'key');

        expect($kpis['conversations'])->toBe(1)->and($kpis['booked'])->toBe(1)
            ->and($res->json('conversations.0.outcome'))->toBe('booked')
            ->and($res->json('conversations.0.name'))->toBe('Lucía Paredes')
            ->and($res->json('handoff_minutes'))->toBe(10)
            ->and($log->action)->toBe('reply');

        $otro = $this->company();
        $this->as($otro->owner)->getJson('/api/assistant/board')->assertOk()->assertJsonPath('conversations', []);
    });
});

describe('avisos de las pasarelas (webhooks)', function () {
    beforeEach(function () {
        $this->cargos = [];
        $this->caida = false;
        $test = $this;
        Http::fake(function (HttpRequest $request) use ($test) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($test->caida) {
                throw new RuntimeException('Culqi se cayó a la mitad');
            }

            if (str_contains($request->url(), 'culqi.com') && $request->method() === 'GET' && str_starts_with($path, '/v2/charges/')) {
                $id = substr($path, strlen('/v2/charges/'));

                return isset($test->cargos[$id]) ? Http::response($test->cargos[$id]) : Http::response(['object' => 'error'], 404);
            }

            return Http::response(['object' => 'error'], 404);
        });
        ilcPlatformCulqi();
        $this->co = $this->company();
        $sub = Subscriptions::latest($this->co->tenant->id);
        $sub->update(['ends_at' => now()->subDays(2)]);
        Subscriptions::forget($this->co->tenant->id);
        $this->pago = $this->as($this->co->owner)->postJson('/api/billing/pay')->assertOk()->json('culqi.payment_id');
        $this->cargos['chr_aviso'] = ['object' => 'charge', 'id' => 'chr_aviso', 'amount' => 100, 'outcome' => ['type' => 'venta_exitosa'], 'metadata' => ['plan_payment' => $this->pago]];
        $this->aviso = ['object' => 'event', 'id' => 'evt_123', 'type' => 'charge.creation.succeeded', 'data' => json_encode(['id' => 'chr_aviso'])];
    });

    it('guarda cada aviso con su estado y uno repetido no se procesa dos veces', function () {
        $this->postJson('/api/webhooks/payments/culqi/platform', $this->aviso)->assertOk()->assertJsonPath('status', 'processed');
        $this->postJson('/api/webhooks/payments/culqi/platform', $this->aviso)->assertOk()->assertJsonPath('status', 'duplicate');

        $row = DB::table('payment_webhooks')->first();
        expect(DB::table('payment_webhooks')->count())->toBe(1)
            ->and($row->status)->toBe('processed')
            ->and($row->event_key)->toBe('evt_123')
            ->and($row->reference)->toBe('chr_aviso')
            ->and($row->repeats)->toBe(1)
            ->and(DB::table('plan_payments')->where('id', $this->pago)->value('status'))->toBe('paid');

        $this->as(ilcAdmin())->getJson('/api/admin/payment-webhooks')->assertOk()->assertJsonPath('webhooks.0.status_label', 'Procesado');
        $this->as($this->co->owner)->getJson('/api/admin/payment-webhooks')->assertForbidden();
    });

    it('si el aviso falla queda para reintentar, y se reintenta desde la consola o solo', function () {
        $this->caida = true;
        $this->postJson('/api/webhooks/payments/culqi/platform', $this->aviso)->assertStatus(500);

        $row = DB::table('payment_webhooks')->first();
        expect($row->status)->toBe('failed')->and($row->attempts)->toBe(1)->and($row->next_retry_at)->not->toBeNull();

        $this->artisan('integrations:watch')->assertSuccessful();
        expect(DB::table('payment_webhooks')->value('attempts'))->toBe(1);

        $this->caida = false;
        $this->as(ilcAdmin())->postJson("/api/admin/payment-webhooks/{$row->id}/retry")->assertOk()->assertJsonPath('webhook.status', 'processed');
        expect(DB::table('plan_payments')->where('id', $this->pago)->value('status'))->toBe('paid');

        $this->as(ilcAdmin())->postJson("/api/admin/payment-webhooks/{$row->id}/retry")->assertStatus(422);
    });

    it('la revisión automática reintenta los avisos que fallaron cuando les toca', function () {
        $this->caida = true;
        $this->postJson('/api/webhooks/payments/culqi/platform', $this->aviso)->assertStatus(500);
        $this->caida = false;
        $this->travel(6)->minutes();

        $this->artisan('integrations:watch')->assertSuccessful();

        expect(DB::table('payment_webhooks')->value('status'))->toBe('processed')
            ->and(DB::table('plan_payments')->where('id', $this->pago)->value('status'))->toBe('paid');
    });

    it('los avisos de Mercado Pago de una empresa: firma mala rechazada y solo los ve esa empresa', function () {
        DB::table('tenant_integrations')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'kind' => 'payments', 'provider' => 'mercadopago', 'mode' => 'sandbox',
            'credentials' => Crypt::encryptString(json_encode(['public_key' => 'TEST-public', 'secret_key' => 'TEST-token-empresa', 'webhook_secret' => 'firma-mp'])),
            'settings' => json_encode(['currency' => 'PEN']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->call('POST', "/api/webhooks/payments/mercadopago/{$this->co->tenant->id}?type=order&data.id=ORD-1", [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => 'ts=1,v1=mala', 'HTTP_X_REQUEST_ID' => 'req-1',
        ], json_encode(['id' => 'n-1', 'type' => 'order', 'action' => 'order.processed', 'data' => ['id' => 'ORD-1']]))->assertStatus(400);

        $row = DB::table('payment_webhooks')->where('provider', 'mercadopago')->first();
        expect($row->status)->toBe('rejected')->and($row->tenant_id)->toBe($this->co->tenant->id)
            ->and(json_decode($row->headers, true)['x-request-id'])->toBe('req-1');

        $this->as($this->co->owner)->getJson('/api/integrations/payment-webhooks')->assertOk()->assertJsonPath('webhooks.0.provider_label', 'Mercado Pago');
        $otro = $this->company();
        $this->as($otro->owner)->getJson('/api/integrations/payment-webhooks')->assertOk()->assertJsonPath('webhooks', []);
        $this->as($otro->owner)->postJson("/api/integrations/payment-webhooks/{$row->id}/retry")->assertNotFound();
    });
});

describe('vencimiento de la tarjeta del plan', function () {
    beforeEach(function () {
        $test = $this;
        Http::fake(function (HttpRequest $request) use ($test) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $request->method() === 'POST' && $path === '/v2/customers' => Http::response(['object' => 'customer', 'id' => 'cus_1'], 201),
                $request->method() === 'POST' && $path === '/v2/cards'     => Http::response(['object' => 'card', 'id' => 'crd_1', 'source' => ['card_number' => '411111******1111', 'last_four' => '1111', 'expiration_month' => '8', 'expiration_year' => '2027', 'iin' => ['card_brand' => 'Visa']]], 201),
                default                                                     => Http::response(['data' => [], 'paging' => []]),
            };
        });
        ilcPlatformCulqi();
        $this->co = $this->company(['trial' => true]);
    });

    it('guarda el mes y año de vencimiento que manda la pasarela y lo muestra en el plan', function () {
        $res = $this->as($this->co->owner)->postJson('/api/billing/card')->assertOk();
        $this->postJson('/api/billing/culqi', ['payment_id' => $res->json('culqi.payment_id'), 'token' => 'tkn_test_bueno'])->assertOk();

        $sub = DB::table('subscriptions')->where('tenant_id', $this->co->tenant->id)->first();
        expect((int) $sub->card_exp_month)->toBe(8)->and((int) $sub->card_exp_year)->toBe(2027);

        $state = CardExpiry::state($sub);
        expect($state['label'])->toBe('08/27');
    });

    it('avisa una vez cuando está por vencer y otra cuando venció', function () {
        $sub = Subscriptions::latest($this->co->tenant->id);
        $soon = now('America/Lima');
        DB::table('subscriptions')->where('id', $sub->id)->update(['card_status' => 'authorized', 'card_brand' => 'visa', 'card_last4' => '4242', 'card_exp_month' => (int) $soon->month, 'card_exp_year' => (int) $soon->year]);

        $this->artisan('billing:card-expiry')->assertSuccessful();
        $this->artisan('billing:card-expiry')->assertSuccessful();

        $notices = DB::table('user_notifications')->where('tenant_id', $this->co->tenant->id)->where('type', 'subscription.card_expiry')->get();
        expect($notices->count())->toBeGreaterThanOrEqual(1)
            ->and($notices->pluck('title')->unique()->all())->toBe(['La tarjeta del plan está por vencer'])
            ->and($notices->first()->body)->toContain('terminada en 4242');

        $count = $notices->count();
        $this->travelTo(now()->addMonths(2));
        $this->artisan('billing:card-expiry')->assertSuccessful();

        expect(DB::table('user_notifications')->where('tenant_id', $this->co->tenant->id)->where('title', 'La tarjeta del plan venció')->count())->toBe($count)
            ->and(CardExpiry::state(DB::table('subscriptions')->where('id', $sub->id)->first())['state'])->toBe('expired');
    });

    it('sin el dato de la pasarela no inventa un vencimiento', function () {
        expect(CardExpiry::from(['source' => ['last_four' => '1111']]))->toBeNull()
            ->and(CardExpiry::from(['card' => ['expiration_month' => 3, 'expiration_year' => 29]]))->toBe(['month' => 3, 'year' => 2029])
            ->and(CardExpiry::from(['expiration_date' => '11/2030']))->toBe(['month' => 11, 'year' => 2030]);
    });
});
