<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company();
    $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Corte');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 60, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0]);
    $this->ana = $this->professional($this->co, 'Ana Torres');
    $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Paredes');

    $this->desde = $this->workday($this->co->tenant, 10);
    $this->antes = $this->desde->copy()->subDays(7);
    $this->semana = fn (string $inicio, string $fin, ?array $dias = null) => collect($dias ?? range(0, 6))
        ->map(fn (int $dia) => ['weekday' => $dia, 'starts' => $inicio, 'ends' => $fin])->all();

    $this->guardar = fn (array $periodos) => $this->as($this->co->owner)->putJson("/api/professionals/{$this->ana->id}/schedule", [
        'branch_id' => $this->co->branch->id, 'periods' => $periodos,
    ]);

    $this->horarios = fn ($fecha) => collect($this->as($this->co->owner)->getJson('/api/appointments/availability?' . http_build_query([
        'service_id' => $this->servicio->id, 'branch_id' => $this->co->branch->id, 'professional_id' => $this->ana->id,
        'date' => $fecha->format('Y-m-d'),
    ]))->assertOk()->json('professionals.0.slots'));
});

it('un horario sin fechas vale siempre, como hoy', function () {
    $this->as($this->co->owner)->putJson("/api/professionals/{$this->ana->id}/schedule", [
        'branch_id' => $this->co->branch->id, 'ranges' => ($this->semana)('08:00', '20:00'),
    ])->assertOk();

    expect(($this->horarios)($this->desde))->toContain('15:00')
        ->and(DB::table('professional_schedules')->where('professional_id', $this->ana->id)->whereNotNull('valid_from')->exists())->toBeFalse();
});

it('desde una fecha usa el horario nuevo y antes sigue el de siempre', function () {
    ($this->guardar)([
        ['valid_from' => null, 'valid_until' => null, 'ranges' => ($this->semana)('08:00', '20:00')],
        ['valid_from' => $this->desde->format('Y-m-d'), 'valid_until' => null, 'ranges' => ($this->semana)('09:00', '13:00', [$this->desde->dayOfWeek])],
    ])->assertOk()->assertJsonPath('schedules.0.valid_from', fn ($v) => $v === null || is_string($v));

    expect(($this->horarios)($this->antes))->toContain('15:00')
        ->and(($this->horarios)($this->desde))->not->toContain('15:00')
        ->and(($this->horarios)($this->desde))->toContain('09:00')
        ->and(($this->horarios)($this->desde))->toContain('12:00')
        ->and(($this->horarios)($this->desde->copy()->addDay())->all())->toBe([]);

    $fecha = $this->desde->format('Y-m-d');
    $this->book($this->co, $this->clienta, $this->servicio, $this->ana, "{$fecha}T15:00")
        ->assertStatus(409)->assertJsonPath('reasons.0.code', 'OUT_OF_SCHEDULE');
    $this->book($this->co, $this->clienta, $this->servicio, $this->ana, "{$fecha}T10:00")->assertCreated();

    $this->as($this->co->owner)->getJson("/api/appointments/day?date={$fecha}&branch_id={$this->co->branch->id}")->assertOk()
        ->assertJsonPath('professionals.0.schedules.0.starts', '09:00')
        ->assertJsonPath('professionals.0.schedules.0.ends', '13:00');
});

it('pasada la fecha «hasta», vuelve al horario de siempre', function () {
    $hasta = $this->desde->copy()->addDays(6);

    ($this->guardar)([
        ['valid_from' => null, 'valid_until' => null, 'ranges' => ($this->semana)('08:00', '20:00')],
        ['valid_from' => $this->desde->format('Y-m-d'), 'valid_until' => $hasta->format('Y-m-d'), 'ranges' => ($this->semana)('14:00', '18:00')],
    ])->assertOk();

    expect(($this->horarios)($this->desde))->not->toContain('09:00')
        ->and(($this->horarios)($this->desde))->toContain('14:00')
        ->and(($this->horarios)($this->desde->copy()->addDays(7)))->toContain('09:00');
});

it('la semana del equipo muestra el horario de hoy y avisa del cambio que viene', function () {
    ($this->guardar)([
        ['valid_from' => null, 'valid_until' => null, 'ranges' => ($this->semana)('08:00', '20:00')],
        ['valid_from' => $this->desde->format('Y-m-d'), 'valid_until' => null, 'ranges' => ($this->semana)('09:00', '13:00')],
    ])->assertOk();

    $fila = collect($this->as($this->co->owner)->getJson('/api/schedules')->assertOk()->json('branches.0.professionals'))->firstWhere('id', $this->ana->id);

    expect($fila['ranges'][0]['starts'])->toBe('08:00')
        ->and($fila['next_change'])->toBe($this->desde->format('Y-m-d'));
});

it('no acepta fechas al revés, horarios con fechas que se cruzan ni dos horarios de siempre', function () {
    $base = ['valid_from' => null, 'valid_until' => null, 'ranges' => ($this->semana)('08:00', '20:00')];
    $d = fn (int $dias) => $this->desde->copy()->addDays($dias)->format('Y-m-d');

    ($this->guardar)([['valid_from' => $d(5), 'valid_until' => $d(1), 'ranges' => ($this->semana)('09:00', '13:00')]])
        ->assertStatus(422)->assertJsonValidationErrors('periods');

    ($this->guardar)([
        $base,
        ['valid_from' => $d(0), 'valid_until' => $d(10), 'ranges' => ($this->semana)('09:00', '13:00')],
        ['valid_from' => $d(5), 'valid_until' => null, 'ranges' => ($this->semana)('14:00', '18:00')],
    ])->assertStatus(422)->assertJsonValidationErrors('periods');

    ($this->guardar)([$base, $base])->assertStatus(422)->assertJsonValidationErrors('periods');

    ($this->guardar)([
        $base,
        ['valid_from' => $d(0), 'valid_until' => $d(4), 'ranges' => ($this->semana)('09:00', '13:00')],
        ['valid_from' => $d(5), 'valid_until' => null, 'ranges' => ($this->semana)('14:00', '18:00')],
    ])->assertOk();
});

it('el cruce con otra sede solo cuenta si las fechas coinciden', function () {
    $sede2 = $this->branch($this->co);
    DB::table('professional_schedules')->where('professional_id', $this->ana->id)->update([
        'valid_until' => $this->desde->copy()->subDay()->format('Y-m-d'),
    ]);

    $this->as($this->co->owner)->putJson("/api/professionals/{$this->ana->id}/schedule", [
        'branch_id' => $sede2->id,
        'periods'   => [['valid_from' => $this->desde->format('Y-m-d'), 'valid_until' => null, 'ranges' => ($this->semana)('09:00', '13:00')]],
    ])->assertOk();

    $this->as($this->co->owner)->putJson("/api/professionals/{$this->ana->id}/schedule", [
        'branch_id' => $sede2->id,
        'periods'   => [['valid_from' => $this->antes->format('Y-m-d'), 'valid_until' => null, 'ranges' => ($this->semana)('09:00', '13:00')]],
    ])->assertStatus(422)->assertJsonValidationErrors('ranges');
});
