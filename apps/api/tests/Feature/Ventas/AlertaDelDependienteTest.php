<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company(['business' => 'vet']);
    $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Baño y corte');
    $this->vet = $this->professional($this->co, 'Rosa Vet');
    $this->tutora = $this->customer($this->co->tenant, 'Carla', 'Ríos');
    $this->dia = $this->workday($this->co->tenant)->format('Y-m-d');
    $this->mascota = fn (array $datos): array => $this->as($this->co->owner)
        ->postJson("/api/customers/{$this->tutora->id}/dependents", $datos + ['name' => 'Toby', 'species' => 'Perro'])
        ->assertCreated()->json();
});

it('guarda la alerta de la mascota sin espacios de más y la deja en el historial', function () {
    $toby = ($this->mascota)(['alert' => '  Muerde al cortarle las uñas  ']);

    expect($toby['alert'])->toBe('Muerde al cortarle las uñas');

    $this->as($this->co->owner)
        ->putJson("/api/customers/{$this->tutora->id}/dependents/{$toby['id']}", ['name' => 'Toby', 'alert' => '   '])
        ->assertOk()->assertJsonPath('alert', null);

    $cambio = DB::table('audit_events')->where('entity_id', $toby['id'])->where('action', 'customer_dependent.updated')->first();
    expect($cambio)->not->toBeNull()->and($cambio->before_data)->toContain('Muerde');
});

it('no acepta una alerta de más de 160 letras', function () {
    $this->as($this->co->owner)
        ->postJson("/api/customers/{$this->tutora->id}/dependents", ['name' => 'Toby', 'alert' => str_repeat('a', 161)])
        ->assertStatus(422)->assertJsonValidationErrors('alert');
});

it('se guarda al crear la ficha del cliente con sus mascotas', function () {
    $nuevo = $this->as($this->co->owner)->postJson('/api/customers', [
        'first_name' => 'Pedro', 'last_name' => 'Soto', 'phone' => '987111222',
        'dependents' => [['name' => 'Mishi', 'species' => 'Gato', 'alert' => 'Se estresa con secador']],
    ])->assertCreated()->json('customer');

    expect($nuevo['dependents'][0]['alert'])->toBe('Se estresa con secador');
    expect(DB::table('customer_dependents')->where('customer_id', $nuevo['id'])->value('alert'))->toBe('Se estresa con secador');
});

it('aparece al agendar solo para la mascota elegida', function () {
    $toby = ($this->mascota)(['alert' => 'Muerde al cortarle las uñas']);
    $luna = ($this->mascota)(['name' => 'Luna']);

    $avisos = fn (?string $id) => collect($this->as($this->co->owner)->getJson('/api/appointments/customer-notes?' . http_build_query(array_filter([
        'customer_id' => $this->tutora->id, 'customer_dependent_id' => $id,
    ])))->assertOk()->json('notes'));

    $conToby = $avisos($toby['id'])->firstWhere('kind', 'dependent_alert');

    expect($conToby['title'])->toBe('Alerta de Toby')
        ->and($conToby['text'])->toBe('Muerde al cortarle las uñas')
        ->and($avisos($luna['id'])->firstWhere('kind', 'dependent_alert'))->toBeNull()
        ->and($avisos(null)->firstWhere('kind', 'dependent_alert'))->toBeNull();
});

it('no muestra la alerta de la mascota de otro cliente', function () {
    $otro = $this->customer($this->co->tenant, 'Otro', 'Tutor');
    $ajena = DB::table('customer_dependents')->insertGetId([
        'id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'customer_id' => $otro->id,
        'name' => 'Rex', 'kind' => 'pet', 'alert' => 'Agresivo', 'status' => 'active',
    ], 'id');

    $notas = $this->as($this->co->owner)->getJson('/api/appointments/customer-notes?' . http_build_query([
        'customer_id' => $this->tutora->id, 'customer_dependent_id' => $ajena,
    ]))->assertOk()->json('notes');

    expect(collect($notas)->firstWhere('kind', 'dependent_alert'))->toBeNull();
});

it('se ve en la cita y en la ficha de atención, pero no en el portal del cliente', function () {
    $toby = ($this->mascota)(['alert' => 'Muerde al cortarle las uñas']);
    $cita = $this->book($this->co, $this->tutora, $this->servicio, $this->vet, "{$this->dia}T10:00", ['customer_dependent_id' => $toby['id']])->assertCreated()->json();

    $detalle = $this->as($this->co->owner)->getJson("/api/appointments/{$cita['id']}")->assertOk()->json();
    expect($detalle['dependent_alert'])->toBe('Muerde al cortarle las uñas')
        ->and($detalle['dependent']['name'])->toBe('Toby');

    $ficha = $this->as($this->co->owner)->getJson("/api/appointments/{$cita['id']}/record")->assertOk()->json();
    expect($ficha['appointment']['dependent_alert'])->toBe('Muerde al cortarle las uñas');

    DB::table('customers')->where('id', $this->tutora->id)->update(['must_change_password' => false]);
    $portal = $this->as($this->tutora)->getJson('/api/portal/overview')->assertOk()->getContent();
    expect($portal)->not->toContain('Muerde');
});

it('el cliente no puede ponerle una alerta a su mascota desde el portal', function () {
    DB::table('customers')->where('id', $this->tutora->id)->update(['must_change_password' => false]);

    $creada = $this->as($this->tutora)->postJson('/api/portal/dependents', ['name' => 'Coco', 'alert' => 'Inventada'])->assertCreated()->json();

    expect(DB::table('customer_dependents')->where('id', $creada['id'])->value('alert'))->toBeNull();
});

it('al borrar los datos personales del cliente también se borra la alerta', function () {
    $toby = ($this->mascota)(['alert' => 'Muerde']);

    \App\Support\CustomerRecords::anonymize(\App\Models\Customer::find($this->tutora->id));

    expect(DB::table('customer_dependents')->where('id', $toby['id'])->value('alert'))->toBeNull();
});
