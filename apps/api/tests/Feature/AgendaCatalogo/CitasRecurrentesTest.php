<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company();
    $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Terapia semanal');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 60]);
    $this->ana = $this->professional($this->co, 'Ana Torres');
    $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Paredes');
    $this->dia = $this->workday($this->co->tenant, 2);
    DB::table('holidays')->where('tenant_id', $this->co->tenant->id)->delete();
});

function serie(object $test, array $extra = []): \Illuminate\Testing\TestResponse
{
    \Tests\TestCase::forgetCaches();
    \Laravel\Sanctum\Sanctum::actingAs($test->co->owner, ["*"]);

    return $test->postJson('/api/appointments/series', $extra + [
        'customer_id' => $test->clienta->id, 'service_id' => $test->servicio->id, 'professional_id' => $test->ana->id,
        'branch_id' => $test->co->branch->id, 'starts_at' => $test->dia->format('Y-m-d') . 'T10:00',
        'frequency' => 'weekly', 'occurrences' => 4,
    ]);
}

it('agenda cada semana el número de veces pedido', function () {
    $respuesta = serie($this)->assertCreated();

    expect($respuesta->json('created'))->toHaveCount(4)
        ->and($respuesta->json('skipped'))->toBe([])
        ->and($respuesta->json('label'))->toBe('Cada semana');

    $fechas = DB::table('appointments')->where('series_id', $respuesta->json('series_id'))->orderBy('series_number')->pluck('starts_at')
        ->map(fn ($f) => \Illuminate\Support\Carbon::parse($f)->setTimezone('America/Lima')->format('Y-m-d H:i'))->all();

    expect($fechas)->toBe([
        $this->dia->format('Y-m-d') . ' 10:00',
        $this->dia->copy()->addWeek()->format('Y-m-d') . ' 10:00',
        $this->dia->copy()->addWeeks(2)->format('Y-m-d') . ' 10:00',
        $this->dia->copy()->addWeeks(3)->format('Y-m-d') . ' 10:00',
    ]);
});

it('cada N días hasta una fecha', function () {
    $hasta = $this->dia->copy()->addDays(9)->format('Y-m-d');
    $respuesta = serie($this, ['frequency' => 'days', 'every' => 3, 'occurrences' => null, 'until_date' => $hasta])->assertCreated();

    expect($respuesta->json('created'))->toHaveCount(4)
        ->and($respuesta->json('label'))->toBe('Cada 3 días');
});

it('dice qué fechas no se pudieron agendar y por qué, y agenda las demás', function () {
    $otra = $this->customer($this->co->tenant, 'Rosa', 'Vega');
    $choque = $this->dia->copy()->addWeek()->format('Y-m-d') . 'T10:00';
    $this->book($this->co, $otra, $this->servicio, $this->ana, $choque)->assertCreated();

    $respuesta = serie($this)->assertCreated();

    expect($respuesta->json('created'))->toHaveCount(3)
        ->and($respuesta->json('skipped'))->toHaveCount(1)
        ->and($respuesta->json('skipped.0.date'))->toBe($this->dia->copy()->addWeek()->format('Y-m-d'))
        ->and($respuesta->json('skipped.0.reason'))->toContain('Ana Torres ya tiene una cita');
});

it('la vista previa revisa cada fecha sin crear nada', function () {
    $otra = $this->customer($this->co->tenant, 'Rosa', 'Vega');
    $this->book($this->co, $otra, $this->servicio, $this->ana, $this->dia->copy()->addWeeks(2)->format('Y-m-d') . 'T10:00')->assertCreated();
    $antes = DB::table('appointments')->count();

    $respuesta = serie($this, ['preview' => true])->assertOk();

    expect(collect($respuesta->json('dates'))->pluck('ok')->all())->toBe([true, true, false, true])
        ->and($respuesta->json('dates.2.reason'))->toContain('ya tiene una cita')
        ->and(DB::table('appointments')->count())->toBe($antes);
});

it('si ninguna fecha se puede, no deja una serie vacía', function () {
    DB::table('professional_schedules')->where('professional_id', $this->ana->id)->delete();

    serie($this)->assertStatus(409)->assertJsonPath('code', 'SERIES_EMPTY');

    expect(DB::table('appointment_series')->count())->toBe(0);
});

it('pide hasta cuándo o cuántas veces', function () {
    serie($this, ['occurrences' => null])->assertStatus(422)->assertJsonValidationErrors(['until_date']);
});

it('cancelar solo una deja las demás', function () {
    $ids = collect(serie($this)->assertCreated()->json('created'))->pluck('id');

    $this->as($this->co->owner)->postJson("/api/appointments/{$ids[1]}/status", ['status' => 'cancelled', 'reason' => 'Viaje', 'series_scope' => 'one'])->assertOk();

    expect(DB::table('appointments')->whereIn('id', $ids)->where('status', 'cancelled')->count())->toBe(1);
});

it('cancelar esta y las siguientes deja las anteriores', function () {
    $ids = collect(serie($this)->assertCreated()->json('created'))->pluck('id');

    $respuesta = $this->as($this->co->owner)->postJson("/api/appointments/{$ids[1]}/status", ['status' => 'cancelled', 'reason' => 'Terminó el tratamiento', 'series_scope' => 'following'])->assertOk();

    expect($respuesta->json('series_cancelled'))->toBe(2)
        ->and(DB::table('appointments')->where('id', $ids[0])->value('status'))->not->toBe('cancelled')
        ->and(DB::table('appointments')->whereIn('id', $ids->slice(1))->where('status', 'cancelled')->count())->toBe(3);
});

it('cancelar toda la serie cancela las que faltan', function () {
    $ids = collect(serie($this)->assertCreated()->json('created'))->pluck('id');

    $this->as($this->co->owner)->postJson("/api/appointments/{$ids[2]}/status", ['status' => 'cancelled', 'reason' => 'Se mudó', 'series_scope' => 'all'])
        ->assertOk()->assertJsonPath('series_cancelled', 3);

    expect(DB::table('appointments')->whereIn('id', $ids)->where('status', 'cancelled')->count())->toBe(4);
});

it('el detalle de la cita muestra la serie', function () {
    $ids = collect(serie($this)->assertCreated()->json('created'))->pluck('id');

    $this->as($this->co->owner)->getJson("/api/appointments/{$ids[0]}")->assertOk()
        ->assertJsonPath('series.number', 1)
        ->assertJsonPath('series.total', 4)
        ->assertJsonPath('series.following', 3)
        ->assertJsonPath('series.label', 'Cada semana');
});

it('quien no puede agendar no crea series', function () {
    $caja = $this->staff($this->co, 'cashier');

    $this->as($caja)->postJson('/api/appointments/series', [
        'customer_id' => $this->clienta->id, 'service_id' => $this->servicio->id, 'professional_id' => $this->ana->id,
        'branch_id' => $this->co->branch->id, 'starts_at' => $this->dia->format('Y-m-d') . 'T10:00', 'frequency' => 'weekly', 'occurrences' => 3,
    ])->assertForbidden();
});

it('no se agenda una serie con datos de otra empresa', function () {
    $otra = $this->company();
    $ajena = $this->customer($otra->tenant);

    serie($this, ['customer_id' => $ajena->id])->assertNotFound();
});
