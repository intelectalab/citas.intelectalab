<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);

    $this->producto = function (array $datos = [], ?object $co = null) {
        $co ??= $this->co;
        $this->as($co->owner)->postJson('/api/products', $datos + [
            'name' => 'Shampoo ' . Str::random(4), 'price' => 30, 'cost' => 10, 'kind' => 'both', 'min_stock' => 2,
        ])->assertCreated();

        return DB::table('products')->where('tenant_id', $co->tenant->id)->orderByDesc('created_at')->first();
    };

    $this->almacen = function (?string $branchId = null, ?object $co = null) {
        $co ??= $this->co;
        $this->as($co->owner)->getJson('/api/inventory')->assertOk();

        return DB::table('warehouses')->where('tenant_id', $co->tenant->id)->where('branch_id', $branchId ?? $co->branch->id)->value('id');
    };

    $this->stock = fn (string $productId, string $warehouseId) => (float) DB::table('stock_balances')
        ->where('product_id', $productId)->where('warehouse_id', $warehouseId)->value('quantity');

    $this->mover = fn (array $datos, $user = null) => $this->as($user ?? $this->co->owner)->postJson('/api/inventory/movements', $datos);
});

it('el ingreso suma stock, deja el movimiento y cambia el costo al último de compra', function () {
    $p = ($this->producto)();
    $w = ($this->almacen)();

    ($this->mover)(['mode' => 'entry', 'warehouse_id' => $w, 'product_id' => $p->id, 'quantity' => 10, 'unit_cost' => 12])->assertCreated();

    $mov = DB::table('inventory_movements')->where('product_id', $p->id)->first();

    expect(($this->stock)($p->id, $w))->toBe(10.0)
        ->and($mov->type)->toBe('purchase')
        ->and((float) $mov->balance_after)->toBe(10.0)
        ->and(money(DB::table('products')->where('id', $p->id)->value('cost')))->toBe(12.0);
});

it('con costo promedio pondera el stock previo con lo que entra', function () {
    $this->setRules($this->co->tenant, ['inventory.cost_method' => 'average']);
    $p = ($this->producto)(['cost' => 0]);
    $w = ($this->almacen)();

    ($this->mover)(['mode' => 'entry', 'warehouse_id' => $w, 'product_id' => $p->id, 'quantity' => 10, 'unit_cost' => 10])->assertCreated();
    ($this->mover)(['mode' => 'entry', 'warehouse_id' => $w, 'product_id' => $p->id, 'quantity' => 10, 'unit_cost' => 20])->assertCreated();

    expect(money(DB::table('products')->where('id', $p->id)->value('cost')))->toBe(15.0);
});

it('la salida pide motivo y no deja el stock en negativo salvo que la empresa lo permita', function () {
    $p = ($this->producto)();
    $w = ($this->almacen)();
    ($this->mover)(['mode' => 'entry', 'warehouse_id' => $w, 'product_id' => $p->id, 'quantity' => 3])->assertCreated();

    ($this->mover)(['mode' => 'exit', 'warehouse_id' => $w, 'product_id' => $p->id, 'quantity' => 1])->assertStatus(422)->assertJsonValidationErrors('reason');
    ($this->mover)(['mode' => 'exit', 'warehouse_id' => $w, 'product_id' => $p->id, 'quantity' => 5, 'reason' => 'Rotura'])->assertStatus(422)->assertJsonValidationErrors('quantity');
    expect(($this->stock)($p->id, $w))->toBe(3.0);

    $this->setRules($this->co->tenant, ['inventory.allow_negative' => true]);
    ($this->mover)(['mode' => 'exit', 'warehouse_id' => $w, 'product_id' => $p->id, 'quantity' => 5, 'reason' => 'Rotura'])->assertCreated();
    expect(($this->stock)($p->id, $w))->toBe(-2.0);
});

it('el conteo físico ajusta a lo contado y si no hay diferencia no crea movimiento', function () {
    $p = ($this->producto)();
    $w = ($this->almacen)();
    ($this->mover)(['mode' => 'entry', 'warehouse_id' => $w, 'product_id' => $p->id, 'quantity' => 8])->assertCreated();

    ($this->mover)(['mode' => 'count', 'warehouse_id' => $w, 'product_id' => $p->id, 'quantity' => 6])->assertStatus(422)->assertJsonValidationErrors('reason');
    ($this->mover)(['mode' => 'count', 'warehouse_id' => $w, 'product_id' => $p->id, 'quantity' => 6, 'reason' => 'Inventario mensual: faltan dos'])->assertCreated();
    $ajuste = DB::table('inventory_movements')->where('product_id', $p->id)->where('type', 'adjustment')->first();
    expect(($this->stock)($p->id, $w))->toBe(6.0)->and((float) $ajuste->quantity)->toBe(-2.0);

    ($this->mover)(['mode' => 'count', 'warehouse_id' => $w, 'product_id' => $p->id, 'quantity' => 6])->assertCreated();
    expect(DB::table('inventory_movements')->where('product_id', $p->id)->count())->toBe(2);
});

