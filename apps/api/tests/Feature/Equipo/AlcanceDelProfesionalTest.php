<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);
    $this->servicio = $this->servicePriced($this->co->tenant, 50, 'Corte');
    $this->ana = $this->professional($this->co, 'Ana Torres', 10);
    $this->luis = $this->professional($this->co, 'Luis Rojas', 20);
    $this->suya = $this->customer($this->co->tenant, 'Clienta', 'DeAna');
    $this->ajena = $this->customer($this->co->tenant, 'Clienta', 'DeLuis');
    $this->book($this->co, $this->suya, $this->servicio, $this->ana, $this->at($this->co->tenant, '10:00'))->assertCreated();
    $this->book($this->co, $this->ajena, $this->servicio, $this->luis, $this->at($this->co->tenant, '11:00'))->assertCreated();
});

it('el profesional ve en Clientes solo a quienes atiende', function () {
    $ids = collect($this->as($this->ana->user)->getJson('/api/customers?per_page=100')->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($this->suya->id)->not->toContain($this->ajena->id);

    $this->as($this->ana->user)->getJson("/api/customers/{$this->suya->id}")->assertOk();
    $this->as($this->ana->user)->getJson("/api/customers/{$this->ajena->id}")->assertNotFound();
    $this->as($this->ana->user)->putJson("/api/customers/{$this->ajena->id}", ['first_name' => 'Cambiado'])->assertNotFound();
    $this->as($this->ana->user)->getJson("/api/customers/{$this->ajena->id}/profile")->assertNotFound();
});

it('al agendar, el profesional encuentra a una clienta nueva buscándola, pero solo con sus datos básicos', function () {
    DB::table('customers')->where('id', $this->ajena->id)->update(['medical_notes' => 'Dato reservado', 'email' => 'reservado@cliente.test']);

    $res = $this->as($this->ana->user)->getJson('/api/customers?pick=1&search=DeLuis')->assertOk();
    $fila = collect($res->json('data'))->firstWhere('id', $this->ajena->id);

    expect($fila)->not->toBeNull()
        ->and($fila)->toHaveKeys(['id', 'first_name', 'last_name'])
        ->and($res->getContent())->not->toContain('reservado@cliente.test')
        ->and($res->getContent())->not->toContain('Dato reservado');
});

it('recepción y la dueña siguen viendo a todas las clientas', function () {
    $recep = $this->staff($this->co, 'reception');

    foreach ([$recep, $this->co->owner] as $persona) {
        $ids = collect($this->as($persona)->getJson('/api/customers?per_page=100')->assertOk()->json('data'))->pluck('id');
        expect($ids)->toContain($this->suya->id)->toContain($this->ajena->id);
    }
});

it('el profesional no ve las reglas ni el porcentaje de comisión de sus compañeros', function () {
    foreach ([$this->ana, $this->luis] as $pro) {
        DB::table('commission_rules')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'name' => 'Excepción', 'kind' => 'service', 'service_id' => $this->servicio->id,
            'professional_id' => $pro->id, 'percent' => $pro === $this->ana ? 15 : 35, 'fixed' => 0, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $res = $this->as($this->ana->user)->getJson('/api/commission-rules')->assertOk();

    expect(collect($res->json('rules'))->pluck('professional_id')->filter()->unique()->values()->all())->toBe([$this->ana->id])
        ->and(collect($res->json('professionals'))->pluck('id')->all())->toBe([$this->ana->id]);

    $this->as($this->ana->user)->getJson("/api/commission-rules/preview?professional_id={$this->luis->id}&kind=service&reference_id={$this->servicio->id}")->assertForbidden();
    $this->as($this->ana->user)->getJson("/api/commission-rules/preview?professional_id={$this->ana->id}&kind=service&reference_id={$this->servicio->id}")->assertOk();

    $duena = $this->as($this->co->owner)->getJson('/api/commission-rules')->assertOk();
    expect(collect($duena->json('professionals'))->pluck('id'))->toContain($this->luis->id);
});
