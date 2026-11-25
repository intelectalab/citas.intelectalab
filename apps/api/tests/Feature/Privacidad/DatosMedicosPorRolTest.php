<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company();
    $this->setRules($this->co->tenant, ['customers.clinical_fields' => true]);
    $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Medina');
    DB::table('customers')->where('id', $this->clienta->id)->update(['medical_notes' => 'Alergia a la penicilina', 'blood_type' => 'O+']);
});

it('recepción y caja no reciben antecedentes ni grupo sanguíneo en la lista ni en la ficha', function (string $rol) {
    $persona = $this->staff($this->co, $rol);

    $lista = $this->as($persona)->getJson('/api/customers')->assertOk();
    $ficha = $this->as($persona)->getJson("/api/customers/{$this->clienta->id}")->assertOk();

    expect($lista->getContent())->not->toContain('penicilina')
        ->and($ficha->getContent())->not->toContain('penicilina')
        ->and($ficha->json('customer'))->not->toHaveKey('blood_type')
        ->and($ficha->json('customer'))->not->toHaveKey('medical_notes')
        ->and($ficha->json('customer.first_name'))->toBe('Lucía');
})->with(['reception', 'cashier']);

it('quien puede ver fichas sí recibe los datos médicos', function () {
    $gerente = $this->staff($this->co, 'manager');

    $this->as($this->co->owner)->getJson("/api/customers/{$this->clienta->id}")->assertOk()->assertJsonPath('customer.medical_notes', 'Alergia a la penicilina');
    $this->as($gerente)->getJson("/api/customers/{$this->clienta->id}")->assertOk()->assertJsonPath('customer.blood_type', 'O+');
});

it('si recepción edita a la clienta, los datos médicos no se borran ni se cambian', function () {
    $recep = $this->staff($this->co, 'reception');

    $this->as($recep)->putJson("/api/customers/{$this->clienta->id}", [
        'first_name' => 'Lucía', 'last_name' => 'Medina Paz', 'medical_notes' => null, 'blood_type' => 'AB-',
    ])->assertOk();

    $fila = DB::table('customers')->where('id', $this->clienta->id)->first();

    expect($fila->last_name)->toBe('Medina Paz')
        ->and($fila->medical_notes)->toBe('Alergia a la penicilina')
        ->and($fila->blood_type)->toBe('O+');
});

it('la cita que ve recepción tampoco trae los datos médicos de la clienta', function () {
    $recep = $this->staff($this->co, 'reception');
    $servicio = $this->servicePriced($this->co->tenant, 50);
    $pro = $this->professional($this->co, 'Ana Torres');
    $cita = $this->book($this->co, $this->clienta, $servicio, $pro, $this->at($this->co->tenant, '10:00'))->assertCreated()->json('id');

    expect($this->as($recep)->getJson("/api/appointments/{$cita}")->assertOk()->getContent())->not->toContain('penicilina');
});
