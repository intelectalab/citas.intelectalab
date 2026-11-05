<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company();
    $this->servicio = $this->servicePriced($this->co->tenant, 118, 'Corte de prueba');
});

it('abre la caja con el monto inicial', function () {
    $turno = $this->openCash($this->co, 150);

    expect($turno['status'])->toBe('open')
        ->and(money($turno['opening_amount']))->toBe(150.0)
        ->and(money($turno['expected_amount']))->toBe(150.0);
});

it('no deja abrir dos veces la misma caja', function () {
    $turno = $this->openCash($this->co);

    $this->as($this->co->owner)
        ->postJson("/api/cash/registers/{$turno['register_id']}/open", ['opening_amount' => 0])
        ->assertStatus(409);
});

it('no cobra en efectivo con la caja cerrada', function () {
    $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]]);

    $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => 118])
        ->assertStatus(409)
        ->assertJsonPath('code', 'CASH_CLOSED');

    expect($this->sale($venta['id'])->status)->toBe('pending');
});

it('cobra en efectivo, da el vuelto y lo suma a la caja', function () {
    $turno = $this->openCash($this->co, 100);
    $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]]);

    $respuesta = $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => 118, 'received' => 200])->assertOk();

    expect(money($respuesta->json('change')))->toBe(82.0)
        ->and($this->sale($venta['id'])->status)->toBe('paid')
        ->and(money($this->cashSession($turno['id'])['expected_amount']))->toBe(218.0);
});

it('rechaza un pago en efectivo si lo recibido no alcanza', function () {
    $this->openCash($this->co);
    $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]]);

    $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => 118, 'received' => 100])
        ->assertStatus(422)
        ->assertJsonValidationErrors('received');
});

it('los pagos con tarjeta no entran a la caja de efectivo', function () {
    $turno = $this->openCash($this->co, 50);
    $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]]);

    $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => 118])->assertOk();

    $caja = $this->cashSession($turno['id']);

    expect(money($caja['expected_amount']))->toBe(50.0)
        ->and(collect($caja['by_method'])->firstWhere('method', 'card')['total'])->toEqual(118);
});

it('pide el número de operación para tarjeta, Yape, Plin y transferencia', function (string $medio) {
    $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]]);

    $this->as($this->co->owner)
        ->postJson("/api/sales/{$venta['id']}/pay", ['method' => $medio, 'amount' => 118])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reference');
})->with(['card', 'yape', 'plin', 'transfer']);

it('registra ingresos, gastos y retiros y no deja sacar más de lo que hay', function () {
    $turno = $this->openCash($this->co, 100);
    $url = "/api/cash/sessions/{$turno['id']}/movements";

    $this->as($this->co->owner)->postJson($url, ['type' => 'income', 'amount' => 20, 'description' => 'Sencillo'])->assertCreated();
    $this->as($this->co->owner)->postJson($url, ['type' => 'expense', 'amount' => 30, 'description' => 'Limpieza'])->assertCreated();
    $this->as($this->co->owner)->postJson($url, ['type' => 'withdrawal', 'amount' => 500, 'description' => 'Depósito'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('amount');

    expect(money($this->cashSession($turno['id'])['expected_amount']))->toBe(90.0);
});

it('cierra la caja cuadrada sin pedir explicación', function () {
    $turno = $this->openCash($this->co, 100);

    $cierre = $this->as($this->co->owner)
        ->postJson("/api/cash/sessions/{$turno['id']}/close", ['counted_amount' => 100])
        ->assertOk()
        ->json();

    expect($cierre['status'])->toBe('closed')
        ->and(money($cierre['difference']))->toBe(0.0);
});

it('con diferencia, pide explicar el motivo antes de cerrar', function () {
    $turno = $this->openCash($this->co, 100);
    $url = "/api/cash/sessions/{$turno['id']}/close";

    $this->as($this->co->owner)->postJson($url, ['counted_amount' => 95])
        ->assertStatus(422)
        ->assertJsonValidationErrors('notes');

    $cierre = $this->as($this->co->owner)->postJson($url, ['counted_amount' => 95, 'notes' => 'Vuelto mal dado'])->assertOk()->json();

    expect(money($cierre['difference']))->toBe(-5.0)
        ->and($cierre['closing_notes'])->toBe('Vuelto mal dado');
});

it('respeta la tolerancia que la empresa configura para el cierre', function () {
    $this->setRules($this->co->tenant, ['cash.tolerance' => 2]);
    $turno = $this->openCash($this->co, 100);

    $this->as($this->co->owner)
        ->postJson("/api/cash/sessions/{$turno['id']}/close", ['counted_amount' => 98.5])
        ->assertOk();
});

it('un turno cerrado ya no recibe movimientos', function () {
    $turno = $this->openCash($this->co, 0);
    $this->as($this->co->owner)->postJson("/api/cash/sessions/{$turno['id']}/close", ['counted_amount' => 0])->assertOk();

    $this->as($this->co->owner)
        ->postJson("/api/cash/sessions/{$turno['id']}/movements", ['type' => 'income', 'amount' => 5, 'description' => 'Tarde'])
        ->assertStatus(409);
});

it('el cierre queda en la auditoría', function () {
    $turno = $this->openCash($this->co, 10);
    $this->as($this->co->owner)->postJson("/api/cash/sessions/{$turno['id']}/close", ['counted_amount' => 10])->assertOk();

    expect(DB::table('audit_events')->where('tenant_id', $this->co->tenant->id)->whereIn('action', ['cash.opened', 'cash.closed'])->count())->toBe(2);
});
