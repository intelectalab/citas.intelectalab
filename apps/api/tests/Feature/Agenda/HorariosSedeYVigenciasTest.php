<?php

use App\Support\BranchHours;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);
    $this->servicio = $this->servicePriced($this->co->tenant, 40, 'Corte');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 30]);
    $this->ana = $this->professional($this->co, 'Ana Torres');
});

it('avisa si el horario del profesional queda fuera del horario de la sede, pero lo guarda', function () {
    BranchHours::replace($this->co->tenant->id, $this->co->branch->id, [
        ['weekday' => 1, 'opens' => '09:00', 'closes' => '18:00'],
    ]);

    $respuesta = $this->as($this->co->owner)->putJson("/api/professionals/{$this->ana->id}/schedule", [
        'branch_id' => $this->co->branch->id,
        'ranges'    => [
            ['weekday' => 1, 'starts' => '07:00', 'ends' => '20:00'],
            ['weekday' => 2, 'starts' => '10:00', 'ends' => '12:00'],
        ],
    ])->assertOk()->json();

    expect($respuesta['branch_closed'])->toBe([
        'lunes 07:00–20:00: la sede abre 09:00–18:00',
        'martes 10:00–12:00: la sede está cerrada ese día',
    ])->and(DB::table('professional_schedules')->where('professional_id', $this->ana->id)->count())->toBe(2);
});

it('sin horario de sede cargado no avisa nada', function () {
    $this->as($this->co->owner)->putJson("/api/professionals/{$this->ana->id}/schedule", [
        'branch_id' => $this->co->branch->id,
        'ranges'    => [['weekday' => 1, 'starts' => '07:00', 'ends' => '20:00']],
    ])->assertOk()->assertJsonPath('branch_closed', []);
});

it('la cola sin cita no cuenta a quien tiene un horario que ya venció', function () {
    $hoy = $this->workday($this->co->tenant, 1)->setTime(10, 0);
    $this->travelTo($hoy);
    $beto = $this->professional($this->co, 'Beto Temporal');
    DB::table('professional_schedules')->where('professional_id', $beto->id)->update([
        'valid_from' => $hoy->copy()->subDays(30)->toDateString(), 'valid_until' => $hoy->copy()->subDay()->toDateString(),
    ]);

    $this->as($this->co->owner)->postJson('/api/walkins', [
        'branch_id' => $this->co->branch->id, 'service_id' => $this->servicio->id, 'first_name' => 'Llegó', 'last_name' => 'Sin Cita', 'professional_id' => $beto->id,
    ])->assertStatus(422);

    $working = \App\Support\Walkin::workingToday($this->co->tenant, $this->co->branch)->pluck('id')->all();
    expect($working)->toContain($this->ana->id)->not->toContain($beto->id);
});

it('la ocupación del mes no se diluye cuando alguien tiene horario con fechas', function () {
    $desde = $this->workday($this->co->tenant, 1)->startOfMonth();
    $beto = $this->professional($this->co, 'Beto Temporal');
    DB::table('professional_schedules')->where('professional_id', $beto->id)->update(['valid_from' => $desde->toDateString(), 'valid_until' => $desde->copy()->addDays(9)->toDateString()]);

    $semanas = $this->as($this->co->owner)->getJson('/api/appointments/range?' . http_build_query([
        'from' => $desde->toDateString(), 'to' => $desde->copy()->addDays(27)->toDateString(), 'branch_id' => $this->co->branch->id,
    ]))->assertOk()->json('professionals');

    $ana = collect($semanas)->firstWhere('id', $this->ana->id)['schedules'];
    $betoRows = collect($semanas)->firstWhere('id', $beto->id)['schedules'];
    $dia = $desde->copy()->addDays(3)->toDateString();

    expect(collect($ana)->where('date', $dia)->count())->toBe(1)
        ->and(collect($ana)->count())->toBe(28)
        ->and(collect($betoRows)->count())->toBe(10)
        ->and(collect($betoRows)->where('date', $desde->copy()->addDays(12)->toDateString())->count())->toBe(0);
});
