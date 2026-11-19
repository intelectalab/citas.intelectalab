<?php

use App\Support\CustomerStages;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);
    $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Servicio tablero');
    $this->pro = $this->professional($this->co);
    $this->clienta = $this->customer($this->co->tenant, 'Carla', 'Tablero');

    $this->tablero = fn ($user = null) => $this->as($user ?? $this->co->owner)->getJson('/api/customer-board')->assertOk()->json();
    $this->columna = fn (string $key) => collect(($this->tablero)()['stages'])->firstWhere('system_key', $key);
    $this->etapa = function (object $customer) {
        $id = DB::table('customers')->where('id', $customer->id)->value('pipeline_stage_id');

        return $id ? DB::table('pipeline_stages')->where('id', $id)->value('system_key') : 'lead';
    };
    $this->mover = fn (object $customer, ?string $stageId, $user = null) => $this->as($user ?? $this->co->owner)
        ->putJson("/api/customers/{$customer->id}/stage", ['stage_id' => $stageId]);
    $this->atendida = function (object $customer, int $diasAtras = 1) {
        $id = $this->book($this->co, $customer, $this->servicio, $this->pro, $this->at($this->co->tenant, '11:00', 5))->assertCreated()->json('id');
        $inicio = now()->subDays($diasAtras);
        DB::table('appointments')->where('id', $id)->update(['status' => 'completed', 'starts_at' => $inicio, 'ends_at' => $inicio->copy()->addHour(), 'completed_at' => $inicio->copy()->addHour()]);
        CustomerStages::refresh($this->co->tenant, [$customer->id]);

        return $id;
    };
});

it('cada empresa empieza con las siete columnas y la clienta nueva cae en Nuevo contacto', function () {
    $datos = ($this->tablero)();

    expect(collect($datos['stages'])->pluck('system_key')->all())->toBe(['lead', 'contacted', 'booked', 'active', 'loyal', 'recover', 'lost'])
        ->and(collect($datos['stages'])->firstWhere('system_key', 'lead')['customers'])->toBe(1)
        ->and($datos['default_stage_id'])->toBe(collect($datos['stages'])->firstWhere('system_key', 'lead')['id']);
});

it('al reservar pasa sola a Primera cita y al atenderla a Activo', function () {
    ($this->tablero)();
    $this->book($this->co, $this->clienta, $this->servicio, $this->pro, $this->at($this->co->tenant, '10:00'))->assertCreated();
    expect(($this->etapa)($this->clienta))->toBe('booked');

    DB::table('appointments')->where('customer_id', $this->clienta->id)->update(['status' => 'cancelled']);
    ($this->atendida)($this->clienta);
    expect(($this->etapa)($this->clienta))->toBe('active');

    $cambios = $this->as($this->co->owner)->getJson("/api/customers/{$this->clienta->id}/stage-changes")->assertOk()->json('changes');
    expect($cambios[0]['source'])->toBe('auto')->and($cambios[0]['by'])->toBeNull()->and($cambios[0]['to'])->toBe('Activo');
});

it('llega a Fidelizado por visitas frecuentes o por lo que gastó', function () {
    ($this->tablero)();
    foreach ([2, 10, 20, 30] as $dias) {
        ($this->atendida)($this->clienta, $dias);
    }
    expect(($this->etapa)($this->clienta))->toBe('loyal');

    $gasta = $this->customer($this->co->tenant, 'Gasta', 'Mucho');
    ($this->atendida)($gasta);
    expect(($this->etapa)($gasta))->toBe('active');

    $this->setRules($this->co->tenant, ['crm.vip_spend' => 50]);
    $this->openCash($this->co);
    $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id, 'quantity' => 1]], ['customer_id' => $gasta->id]);
    $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => $venta['total']])->assertOk();
    CustomerStages::refresh(\App\Models\Tenant::find($this->co->tenant->id), [$gasta->id]);

    expect(($this->etapa)($gasta))->toBe('loyal');
});

it('pasa a Por recuperar si dejó de venir y no tiene cita próxima', function () {
    ($this->tablero)();
    ($this->atendida)($this->clienta, 61);

    expect(($this->etapa)($this->clienta))->toBe('recover');

    $this->book($this->co, $this->clienta, $this->servicio, $this->pro, $this->at($this->co->tenant, '10:00'))->assertCreated();
    expect(($this->etapa)($this->clienta))->toBe('active');
});

