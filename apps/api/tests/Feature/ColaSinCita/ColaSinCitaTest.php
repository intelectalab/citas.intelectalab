<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);
    $this->travelTo($this->workday($this->co->tenant, 1)->setTime(10, 0));
    $this->servicio = $this->servicePriced($this->co->tenant, 40, 'Corte rápido');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 30]);
    $this->ana = $this->professional($this->co, 'Ana Cola');

    $this->anotar = fn (array $datos = [], $user = null) => $this->as($user ?? $this->co->owner)->postJson('/api/walkins', $datos + [
        'branch_id' => $this->co->branch->id, 'service_id' => $this->servicio->id, 'first_name' => 'Llegó', 'last_name' => 'Sin Cita',
    ]);
    $this->turno = fn (string $id) => DB::table('walkin_entries')->where('id', $id)->first();
});

it('anota a quien llega con turno correlativo del día y crea su ficha si no existe', function () {
    $primero = ($this->anotar)(['phone' => '987 654 321'])->assertCreated()->json('data');
    $segundo = ($this->anotar)(['first_name' => 'Otra', 'phone' => '911222333'])->assertCreated()->json('data');

    expect(($this->turno)($primero['id'])->ticket)->toBe('T-001')
        ->and(($this->turno)($segundo['id'])->ticket)->toBe('T-002')
        ->and(($this->turno)($primero['id'])->status)->toBe('waiting')
        ->and(DB::table('customers')->where('tenant_id', $this->co->tenant->id)->where('phone', '987 654 321')->exists())->toBeTrue();
});

it('no anota dos veces a la misma persona en la cola del día', function () {
    $clienta = $this->customer($this->co->tenant);
    ($this->anotar)(['customer_id' => $clienta->id])->assertCreated();
    ($this->anotar)(['customer_id' => $clienta->id])->assertStatus(422)->assertJsonPath('code', 'DUPLICATE');

    ($this->anotar)(['first_name' => 'Mismo', 'phone' => '900111222'])->assertCreated();
    ($this->anotar)(['first_name' => 'Mismo', 'phone' => '900111222'])->assertStatus(422)->assertJsonPath('code', 'DUPLICATE');
});

it('calcula la posición y la espera: con una sola profesional el segundo espera al primero', function () {
    ($this->anotar)()->assertCreated();
    $this->travelTo(now()->addMinute());
    ($this->anotar)(['first_name' => 'Segunda'])->assertCreated();

    $turnos = collect($this->as($this->co->owner)->getJson('/api/walkins')->assertOk()->json('entries'))->values();

    expect($turnos->pluck('position')->all())->toBe([1, 2])
        ->and($turnos[0]['suggested_professional']['id'])->toBe($this->ana->id)
        ->and($turnos[1]['wait_minutes'] - $turnos[0]['wait_minutes'])->toBeGreaterThanOrEqual(30);
});

it('no deja pedir a una profesional que no atiende hoy en esa sede', function () {
    $sede2 = $this->branch($this->co);
    $lejos = $this->professional($this->co, 'Beto Lejos', 0, $sede2);

    ($this->anotar)(['professional_id' => $lejos->id])->assertStatus(422);
    ($this->anotar)(['professional_id' => $this->ana->id])->assertCreated();
});

it('atender crea la cita en curso y al terminarla el turno queda atendido', function () {
    $id = ($this->anotar)()->json('data.id');

    $respuesta = $this->as($this->co->owner)->postJson("/api/walkins/{$id}/serve", ['professional_id' => $this->ana->id])->assertOk();
    $cita = DB::table('appointments')->where('id', $respuesta->json('appointment.id'))->first();
    $turno = ($this->turno)($id);

    expect($turno->status)->toBe('serving')
        ->and($turno->appointment_id)->toBe($cita->id)
        ->and($turno->professional_id)->toBe($this->ana->id)
        ->and($cita->status)->toBe('in_progress');

    $this->as($this->co->owner)->postJson("/api/walkins/{$id}/serve", ['professional_id' => $this->ana->id])->assertStatus(422);

    $this->as($this->co->owner)->postJson("/api/appointments/{$cita->id}/status", ['status' => 'completed'])->assertOk();
    expect(($this->turno)($id)->status)->toBe('done');
});

it('quien se retira puede volver a la cola el mismo día, pero no un turno de ayer', function () {
    $id = ($this->anotar)()->json('data.id');

    $this->as($this->co->owner)->postJson("/api/walkins/{$id}/status", ['status' => 'left'])->assertOk();
    expect(($this->turno)($id)->status)->toBe('left');
    $this->as($this->co->owner)->postJson("/api/walkins/{$id}/serve", ['professional_id' => $this->ana->id])->assertStatus(422);
    $this->as($this->co->owner)->putJson("/api/walkins/{$id}", ['service_id' => $this->servicio->id])->assertStatus(422);

    $this->as($this->co->owner)->postJson("/api/walkins/{$id}/status", ['status' => 'waiting'])->assertOk();
    expect(($this->turno)($id)->status)->toBe('waiting');

    $this->as($this->co->owner)->postJson("/api/walkins/{$id}/status", ['status' => 'left'])->assertOk();
    $this->travelTo(now()->addDay());
    $this->as($this->co->owner)->postJson("/api/walkins/{$id}/status", ['status' => 'waiting'])->assertStatus(422);
});

