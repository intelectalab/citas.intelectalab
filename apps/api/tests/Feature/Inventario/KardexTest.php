<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);
    $this->as($this->co->owner)->postJson('/api/products', ['name' => 'Crema kárdex', 'price' => 40, 'cost' => 15, 'kind' => 'both', 'min_stock' => 1])->assertCreated();
    $this->p = DB::table('products')->where('tenant_id', $this->co->tenant->id)->where('name', 'Crema kárdex')->first();
    $this->tablero = fn ($user = null) => $this->as($user ?? $this->co->owner)->getJson('/api/inventory')->assertOk()->json();
    $this->almacen = function (?string $branchId = null) {
        ($this->tablero)();

        return DB::table('warehouses')->where('tenant_id', $this->co->tenant->id)->where('branch_id', $branchId ?? $this->co->branch->id)->value('id');
    };
    $this->mover = fn (array $datos) => $this->as($this->co->owner)->postJson('/api/inventory/movements', $datos + ['product_id' => $this->p->id])->assertCreated();
});

it('el kárdex muestra cada entrada, venta y ajuste con el saldo que quedó', function () {
    $w = ($this->almacen)();
    ($this->mover)(['mode' => 'entry', 'warehouse_id' => $w, 'quantity' => 10, 'unit_cost' => 15]);
    $this->openCash($this->co);
    $venta = $this->sell($this->co, [['type' => 'product', 'id' => $this->p->id, 'quantity' => 3]]);
    $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => $venta['total']])->assertOk();
    ($this->mover)(['mode' => 'count', 'warehouse_id' => $w, 'quantity' => 5, 'reason' => 'Conteo de fin de mes']);

    $kardex = collect(($this->tablero)()['movements'])->where('product_id', $this->p->id)
        ->map(fn ($m) => [$m['type'], (float) $m['quantity'], (float) $m['balance_after']])->sortByDesc(fn ($m) => $m[2])->values()->all();

    expect($kardex)->toBe([['purchase', 10.0, 10.0], ['sale', -3.0, 7.0], ['adjustment', -2.0, 5.0]])
        ->and((float) DB::table('stock_balances')->where('product_id', $this->p->id)->where('warehouse_id', $w)->value('quantity'))->toBe(5.0);

    $ventaMov = collect(($this->tablero)()['movements'])->firstWhere('type', 'sale');
    expect($ventaMov['reference_id'])->toBe($venta['id'])
        ->and($ventaMov['type_label'])->not->toBe('sale');
});

it('la persona de una sede solo ve el kárdex de su sede', function () {
    $sede2 = $this->branch($this->co);
    $w1 = ($this->almacen)();
    $w2 = ($this->almacen)($sede2->id);
    ($this->mover)(['mode' => 'entry', 'warehouse_id' => $w1, 'quantity' => 4]);
    ($this->mover)(['mode' => 'entry', 'warehouse_id' => $w2, 'quantity' => 6]);
    $recep = $this->staff($this->co, 'reception', $sede2);

    $suyos = collect(($this->tablero)($recep)['movements']);
    $todos = collect(($this->tablero)()['movements']);

    expect($suyos->pluck('warehouse_id')->unique()->values()->all())->toBe([$w2])
        ->and($todos->pluck('warehouse_id')->unique()->sort()->values()->all())->toBe(collect([$w1, $w2])->sort()->values()->all());
});

it('el kárdex de una empresa no muestra movimientos de otra', function () {
    $w = ($this->almacen)();
    ($this->mover)(['mode' => 'entry', 'warehouse_id' => $w, 'quantity' => 2]);
    $otra = $this->company(['modules_off' => []]);

    expect($this->as($otra->owner)->getJson('/api/inventory')->assertOk()->json('movements'))->toBe([]);
});
