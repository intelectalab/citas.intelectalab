<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);

    $this->crear = fn (array $datos = [], $user = null, $branchId = null) => $this->as($user ?? $this->co->owner)
        ->postJson('/api/branches/' . ($branchId ?? $this->co->branch->id) . '/rooms', $datos + ['name' => 'Sala ' . Str::random(4)]);
});

it('crea salas y equipos y los lista con su sede', function () {
    $sala = ($this->crear)(['name' => 'Cabina 1'])->assertCreated()->json();
    ($this->crear)(['name' => 'Láser', 'kind' => 'equipment', 'parent_id' => $sala['id']])->assertCreated();

    $lista = collect($this->as($this->co->owner)->getJson('/api/rooms')->assertOk()->json('rooms'))->keyBy('name');

    expect($lista)->toHaveCount(2)
        ->and($lista['Cabina 1']['kind'])->toBe('room')
        ->and($lista['Cabina 1']['branch_name'])->toBe($this->co->branch->name)
        ->and($lista['Láser']['parent_name'])->toBe('Cabina 1');
});

it('no repite el nombre en la misma sede pero sí en otra', function () {
    $sede2 = $this->branch($this->co);
    ($this->crear)(['name' => 'Cabina 1'])->assertCreated();
    ($this->crear)(['name' => 'Cabina 1'])->assertStatus(422)->assertJsonValidationErrors('name');
    ($this->crear)(['name' => 'Cabina 1'], null, $sede2->id)->assertCreated();
});

it('un equipo solo se vincula a una sala de su misma sede', function () {
    $sede2 = $this->branch($this->co);
    $salaOtraSede = ($this->crear)(['name' => 'Sala lejos'], null, $sede2->id)->json('id');

    ($this->crear)(['name' => 'Láser', 'kind' => 'equipment', 'parent_id' => $salaOtraSede])->assertStatus(422)->assertJsonValidationErrors('parent_id');
});

it('editar cambia nombre y estado; una sala sin uso se borra y con citas o clases solo se desactiva', function () {
    $libre = ($this->crear)(['name' => 'Libre'])->json('id');
    $conCita = ($this->crear)(['name' => 'Con cita'])->json('id');
    $conClase = ($this->crear)(['name' => 'Con clase'])->json('id');

    $this->as($this->co->owner)->putJson("/api/rooms/{$libre}", ['name' => 'Libre renombrada', 'status' => 'inactive'])->assertOk()
        ->assertJsonPath('name', 'Libre renombrada')->assertJsonPath('status', 'inactive');

    $servicio = $this->servicePriced($this->co->tenant, 50);
    $pro = $this->professional($this->co);
    $clienta = $this->customer($this->co->tenant);
    $cita = $this->book($this->co, $clienta, $servicio, $pro, $this->at($this->co->tenant))->assertCreated()->json('id');
    DB::table('appointments')->where('id', $cita)->update(['room_id' => $conCita]);
    DB::table('class_sessions')->insert([
        'tenant_id' => $this->co->tenant->id, 'branch_id' => $this->co->branch->id, 'service_id' => $servicio->id,
        'professional_id' => $pro->id, 'room_id' => $conClase, 'title' => 'Yoga', 'capacity' => 5,
        'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHour(),
    ]);

    $this->as($this->co->owner)->deleteJson("/api/rooms/{$libre}")->assertOk()->assertJsonPath('deactivated', false);
    $this->as($this->co->owner)->deleteJson("/api/rooms/{$conCita}")->assertOk()->assertJsonPath('deactivated', true);
    $this->as($this->co->owner)->deleteJson("/api/rooms/{$conClase}")->assertOk()->assertJsonPath('deactivated', true);

    expect(DB::table('rooms')->where('id', $libre)->exists())->toBeFalse()
        ->and(DB::table('rooms')->where('id', $conCita)->value('status'))->toBe('inactive')
        ->and(DB::table('rooms')->where('id', $conClase)->value('status'))->toBe('inactive')
        ->and(DB::table('class_sessions')->where('room_id', $conClase)->exists())->toBeTrue();
});

