<?php

use App\Support\Access;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);
    $this->as($this->co->owner)->postJson('/api/products', ['name' => 'Shampoo', 'price' => 30, 'cost' => 10, 'kind' => 'both', 'min_stock' => 3])->assertCreated();
    $this->p = DB::table('products')->where('tenant_id', $this->co->tenant->id)->where('name', 'Shampoo')->first();
    $this->as($this->co->owner)->getJson('/api/inventory')->assertOk();
    $this->w = DB::table('warehouses')->where('tenant_id', $this->co->tenant->id)->where('branch_id', $this->co->branch->id)->value('id');
    $this->pendiente = fn ($user, string $key) => collect($this->as($user)->getJson('/api/dashboard')->assertOk()->json('pending'))->firstWhere('key', $key);
});

it('el aviso de stock bajo aparece en el panel de Inicio y se va al reponer', function () {
    $this->as($this->co->owner)->postJson('/api/inventory/movements', ['mode' => 'entry', 'warehouse_id' => $this->w, 'product_id' => $this->p->id, 'quantity' => 2])->assertCreated();

    expect(($this->pendiente)($this->co->owner, 'low_stock')['count'])->toBe(1);

    $this->as($this->co->owner)->postJson('/api/inventory/movements', ['mode' => 'entry', 'warehouse_id' => $this->w, 'product_id' => $this->p->id, 'quantity' => 10])->assertCreated();

    expect(($this->pendiente)($this->co->owner, 'low_stock')['count'])->toBe(0);
});

it('el conteo con diferencia pide motivo y el profesional no puede ajustar', function () {
    $this->as($this->co->owner)->postJson('/api/inventory/movements', ['mode' => 'entry', 'warehouse_id' => $this->w, 'product_id' => $this->p->id, 'quantity' => 8])->assertCreated();

    $this->as($this->co->owner)->postJson('/api/inventory/movements', ['mode' => 'count', 'warehouse_id' => $this->w, 'product_id' => $this->p->id, 'quantity' => 8])->assertCreated();
    $this->as($this->co->owner)->postJson('/api/inventory/movements', ['mode' => 'count', 'warehouse_id' => $this->w, 'product_id' => $this->p->id, 'quantity' => 5])
        ->assertStatus(422)->assertJsonValidationErrors('reason');

    $pro = $this->professional($this->co, 'Pía Profesional');
    $this->as($pro->user)->postJson('/api/inventory/movements', ['mode' => 'count', 'warehouse_id' => $this->w, 'product_id' => $this->p->id, 'quantity' => 5, 'reason' => 'Se rompió'])->assertForbidden();
});

it('los montos del panel se ocultan a quien no tiene permiso de ventas ni de reportes', function () {
    $recepcion = $this->staff($this->co, 'reception');
    expect($this->as($recepcion)->getJson('/api/dashboard')->assertOk()->json('money'))->toBeTrue();

    $role = DB::table('user_roles')->where('user_id', $recepcion->id)->value('role_id');
    $ids = Access::permissionIds();
    DB::table('role_permissions')->where('role_id', $role)->whereIn('permission_id', [$ids['sales.view'], $ids['reports.money']])->delete();

    $panel = $this->as(\App\Models\User::find($recepcion->id))->getJson('/api/dashboard')->assertOk()->json();

    expect($panel['money'])->toBeFalse()
        ->and($panel['today']['value'])->toBeNull()
        ->and($panel['month']['value'])->toBeNull()
        ->and($this->as($this->co->owner)->getJson('/api/dashboard')->json('month.value'))->not->toBeNull();
});
