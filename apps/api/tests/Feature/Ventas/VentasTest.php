<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company();
    $this->servicio = $this->servicePriced($this->co->tenant, 118, 'Corte de prueba');
    $this->linea = ['type' => 'service', 'id' => $this->servicio->id];
});

describe('montos', function () {
    it('con precios que incluyen IGV, separa el impuesto sin cambiar el total', function () {
        $venta = $this->sell($this->co, [$this->linea + ['quantity' => 2]]);

        expect(money($venta['total']))->toBe(236.0)
            ->and(money($this->sale($venta['id'])->tax))->toBe(36.0)
            ->and(money($venta['balance']))->toBe(236.0);
    });

    it('con precios sin IGV, suma el impuesto al total', function () {
        $this->setRules($this->co->tenant, ['pos.prices_include_tax' => false]);

        $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicePriced($this->co->tenant, 100)->id]]);

        expect(money($venta['total']))->toBe(118.0)
            ->and(money($this->sale($venta['id'])->tax))->toBe(18.0);
    });

    it('cada venta recibe un código propio y correlativo', function () {
        $a = $this->sell($this->co, [$this->linea]);
        $b = $this->sell($this->co, [$this->linea]);

        expect($a['code'])->not->toBe($b['code'])
            ->and(DB::table('sales')->where('tenant_id', $this->co->tenant->id)->count())->toBe(2);
    });
});

describe('cobro', function () {
    it('no acepta pagos parciales si la empresa no los permite', function () {
        $venta = $this->sell($this->co, [$this->linea]);

        $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => 50])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        expect($this->sale($venta['id'])->status)->toBe('pending');
    });

    it('con pagos parciales, queda en parcial y se completa con el segundo pago', function () {
        $this->setRules($this->co->tenant, ['pos.partial_payments' => true]);
        $venta = $this->sell($this->co, [$this->linea]);

        $this->pay($this->co, $venta['id'], ['method' => 'yape', 'amount' => 50])->assertOk();
        $parcial = $this->sale($venta['id']);

        expect($parcial->status)->toBe('partial')
            ->and(money($parcial->balance))->toBe(68.0)
            ->and(DB::table('invoices')->where('sale_id', $venta['id'])->exists())->toBeFalse();

        $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => 68])->assertOk();

        expect($this->sale($venta['id'])->status)->toBe('paid')
            ->and(DB::table('invoices')->where('sale_id', $venta['id'])->count())->toBe(1);
    });

    it('no deja cobrar más que el saldo', function () {
        $venta = $this->sell($this->co, [$this->linea]);

        $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => 200])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    });

    it('una venta pagada no admite otro cobro', function () {
        $venta = $this->sell($this->co, [$this->linea]);
        $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => 118])->assertOk();

        $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        expect(DB::table('payments')->where('sale_id', $venta['id'])->count())->toBe(1);
    });

    it('si el mismo cobro llega dos veces (doble clic), se registra una sola vez', function () {
        $venta = $this->sell($this->co, [$this->linea]);
        $datos = ['method' => 'card', 'amount' => 118, 'reference' => 'VOUCHER-1', 'idempotency_key' => (string) Str::uuid()];

        $this->pay($this->co, $venta['id'], $datos)->assertOk()->assertJsonPath('duplicate', false);
        $this->pay($this->co, $venta['id'], $datos)->assertOk()->assertJsonPath('duplicate', true);

        expect(DB::table('payments')->where('sale_id', $venta['id'])->count())->toBe(1);
    });
});

describe('comprobantes', function () {
    it('al pagar todo emite una boleta con número correlativo', function () {
        $a = $this->sell($this->co, [$this->linea]);
        $b = $this->sell($this->co, [$this->linea]);
        $this->pay($this->co, $a['id'], ['method' => 'card', 'amount' => 118])->assertOk();
        $this->pay($this->co, $b['id'], ['method' => 'card', 'amount' => 118])->assertOk();

        $boletas = DB::table('invoices')->where('tenant_id', $this->co->tenant->id)->orderBy('number')->get();

        expect($boletas)->toHaveCount(2)
            ->and($boletas->pluck('doc_type')->unique()->all())->toBe(['boleta'])
            ->and($boletas->pluck('serie')->unique()->all())->toBe(['B001'])
            ->and($boletas->pluck('number')->map(fn ($n) => (int) $n)->all())->toBe([1, 2])
            ->and(money($boletas[0]->tax))->toBe(18.0)
            ->and(money($boletas[0]->taxable))->toBe(100.0);
    });

    it('la factura exige un RUC válido y la razón social', function () {
        $venta = $this->sell($this->co, [$this->linea]);

        $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => 118, 'doc_type' => 'factura', 'ruc' => '123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ruc');

        $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => 118, 'doc_type' => 'factura', 'ruc' => '20123456789'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('business_name');

        $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => 118, 'doc_type' => 'factura', 'ruc' => '20123456789', 'business_name' => 'Cliente SAC'])
            ->assertOk();

        $factura = DB::table('invoices')->where('sale_id', $venta['id'])->first();

        expect($factura->doc_type)->toBe('factura')
            ->and($factura->serie)->toBe('F001')
            ->and($factura->customer_doc_number)->toBe('20123456789');
    });

    it('sin el módulo de facturación emite un ticket interno', function () {
        $co = $this->company(['modules_off' => ['invoicing']]);
        $linea = ['type' => 'service', 'id' => $this->servicePriced($co->tenant, 50)->id];
        $venta = $this->sell($co, [$linea]);

        $this->pay($co, $venta['id'], ['method' => 'card', 'amount' => 50])->assertOk();

        expect(DB::table('invoices')->where('sale_id', $venta['id'])->value('doc_type'))->toBe('ticket');
    });
});

