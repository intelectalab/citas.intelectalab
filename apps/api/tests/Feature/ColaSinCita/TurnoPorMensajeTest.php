<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);
    $this->travelTo($this->workday($this->co->tenant, 1)->setTime(10, 0));
    $this->servicio = $this->servicePriced($this->co->tenant, 40, 'Corte rápido');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 30]);
    $this->professional($this->co, 'Ana Cola');

    DB::table('tenant_integrations')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'kind' => 'email', 'provider' => 'simulated', 'mode' => 'simulated',
        'settings' => json_encode(['from_address' => 'citas@empresa.test', 'from_name' => 'Empresa']), 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->encender = fn () => DB::table('message_settings')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'kind' => 'walkin_ticket', 'enabled' => true,
        'options' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->mensajes = fn () => DB::table('messages')->where('tenant_id', $this->co->tenant->id)->where('kind', 'walkin_ticket');
});

it('viene apagado: anotar en la cola no envía nada', function () {
    $clienta = $this->customer($this->co->tenant);

    $this->as($this->co->owner)->postJson('/api/walkins', ['branch_id' => $this->co->branch->id, 'service_id' => $this->servicio->id, 'customer_id' => $clienta->id])->assertCreated();

    expect(($this->mensajes)()->count())->toBe(0);
});

it('encendido: al anotar desde recepción le llega su turno con la espera', function () {
    ($this->encender)();
    $clienta = $this->customer($this->co->tenant, 'Rosa', 'Turno');

    $this->as($this->co->owner)->postJson('/api/walkins', ['branch_id' => $this->co->branch->id, 'service_id' => $this->servicio->id, 'customer_id' => $clienta->id])->assertCreated();

    $mensaje = ($this->mensajes)()->first();

    expect($mensaje)->not->toBeNull()
        ->and($mensaje->to_address)->toBe($clienta->email)
        ->and($mensaje->subject)->toContain('T-001')
        ->and($mensaje->body)->toContain('Corte rápido');
});

it('encendido: el kiosko también lo envía y avisa en pantalla si salió', function () {
    ($this->encender)();
    $clienta = $this->customer($this->co->tenant, 'Kiosko', 'Con Correo');
    DB::table('customers')->where('id', $clienta->id)->update(['phone' => '+51 955444333']);

    $respuesta = $this->postJson("/api/kiosk/{$this->co->tenant->code}/queue", ['phone' => '955444333', 'service_id' => $this->servicio->id])->assertCreated();

    expect($respuesta->json('message_sent'))->toBeTrue()
        ->and(($this->mensajes)()->count())->toBe(1);
});
