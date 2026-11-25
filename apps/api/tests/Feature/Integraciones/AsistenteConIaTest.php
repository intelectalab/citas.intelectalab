<?php

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

const CLAUDE = 'https://api.anthropic.com/v1/messages';
const OPENAI = 'https://api.openai.com/v1/chat/completions';

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

    $this->ai = function (string $provider = 'anthropic', ?string $tenantId = null) {
        DB::table('tenant_integrations')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId ?? $this->co->tenant->id, 'kind' => 'ai', 'provider' => $provider, 'mode' => 'production', 'status' => 'active',
            'credentials' => Crypt::encryptString(json_encode(['api_key' => 'sk-ant-prueba'])), 'settings' => json_encode(['model' => null]),
        ]);
    };

    $this->inbound = function (string $text, string $from = '51987654321') {
        $payload = json_encode(['entry' => [['changes' => [['value' => [
            'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Lucía']]],
            'messages' => [['from' => $from, 'id' => 'wamid.' . Str::random(10), 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]]);

        return $this->call('POST', "/api/webhooks/whatsapp/{$this->co->tenant->id}", [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload)->assertOk();
    };

    $this->claude = fn (array $content, string $stop = 'end_turn') => Http::response(['id' => 'msg_' . Str::random(6), 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'content' => $content, 'stop_reason' => $stop]);
    $this->replies = fn () => DB::table('whatsapp_messages')->where('by_assistant', true)->orderBy('created_at')->pluck('body')->all();
});

describe('conversaciones de prueba con Claude', function () {
    it('pregunta horarios: consulta las horas libres reales y responde', function () {
        ($this->ai)();
        $test = $this;
        $calls = [];
        Http::fake([CLAUDE => function (HttpRequest $request) use ($test, &$calls) {
            $calls[] = $request->data();

            return count($calls) === 1
                ? ($test->claude)([['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'buscar_horarios', 'input' => ['service_id' => $test->servicio->id, 'branch_id' => $test->co->branch->id, 'date' => $test->dia]]], 'tool_use')
                : ($test->claude)([['type' => 'text', 'text' => 'Ese día tengo libre a las 09:00, 10:00 y 11:00. ¿Cuál prefieres?']]);
        }]);

        ($this->inbound)('Hola, ¿qué horas tienen para manicure?');

        expect(($this->replies)())->toBe(['Ese día tengo libre a las 09:00, 10:00 y 11:00. ¿Cuál prefieres?'])
            ->and($calls[0]['model'])->toBe('claude-sonnet-5-5')
            ->and(collect($calls[0]['tools'])->pluck('name')->all())->toContain('buscar_horarios', 'reservar_cita', 'pasar_a_una_persona')
            ->and($calls[0]['system'])->toContain('Lucía Paredes');

        $result = json_decode($calls[1]['messages'][2]['content'][0]['content'], true);
        expect($calls[1]['messages'][2]['content'][0]['tool_use_id'])->toBe('toolu_1')
            ->and($result['ok'])->toBeTrue()->and($result['horas'])->not->toBeEmpty()
            ->and(DB::table('assistant_logs')->value('action'))->toBe('reply');
        Http::assertSent(fn ($r) => $r->hasHeader('x-api-key', 'sk-ant-prueba') && $r->hasHeader('anthropic-version', '2023-06-01'));
    });

    it('reserva cuando el cliente confirma y deja la acción en la auditoría como hecha por el asistente', function () {
        ($this->ai)();
        $test = $this;
        $step = 0;
        Http::fake([CLAUDE => function () use ($test, &$step) {
            $step++;

            return $step === 1
                ? ($test->claude)([['type' => 'text', 'text' => 'Te reservo.'], ['type' => 'tool_use', 'id' => 'toolu_9', 'name' => 'reservar_cita', 'input' => [
                    'service_id' => $test->servicio->id, 'branch_id' => $test->co->branch->id, 'professional_id' => $test->ana->id, 'starts_at' => "{$test->dia}T10:00",
                ]]], 'tool_use')
                : ($test->claude)([['type' => 'text', 'text' => 'Listo, tu cita quedó reservada.']]);
        }]);

        ($this->inbound)('Sí, la de las 10 por favor');

        $cita = DB::table('appointments')->where('customer_id', $this->clienta->id)->first();
        expect($cita)->not->toBeNull()
            ->and(DB::table('assistant_logs')->where('action', 'booking')->value('reason'))->toContain($cita->code)
            ->and(DB::table('audit_events')->where('action', 'assistant.acted')->where('entity_id', $cita->id)->value('actor_name'))->toBe('Asistente de WhatsApp')
            ->and(($this->replies)())->toBe(['Listo, tu cita quedó reservada.']);
    });

    it('no reserva una hora ocupada: la herramienta devuelve el error y el asistente lo explica', function () {
        ($this->ai)();
        $otra = $this->customer($this->co->tenant, 'Otra', 'Clienta');
        $this->book($this->co, $otra, $this->servicio, $this->ana, "{$this->dia}T10:00")->assertCreated();
        $test = $this;
        $results = [];
        Http::fake([CLAUDE => function (HttpRequest $request) use ($test, &$results) {
            $messages = $request->data()['messages'];
            $last = end($messages);

            if (is_array($last['content']) && ($last['content'][0]['type'] ?? null) === 'tool_result') {
                $results[] = json_decode($last['content'][0]['content'], true);

                return ($test->claude)([['type' => 'text', 'text' => 'Esa hora ya no está libre, ¿te sirve otra?']]);
            }

            return ($test->claude)([['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'reservar_cita', 'input' => [
                'service_id' => $test->servicio->id, 'branch_id' => $test->co->branch->id, 'professional_id' => $test->ana->id, 'starts_at' => "{$test->dia}T10:00",
            ]]], 'tool_use');
        }]);

        ($this->inbound)('Quiero a las 10');

        expect($results[0]['ok'])->toBeFalse()
            ->and(DB::table('appointments')->where('customer_id', $this->clienta->id)->exists())->toBeFalse()
            ->and(($this->replies)())->toBe(['Esa hora ya no está libre, ¿te sirve otra?']);
    });

    it('con «solo informa» no le da al modelo las herramientas de reservar', function () {
        ($this->ai)();
        $this->setRules($this->co->tenant, ['assistant.enabled' => true, 'assistant.can_book' => false]);
        $test = $this;
        $tools = [];
        Http::fake([CLAUDE => function (HttpRequest $request) use ($test, &$tools) {
            $tools = collect($request->data()['tools'])->pluck('name')->all();

            return ($test->claude)([['type' => 'tool_use', 'id' => 'toolu_3', 'name' => 'cancelar_cita', 'input' => ['appointment_id' => (string) Str::uuid()]]], count($tools) ? 'tool_use' : 'end_turn');
        }]);

        ($this->inbound)('Cancela mi cita');

        expect($tools)->not->toContain('reservar_cita')->not->toContain('cancelar_cita')->toContain('ver_mis_citas')
            ->and(DB::table('audit_events')->where('action', 'assistant.acted')->exists())->toBeFalse();
    });

    it('un número desconocido no puede reservar y el modelo no puede llamar acciones fuera de las permitidas', function () {
        ($this->ai)();
        $test = $this;
        $seen = [];
        Http::fake([CLAUDE => function (HttpRequest $request) use ($test, &$seen) {
            $data = $request->data();
            $last = end($data['messages']);

            if (is_array($last['content'])) {
                $seen[] = json_decode($last['content'][0]['content'], true);

                return ($test->claude)([['type' => 'text', 'text' => 'Para reservar te paso con una persona.']]);
            }

            $seen[] = collect($data['tools'])->pluck('name')->all();

            return ($test->claude)([['type' => 'tool_use', 'id' => 'toolu_4', 'name' => 'reservar_cita', 'input' => []]], 'tool_use');
        }]);

        ($this->inbound)('Hola, quiero una cita', '51911222333');

        expect($seen[0])->not->toContain('reservar_cita')->not->toContain('ver_mis_citas')
            ->and($seen[1]['ok'])->toBeFalse()->and($seen[1]['error'])->toBe('Esa acción no está permitida.');
    });

    it('pasar a una persona deja la conversación esperando y el asistente ya no responde', function () {
        ($this->ai)();
        $test = $this;
        Http::fake([CLAUDE => Http::sequence()
            ->push(['content' => [['type' => 'tool_use', 'id' => 'toolu_5', 'name' => 'pasar_a_una_persona', 'input' => ['reason' => 'Quiere reclamar un cobro.']]], 'stop_reason' => 'tool_use'])
            ->push(['content' => [['type' => 'text', 'text' => 'Te paso con una persona del equipo.']], 'stop_reason' => 'end_turn'])]);

        ($this->inbound)('Me cobraron de más, quiero reclamar');
        $conversation = DB::table('whatsapp_conversations')->where('tenant_id', $this->co->tenant->id)->first();

        expect($conversation->status)->toBe('needs_human')->and($conversation->handoff_reason)->toBe('Quiere reclamar un cobro.')
            ->and(($this->replies)())->toBe(['Te paso con una persona del equipo.']);

        ($this->inbound)('¿Hola?');
        Http::assertSentCount(2);
    });

    it('una consulta de salud nunca llega al proveedor de IA', function () {
        ($this->ai)();
        Http::fake([CLAUDE => Http::response([], 500)]);

        ($this->inbound)('¿Qué dosis de ibuprofeno tomo para el dolor?');

        Http::assertNothingSent();
        expect(DB::table('assistant_logs')->value('action'))->toBe('blocked');
    });

    it('si Claude se niega, pasa a una persona', function () {
        ($this->ai)();
        Http::fake([CLAUDE => Http::response(['content' => [], 'stop_reason' => 'refusal'])]);

        ($this->inbound)('Hola');

        expect(DB::table('whatsapp_conversations')->value('status'))->toBe('needs_human');
    });

    it('si la clave es mala lo dice con palabras y pasa a una persona', function () {
        ($this->ai)();
        Http::fake([CLAUDE => Http::response(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], 401)]);

        ($this->inbound)('Hola');

        expect(DB::table('assistant_logs')->value('action'))->toBe('error')
            ->and(DB::table('integration_logs')->where('kind', 'ai')->value('message'))->toContain('no aceptó la clave');
    });

    it('al llegar al tope del plan no llama al proveedor', function () {
        ($this->ai)();
        $plan = $this->co->tenant->activeSubscription->plan;
        DB::table($plan->getTable())->where('id', $plan->id)->update(['limits' => json_encode(array_merge((array) $plan->limits, ['assistant_month' => 0]))]);
        \Tests\TestCase::forgetCaches();
        Http::fake([CLAUDE => Http::response([], 500)]);

        ($this->inbound)('Hola');

        Http::assertNothingSent();
        expect(DB::table('whatsapp_conversations')->value('handoff_reason'))->toContain('tope');
    });
});

describe('OpenAI como alternativa', function () {
    it('usa funciones y responde', function () {
        ($this->ai)('openai');
        $test = $this;
        Http::fake([OPENAI => Http::sequence()
            ->push(['model' => 'gpt-5-mini', 'choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'ver_servicios', 'arguments' => '{}']]]], 'finish_reason' => 'tool_calls']]])
            ->push(['model' => 'gpt-5-mini', 'choices' => [['message' => ['role' => 'assistant', 'content' => 'La manicure cuesta S/ 80.00.'], 'finish_reason' => 'stop']]])]);

        ($this->inbound)('¿Cuánto cuesta la manicure?');

        expect(($this->replies)())->toBe(['La manicure cuesta S/ 80.00.']);
        Http::assertSent(fn ($r) => $r->url() === OPENAI && collect($r['messages'])->contains(fn ($m) => ($m['role'] ?? null) === 'tool' && str_contains($m['content'], 'Manicure')));
    });
});

describe('pantalla del asistente', function () {
    it('guarda la clave de la empresa probándola antes y la muestra enmascarada', function () {
        Http::fake([CLAUDE => Http::sequence()
            ->push(['type' => 'error', 'error' => ['message' => 'invalid x-api-key']], 401)
            ->push(['model' => 'claude-sonnet-5-5', 'content' => [['type' => 'text', 'text' => 'OK']], 'stop_reason' => 'end_turn'])]);

        $this->as($this->co->owner)->putJson('/api/messaging/assistant', ['provider' => 'anthropic', 'api_key' => 'sk-ant-mala'])->assertStatus(422)->assertJsonValidationErrors('api_key');
        $res = $this->as($this->co->owner)->putJson('/api/messaging/assistant', ['provider' => 'anthropic', 'api_key' => 'sk-ant-buena-1234'])->assertOk()->json('ai');

        expect($res['own']['key_saved'])->toBe('••••••••1234')->and($res['own']['provider'])->toBe('anthropic')
            ->and(collect($res['log'])->pluck('ok')->all())->toBe([true, false]);
    });

    it('sin clave propia usa la de la plataforma', function () {
        DB::table('platform_integrations')->insert(['id' => (string) Str::uuid(), 'kind' => 'ai', 'provider' => 'anthropic', 'mode' => 'production', 'status' => 'active', 'credentials' => Crypt::encryptString(json_encode(['api_key' => 'sk-ant-plataforma'])), 'settings' => '{}']);
        Http::fake([CLAUDE => Http::response(['content' => [['type' => 'text', 'text' => 'Hola, ¿en qué te ayudo?']], 'stop_reason' => 'end_turn'])]);

        ($this->inbound)('Hola');

        Http::assertSent(fn ($r) => $r->hasHeader('x-api-key', 'sk-ant-plataforma'));
        expect($this->as($this->co->owner)->getJson('/api/messaging/whatsapp')->assertOk()->json('assistant.ai.platform.label'))->toBe('Claude (Anthropic)');
    });

    it('la recepción no puede cambiar la clave', function () {
        $this->as($this->staff($this->co, 'reception'))->putJson('/api/messaging/assistant', ['provider' => 'anthropic', 'api_key' => 'x'])->assertForbidden();
    });
});
