<?php

use App\Support\Surveys;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function correoParaEncuestas(string $tenantId): void
{
    DB::table('tenant_integrations')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'kind' => 'email', 'provider' => 'simulated', 'mode' => 'simulated',
        'settings' => json_encode(['from_address' => 'hola@empresa.test', 'from_name' => 'Empresa']), 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function aceptaMensajes(object $customer): void
{
    DB::table('customer_consents')->insert([
        'tenant_id' => $customer->tenant_id, 'customer_id' => $customer->id, 'channel' => 'email', 'granted' => true, 'granted_at' => now(),
    ]);
}

beforeEach(function () {
    $this->co = $this->company();
    correoParaEncuestas($this->co->tenant->id);
    $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Limpieza facial');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 60]);
    $this->ana = $this->professional($this->co, 'Ana Torres');
    $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Paredes');
    aceptaMensajes($this->clienta);

    $this->atender = function (?object $cliente = null, string $hora = '10:00') {
        $cita = $this->book($this->co, $cliente ?? $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant, $hora))->assertCreated()->json('id');

        foreach (['confirmed', 'checked_in', 'completed'] as $fase) {
            $this->as($this->co->owner)->postJson("/api/appointments/{$cita}/status", ['status' => $fase])->assertOk();
        }

        return $cita;
    };

    $this->encender = fn (array $extra = []) => $this->as($this->co->owner)->putJson('/api/surveys/settings', $extra + [
        'enabled' => true, 'delay_hours' => 2, 'google_min' => 9, 'publish_reviews' => true, 'valid_days' => 7,
        'google_url' => 'https://g.page/r/empresa-de-prueba/review',
    ])->assertOk();

    $this->encuesta = fn (string $cita) => DB::table('surveys')->where('appointment_id', $cita)->first();
});

it('viene apagada: al atender una cita no se programa ninguna encuesta', function () {
    ($this->atender)();

    expect(DB::table('surveys')->where('tenant_id', $this->co->tenant->id)->count())->toBe(0)
        ->and(Surveys::config($this->co->tenant->fresh())['enabled'])->toBeFalse();
});

it('al atender la cita se programa una sola encuesta y sale pasadas las horas de la regla', function () {
    ($this->encender)();
    $cita = ($this->atender)();
    $encuesta = ($this->encuesta)($cita);

    expect($encuesta->status)->toBe('scheduled')
        ->and(Carbon::parse($encuesta->send_at)->diffInMinutes(now()->addHours(2), true))->toBeLessThan(2);

    Surveys::sendDue($this->co->tenant->id);
    expect(($this->encuesta)($cita)->status)->toBe('scheduled');

    $this->travel(3)->hours();
    expect(Surveys::sendDue($this->co->tenant->id))->toBe(1);

    $enviada = ($this->encuesta)($cita);
    $mensaje = DB::table('messages')->where('id', $enviada->message_id)->first();

    expect($enviada->status)->toBe('sent')
        ->and($mensaje->kind)->toBe('survey')
        ->and($mensaje->body)->toContain('/encuesta/' . $enviada->token);

    expect(Surveys::sendDue($this->co->tenant->id))->toBe(0)
        ->and(DB::table('surveys')->where('appointment_id', $cita)->count())->toBe(1)
        ->and(DB::table('messages')->where('kind', 'survey')->where('tenant_id', $this->co->tenant->id)->count())->toBe(1);
});

it('no se envía a quien no aceptó recibir mensajes', function () {
    ($this->encender)(['delay_hours' => 0]);
    $sinPermiso = $this->customer($this->co->tenant, 'Pedro', 'Sin Permiso');
    $cita = ($this->atender)($sinPermiso);

    $encuesta = ($this->encuesta)($cita);

    expect($encuesta->status)->toBe('skipped')
        ->and($encuesta->skip_reason)->toContain('no aceptó')
        ->and(DB::table('messages')->where('kind', 'survey')->where('customer_id', $sinPermiso->id)->exists())->toBeFalse();
});

