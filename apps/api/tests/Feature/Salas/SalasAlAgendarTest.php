<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);
    $this->servicio = $this->servicePriced($this->co->tenant, 120, 'Limpieza facial');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 60, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'online_booking' => true, 'requires_deposit' => false]);
    $this->ana = $this->professional($this->co, 'Ana Torres');
    $this->beto = $this->professional($this->co, 'Beto Ríos');
    $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Paredes');
    $this->otra = $this->customer($this->co->tenant, 'Marta', 'Quispe');
    $this->dia = fn (string $hora) => $this->at($this->co->tenant, $hora);

    $this->sala = function (string $nombre, array $extra = [], $co = null) {
        $co ??= $this->co;
        $id = (string) Str::uuid();
        DB::table('rooms')->insert($extra + [
            'id' => $id, 'tenant_id' => $co->tenant->id, 'branch_id' => $co->branch->id, 'name' => $nombre,
            'kind' => 'room', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    };

    $this->marcar = function (string ...$salas) {
        foreach ($salas as $sala) {
            DB::table('service_rooms')->insert(['tenant_id' => $this->co->tenant->id, 'service_id' => $this->servicio->id, 'room_id' => $sala]);
        }
    };

    $this->horarios = fn ($pro) => collect($this->as($this->co->owner)->getJson('/api/appointments/availability?' . http_build_query([
        'service_id' => $this->servicio->id, 'branch_id' => $this->co->branch->id, 'professional_id' => $pro->id,
        'date' => substr(($this->dia)('10:00'), 0, 10),
    ]))->assertOk()->json('professionals.0.slots'));
});

it('un servicio sin salas marcadas se agenda igual que hoy, sin sala', function () {
    ($this->sala)('Cabina 1');

    $a = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('10:00'))->assertCreated()->json('id');
    $b = $this->book($this->co, $this->otra, $this->servicio, $this->beto, ($this->dia)('10:00'))->assertCreated()->json('id');

    expect(DB::table('appointments')->whereIn('id', [$a, $b])->whereNotNull('room_id')->count())->toBe(0);
});

it('al agendar guarda una sala libre y la muestra con su nombre', function () {
    $cabina1 = ($this->sala)('Cabina 1');
    $cabina2 = ($this->sala)('Cabina 2');
    ($this->marcar)($cabina1, $cabina2);

    $a = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('10:00'))->assertCreated()->json();
    $b = $this->book($this->co, $this->otra, $this->servicio, $this->beto, ($this->dia)('10:00'))->assertCreated()->json();

    expect($a['room']['name'])->toBe('Cabina 1')
        ->and($b['room']['name'])->toBe('Cabina 2')
        ->and(DB::table('appointments')->where('id', $a['id'])->value('room_id'))->toBe($cabina1);

    $detalle = $this->as($this->co->owner)->getJson("/api/appointments/{$a['id']}")->assertOk()->json();
    expect($detalle['room']['name'])->toBe('Cabina 1')
        ->and(collect($detalle['room_options'])->pluck('name')->all())->toBe(['Cabina 1', 'Cabina 2']);
});

it('si la única sala está ocupada no deja agendar y explica por qué', function () {
    ($this->marcar)(($this->sala)('Cabina 1'));

    $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('10:00'))->assertCreated();

    $this->book($this->co, $this->otra, $this->servicio, $this->beto, ($this->dia)('10:30'), ['override_reason' => 'Es urgente'])
        ->assertStatus(409)
        ->assertJsonPath('reasons.0.code', 'ROOM_BUSY')
        ->assertJsonPath('reasons.0.message', 'La sala Cabina 1 ya está ocupada a esa hora')
        ->assertJsonPath('overridable', false);
});

it('no ofrece el horario si no hay ninguna sala libre', function () {
    ($this->marcar)(($this->sala)('Cabina 1'));
    $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('10:00'))->assertCreated();

    $libres = ($this->horarios)($this->beto);

    expect($libres)->not->toContain('10:00')
        ->and($libres)->not->toContain('10:30')
        ->and($libres)->toContain('11:00')
        ->and($libres)->toContain('09:00');
});

