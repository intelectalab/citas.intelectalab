<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);
    $this->reporte = fn (array $query = [], $user = null) => $this->as($user ?? $this->co->owner)->getJson('/api/reports?' . http_build_query($query));
});

it('caja: suma lo cobrado en efectivo, ingresos, gastos y faltantes del periodo', function () {
    $servicio = $this->servicePriced($this->co->tenant, 100, 'Corte caja');
    $turno = $this->openCash($this->co, 100);
    $venta = $this->sell($this->co, [['type' => 'service', 'id' => $servicio->id]]);
    $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => $venta['total']])->assertOk();
    $url = "/api/cash/sessions/{$turno['id']}/movements";
    $this->as($this->co->owner)->postJson($url, ['type' => 'income', 'amount' => 20, 'description' => 'Sencillo'])->assertCreated();
    $this->as($this->co->owner)->postJson($url, ['type' => 'expense', 'amount' => 30, 'description' => 'Limpieza'])->assertCreated();
    $esperado = money($this->cashSession($turno['id'])['expected_amount']);
    $this->as($this->co->owner)->postJson("/api/cash/sessions/{$turno['id']}/close", ['counted_amount' => $esperado - 5, 'notes' => 'Vuelto mal dado'])->assertOk();

    $datos = ($this->reporte)(['tab' => 'cash'])->assertOk()->json('data');

    expect($datos['summary']['sessions'])->toBe(1)
        ->and($datos['summary']['closed'])->toBe(1)
        ->and($datos['summary']['with_difference'])->toBe(1)
        ->and(money($datos['summary']['sales']))->toBe(money($venta['total']))
        ->and(money($datos['summary']['income']))->toBe(20.0)
        ->and(money($datos['summary']['expense']))->toBe(30.0)
        ->and(money($datos['summary']['short']))->toBe(-5.0)
        ->and($datos['registers'])->toHaveCount(1)
        ->and($datos['differences'][0]['notes'])->toBe('Vuelto mal dado')
        ->and(money(collect($datos['by_day'])->sum('value')))->toBe(money($venta['total']) - 10);
});

it('caja: sin permiso de ver montos solo muestra cuántos turnos hubo', function () {
    $this->openCash($this->co, 100);
    $gerente = $this->staff($this->co, 'manager');
    DB::table('user_permissions')->insert([
        'tenant_id' => $this->co->tenant->id, 'user_id' => $gerente->id, 'granted' => false,
        'permission_id' => DB::table('permissions')->where('name', 'reports.money')->value('id'),
    ]);

    $datos = ($this->reporte)(['tab' => 'cash'], $gerente)->assertOk()->json('data');

    expect($datos['summary']['sessions'])->toBe(1)
        ->and($datos['summary']['sales'])->toBeNull()
        ->and($datos['registers'])->toBeNull();
});

it('inventario: valor del stock, productos por reponer y lo que sale', function () {
    $this->as($this->co->owner)->postJson('/api/products', ['name' => 'Shampoo', 'price' => 30, 'cost' => 10, 'kind' => 'both', 'min_stock' => 3])->assertCreated();
    $producto = DB::table('products')->where('tenant_id', $this->co->tenant->id)->where('name', 'Shampoo')->first();
    $this->as($this->co->owner)->getJson('/api/inventory')->assertOk();
    $almacen = DB::table('warehouses')->where('tenant_id', $this->co->tenant->id)->where('branch_id', $this->co->branch->id)->value('id');
    $this->as($this->co->owner)->postJson('/api/inventory/movements', ['mode' => 'entry', 'warehouse_id' => $almacen, 'product_id' => $producto->id, 'quantity' => 5])->assertCreated();
    $this->openCash($this->co, 100);
    $venta = $this->sell($this->co, [['type' => 'product', 'id' => $producto->id, 'quantity' => 3]]);
    $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => $venta['total']])->assertOk();

    $datos = ($this->reporte)(['tab' => 'inventory'])->assertOk()->json('data');

    expect($datos['summary']['products'])->toBe(1)
        ->and(money($datos['summary']['value']))->toBe(20.0)
        ->and($datos['summary']['low'])->toBe(1)
        ->and(money($datos['summary']['out_value']))->toBe(30.0)
        ->and($datos['moved'][0]['sold'])->toEqual(3)
        ->and($datos['low'][0]['name'])->toBe('Shampoo');
});

it('las pestañas de caja e inventario no aparecen si la empresa no usa esos módulos', function () {
    DB::table('tenant_settings')->where('tenant_id', $this->co->tenant->id)->update(['modules_off' => json_encode(['inventory'])]);
    self::forgetCaches();

    $respuesta = ($this->reporte)(['tab' => 'inventory'])->assertOk()->json();

    expect(collect($respuesta['tabs'])->pluck('key')->all())->toContain('cash')->not->toContain('inventory')
        ->and($respuesta['tab'])->toBe('sales');
});