describe('cupones', function () {
    function cupon(string $tenantId, array $datos = []): object
    {
        $id = (string) Str::uuid();
        DB::table('coupons')->insert($datos + [
            'id' => $id, 'tenant_id' => $tenantId, 'code' => 'PRUEBA10', 'discount_type' => 'percent', 'discount_value' => 10,
            'min_purchase' => 0, 'used_count' => 0, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('coupons')->where('id', $id)->first();
    }

    it('aplica un descuento por porcentaje y cuenta el uso', function () {
        $cupon = cupon($this->co->tenant->id);

        $venta = $this->sell($this->co, [$this->linea], ['coupon_code' => 'prueba10']);

        expect(money($venta['total']))->toBe(106.2)
            ->and((int) DB::table('coupons')->where('id', $cupon->id)->value('used_count'))->toBe(1);
    });

    it('aplica un descuento por monto fijo', function () {
        cupon($this->co->tenant->id, ['code' => 'MENOS20', 'discount_type' => 'amount', 'discount_value' => 20]);

        expect(money($this->sell($this->co, [$this->linea], ['coupon_code' => 'MENOS20'])['total']))->toBe(98.0);
    });

    it('rechaza cupones vencidos, agotados, desactivados o con compra mínima', function (array $datos, string $mensaje) {
        cupon($this->co->tenant->id, $datos);

        $this->as($this->co->owner)
            ->postJson('/api/pos/sales', ['branch_id' => $this->co->branch->id, 'items' => [$this->linea], 'coupon_code' => 'PRUEBA10'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['coupon_code' => $mensaje]);
    })->with([
        'vencido'      => [['ends_at' => now()->subDay()], 'venció'],
        'agotado'      => [['max_uses' => 1, 'used_count' => 1], 'agotó'],
        'desactivado'  => [['status' => 'inactive'], 'desactivado'],
        'compra mínima' => [['min_purchase' => 500], 'compra mínima'],
    ]);

    it('no acepta un cupón que dejaría la venta en cero', function () {
        cupon($this->co->tenant->id, ['code' => 'TODO', 'discount_type' => 'amount', 'discount_value' => 500]);

        $this->as($this->co->owner)
            ->postJson('/api/pos/sales', ['branch_id' => $this->co->branch->id, 'items' => [$this->linea], 'coupon_code' => 'TODO'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('coupon_code');
    });

    it('el cupón de una empresa no sirve en otra', function () {
        cupon($this->co->tenant->id, ['code' => 'SOLOMIA']);
        $otra = $this->company();
        $linea = ['type' => 'service', 'id' => $this->servicePriced($otra->tenant, 118)->id];

        $this->as($otra->owner)
            ->postJson('/api/pos/sales', ['branch_id' => $otra->branch->id, 'items' => [$linea], 'coupon_code' => 'SOLOMIA'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['coupon_code' => 'No existe']);
    });

    it('al anular la venta, el cupón recupera su uso', function () {
        $cupon = cupon($this->co->tenant->id, ['max_uses' => 1]);
        $venta = $this->sell($this->co, [$this->linea], ['coupon_code' => 'PRUEBA10']);

        $this->as($this->co->owner)->postJson("/api/sales/{$venta['id']}/void", ['reason' => 'Se equivocó de servicio'])->assertOk();

        expect((int) DB::table('coupons')->where('id', $cupon->id)->value('used_count'))->toBe(0)
            ->and(DB::table('coupon_redemptions')->where('sale_id', $venta['id'])->exists())->toBeFalse();
    });
});

describe('anular', function () {
    it('anula una venta sin cobrar y pide el motivo', function () {
        $venta = $this->sell($this->co, [$this->linea]);
        $url = "/api/sales/{$venta['id']}/void";

        $this->as($this->co->owner)->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->as($this->co->owner)->postJson($url, ['reason' => 'No vino'])->assertOk();

        $fila = $this->sale($venta['id']);

        expect($fila->status)->toBe('void')
            ->and(money($fila->balance))->toBe(0.0)
            ->and($fila->void_reason)->toBe('No vino');
    });

    it('una venta con dinero cobrado no se anula: se devuelve', function () {
        $venta = $this->sell($this->co, [$this->linea]);
        $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => 118])->assertOk();

        $this->as($this->co->owner)
            ->postJson("/api/sales/{$venta['id']}/void", ['reason' => 'Error'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason' => 'se devuelve']);
    });

    it('una venta anulada no se puede cobrar', function () {
        $venta = $this->sell($this->co, [$this->linea]);
        $this->as($this->co->owner)->postJson("/api/sales/{$venta['id']}/void", ['reason' => 'No vino'])->assertOk();

        $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => 118])->assertStatus(422);
    });
});

describe('devolver', function () {
    beforeEach(function () {
        $this->turno = $this->openCash($this->co, 0);
        $this->venta = $this->sell($this->co, [$this->linea]);
        $this->pay($this->co, $this->venta['id'], ['method' => 'cash', 'amount' => 118])->assertOk();
        $this->url = "/api/sales/{$this->venta['id']}/refunds";
    });

    it('devuelve una parte en efectivo, la saca de la caja y emite nota de crédito', function () {
        $this->as($this->co->owner)->postJson($this->url, ['amount' => 18, 'method' => 'cash', 'reason' => 'Corte incompleto'])->assertOk();

        $fila = $this->sale($this->venta['id']);
        $nota = DB::table('invoices')->where('sale_id', $this->venta['id'])->where('doc_type', 'nota_credito')->first();

        expect($fila->status)->toBe('partially_refunded')
            ->and(money($fila->paid))->toBe(100.0)
            ->and(money($this->cashSession($this->turno['id'])['expected_amount']))->toBe(100.0)
            ->and($nota)->not->toBeNull()
            ->and(money($nota->total))->toBe(18.0)
            ->and($nota->serie)->toBe('BC01');
    });

    it('devolver todo deja la venta como devuelta', function () {
        $this->as($this->co->owner)->postJson($this->url, ['amount' => 118, 'method' => 'cash', 'reason' => 'No le gustó'])->assertOk();

        expect($this->sale($this->venta['id'])->status)->toBe('refunded')
            ->and(money(DB::table('payments')->where('sale_id', $this->venta['id'])->sum('amount')))->toBe(0.0);
    });

    it('no devuelve más de lo cobrado, ni dos veces lo mismo', function () {
        $this->as($this->co->owner)->postJson($this->url, ['amount' => 200, 'method' => 'card', 'reason' => 'x'])->assertStatus(422);
        $this->as($this->co->owner)->postJson($this->url, ['amount' => 100, 'method' => 'card', 'reason' => 'x'])->assertOk();
        $this->as($this->co->owner)->postJson($this->url, ['amount' => 20, 'method' => 'card', 'reason' => 'x'])->assertStatus(422);
        $this->as($this->co->owner)->postJson($this->url, ['amount' => 18, 'method' => 'card', 'reason' => 'x'])->assertOk();
        $this->as($this->co->owner)->postJson($this->url, ['amount' => 1, 'method' => 'card', 'reason' => 'x'])->assertStatus(422);

        expect(money(DB::table('sale_refunds')->where('sale_id', $this->venta['id'])->sum('amount')))->toBe(118.0);
    });

    it('no devuelve en efectivo más de lo que hay en la caja', function () {
        $this->as($this->co->owner)
            ->postJson("/api/cash/sessions/{$this->turno['id']}/movements", ['type' => 'withdrawal', 'amount' => 100, 'description' => 'Al banco'])
            ->assertCreated();

        $this->as($this->co->owner)->postJson($this->url, ['amount' => 50, 'method' => 'cash', 'reason' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    });

    it('respeta el plazo de días para devolver', function () {
        DB::table('sales')->where('id', $this->venta['id'])->update(['created_at' => now()->subDays(31)]);

        $this->as($this->co->owner)->postJson($this->url, ['amount' => 10, 'method' => 'card', 'reason' => 'Tarde'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount' => 'plazo']);
    });
});

describe('cada empresa ve solo lo suyo', function () {
    it('otra empresa no puede ver, cobrar, anular ni devolver mis ventas', function () {
        $venta = $this->sell($this->co, [$this->linea]);
        $otra = $this->company();

        $this->as($otra->owner)->getJson("/api/sales/{$venta['id']}")->assertNotFound();
        $this->as($otra->owner)->postJson("/api/sales/{$venta['id']}/pay", ['method' => 'card', 'amount' => 118, 'reference' => 'X'])->assertNotFound();
        $this->as($otra->owner)->postJson("/api/sales/{$venta['id']}/void", ['reason' => 'x'])->assertNotFound();

        expect($this->sale($venta['id'])->status)->toBe('pending');
    });

    it('la lista de ventas solo trae las de la empresa', function () {
        $mia = $this->sell($this->co, [$this->linea]);
        $otra = $this->company();
        $suya = $this->sell($otra, [['type' => 'service', 'id' => $this->servicePriced($otra->tenant, 10)->id]]);

        $ids = collect($this->as($otra->owner)->getJson('/api/sales')->assertOk()->json('sales'))->pluck('id');

        expect($ids->all())->toBe([$suya['id']])
            ->and($ids)->not->toContain($mia['id']);
    });
});
