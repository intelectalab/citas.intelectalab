<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company(['business' => 'vet', 'modules_off' => []]);
    $this->servicio = $this->servicePriced($this->co->tenant, 100, 'Consulta general');
    $this->vet = $this->professional($this->co, 'Rosa Vet');
    $this->ana = $this->customer($this->co->tenant, 'Ana', 'Torres');
    $this->beto = $this->customer($this->co->tenant, 'Beto', 'Ramos');

    $this->completar = function (object $customer, int $daysAgo, ?string $dependentId = null) {
        DB::table('appointments')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'branch_id' => $this->co->branch->id,
            'customer_id' => $customer->id, 'customer_dependent_id' => $dependentId, 'service_id' => $this->servicio->id,
            'professional_id' => $this->vet->id, 'code' => 'CIT-' . Str::upper(Str::random(6)), 'status' => 'completed',
            'starts_at' => now()->subDays($daysAgo), 'ends_at' => now()->subDays($daysAgo)->addHour(), 'price' => 100,
            'source' => 'online', 'created_at' => now()->subDays($daysAgo + 1), 'updated_at' => now(),
        ]);
    };
});

it('filtra la lista por segmento, cuenta los segmentos y suma visitas, origen y gasto', function () {
    ($this->completar)($this->ana, 5);

    $todos = $this->as($this->co->owner)->getJson('/api/customers?per_page=50')->assertOk()->json('data');
    $ana = collect($todos)->firstWhere('id', $this->ana->id);

    expect($ana['insights']['visits'])->toBe(1)
        ->and($ana['insights']['origin'])->toBe('online')
        ->and($ana['insights']['origin_label'])->toBe('Reserva en línea')
        ->and($ana['insights']['spend_12m'])->toEqual(0)
        ->and($ana['insights']['segments'])->toContain('new');

    $sinVisitas = $this->as($this->co->owner)->getJson('/api/customers?segment=no_visits&per_page=50')->assertOk()->json('data');
    expect(collect($sinVisitas)->pluck('id'))->toContain($this->beto->id)->not->toContain($this->ana->id);

    $conteo = collect($this->as($this->co->owner)->getJson('/api/customers/segments')->assertOk()->json('segments'))->keyBy('key');
    expect($conteo['new']['count'])->toBe(1)->and($conteo['no_visits']['count'])->toBeGreaterThanOrEqual(1)->and($conteo->has('debt'))->toBeTrue();
});

it('busca por el nombre de la mascota y filtra por sede habitual', function () {
    $this->as($this->co->owner)->postJson("/api/customers/{$this->ana->id}/dependents", ['name' => 'Firulais', 'species' => 'Perro'])->assertCreated();
    $otra = $this->branch($this->co, 'Sede Norte');
    DB::table('customers')->where('id', $this->beto->id)->update(['home_branch_id' => $otra->id]);

    $porMascota = $this->as($this->co->owner)->getJson('/api/customers?search=firul')->assertOk()->json('data');
    expect(collect($porMascota)->pluck('id')->all())->toBe([$this->ana->id]);

    $porSede = $this->as($this->co->owner)->getJson("/api/customers?home_branch_id={$otra->id}")->assertOk()->json('data');
    expect(collect($porSede)->pluck('id')->all())->toBe([$this->beto->id]);
});

it('ordena por última visita', function () {
    ($this->completar)($this->ana, 30);
    ($this->completar)($this->beto, 2);

    $ids = collect($this->as($this->co->owner)->getJson('/api/customers?sort=last_visit&per_page=50')->assertOk()->json('data'))->pluck('id')->values();

    expect($ids->search($this->beto->id))->toBeLessThan($ids->search($this->ana->id));
});

it('la ficha 360 trae preferencias, ticket promedio, presupuestos y puntos, y no muestra clientes de otra empresa', function () {
    ($this->completar)($this->ana, 3);
    $perfil = $this->as($this->co->owner)->getJson("/api/customers/{$this->ana->id}/profile")->assertOk();

    expect($perfil->json('preferences.favorite_service.name'))->toBe('Consulta general')
        ->and($perfil->json('preferences.usual_professional.name'))->toBe('Rosa Vet')
        ->and($perfil->json('preferences.origin'))->toBe('Reserva en línea')
        ->and($perfil->json('money'))->toHaveKeys(['avg_ticket', 'per_month'])
        ->and($perfil->json('quotes'))->toBeArray()
        ->and($perfil->json('recalls'))->toBeArray();

    $otra = $this->company();
    $this->as($otra->owner)->getJson("/api/customers/{$this->ana->id}/profile")->assertNotFound();
    $this->as($otra->owner)->getJson('/api/customers/segments')->assertOk()->assertJsonPath('total', 0);
});