it('respeta el tiempo de limpieza de la sala si la empresa lo pide', function () {
    $this->setRules($this->co->tenant, ['agenda.respect_buffers' => true]);
    DB::table('services')->where('id', $this->servicio->id)->update(['buffer_after_minutes' => 15]);
    ($this->marcar)(($this->sala)('Cabina 1'));
    $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('10:00'))->assertCreated();

    $libres = ($this->horarios)($this->beto);

    expect($libres)->not->toContain('11:00')->and($libres)->toContain('11:30');
});

it('una clase en la sala la ocupa', function () {
    $cabina = ($this->sala)('Cabina 1');
    ($this->marcar)($cabina);
    $inicio = \Illuminate\Support\Carbon::parse(($this->dia)('15:00'), 'America/Lima');
    DB::table('class_sessions')->insert([
        'tenant_id' => $this->co->tenant->id, 'branch_id' => $this->co->branch->id, 'service_id' => $this->servicio->id,
        'professional_id' => $this->beto->id, 'room_id' => $cabina, 'title' => 'Yoga', 'capacity' => 5,
        'starts_at' => $inicio->copy()->utc(), 'ends_at' => $inicio->copy()->addHour()->utc(),
    ]);

    $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('15:00'))
        ->assertStatus(409)->assertJsonPath('reasons.0.code', 'ROOM_BUSY');
    $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('16:00'))->assertCreated();
});

it('una sala fuera de uso no se ofrece y, sin otra, no se puede agendar', function () {
    $cabina1 = ($this->sala)('Cabina 1', ['status' => 'inactive']);
    $cabina2 = ($this->sala)('Cabina 2');
    ($this->marcar)($cabina1, $cabina2);

    $cita = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('10:00'))->assertCreated()->json();
    expect($cita['room']['name'])->toBe('Cabina 2');

    DB::table('rooms')->where('id', $cabina2)->update(['status' => 'inactive']);

    $this->book($this->co, $this->otra, $this->servicio, $this->beto, ($this->dia)('12:00'))
        ->assertStatus(409)->assertJsonPath('reasons.0.code', 'NO_ROOM');
});

it('la sala sigue viéndose en la cita aunque luego se desactive o se borre', function () {
    $cabina = ($this->sala)('Cabina 1');
    ($this->marcar)($cabina);
    $cita = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('10:00'))->assertCreated()->json('id');

    $this->as($this->co->owner)->deleteJson("/api/rooms/{$cabina}")->assertOk()->assertJsonPath('deactivated', true);

    $this->as($this->co->owner)->getJson("/api/appointments/{$cita}")->assertOk()->assertJsonPath('room.name', 'Cabina 1');
    $dia = substr(($this->dia)('10:00'), 0, 10);
    $this->as($this->co->owner)->getJson("/api/appointments/day?date={$dia}&branch_id={$this->co->branch->id}")->assertOk()
        ->assertJsonPath('appointments.0.room.name', 'Cabina 1');
});

it('recepción cambia la sala por otra permitida y libre; si está ocupada lo dice', function () {
    $cabina1 = ($this->sala)('Cabina 1');
    $cabina2 = ($this->sala)('Cabina 2');
    $cabina3 = ($this->sala)('Cabina 3');
    ($this->marcar)($cabina1, $cabina2);

    $a = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('10:00'))->json('id');
    $b = $this->book($this->co, $this->otra, $this->servicio, $this->beto, ($this->dia)('10:00'))->json('id');

    $this->as($this->co->owner)->postJson("/api/appointments/{$a}/room", ['room_id' => $cabina2])
        ->assertStatus(422)->assertJsonPath('errors.room_id.0', 'La sala Cabina 2 ya está ocupada a esa hora.');

    $this->as($this->co->owner)->postJson("/api/appointments/{$a}/room", ['room_id' => $cabina3])
        ->assertStatus(422)->assertJsonValidationErrors('room_id');

    $this->as($this->co->owner)->postJson("/api/appointments/{$b}/reschedule", ['starts_at' => ($this->dia)('12:00')])->assertOk();
    $this->as($this->co->owner)->postJson("/api/appointments/{$a}/room", ['room_id' => $cabina2])->assertOk()->assertJsonPath('room.name', 'Cabina 2');

    expect(DB::table('appointments')->where('id', $a)->value('room_id'))->toBe($cabina2)
        ->and(DB::table('audit_events')->where('action', 'appointment.room_changed')->where('entity_id', $a)->exists())->toBeTrue();
});

