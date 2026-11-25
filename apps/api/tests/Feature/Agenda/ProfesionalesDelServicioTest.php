<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);
    $this->servicio = $this->servicePriced($this->co->tenant, 90, 'Peeling');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 60, 'online_booking' => true, 'requires_deposit' => false]);
    $this->ana = $this->professional($this->co, 'Ana Torres');
    $this->beto = $this->professional($this->co, 'Beto Barbero');
    $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Paredes');

    $this->guardar = fn (array $ids) => $this->as($this->co->owner)->putJson("/api/services/{$this->servicio->id}", [
        'name' => 'Peeling', 'duration_minutes' => 60, 'price' => 90,
        'status_id' => DB::table('services')->where('id', $this->servicio->id)->value('status_id'),
        'professional_ids' => $ids,
    ]);
});

it('el servicio guarda quiénes lo hacen y lo muestra en la lista', function () {
    ($this->guardar)([$this->ana->id])->assertOk()->assertJsonPath('professional_ids', [$this->ana->id]);

    $fila = collect($this->as($this->co->owner)->getJson('/api/services')->assertOk()->json())->firstWhere('id', $this->servicio->id);
    expect($fila['professional_ids'])->toBe([$this->ana->id]);

    $auditoria = DB::table('audit_events')->where('entity_id', $this->servicio->id)->where('action', 'service.updated')->latest('created_at')->first();
    expect(json_decode($auditoria->after_data, true)['professionals'] ?? null)->toBe('Ana Torres');
});

it('vacío quiere decir todos: no cambia nada para las empresas que no lo usan', function () {
    $this->book($this->co, $this->clienta, $this->servicio, $this->beto, $this->at($this->co->tenant, '10:00'))->assertCreated();

    ($this->guardar)([$this->ana->id])->assertOk();
    ($this->guardar)([])->assertOk()->assertJsonPath('professional_ids', []);

    $this->book($this->co, $this->customer($this->co->tenant, 'Otra'), $this->servicio, $this->beto, $this->at($this->co->tenant, '12:00'))->assertCreated();
});

it('no se puede agendar el servicio con quien no lo hace, ni forzándolo', function () {
    ($this->guardar)([$this->ana->id])->assertOk();

    $this->book($this->co, $this->clienta, $this->servicio, $this->beto, $this->at($this->co->tenant, '10:00'), ['override_reason' => 'Lo pidió'])
        ->assertStatus(409)
        ->assertJsonPath('reasons.0.code', 'PRO_NO_SERVICE');

    $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant, '10:00'))->assertCreated();
});

it('la reserva en línea solo ofrece y acepta a quien hace el servicio', function () {
    ($this->guardar)([$this->ana->id])->assertOk();
    $dia = substr($this->at($this->co->tenant, '10:00'), 0, 10);

    $catalogo = collect($this->as($this->clienta)->getJson('/api/portal/catalog')->assertOk()->json('services'))->firstWhere('id', $this->servicio->id);
    expect($catalogo['professional_ids'])->toBe([$this->ana->id]);

    $libres = $this->as($this->clienta)->getJson("/api/portal/availability?service_id={$this->servicio->id}&branch_id={$this->co->branch->id}&date={$dia}&professional_id={$this->beto->id}")->assertOk()->json('times');
    expect($libres)->toBe([]);

    $this->as($this->clienta)->postJson('/api/portal/appointments', [
        'service_id' => $this->servicio->id, 'branch_id' => $this->co->branch->id, 'professional_id' => $this->beto->id, 'starts_at' => "{$dia}T10:00",
    ])->assertStatus(409);

    $cita = $this->as($this->clienta)->postJson('/api/portal/appointments', [
        'service_id' => $this->servicio->id, 'branch_id' => $this->co->branch->id, 'starts_at' => "{$dia}T10:00",
    ])->assertCreated()->json();

    expect(DB::table('appointments')->where('id', $cita['id'] ?? $cita['appointment']['id'] ?? null)->value('professional_id'))->toBe($this->ana->id);
});

it('la cola sin cita no deja pedir a quien no hace el servicio', function () {
    $this->travelTo($this->workday($this->co->tenant, 1)->setTime(10, 0));
    ($this->guardar)([$this->ana->id])->assertOk();

    $anotar = fn (string $pro) => $this->as($this->co->owner)->postJson('/api/walkins', [
        'branch_id' => $this->co->branch->id, 'service_id' => $this->servicio->id, 'first_name' => 'Llegó', 'last_name' => 'Sin Cita', 'professional_id' => $pro,
    ]);

    $anotar($this->beto->id)->assertStatus(422);
    $anotar($this->ana->id)->assertCreated();
});

it('no acepta profesionales de otra empresa', function () {
    $otra = $this->company();
    $ajeno = $this->professional($otra, 'De Otra Empresa');

    ($this->guardar)([$ajeno->id])->assertStatus(422);
    expect(DB::table('service_professionals')->where('service_id', $this->servicio->id)->exists())->toBeFalse();
});

it('la gerencia de una sede no borra a quienes trabajan en otra sede', function () {
    $sede2 = $this->branch($this->co);
    $lejos = $this->professional($this->co, 'Carla Lejos', 0, $sede2);
    DB::table('professional_schedules')->where('professional_id', $this->ana->id)->delete();
    DB::table('professional_schedules')->where('professional_id', $this->beto->id)->delete();
    foreach ([$this->ana, $this->beto] as $pro) {
        DB::table('professional_schedules')->insert(['id' => (string) Illuminate\Support\Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'professional_id' => $pro->id, 'branch_id' => $this->co->branch->id, 'weekday' => 1, 'starts_local' => '09:00', 'ends_local' => '18:00']);
    }
    ($this->guardar)([$this->ana->id, $lejos->id])->assertOk();

    $gerente = $this->staff($this->co, 'manager', $this->co->branch, 'Gerente Sede');
    $this->as($gerente)->putJson("/api/services/{$this->servicio->id}", [
        'name' => 'Peeling', 'duration_minutes' => 60, 'price' => 90,
        'status_id' => DB::table('services')->where('id', $this->servicio->id)->value('status_id'),
        'professional_ids' => [$this->beto->id],
    ])->assertOk();

    expect(collect(DB::table('service_professionals')->where('service_id', $this->servicio->id)->pluck('professional_id'))->sort()->values()->all())
        ->toBe(collect([$this->beto->id, $lejos->id])->sort()->values()->all());
});

it('un servicio sin historia se borra aunque tenga profesionales marcados', function () {
    ($this->guardar)([$this->ana->id])->assertOk();

    $this->as($this->co->owner)->deleteJson("/api/services/{$this->servicio->id}")->assertOk();

    expect(DB::table('services')->where('id', $this->servicio->id)->exists())->toBeFalse()
        ->and(DB::table('service_professionals')->where('service_id', $this->servicio->id)->exists())->toBeFalse();
});