it('si la regla apaga la sala, un equipo fuera de uso la deja fuera de uso y avisa', function () {
    $this->setRules($this->co->tenant, ['resources.equipment_down' => 'disable']);
    $sala = ($this->crear)(['name' => 'Cabina 1'])->json('id');
    $equipo = ($this->crear)(['name' => 'Láser', 'kind' => 'equipment', 'parent_id' => $sala])->json('id');

    $this->as($this->co->owner)->putJson("/api/rooms/{$equipo}", ['name' => 'Láser', 'kind' => 'equipment', 'parent_id' => $sala, 'status' => 'inactive'])->assertOk();

    $fila = collect($this->as($this->co->owner)->getJson('/api/rooms')->json('rooms'))->firstWhere('id', $sala);
    expect($fila['effective_status'])->toBe('inactive')
        ->and($fila['auto_down'])->toBeTrue()
        ->and($fila['down_equipment'])->toBe(['Láser'])
        ->and(DB::table('user_notifications')->where('user_id', $this->co->owner->id)->where('type', 'room.down')->exists())->toBeTrue();

    $this->as($this->co->owner)->putJson("/api/rooms/{$equipo}", ['name' => 'Láser', 'kind' => 'equipment', 'parent_id' => $sala, 'status' => 'active'])->assertOk();
    $fila = collect($this->as($this->co->owner)->getJson('/api/rooms')->json('rooms'))->firstWhere('id', $sala);
    expect($fila['effective_status'])->toBe('active');
});

it('recepción ve las salas pero no las cambia; la gerencia de una sede no toca otra', function () {
    $recep = $this->staff($this->co, 'reception');
    $sala = ($this->crear)(['name' => 'Cabina 1'])->json('id');

    $this->as($recep)->getJson('/api/rooms')->assertOk();
    ($this->crear)([], $recep)->assertForbidden();
    $this->as($recep)->deleteJson("/api/rooms/{$sala}")->assertForbidden();

    $sede2 = $this->branch($this->co);
    $gerente = $this->staff($this->co, 'manager');
    ($this->crear)(['name' => 'Ajena'], $gerente, $sede2->id)->assertForbidden();
    $salaSede2 = ($this->crear)(['name' => 'Sede dos'], null, $sede2->id)->json('id');
    $this->as($gerente)->putJson("/api/rooms/{$salaSede2}", ['name' => 'Cambio'])->assertForbidden();

    expect(collect($this->as($gerente)->getJson('/api/rooms')->json('rooms'))->pluck('id'))->not->toContain($salaSede2);
});

it('no se ven, cambian ni borran salas de otra empresa ni se crean en sus sedes', function () {
    $otra = $this->company(['modules_off' => []]);
    $ajena = $this->as($otra->owner)->postJson("/api/branches/{$otra->branch->id}/rooms", ['name' => 'Ajena'])->assertCreated()->json('id');

    $this->as($this->co->owner)->putJson("/api/rooms/{$ajena}", ['name' => 'Hackeada'])->assertNotFound();
    $this->as($this->co->owner)->deleteJson("/api/rooms/{$ajena}")->assertNotFound();
    ($this->crear)(['name' => 'Intrusa'], null, $otra->branch->id)->assertNotFound();

    expect(collect($this->as($this->co->owner)->getJson('/api/rooms')->json('rooms'))->pluck('id'))->not->toContain($ajena)
        ->and(DB::table('rooms')->where('id', $ajena)->value('name'))->toBe('Ajena');
});

it('con el módulo de recursos apagado no se entra a salas', function () {
    DB::table('tenant_settings')->where('tenant_id', $this->co->tenant->id)->update(['modules_off' => json_encode(['resources'])]);
    $this->as($this->co->owner)->getJson('/api/rooms')->assertForbidden();
});
