<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company();
    $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Manicure');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 60]);
    $this->ana = $this->professional($this->co, 'Ana Torres');
    $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Paredes');

    $this->citaEn = function (string $inicio) {
        return $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $inicio)->assertCreated()->json('id');
    };

    $this->pasada = function () {
        $cita = ($this->citaEn)($this->at($this->co->tenant, '10:00'));
        $this->travelTo(\Illuminate\Support\Carbon::parse(DB::table('appointments')->where('id', $cita)->value('starts_at'))->addHours(2));

        return $cita;
    };

    $this->penalidad = fn (string $cita) => DB::table('sale_items')->where('appointment_id', $cita)->where('item_type', 'penalty')->first();
});

it('por defecto no se cobra nada al marcar que no asistió', function () {
    $cita = ($this->pasada)();

    $this->as($this->co->owner)->postJson("/api/appointments/{$cita}/status", ['status' => 'no_show'])->assertOk()->assertJsonPath('penalty', null);

    expect(($this->penalidad)($cita))->toBeNull();
});

it('con penalidades encendidas, no asistir genera un cobro pendiente por el porcentaje', function () {
    $this->setRules($this->co->tenant, ['penalties.enabled' => true, 'noshow.fee_percent' => 50]);
    $cita = ($this->pasada)();

    $respuesta = $this->as($this->co->owner)->postJson("/api/appointments/{$cita}/status", ['status' => 'no_show'])->assertOk()->json('penalty');
    $venta = DB::table('sales')->where('id', $respuesta['sale_id'])->first();

    expect($respuesta['total'])->toEqual(40)
        ->and($venta->status)->toBe('pending')
        ->and((float) $venta->balance)->toBe(40.0)
        ->and($venta->customer_id)->toBe($this->clienta->id)
        ->and($venta->appointment_id)->toBeNull();
});

it('no duplica la penalidad', function () {
    $this->setRules($this->co->tenant, ['penalties.enabled' => true, 'noshow.fee_percent' => 50]);
    $cita = ($this->pasada)();
    $modelo = \App\Models\Appointment::find($cita);

    $this->as($this->co->owner)->postJson("/api/appointments/{$cita}/status", ['status' => 'no_show'])->assertOk();
    \App\Support\Penalties::apply($this->co->tenant->fresh(), $modelo->fresh(), \App\Support\Penalties::NO_SHOW, null);

    expect(DB::table('sale_items')->where('appointment_id', $cita)->where('item_type', 'penalty')->count())->toBe(1);
});

it('cancelar a última hora a pedido del cliente genera la penalidad; si no lo pidió, no', function () {
    $this->setRules($this->co->tenant, ['penalties.enabled' => true, 'cancel.late_fee_percent' => 25, 'cancel.window_hours' => 24]);
    $cita = ($this->citaEn)($this->at($this->co->tenant, '10:00'));
    $otra = ($this->citaEn)($this->at($this->co->tenant, '12:00'));
    $this->travelTo(\Illuminate\Support\Carbon::parse(DB::table('appointments')->where('id', $cita)->value('starts_at'))->subHours(5));

    $vista = $this->as($this->co->owner)->getJson("/api/appointments/{$cita}")->assertOk()->json('penalty_preview');
    expect($vista['late_cancel'])->toEqual(20);

    $this->as($this->co->owner)->postJson("/api/appointments/{$cita}/status", ['status' => 'cancelled', 'reason' => 'Viaje', 'by_customer' => true])->assertOk()->assertJsonPath('penalty.total', 20);

    $this->as($this->co->owner)->postJson("/api/appointments/{$otra}/status", ['status' => 'cancelled', 'reason' => 'La sede cerró'])->assertOk()->assertJsonPath('penalty', null);
});

it('cancelar con tiempo no genera penalidad', function () {
    $this->setRules($this->co->tenant, ['penalties.enabled' => true, 'cancel.late_fee_percent' => 25, 'cancel.window_hours' => 2]);
    $cita = ($this->citaEn)($this->at($this->co->tenant, '10:00'));

    $this->as($this->co->owner)->postJson("/api/appointments/{$cita}/status", ['status' => 'cancelled', 'reason' => 'Viaje', 'by_customer' => true])->assertOk()->assertJsonPath('penalty', null);
});

it('perdonar la penalidad es anular su venta pendiente', function () {
    $this->setRules($this->co->tenant, ['penalties.enabled' => true, 'noshow.fee_percent' => 50]);
    $cita = ($this->pasada)();
    $venta = $this->as($this->co->owner)->postJson("/api/appointments/{$cita}/status", ['status' => 'no_show'])->json('penalty.sale_id');

    $this->as($this->co->owner)->postJson("/api/sales/{$venta}/void", ["reason" => "Se le perdonó"])->assertSuccessful();

    expect(DB::table('sales')->where('id', $venta)->value('status'))->toBe('void');
});

it('la empresa enciende las penalidades desde Configuración', function () {
    $this->as($this->co->owner)->putJson('/api/tenant-settings', ['penalty_rules' => ['enabled' => true, 'no_show_percent' => 30, 'late_cancel_percent' => 10]])->assertOk();

    $reglas = \App\Support\Penalties::rules($this->co->tenant->fresh());

    expect($reglas['enabled'])->toBeTrue()->and($reglas['no_show_percent'])->toBe(30.0)->and($reglas['late_cancel_percent'])->toBe(10.0);
});