it('el enlace se abre sin iniciar sesión, se responde una vez y vence', function () {
    ($this->encender)(['delay_hours' => 0]);
    $encuesta = ($this->encuesta)(($this->atender)());

    $this->getJson("/api/public/surveys/{$encuesta->token}")->assertOk()
        ->assertJsonPath('state', 'open')
        ->assertJsonPath('service', 'Limpieza facial')
        ->assertJsonPath('professional', 'Ana Torres');

    $this->postJson("/api/public/surveys/{$encuesta->token}", ['score' => 10, 'comment' => 'Me encantó la atención', 'publish_consent' => true])
        ->assertOk()->assertJsonPath('state', 'answered')->assertJsonPath('google', true);

    $this->postJson("/api/public/surveys/{$encuesta->token}", ['score' => 2])->assertStatus(409);
    expect((int) DB::table('surveys')->where('id', $encuesta->id)->value('score'))->toBe(10);

    $this->getJson('/api/public/surveys/' . Str::random(40))->assertNotFound();

    $otra = $this->customer($this->co->tenant, 'Rosa', 'Tarde');
    aceptaMensajes($otra);
    $vencida = ($this->encuesta)(($this->atender)($otra, '12:00'));
    $this->travel(8)->days();

    $this->getJson("/api/public/surveys/{$vencida->token}")->assertOk()->assertJsonPath('state', 'expired');
    $this->postJson("/api/public/surveys/{$vencida->token}", ['score' => 8])->assertStatus(410);
});

it('a una nota baja no se le ofrece Google y avisa para recuperar al cliente', function () {
    ($this->encender)(['delay_hours' => 0]);
    $encuesta = ($this->encuesta)(($this->atender)());

    $this->postJson("/api/public/surveys/{$encuesta->token}", ['score' => 4, 'comment' => 'Esperé mucho'])
        ->assertOk()->assertJsonPath('google', false)->assertJsonPath('detractor', true);
    $this->postJson("/api/public/surveys/{$encuesta->token}/google")->assertNotFound();

    $fila = DB::table('surveys')->where('id', $encuesta->id)->first();
    expect($fila->follow_up)->toBe('pending')->and((bool) $fila->google_invited)->toBeFalse()
        ->and(DB::table('user_notifications')->where('user_id', $this->co->owner->id)->where('type', 'survey.detractor')->exists())->toBeTrue();
});

it('el NPS es promotores menos detractores y sale por profesional', function () {
    ($this->encender)(['delay_hours' => 0]);
    $notas = [10, 9, 8, 3];

    foreach ($notas as $i => $nota) {
        $cliente = $this->customer($this->co->tenant, "Cliente{$i}", 'Prueba');
        aceptaMensajes($cliente);
        $encuesta = ($this->encuesta)(($this->atender)($cliente, sprintf('%02d:00', 9 + $i)));
        $this->postJson("/api/public/surveys/{$encuesta->token}", ['score' => $nota])->assertOk();
    }

    $datos = $this->as($this->co->owner)->getJson('/api/surveys')->assertOk()->json();

    expect($datos['summary']['answered'])->toBe(4)
        ->and($datos['summary']['promoters'])->toBe(2)
        ->and($datos['summary']['detractors'])->toBe(1)
        ->and($datos['summary']['nps'])->toBe(25)
        ->and($datos['summary']['average'])->toEqual(7.5)
        ->and($datos['summary']['pending'])->toBe(1)
        ->and($datos['by_professional'][0]['name'])->toBe('Ana Torres')
        ->and($datos['by_professional'][0]['nps'])->toBe(25)
        ->and($datos['by_service'][0]['name'])->toBe('Limpieza facial');
});

it('un detractor queda pendiente hasta que alguien registra el contacto', function () {
    ($this->encender)(['delay_hours' => 0]);
    $encuesta = ($this->encuesta)(($this->atender)());
    $this->postJson("/api/public/surveys/{$encuesta->token}", ['score' => 2])->assertOk();

    $this->as($this->co->owner)->putJson("/api/surveys/{$encuesta->id}/recovery", ['follow_up' => 'contacted'])->assertStatus(422)->assertJsonValidationErrors('note');
    $this->as($this->co->owner)->putJson("/api/surveys/{$encuesta->id}/recovery", ['follow_up' => 'resolved', 'note' => 'La llamamos y le dimos una sesión de cortesía.'])
        ->assertOk()->assertJsonPath('survey.follow_up', 'resolved');

    expect($this->as($this->co->owner)->getJson('/api/surveys')->json('summary.pending'))->toBe(0)
        ->and(DB::table('audit_events')->where('entity_id', $encuesta->id)->where('action', 'survey.follow_up')->exists())->toBeTrue();
});

