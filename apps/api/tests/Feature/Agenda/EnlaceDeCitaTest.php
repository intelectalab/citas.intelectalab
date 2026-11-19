<?php

use App\Models\Appointment;
use App\Support\AppointmentLinks;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company();
    $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Manicure');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 60]);
    $this->ana = $this->professional($this->co, 'Ana Torres');
    $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Paredes');
    $this->cita = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant, '10:00'))->assertCreated()->json('id');
    DB::table('appointments')->where('id', $this->cita)->update(['status' => 'pending', 'confirmed_at' => null]);
    $this->token = AppointmentLinks::token(Appointment::find($this->cita));
});

it('el enlace muestra la cita sin iniciar sesión', function () {
    $this->getJson("/api/public/appointment/{$this->token}")
        ->assertOk()
        ->assertJsonPath('appointment.id', $this->cita)
        ->assertJsonPath('appointment.can_confirm', true)
        ->assertJsonPath('customer', 'Lucía');
});

it('un enlace alterado no abre nada', function () {
    $malo = substr($this->token, 0, -1) . (str_ends_with($this->token, 'a') ? 'b' : 'a');

    $this->getJson("/api/public/appointment/{$malo}")->assertNotFound();
    $this->getJson('/api/public/appointment/no-es-un-enlace')->assertNotFound();
});

it('confirma la cita desde el enlace', function () {
    $this->postJson("/api/public/appointment/{$this->token}/confirm")->assertOk()->assertJsonPath('status', 'confirmed');

    expect(DB::table('appointments')->where('id', $this->cita)->value('status'))->toBe('confirmed')
        ->and(DB::table('appointment_status_history')->where('appointment_id', $this->cita)->where('to_status', 'confirmed')->value('actor_customer_id'))->toBe($this->clienta->id);
});

it('cancela la cita desde el enlace con las mismas reglas del portal', function () {
    $this->postJson("/api/public/appointment/{$this->token}/cancel", ['reason' => 'Viaje'])->assertOk()->assertJsonPath('status', 'cancelled');

    expect(DB::table('appointments')->where('id', $this->cita)->value('cancelled_by'))->toBe('customer');
});

it('no cancela si la empresa no deja cancelar en línea', function () {
    $this->setRules($this->co->tenant, ['cancel.client_allowed' => false]);

    $this->postJson("/api/public/appointment/{$this->token}/cancel")->assertStatus(403);
});

it('el correo de confirmación trae el enlace', function () {
    $vars = \App\Support\Messaging::appointmentVars($this->co->tenant, Appointment::find($this->cita));

    expect($vars['Enlace'])->toEndWith('/cita/' . $this->token);
});