it('al bajar del mínimo avisa a la dueña', function () {
    $p = ($this->producto)(['min_stock' => 2]);
    $w = ($this->almacen)();
    ($this->mover)(['mode' => 'entry', 'warehouse_id' => $w, 'product_id' => $p->id, 'quantity' => 3])->assertCreated();
    ($this->mover)(['mode' => 'exit', 'warehouse_id' => $w, 'product_id' => $p->id, 'quantity' => 1, 'reason' => 'Uso interno'])->assertCreated();

    $tablero = $this->as($this->co->owner)->getJson('/api/inventory')->assertOk()->json('products');
    $fila = collect($tablero)->firstWhere('id', $p->id);

    expect(DB::table('user_notifications')->where('user_id', $this->co->owner->id)->where('title', 'Stock bajo el mínimo')->exists())->toBeTrue()
        ->and($fila['low_stock'])->toBeTrue()
        ->and(money($fila['stock_value']))->toBe(20.0);
});

it('vender un producto descuenta stock de la sede y devolverlo lo repone', function () {
    $p = ($this->producto)();
    $w = ($this->almacen)();
    ($this->mover)(['mode' => 'entry', 'warehouse_id' => $w, 'product_id' => $p->id, 'quantity' => 5])->assertCreated();
    $this->openCash($this->co);

    $venta = $this->sell($this->co, [['type' => 'product', 'id' => $p->id, 'quantity' => 2]]);
    $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => $venta['total']])->assertOk();
    expect(($this->stock)($p->id, $w))->toBe(3.0);

    \App\Support\Inventory::returnForSale($this->co->tenant, $this->sale($venta['id']), $this->co->owner, 'prueba');
    expect(($this->stock)($p->id, $w))->toBe(5.0);
});

it('no deja vender más unidades de las que hay en la sede', function () {
    $p = ($this->producto)();
    $w = ($this->almacen)();
    ($this->mover)(['mode' => 'entry', 'warehouse_id' => $w, 'product_id' => $p->id, 'quantity' => 1])->assertCreated();
    $this->openCash($this->co);

    $this->as($this->co->owner)->postJson('/api/pos/sales', ['branch_id' => $this->co->branch->id, 'items' => [['type' => 'product', 'id' => $p->id, 'quantity' => 3]]])
        ->assertStatus(422);
});

it('el traslado en dos pasos deja la mercadería en tránsito hasta que la otra sede la recibe', function () {
    $sede2 = $this->branch($this->co);
    $p = ($this->producto)();
    $origen = ($this->almacen)();
    $destino = ($this->almacen)($sede2->id);
    ($this->mover)(['mode' => 'entry', 'warehouse_id' => $origen, 'product_id' => $p->id, 'quantity' => 10])->assertCreated();

    $this->as($this->co->owner)->postJson('/api/inventory/transfers', ['from_warehouse_id' => $origen, 'to_warehouse_id' => $destino, 'items' => [['product_id' => $p->id, 'quantity' => 4]]])->assertCreated();
    $traslado = DB::table('stock_transfers')->where('tenant_id', $this->co->tenant->id)->first();

    expect($traslado->status)->toBe('in_transit')
        ->and(($this->stock)($p->id, $origen))->toBe(6.0)
        ->and((float) DB::table('stock_balances')->where('warehouse_id', $destino)->where('product_id', $p->id)->value('in_transit'))->toBe(4.0);

    $this->as($this->co->owner)->postJson("/api/inventory/transfers/{$traslado->id}/receive", ['items' => [['product_id' => $p->id, 'received_quantity' => 3]]])->assertOk();

    expect(DB::table('stock_transfers')->where('id', $traslado->id)->value('status'))->toBe('received')
        ->and(($this->stock)($p->id, $destino))->toBe(3.0)
        ->and(($this->stock)($p->id, $origen))->toBe(7.0)
        ->and((float) DB::table('stock_balances')->where('warehouse_id', $destino)->where('product_id', $p->id)->value('in_transit'))->toBe(0.0);

    $this->as($this->co->owner)->postJson("/api/inventory/transfers/{$traslado->id}/receive")->assertStatus(422);
    $this->as($this->co->owner)->postJson("/api/inventory/transfers/{$traslado->id}/cancel")->assertStatus(422);
});