it('el kiosko anota con el celular, rechaza repetidos y respeta la espera máxima', function () {
    $code = $this->co->tenant->code;

    $this->postJson("/api/kiosk/{$code}/queue", ['phone' => '955444333', 'name' => 'Kiosko Persona', 'service_id' => $this->servicio->id])->assertSuccessful();
    $this->postJson("/api/kiosk/{$code}/queue", ['phone' => '955444333', 'service_id' => $this->servicio->id])->assertStatus(409)->assertJsonPath('code', 'DUPLICATE');

    $this->setRules($this->co->tenant, ['walkin.max_wait' => 10]);
    $this->postJson("/api/kiosk/{$code}/queue", ['phone' => '955000111', 'name' => 'Muy Tarde', 'service_id' => $this->servicio->id])->assertStatus(409)->assertJsonPath('code', 'WAIT_TOO_LONG');

    expect(DB::table('walkin_entries')->where('tenant_id', $this->co->tenant->id)->where('source', 'kiosk')->count())->toBe(1);
});

it('el kiosko no funciona si la empresa lo apaga o no tiene el módulo', function () {
    $code = $this->co->tenant->code;

    $this->setRules($this->co->tenant, ['walkin.self_checkin' => false]);
    $this->postJson("/api/kiosk/{$code}/queue", ['phone' => '955444333', 'name' => 'X', 'service_id' => $this->servicio->id])->assertForbidden();

    $this->setRules($this->co->tenant, ['walkin.self_checkin' => true]);
    DB::table('tenant_settings')->where('tenant_id', $this->co->tenant->id)->update(['modules_off' => json_encode(['walkin'])]);
    $this->postJson("/api/kiosk/{$code}/queue", ['phone' => '955444333', 'name' => 'X', 'service_id' => $this->servicio->id])->assertForbidden();
    $this->as($this->co->owner)->getJson('/api/walkins')->assertForbidden();
});

it('el profesional solo ve los turnos sin preferencia o los suyos; recepción no anota en otra sede', function () {
    $beto = $this->professional($this->co, 'Beto Cola');
    $paraBeto = ($this->anotar)(['first_name' => 'Para', 'last_name' => 'Beto', 'professional_id' => $beto->id])->json('data.id');
    $libre = ($this->anotar)(['first_name' => 'Cualquiera'])->json('data.id');

    $vistos = collect($this->as($this->ana->user)->getJson('/api/walkins')->assertOk()->json('entries'))->pluck('id');
    expect($vistos)->toContain($libre)->not->toContain($paraBeto);

    $sede2 = $this->branch($this->co);
    $recep = $this->staff($this->co, 'reception');
    ($this->anotar)(['branch_id' => $sede2->id], $recep)->assertForbidden();
});

it('no se ven ni tocan turnos de otra empresa ni se usan sus clientas o sedes', function () {
    $otra = $this->company(['modules_off' => []]);
    $servicioAjeno = $this->servicePriced($otra->tenant, 40, 'Ajeno');
    $this->professional($otra, 'Pro Ajena');
    $ajeno = $this->as($otra->owner)->postJson('/api/walkins', ['branch_id' => $otra->branch->id, 'service_id' => $servicioAjeno->id, 'first_name' => 'Ajena'])->assertCreated()->json('data.id');
    $clientaAjena = $this->customer($otra->tenant, 'Clienta', 'Ajena');

    $this->as($this->co->owner)->postJson("/api/walkins/{$ajeno}/status", ['status' => 'left'])->assertNotFound();
    $this->as($this->co->owner)->postJson("/api/walkins/{$ajeno}/serve", ['professional_id' => $this->ana->id])->assertNotFound();
    $this->as($this->co->owner)->getJson("/api/walkins/{$ajeno}/options")->assertNotFound();
    ($this->anotar)(['customer_id' => $clientaAjena->id])->assertNotFound();
    ($this->anotar)(['branch_id' => $otra->branch->id])->assertNotFound();
    ($this->anotar)(['service_id' => $servicioAjeno->id])->assertStatus(422);

    expect(collect($this->as($this->co->owner)->getJson('/api/walkins')->json('entries'))->pluck('id'))->not->toContain($ajeno)
        ->and(($this->turno)($ajeno)->status)->toBe('waiting');
});