it('solo se publican reseñas con consentimiento; se pueden ocultar y responder pero no editar', function () {
    ($this->encender)(['delay_hours' => 0]);
    $con = ($this->encuesta)(($this->atender)());
    $this->postJson("/api/public/surveys/{$con->token}", ['score' => 10, 'comment' => 'Excelente, muy amable', 'publish_consent' => true])->assertOk();

    $otra = $this->customer($this->co->tenant, 'Marta', 'Reservada');
    aceptaMensajes($otra);
    $sin = ($this->encuesta)(($this->atender)($otra, '12:00'));
    $this->postJson("/api/public/surveys/{$sin->token}", ['score' => 9, 'comment' => 'Muy bien', 'publish_consent' => false])->assertOk();

    $this->as($this->co->owner)->putJson("/api/surveys/{$sin->id}/publish", ['published' => true])->assertStatus(422);
    $this->as($this->co->owner)->putJson("/api/surveys/{$con->id}/publish", ['published' => true])->assertOk()->assertJsonPath('survey.published', true);
    $this->as($this->co->owner)->putJson("/api/surveys/{$con->id}/reply", ['reply' => '¡Gracias, Lucía! Te esperamos.'])->assertOk();

    $slug = DB::table('tenants')->where('id', $this->co->tenant->id)->value('slug');
    $pagina = $this->getJson("/api/public/booking/{$slug}")->assertOk()->json('reviews');

    expect($pagina['count'])->toBe(2)
        ->and($pagina['average'])->toEqual(4.8)
        ->and($pagina['items'])->toHaveCount(1)
        ->and($pagina['items'][0]['name'])->toBe('Lucía P.')
        ->and($pagina['items'][0]['comment'])->toBe('Excelente, muy amable')
        ->and($pagina['items'][0]['reply'])->toBe('¡Gracias, Lucía! Te esperamos.');

    $this->as($this->co->owner)->putJson("/api/surveys/{$con->id}/publish", ['published' => false])->assertOk()->assertJsonPath('survey.hidden', true);
    expect($this->getJson("/api/public/booking/{$slug}")->json('reviews'))->toBeNull()
        ->and(DB::table('surveys')->where('id', $con->id)->value('comment'))->toBe('Excelente, muy amable');
});

it('el enlace de Google debe ser https y se registra quién lo abrió', function () {
    $this->as($this->co->owner)->putJson('/api/surveys/settings', [
        'enabled' => true, 'delay_hours' => 0, 'google_min' => 9, 'publish_reviews' => true, 'valid_days' => 7, 'google_url' => 'http://inseguro.test',
    ])->assertStatus(422)->assertJsonValidationErrors('google_url');

    ($this->encender)(['delay_hours' => 0]);
    $encuesta = ($this->encuesta)(($this->atender)());
    $this->postJson("/api/public/surveys/{$encuesta->token}", ['score' => 9])->assertOk()->assertJsonPath('google', true);

    $this->postJson("/api/public/surveys/{$encuesta->token}/google")->assertOk()->assertJsonPath('url', 'https://g.page/r/empresa-de-prueba/review');
    expect(DB::table('surveys')->where('id', $encuesta->id)->value('google_clicked_at'))->not->toBeNull()
        ->and($this->as($this->co->owner)->getJson('/api/surveys')->json('summary.google_clicked'))->toBe(1);
});

it('la recepción ve pero no gestiona, el profesional no entra y nadie ve encuestas de otra empresa', function () {
    ($this->encender)(['delay_hours' => 0]);
    $encuesta = ($this->encuesta)(($this->atender)());
    $this->postJson("/api/public/surveys/{$encuesta->token}", ['score' => 3])->assertOk();

    $recepcion = $this->staff($this->co, 'reception');
    $this->as($recepcion)->getJson('/api/surveys')->assertOk();
    $this->as($recepcion)->putJson("/api/surveys/{$encuesta->id}/recovery", ['follow_up' => 'contacted', 'note' => 'La llamé por teléfono.'])->assertForbidden();

    $gerente = $this->staff($this->co, 'manager');
    $this->as($gerente)->getJson('/api/surveys')->assertOk();
    $this->as($gerente)->putJson("/api/surveys/{$encuesta->id}/recovery", ['follow_up' => 'contacted', 'note' => 'Le escribimos por WhatsApp.'])->assertOk();

    $this->as($this->ana->user)->getJson('/api/surveys')->assertForbidden();

    $otra = $this->company();
    $this->as($otra->owner)->getJson("/api/surveys/{$encuesta->id}")->assertNotFound();
    expect($this->as($otra->owner)->getJson('/api/surveys')->json('summary.answered'))->toBe(0);
});

it('la encuesta enviada también se ve en el portal del cliente hasta que la responde', function () {
    ($this->encender)(['delay_hours' => 0]);
    $encuesta = ($this->encuesta)(($this->atender)());

    $this->as($this->clienta)->getJson('/api/portal/surveys')->assertOk()
        ->assertJsonPath('surveys.0.url', '/encuesta/' . $encuesta->token)
        ->assertJsonPath('surveys.0.service', 'Limpieza facial');

    $this->postJson("/api/public/surveys/{$encuesta->token}", ['score' => 8])->assertOk();

    expect($this->as($this->clienta)->getJson('/api/portal/surveys')->json('surveys'))->toBe([]);
});