it('cancelar un traslado en tránsito devuelve todo al origen; sin dos pasos llega al instante', function () {
    $sede2 = $this->branch($this->co);
    $p = ($this->producto)();
    $origen = ($this->almacen)();
    $destino = ($this->almacen)($sede2->id);
    ($this->mover)(['mode' => 'entry', 'warehouse_id' => $origen, 'product_id' => $p->id, 'quantity' => 10])->assertCreated();

    $this->as($this->co->owner)->postJson('/api/inventory/transfers', ['from_warehouse_id' => $origen, 'to_warehouse_id' => $destino, 'items' => [['product_id' => $p->id, 'quantity' => 4]]])->assertCreated();
    $id = DB::table('stock_transfers')->where('tenant_id', $this->co->tenant->id)->value('id');
    $this->as($this->co->owner)->postJson("/api/inventory/transfers/{$id}/cancel")->assertOk();
    expect(($this->stock)($p->id, $origen))->toBe(10.0);

    $this->setRules($this->co->tenant, ['inventory.transfer_two_step' => false]);
    $this->as($this->co->owner)->postJson('/api/inventory/transfers', ['from_warehouse_id' => $origen, 'to_warehouse_id' => $destino, 'items' => [['product_id' => $p->id, 'quantity' => 4]]])->assertCreated();
    expect(($this->stock)($p->id, $destino))->toBe(4.0)->and(($this->stock)($p->id, $origen))->toBe(6.0);

    $this->as($this->co->owner)->postJson('/api/inventory/transfers', ['from_warehouse_id' => $origen, 'to_warehouse_id' => $destino, 'items' => [['product_id' => $p->id, 'quantity' => 40]]])->assertStatus(422);
    $this->as($this->co->owner)->postJson('/api/inventory/transfers', ['from_warehouse_id' => $origen, 'to_warehouse_id' => $origen, 'items' => [['product_id' => $p->id, 'quantity' => 1]]])->assertStatus(422);
});

it('la orden de compra se recibe por partes, actualiza stock y costo, y solo se cancela sin ingresos', function () {
    $p = ($this->producto)(['cost' => 0]);
    $w = ($this->almacen)();
    $this->as($this->co->owner)->postJson('/api/inventory/suppliers', ['name' => 'Distribuidora Sol'])->assertCreated();
    $prov = DB::table('suppliers')->where('tenant_id', $this->co->tenant->id)->value('id');

    $this->as($this->co->owner)->postJson('/api/inventory/purchases', ['supplier_id' => $prov, 'warehouse_id' => $w, 'items' => [['product_id' => $p->id, 'quantity' => 10, 'unit_cost' => 7.5]]])->assertCreated();
    $orden = DB::table('purchase_orders')->where('tenant_id', $this->co->tenant->id)->first();
    $linea = DB::table('purchase_order_items')->where('purchase_order_id', $orden->id)->first();

    expect($orden->status)->toBe('ordered')->and(money($orden->total))->toBe(75.0)->and(($this->stock)($p->id, $w))->toBe(0.0);

    $this->as($this->co->owner)->postJson("/api/inventory/purchases/{$orden->id}/receive", ['items' => [['id' => $linea->id, 'receive_quantity' => 4]]])->assertOk();
    expect(DB::table('purchase_orders')->where('id', $orden->id)->value('status'))->toBe('partial')
        ->and(($this->stock)($p->id, $w))->toBe(4.0)
        ->and(money(DB::table('products')->where('id', $p->id)->value('cost')))->toBe(7.5);

    $this->as($this->co->owner)->postJson("/api/inventory/purchases/{$orden->id}/cancel")->assertStatus(422);
    $this->as($this->co->owner)->postJson("/api/inventory/purchases/{$orden->id}/receive", ['items' => [['id' => $linea->id, 'receive_quantity' => 7]]])->assertStatus(422);
    $this->as($this->co->owner)->postJson("/api/inventory/purchases/{$orden->id}/receive", ['items' => [['id' => $linea->id, 'receive_quantity' => 6]]])->assertOk();
    expect(DB::table('purchase_orders')->where('id', $orden->id)->value('status'))->toBe('received');

    $this->as($this->co->owner)->postJson('/api/inventory/purchases', ['supplier_id' => $prov, 'warehouse_id' => $w, 'items' => [['product_id' => $p->id, 'quantity' => 1, 'unit_cost' => 1]]])->assertCreated();
    $otra = DB::table('purchase_orders')->where('tenant_id', $this->co->tenant->id)->where('status', 'ordered')->value('id');
    $this->as($this->co->owner)->postJson("/api/inventory/purchases/{$otra}/cancel")->assertOk();
    expect(DB::table('purchase_orders')->where('id', $otra)->value('status'))->toBe('cancelled');
});

