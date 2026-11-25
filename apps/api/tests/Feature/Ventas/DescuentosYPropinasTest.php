<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company();
    $this->servicio = $this->servicePriced($this->co->tenant, 100, 'Corte de prueba');
    $this->linea = ['type' => 'service', 'id' => $this->servicio->id];
    $this->caja = $this->staff($this->co, 'cashier', null, 'Cajera Prueba');
});

function descuentoVender($test, $user, array $extra)
{
    return (fn () => $this->as($user)->postJson('/api/pos/sales', ['branch_id' => $this->co->branch->id, 'items' => [$this->linea]] + $extra))->call($test);
}

describe('descuento a mano', function () {
    it('con el tope de fábrica en 0, la cajera no puede dar descuento a mano', function () {
        descuentoVender($this, $this->caja, ['manual_discount' => 10])
            ->assertStatus(422)
            ->assertJsonValidationErrors('manual_discount');
    });

    it('la cajera descuenta hasta el tope y no más', function () {
        $this->setRules($this->co->tenant, ['pos.max_discount' => 10]);

        $ok = descuentoVender($this, $this->caja, ['manual_discount' => 10, 'discount_reason' => 'Cliente frecuente'])->assertCreated();
        $venta = $this->sale($ok->json('sale.id'));

        expect(money($venta->total))->toBe(90.0)
            ->and(money($venta->manual_discount))->toBe(10.0)
            ->and($venta->discount_reason)->toBe('Cliente frecuente')
            ->and($venta->discount_by)->toBe($this->caja->id);

        descuentoVender($this, $this->caja, ['manual_discount' => 15])
            ->assertStatus(422)
            ->assertJsonValidationErrors('manual_discount');
    });

    it('quien tiene el permiso de descuento alto pasa el tope', function () {
        $gerente = $this->staff($this->co, 'manager', null, 'Gerente Prueba');

        $ok = descuentoVender($this, $gerente, ['manual_discount' => 40])->assertCreated();

        expect(money($this->sale($ok->json('sale.id'))->total))->toBe(60.0);
    });

    it('no descuenta más de lo que vale la venta', function () {
        descuentoVender($this, $this->co->owner, ['manual_discount' => 150])
            ->assertStatus(422)
            ->assertJsonValidationErrors('manual_discount');
    });

    it('al cambiar la venta por cobrar se conserva el descuento', function () {
        $venta = $this->sell($this->co, [$this->linea], ['manual_discount' => 20]);

        $cart = $this->as($this->co->owner)->getJson("/api/pos/sales/{$venta['id']}")->assertOk();

        expect(money($cart->json('manual_discount')))->toBe(20.0);
    });
});

describe('propina', function () {
    it('apagada: no se acepta', function () {
        $this->setRules($this->co->tenant, ['pos.tips_enabled' => false]);

        descuentoVender($this, $this->co->owner, ['tip' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tip');
    });

    it('encendida: se suma al total, pero no al comprobante ni al IGV', function () {
        $this->setRules($this->co->tenant, ['pos.tips_enabled' => true]);
        $this->openCash($this->co);

        $venta = $this->sell($this->co, [$this->linea], ['tip' => 5]);
        $fila = $this->sale($venta['id']);

        expect(money($fila->total))->toBe(105.0)
            ->and(money($fila->tip))->toBe(5.0)
            ->and(money($fila->tax))->toBe(15.25);

        $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => 105])->assertOk();

        $comprobante = DB::table('invoices')->where('sale_id', $venta['id'])->first();

        expect(money($comprobante->total))->toBe(100.0)
            ->and(money($comprobante->taxable) + money($comprobante->tax))->toBe(100.0);
    });
});