it('al mover de hora se queda con su sala si sigue libre o toma otra libre', function () {
    $cabina1 = ($this->sala)('Cabina 1');
    $cabina2 = ($this->sala)('Cabina 2');
    ($this->marcar)($cabina1, $cabina2);

    $a = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('10:00'))->json('id');
    $this->book($this->co, $this->otra, $this->servicio, $this->beto, ($this->dia)('12:00'))->assertCreated();

    $this->as($this->co->owner)->postJson("/api/appointments/{$a}/reschedule", ['starts_at' => ($this->dia)('09:00')])->assertOk();
    expect(DB::table('appointments')->where('id', $a)->value('room_id'))->toBe($cabina1);

    $this->as($this->co->owner)->postJson("/api/appointments/{$a}/reschedule", ['starts_at' => ($this->dia)('12:00')])->assertOk()
        ->assertJsonPath('room.name', 'Cabina 2');
});

it('la reserva del portal también toma una sala y no ofrece horas sin sala', function () {
    $cabina = ($this->sala)('Cabina 1');
    ($this->marcar)($cabina);
    $this->book($this->co, $this->otra, $this->servicio, $this->beto, ($this->dia)('10:00'))->assertCreated();

    $this->as($this->clienta)->postJson('/api/portal/appointments', [
        'service_id' => $this->servicio->id, 'branch_id' => $this->co->branch->id, 'professional_id' => $this->ana->id, 'starts_at' => ($this->dia)('10:00'),
    ])->assertStatus(409);

    $cita = $this->as($this->clienta)->postJson('/api/portal/appointments', [
        'service_id' => $this->servicio->id, 'branch_id' => $this->co->branch->id, 'professional_id' => $this->ana->id, 'starts_at' => ($this->dia)('11:00'),
    ])->assertCreated()->json('id');

    expect(DB::table('appointments')->where('id', $cita)->value('room_id'))->toBe($cabina);
});

it('el servicio guarda sus salas y no acepta salas de otra empresa', function () {
    $cabina = ($this->sala)('Cabina 1');
    $otra = $this->company(['modules_off' => []]);
    $ajena = ($this->sala)('Cabina ajena', [], $otra);

    $datos = ['name' => 'Peeling', 'duration_minutes' => 45, 'price' => 90];

    $this->as($this->co->owner)->postJson('/api/services', $datos + ['room_ids' => [$ajena]])
        ->assertStatus(422)->assertJsonValidationErrors('room_ids.0');

    $nuevo = $this->as($this->co->owner)->postJson('/api/services', $datos + ['room_ids' => [$cabina]])->assertCreated()->json();
    expect($nuevo['room_ids'])->toBe([$cabina]);

    $lista = collect($this->as($this->co->owner)->getJson('/api/services')->assertOk()->json())->firstWhere('id', $nuevo['id']);
    expect($lista['room_ids'])->toBe([$cabina]);
});

it('no se puede poner en una cita la sala de otra empresa', function () {
    ($this->marcar)(($this->sala)('Cabina 1'));
    $otra = $this->company(['modules_off' => []]);
    $ajena = ($this->sala)('Cabina ajena', [], $otra);
    $cita = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('10:00'))->json('id');

    $this->as($this->co->owner)->postJson("/api/appointments/{$cita}/room", ['room_id' => $ajena])->assertStatus(422);
    $this->as($otra->owner)->postJson("/api/appointments/{$cita}/room", ['room_id' => $ajena])->assertNotFound();
});

it('con el módulo de salas apagado no se revisan ni se asignan salas', function () {
    ($this->marcar)(($this->sala)('Cabina 1'));
    DB::table('tenant_settings')->where('tenant_id', $this->co->tenant->id)->update(['modules_off' => json_encode(['resources'])]);
    $this->forgetCaches();

    $a = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('10:00'))->assertCreated()->json();
    $this->book($this->co, $this->otra, $this->servicio, $this->beto, ($this->dia)('10:00'))->assertCreated();

    expect($a['room'])->toBeNull()
        ->and(DB::table('appointments')->where('tenant_id', $this->co->tenant->id)->whereNotNull('room_id')->count())->toBe(0);

    $this->as($this->co->owner)->postJson("/api/appointments/{$a['id']}/room", ['room_id' => DB::table('rooms')->where('tenant_id', $this->co->tenant->id)->value('id')])
        ->assertForbidden();
});
