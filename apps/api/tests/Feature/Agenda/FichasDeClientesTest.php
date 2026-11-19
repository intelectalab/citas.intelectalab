<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company();
    $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Manicure');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 60]);
    $this->ana = $this->professional($this->co, 'Ana Torres');
});

describe('documento sin repetir', function () {
    it('no deja registrar otra ficha con el mismo documento', function () {
        $existente = $this->customer($this->co->tenant, 'Rosa', 'Huamán');
        DB::table('customers')->where('id', $existente->id)->update(['document_number' => '45678912']);

        $this->as($this->co->owner)->postJson('/api/customers', ['first_name' => 'Rosa', 'last_name' => 'H.', 'document_number' => '4567 8912'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('document_number');
    });

    it('no deja cambiar el documento por uno que ya usa otra ficha', function () {
        $una = $this->customer($this->co->tenant, 'Rosa', 'Huamán');
        $otra = $this->customer($this->co->tenant, 'Marta', 'Quispe');
        DB::table('customers')->where('id', $una->id)->update(['document_number' => '45678912']);

        $this->as($this->co->owner)->putJson("/api/customers/{$otra->id}", ['document_number' => '45678912'])->assertStatus(422);
        $this->as($this->co->owner)->putJson("/api/customers/{$una->id}", ['document_number' => '45678912'])->assertOk();
    });
});

describe('unir fichas duplicadas', function () {
    it('pasa las citas y ventas de la ficha repetida a la que queda y completa los datos que faltan', function () {
        $queda = $this->customer($this->co->tenant, 'Rosa', 'Huamán');
        $repetida = $this->customer($this->co->tenant, 'Rosa', 'Huaman');
        DB::table('customers')->where('id', $repetida->id)->update(['phone' => '987654321', 'document_number' => '45678912']);
        $cita = $this->book($this->co, $repetida, $this->servicio, $this->ana, $this->at($this->co->tenant, '10:00'))->assertCreated()->json('id');
        \App\Http\Controllers\Api\PortalController::acceptPrivacy($this->co->tenant->id, $queda->id, 'staff');
        \App\Http\Controllers\Api\PortalController::acceptPrivacy($this->co->tenant->id, $repetida->id, 'staff');

        $vista = $this->as($this->co->owner)->postJson("/api/customers/{$queda->id}/merge-preview", ['duplicate_id' => $repetida->id])->assertOk()->json();
        expect($vista['counts']['appointments'])->toBe(1)->and($vista['fills'])->toContain('phone');

        $this->as($this->co->owner)->postJson("/api/customers/{$queda->id}/merge", ['duplicate_id' => $repetida->id])->assertOk();

        expect(DB::table('appointments')->where('id', $cita)->value('customer_id'))->toBe($queda->id)
            ->and(DB::table('customers')->where('id', $queda->id)->value('phone'))->toBe('987654321')
            ->and(DB::table('customers')->where('id', $repetida->id)->value('deleted_at'))->not->toBeNull()
            ->and(DB::table('customer_consents')->where('customer_id', $queda->id)->where('channel', 'data_processing')->count())->toBe(1);
    });

    it('recepción no puede unir fichas', function () {
        $queda = $this->customer($this->co->tenant, 'Rosa', 'Huamán');
        $repetida = $this->customer($this->co->tenant, 'Rosa', 'Huaman');
        $recepcion = $this->staff($this->co, 'reception', $this->co->branch);

        $this->as($recepcion)->postJson("/api/customers/{$queda->id}/merge", ['duplicate_id' => $repetida->id])->assertForbidden();
    });

    it('no une con una ficha de otra empresa', function () {
        $queda = $this->customer($this->co->tenant, 'Rosa', 'Huamán');
        $otra = $this->company();
        $ajena = $this->customer($otra->tenant, 'Rosa', 'Huamán');

        $this->as($this->co->owner)->postJson("/api/customers/{$queda->id}/merge", ['duplicate_id' => $ajena->id])->assertNotFound();
    });
});

describe('borrar datos personales', function () {
    it('quita nombre, documento y contacto, pero conserva las citas', function () {
        $clienta = $this->customer($this->co->tenant, 'Rosa', 'Huamán');
        DB::table('customers')->where('id', $clienta->id)->update(['document_number' => '45678912', 'phone' => '987654321']);
        $cita = $this->book($this->co, $clienta, $this->servicio, $this->ana, $this->at($this->co->tenant, '10:00'))->assertCreated()->json('id');

        $this->as($this->co->owner)->postJson("/api/customers/{$clienta->id}/anonymize", [])->assertStatus(422);
        $this->as($this->co->owner)->postJson("/api/customers/{$clienta->id}/anonymize", ['confirm' => 'BORRAR'])->assertOk();

        $fila = DB::table('customers')->where('id', $clienta->id)->first();

        expect($fila->first_name)->toBe('Cliente')
            ->and($fila->document_number)->toBeNull()
            ->and($fila->phone)->toBeNull()
            ->and($fila->email)->toBeNull()
            ->and($fila->password_hash)->toBeNull()
            ->and($fila->anonymized_at)->not->toBeNull()
            ->and(DB::table('appointments')->where('id', $cita)->value('customer_id'))->toBe($clienta->id);

        $this->as($this->co->owner)->postJson("/api/customers/{$clienta->id}/anonymize", ['confirm' => 'BORRAR'])->assertStatus(422);
    });
});