it('un proveedor con compras se desactiva en vez de borrarse', function () {
    $p = ($this->producto)();
    $w = ($this->almacen)();
    $this->as($this->co->owner)->postJson('/api/inventory/suppliers', ['name' => 'Con compras'])->assertCreated();
    $this->as($this->co->owner)->postJson('/api/inventory/suppliers', ['name' => 'Sin compras'])->assertCreated();
    $con = DB::table('suppliers')->where('name', 'Con compras')->value('id');
    $sin = DB::table('suppliers')->where('name', 'Sin compras')->value('id');
    $this->as($this->co->owner)->postJson('/api/inventory/purchases', ['supplier_id' => $con, 'warehouse_id' => $w, 'receive_now' => true, 'items' => [['product_id' => $p->id, 'quantity' => 2, 'unit_cost' => 5]]])->assertCreated();

    $this->as($this->co->owner)->deleteJson("/api/inventory/suppliers/{$con}")->assertOk();
    $this->as($this->co->owner)->deleteJson("/api/inventory/suppliers/{$sin}")->assertOk();

    expect(DB::table('suppliers')->where('id', $con)->value('status'))->toBe('inactive')
        ->and(DB::table('suppliers')->where('id', $sin)->exists())->toBeFalse()
        ->and(($this->stock)($p->id, $w))->toBe(2.0);
});

it('los insumos del servicio se descuentan al terminar la cita', function () {
    $p = ($this->producto)(['kind' => 'supply']);
    $w = ($this->almacen)();
    ($this->mover)(['mode' => 'entry', 'warehouse_id' => $w, 'product_id' => $p->id, 'quantity' => 5])->assertCreated();
    $servicio = $this->servicePriced($this->co->tenant, 40, 'Tinte');
    $this->as($this->co->owner)->putJson("/api/inventory/services/{$servicio->id}/supplies", ['supplies' => [['product_id' => $p->id, 'quantity' => 1.5]]])->assertOk();

    $pro = $this->professional($this->co);
    $clienta = $this->customer($this->co->tenant);
    $this->travelTo($this->workday($this->co->tenant)->setTime(10, 0));
    $cita = $this->book($this->co, $clienta, $servicio, $pro, $this->at($this->co->tenant, '10:00'))->assertCreated()->json('id');
    DB::table('appointments')->where('id', $cita)->update(['status' => 'in_progress']);
    $this->as($this->co->owner)->postJson("/api/appointments/{$cita}/status", ['status' => 'completed']);

    expect(DB::table('appointments')->where('id', $cita)->value('status'))->toBe('completed')
        ->and(($this->stock)($p->id, $w))->toBe(3.5);
});

it('respeta permisos: profesional no entra, recepción ingresa pero no cuenta ni cancela', function () {
    $p = ($this->producto)();
    $w = ($this->almacen)();
    $recep = $this->staff($this->co, 'reception');
    $pro = $this->professional($this->co);

    $this->as($pro->user)->getJson('/api/inventory')->assertForbidden();
    $this->as($recep)->getJson('/api/inventory')->assertOk();
    ($this->mover)(['mode' => 'entry', 'warehouse_id' => $w, 'product_id' => $p->id, 'quantity' => 2], $recep)->assertCreated();
    ($this->mover)(['mode' => 'count', 'warehouse_id' => $w, 'product_id' => $p->id, 'quantity' => 0], $recep)->assertForbidden();
    expect(($this->stock)($p->id, $w))->toBe(2.0);
});

it('recepción de una sede no mueve stock de otra sede', function () {
    $sede2 = $this->branch($this->co);
    $p = ($this->producto)();
    $w2 = ($this->almacen)($sede2->id);
    $recep = $this->staff($this->co, 'reception');

    ($this->mover)(['mode' => 'entry', 'warehouse_id' => $w2, 'product_id' => $p->id, 'quantity' => 2], $recep)->assertForbidden();
    expect(($this->stock)($p->id, $w2))->toBe(0.0);
});

it('no se puede mover stock con productos ni almacenes de otra empresa', function () {
    $otra = $this->company(['modules_off' => []]);
    $ajeno = ($this->producto)([], $otra);
    $wAjeno = ($this->almacen)(null, $otra);
    $mio = ($this->producto)();
    $w = ($this->almacen)();

    ($this->mover)(['mode' => 'entry', 'warehouse_id' => $w, 'product_id' => $ajeno->id, 'quantity' => 2])->assertStatus(422);
    ($this->mover)(['mode' => 'entry', 'warehouse_id' => $wAjeno, 'product_id' => $mio->id, 'quantity' => 2])->assertStatus(422);

    $productos = collect($this->as($this->co->owner)->getJson('/api/inventory')->json('products'))->pluck('id');
    expect($productos)->not->toContain($ajeno->id)
        ->and(DB::table('inventory_movements')->count())->toBe(0);
});

it('con el módulo apagado no se puede entrar al inventario', function () {
    DB::table('tenant_settings')->where('tenant_id', $this->co->tenant->id)->update(['modules_off' => json_encode(['inventory'])]);
    $this->as($this->co->owner)->getJson('/api/inventory')->assertForbidden();
});