it('lo movido a mano se respeta hasta su próxima cita, y Perdido solo se libera al reservar', function () {
    $contactado = ($this->columna)('contacted');
    ($this->mover)($this->clienta, $contactado['id'])->assertOk();
    CustomerStages::refresh($this->co->tenant, null, true);
    expect(($this->etapa)($this->clienta))->toBe('contacted');

    $this->travelTo(now()->addMinute());
    $this->book($this->co, $this->clienta, $this->servicio, $this->pro, $this->at($this->co->tenant, '10:00'))->assertCreated();
    expect(($this->etapa)($this->clienta))->toBe('booked')
        ->and(DB::table('customers')->where('id', $this->clienta->id)->value('stage_manual_at'))->toBeNull();

    $perdida = $this->customer($this->co->tenant, 'Se', 'Fue');
    $cita = $this->book($this->co, $perdida, $this->servicio, $this->pro, $this->at($this->co->tenant, '12:00'))->assertCreated()->json('id');
    $this->travelTo(now()->addMinute());
    ($this->mover)($perdida, ($this->columna)('lost')['id'])->assertOk();
    $this->travelTo(now()->addMinute());
    DB::table('appointments')->where('id', $cita)->update(['status' => 'completed', 'completed_at' => now()]);
    CustomerStages::refresh($this->co->tenant, [$perdida->id]);
    expect(($this->etapa)($perdida))->toBe('lost');
});

it('«que se ubique solo» quita la marca manual en el momento', function () {
    ($this->mover)($this->clienta, ($this->columna)('contacted')['id'])->assertOk();
    ($this->mover)($this->clienta, 'auto')->assertOk();

    expect(($this->etapa)($this->clienta))->toBe('lead')
        ->and(DB::table('customers')->where('id', $this->clienta->id)->value('stage_manual_at'))->toBeNull();
});

it('las columnas se renombran y agregan; borrar una con tarjetas exige decir a dónde pasan', function () {
    $etapas = ($this->tablero)()['stages'];
    $lead = $etapas[0];
    $columnas = array_map(fn ($s) => ['id' => $s['id'], 'name' => $s['name'], 'color' => $s['color']], $etapas);

    $renombradas = $columnas;
    $renombradas[1]['name'] = 'Llamado';
    $renombradas[] = ['name' => 'VIP', 'color' => '#EC4899'];
    $this->as($this->co->owner)->putJson('/api/customer-board/columns', ['columns' => $renombradas])->assertOk();
    expect(collect(($this->tablero)()['stages'])->pluck('name'))->toContain('Llamado')->toContain('VIP');

    $sinLead = array_values(array_filter($columnas, fn ($c) => $c['id'] !== $lead['id']));
    $this->as($this->co->owner)->putJson('/api/customer-board/columns', ['columns' => $sinLead])->assertStatus(422)->assertJsonValidationErrors('moves');
    $this->as($this->co->owner)->putJson('/api/customer-board/columns', ['columns' => $sinLead, 'moves' => [$lead['id'] => $sinLead[0]['id']]])->assertOk();

    expect(DB::table('customers')->where('id', $this->clienta->id)->value('pipeline_stage_id'))->toBe($sinLead[0]['id'])
        ->and(DB::table('pipeline_stages')->where('id', $lead['id'])->exists())->toBeFalse();

    $repetidas = [['name' => 'Uno'], ['name' => 'uno']];
    $this->as($this->co->owner)->putJson('/api/customer-board/columns', ['columns' => $repetidas])->assertStatus(422);
    $muchas = array_map(fn ($i) => ['name' => "Columna {$i}"], range(1, 13));
    $this->as($this->co->owner)->putJson('/api/customer-board/columns', ['columns' => $muchas])->assertStatus(422);
});

it('recepción mueve tarjetas pero no cambia las columnas', function () {
    $recep = $this->staff($this->co, 'reception');

    ($this->mover)($this->clienta, ($this->columna)('contacted')['id'], $recep)->assertOk();
    $columnas = array_map(fn ($s) => ['id' => $s['id'], 'name' => $s['name']], ($this->tablero)()['stages']);
    $this->as($recep)->putJson('/api/customer-board/columns', ['columns' => $columnas])->assertForbidden();

    $cambio = $this->as($this->co->owner)->getJson("/api/customers/{$this->clienta->id}/stage-changes")->json('changes.0');
    expect($cambio['source'])->toBe('board')->and($cambio['by']['id'])->toBe($recep->id);
});

it('no se mueven clientas de otra empresa ni a columnas de otra empresa', function () {
    $otra = $this->company(['modules_off' => []]);
    $ajena = $this->customer($otra->tenant, 'Clienta', 'Ajena');
    $columnaAjena = collect($this->as($otra->owner)->getJson('/api/customer-board')->json('stages'))->firstWhere('system_key', 'lost');

    ($this->mover)($ajena, ($this->columna)('lost')['id'])->assertNotFound();
    $this->as($this->co->owner)->getJson("/api/customers/{$ajena->id}/stage-changes")->assertNotFound();
    ($this->mover)($this->clienta, $columnaAjena['id'])->assertStatus(422);

    $columnas = array_map(fn ($s) => ['id' => $s['id'], 'name' => $s['name']], ($this->tablero)()['stages']);
    $columnas[] = ['id' => $columnaAjena['id'], 'name' => 'Robada'];
    $this->as($this->co->owner)->putJson('/api/customer-board/columns', ['columns' => $columnas])->assertStatus(422);

    expect(DB::table('pipeline_stages')->where('id', $columnaAjena['id'])->value('name'))->toBe('Perdido')
        ->and(DB::table('customers')->where('id', $ajena->id)->value('pipeline_stage_id'))->not->toBe(($this->columna)('lost')['id']);
});