it('registra una llamada en la ficha y en la auditoría', function () {
    $this->as($this->co->owner)->postJson("/api/customers/{$this->ana->id}/calls", ['note' => 'Confirmó que vendrá el lunes', 'outcome' => 'answered'])
        ->assertCreated()->assertJsonPath('channel', 'call');

    expect(DB::table('messages')->where('customer_id', $this->ana->id)->where('channel', 'call')->value('subject'))->toBe('Llamada: contestó')
        ->and(DB::table('audit_events')->where('action', 'customer.call_logged')->where('entity_id', $this->ana->id)->exists())->toBeTrue();

    $this->as($this->co->owner)->postJson("/api/customers/{$this->ana->id}/calls", ['note' => ''])->assertStatus(422)->assertJsonValidationErrors(['note', 'outcome']);
});

it('reemplaza las variables del mensaje y no deja escribir por WhatsApp si la empresa no lo conecta', function () {
    $this->as($this->co->owner)->postJson('/api/messages', [
        'customer_id' => $this->ana->id, 'subject' => 'Hola [Nombre]', 'body' => 'Te saluda [Empresa]',
    ])->assertCreated()->assertJsonPath('subject', 'Hola Ana');

    $this->as($this->co->owner)->postJson('/api/messages', [
        'customer_id' => $this->ana->id, 'subject' => 'Hola', 'body' => 'Hola', 'channel' => 'whatsapp',
    ])->assertStatus(422)->assertJsonValidationErrors('channel');

    $canales = $this->as($this->co->owner)->getJson("/api/customers/{$this->ana->id}/channels")->assertOk();
    expect($canales->json('email.available'))->toBeTrue()->and($canales->json('whatsapp.available'))->toBeFalse();
});

it('guarda los datos de la mascota y muestra sus citas y su próximo control', function () {
    $toby = $this->as($this->co->owner)->postJson("/api/customers/{$this->ana->id}/dependents", [
        'name' => 'Toby', 'species' => 'Perro', 'weight_kg' => 12.5, 'color' => 'Marrón', 'sterilized' => true, 'microchip' => '900123',
    ])->assertCreated()->json();

    expect($toby['weight_kg'])->toEqual(12.5)->and($toby['sterilized'])->toBeTrue()->and($toby['microchip'])->toBe('900123');

    ($this->completar)($this->ana, 4, $toby['id']);
    DB::table('recalls')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'customer_id' => $this->ana->id,
        'customer_dependent_id' => $toby['id'], 'label' => 'Vacuna anual', 'due_date' => now()->addDays(20)->toDateString(),
        'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $lista = $this->as($this->co->owner)->getJson("/api/customers/{$this->ana->id}/dependents")->assertOk()->json();

    expect($lista[0]['stats']['appointments'])->toBe(1)
        ->and($lista[0]['stats']['last_visit'])->not->toBeNull()
        ->and($lista[0]['stats']['next_control']['label'])->toBe('Vacuna anual');
});

it('una plantilla confidencial deja privadas sus fichas y una inactiva ya no se usa', function () {
    $plantilla = $this->as($this->co->owner)->postJson('/api/record-templates', [
        'name' => 'Ficha reservada', 'fields' => [['label' => 'Motivo', 'type' => 'text']], 'service_ids' => [$this->servicio->id], 'is_confidential' => true,
    ])->assertCreated()->assertJsonPath('is_confidential', true)->assertJsonPath('active', true)->json();

    $perro = $this->as($this->co->owner)->postJson("/api/customers/{$this->ana->id}/dependents", ['name' => 'Rex'])->assertCreated()->json('id');
    $gato = $this->as($this->co->owner)->postJson("/api/customers/{$this->beto->id}/dependents", ['name' => 'Nube'])->assertCreated()->json('id');

    $cita = $this->book($this->co, $this->ana, $this->servicio, $this->vet, $this->at($this->co->tenant, '10:00'), ['customer_dependent_id' => $perro])->assertCreated()->json('id')
        ?? DB::table('appointments')->where('customer_id', $this->ana->id)->value('id');

    $this->as($this->co->owner)->putJson("/api/appointments/{$cita}/record", ['data' => ['motivo' => 'Control']])->assertOk()->assertJsonPath('is_private', true);

    $this->as($this->co->owner)->putJson("/api/record-templates/{$plantilla['id']}", [
        'name' => 'Ficha reservada', 'fields' => $plantilla['fields'], 'service_ids' => [$this->servicio->id], 'is_confidential' => true, 'active' => false,
    ])->assertOk()->assertJsonPath('active', false);

    $otra = $this->book($this->co, $this->beto, $this->servicio, $this->vet, $this->at($this->co->tenant, '12:00'), ['customer_dependent_id' => $gato])->assertCreated()->json('id')
        ?? DB::table('appointments')->where('customer_id', $this->beto->id)->value('id');

    $this->as($this->co->owner)->getJson("/api/appointments/{$otra}/record")->assertOk()->assertJsonPath('uses_record', false);
});

