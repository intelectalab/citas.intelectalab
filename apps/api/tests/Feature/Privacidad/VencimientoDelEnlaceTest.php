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
    $this->token = AppointmentLinks::token(Appointment::find($this->cita));
    $this->fin = Appointment::find($this->cita)->ends_at;
});

it('el enlace dice hasta cuándo sirve', function () {
    $this->getJson("/api/public/appointment/{$this->token}")
        ->assertOk()
        ->assertJsonPath('link_expires_at', $this->fin->copy()->addDays(AppointmentLinks::DAYS_AFTER)->toIso8601String());
});

it('sigue sirviendo poco después de la cita', function () {
    $this->travelTo($this->fin->copy()->addDay());

    $this->getJson("/api/public/appointment/{$this->token}")->assertOk();
});

it('vence unos días después de la cita y lo explica', function () {
    $this->travelTo($this->fin->copy()->addDays(AppointmentLinks::DAYS_AFTER)->addMinute());

    $this->getJson("/api/public/appointment/{$this->token}")
        ->assertStatus(410)
        ->assertJsonPath('code', 'LINK_EXPIRED')
        ->assertJsonPath('reason', 'past')
        ->assertJsonPath('company.name', $this->co->tenant->name)
        ->assertJson(['message' => 'Este enlace venció porque la cita ya pasó. Para ver tus citas anteriores, entra al portal.']);

    $this->postJson("/api/public/appointment/{$this->token}/confirm")->assertStatus(410);
    $this->postJson("/api/public/appointment/{$this->token}/cancel")->assertStatus(410);
});

it('deja de servir cuando la cita se cancela', function () {
    $this->postJson("/api/public/appointment/{$this->token}/cancel", ['reason' => 'Viaje'])->assertOk()->assertJsonPath('status', 'cancelled');

    $this->getJson("/api/public/appointment/{$this->token}")
        ->assertStatus(410)
        ->assertJsonPath('reason', 'cancelled');

    $this->postJson("/api/public/appointment/{$this->token}/confirm")->assertStatus(410);
});

it('un enlace alterado sigue sin abrir nada', function () {
    $malo = substr($this->token, 0, -1) . (str_ends_with($this->token, 'a') ? 'b' : 'a');

    $this->getJson("/api/public/appointment/{$malo}")->assertNotFound();
});

it('si la cita se reprograma, el plazo se cuenta desde la nueva fecha', function () {
    $nueva = $this->fin->copy()->addDays(10);
    DB::table('appointments')->where('id', $this->cita)->update(['starts_at' => $nueva->copy()->subHour()->utc(), 'ends_at' => $nueva->copy()->utc()]);

    $this->travelTo($this->fin->copy()->addDays(AppointmentLinks::DAYS_AFTER + 1));

    $this->getJson("/api/public/appointment/{$this->token}")->assertOk();
});