it('abre, llena y firma una ficha sin cita, y otra empresa no la ve', function () {
    $plantilla = $this->as($this->co->owner)->postJson('/api/record-templates', [
        'name' => 'Evaluación', 'fields' => [['label' => 'Peso', 'type' => 'text', 'required' => true]], 'service_ids' => [],
    ])->assertCreated()->json();

    expect(collect($this->as($this->co->owner)->getJson('/api/record-sheet-templates')->assertOk()->json('templates'))->pluck('name'))->toContain('Evaluación');

    $id = $this->as($this->co->owner)->postJson('/api/record-sheets', ['customer_id' => $this->ana->id, 'record_template_id' => $plantilla['id']])
        ->assertCreated()->json('id');

    $hoja = $this->as($this->co->owner)->getJson("/api/record-sheets/{$id}")->assertOk();
    expect($hoja->json('record.status'))->toBe('draft')->and($hoja->json('appointment.id'))->toBeNull();

    $clave = $plantilla['fields'][0]['key'];
    $this->as($this->co->owner)->putJson("/api/record-sheets/{$id}", ['data' => [], 'sign' => true])->assertStatus(422);
    $this->as($this->co->owner)->putJson("/api/record-sheets/{$id}", ['data' => [$clave => '12 kg'], 'sign' => true])->assertOk()->assertJsonPath('status', 'locked');

    expect(DB::table('audit_events')->where('action', 'record.created_without_appointment')->where('entity_id', $id)->exists())->toBeTrue();

    $otra = $this->company();
    $this->as($otra->owner)->getJson("/api/record-sheets/{$id}")->assertNotFound();
    $this->as($otra->owner)->postJson('/api/record-sheets', ['customer_id' => $this->ana->id, 'record_template_id' => $plantilla['id']])->assertNotFound();
});

it('guarda el dependiente del presupuesto y avisa el tope de descuento', function () {
    $toby = $this->as($this->co->owner)->postJson("/api/customers/{$this->ana->id}/dependents", ['name' => 'Toby'])->assertCreated()->json();
    $mishi = $this->as($this->co->owner)->postJson("/api/customers/{$this->beto->id}/dependents", ['name' => 'Mishi'])->assertCreated()->json();

    $base = [
        'customer_id' => $this->ana->id, 'branch_id' => $this->co->branch->id, 'title' => 'Plan de vacunas',
        'items' => [['item_type' => 'service', 'reference_id' => $this->servicio->id, 'description' => 'Vacuna', 'quantity' => 1, 'unit_price' => 100]],
    ];

    $this->as($this->co->owner)->postJson('/api/quotes', $base + ['customer_dependent_id' => $mishi['id']])->assertStatus(422)->assertJsonValidationErrors('customer_dependent_id');

    $tablero = $this->as($this->co->owner)->postJson('/api/quotes', $base + ['customer_dependent_id' => $toby['id']])->assertOk();
    $fila = collect($tablero->json('quotes'))->firstWhere('customer_id', $this->ana->id);

    expect($fila['customer_dependent_id'])->toBe($toby['id'])
        ->and($fila['dependent_name'])->toBe('Toby')
        ->and($tablero->json('discount_limit'))->toHaveKeys(['max_percent', 'can_exceed']);
});

it('cuenta cuántos controles terminaron en cita', function () {
    $nuevo = fn (string $status, int $days) => DB::table('recalls')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'customer_id' => $this->ana->id,
        'label' => 'Control', 'due_date' => now()->subDays($days)->toDateString(), 'status' => $status,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $nuevo('booked', 10);
    $nuevo('pending', 5);
    $nuevo('cancelled', 20);
    $nuevo('pending', -10);

    $this->as($this->co->owner)->getJson('/api/recalls/summary')->assertOk()
        ->assertJsonPath('total', 3)->assertJsonPath('booked', 1)->assertJsonPath('rate', 33);
});
