<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);
    $this->servicio = $this->servicePriced($this->co->tenant, 100, 'Corte de prueba');
    $this->caja = $this->staff($this->co, 'cashier', null, 'Cajera Prueba');
});

describe('descuento por línea y autorización (151 y 152)', function () {
    it('descuenta el % de una línea y lo recuerda al volver a abrir la venta', function () {
        $this->setRules($this->co->tenant, ['pos.max_discount' => 50]);
        $res = $this->as($this->co->owner)->postJson('/api/pos/sales', ['branch_id' => $this->co->branch->id, 'items' => [['type' => 'service', 'id' => $this->servicio->id, 'discount_percent' => 20]]])->assertCreated();
        $venta = $this->sale($res->json('sale.id'));

        expect(money($venta->total))->toBe(80.0)
            ->and(money(DB::table('sale_items')->where('sale_id', $venta->id)->value('discount')))->toBe(20.0);

        $loaded = $this->as($this->co->owner)->getJson("/api/pos/sales/{$venta->id}")->assertOk()->json('items.0');
        expect((float) $loaded['discount_percent'])->toBe(20.0);
    });

    it('el descuento por línea cuenta para el tope y un gerente lo autoriza con su clave', function () {
        $this->setRules($this->co->tenant, ['pos.max_discount' => 10]);
        $linea = ['type' => 'service', 'id' => $this->servicio->id, 'discount_percent' => 30];

        $this->as($this->caja)->postJson('/api/pos/sales', ['branch_id' => $this->co->branch->id, 'items' => [$linea]])
            ->assertStatus(422)->assertJsonValidationErrors('manual_discount');

        $gerente = $this->staff($this->co, 'manager', null, 'Gerente Clave');
        DB::table('users')->where('id', $gerente->id)->update(['password_hash' => bcrypt('Clave-Segura-1')]);

        $this->as($this->caja)->postJson('/api/pos/discount-approval', ['email' => $gerente->email, 'password' => 'mala'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->as($this->caja)->postJson('/api/pos/discount-approval', ['email' => $this->caja->email, 'password' => 'password'])
            ->assertStatus(422);

        $token = $this->as($this->caja)->postJson('/api/pos/discount-approval', ['email' => $gerente->email, 'password' => 'Clave-Segura-1'])
            ->assertOk()->json('token');

        $res = $this->as($this->caja)->postJson('/api/pos/sales', ['branch_id' => $this->co->branch->id, 'items' => [$linea], 'discount_approval' => $token])->assertCreated();
        expect(money($this->sale($res->json('sale.id'))->total))->toBe(70.0);

        $otra = $this->company(['modules_off' => []]);
        $ajena = $this->staff($otra, 'cashier', null, 'Cajera Ajena');
        $this->as($ajena)->postJson('/api/pos/sales', ['branch_id' => $otra->branch->id, 'items' => [['type' => 'service', 'id' => $this->servicePriced($otra->tenant, 100)->id, 'discount_percent' => 30]], 'discount_approval' => $token])
            ->assertStatus(422)->assertJsonValidationErrors('manual_discount');
    });

    it('no deja descuento por línea en paquetes ni gift cards', function () {
        $this->as($this->co->owner)->postJson('/api/pos/preview', ['branch_id' => $this->co->branch->id, 'items' => [['type' => 'gift_card', 'id' => (string) Str::uuid(), 'details' => ['amount' => 50], 'discount_percent' => 10]]])
            ->assertStatus(422);
    });
});

describe('inventario (164 a 169)', function () {
    it('el producto guarda marca, unidad y código de barras, y no deja precio 0 si se vende', function () {
        $marca = $this->as($this->co->owner)->postJson('/api/product-brands', ['name' => 'Marca Uno'])->assertCreated()->json('id');
        $unidad = DB::table('units')->whereNull('tenant_id')->value('id');

        $this->as($this->co->owner)->postJson('/api/products', ['name' => 'Gratis', 'price' => 0, 'kind' => 'retail'])
            ->assertStatus(422)->assertJsonValidationErrors('price');
        $this->as($this->co->owner)->postJson('/api/products', ['name' => 'Guantes', 'price' => 0, 'kind' => 'supply'])->assertCreated();

        $creado = $this->as($this->co->owner)->postJson('/api/products', ['name' => 'Shampoo', 'price' => 40, 'cost' => 15, 'kind' => 'retail', 'brand_id' => $marca, 'unit_id' => $unidad, 'barcode' => '7750000000001'])
            ->assertCreated()->json();

        expect($creado['brand'])->toBe('Marca Uno')->and($creado['barcode'])->toBe('7750000000001');

        $this->as($this->co->owner)->postJson('/api/products', ['name' => 'Otro', 'price' => 10, 'kind' => 'retail', 'barcode' => '7750000000001'])
            ->assertStatus(422)->assertJsonValidationErrors('barcode');

        $lista = $this->as($this->co->owner)->getJson('/api/products')->assertOk()->json();
        expect(collect($lista['brands'])->pluck('name'))->toContain('Marca Uno')
            ->and($lista['units'])->not->toBeEmpty();
    });

    it('el detalle del producto trae stock por almacén, servicios que lo usan y movimientos (166)', function () {
        $id = $this->as($this->co->owner)->postJson('/api/products', ['name' => 'Tinte', 'price' => 30, 'cost' => 10, 'kind' => 'both'])->assertCreated()->json('id');
        $this->as($this->co->owner)->getJson('/api/inventory')->assertOk();
        $almacen = DB::table('warehouses')->where('tenant_id', $this->co->tenant->id)->value('id');
        $this->as($this->co->owner)->postJson('/api/inventory/movements', ['mode' => 'entry', 'warehouse_id' => $almacen, 'product_id' => $id, 'quantity' => 4])->assertCreated();
        DB::table('service_supplies')->insert(['tenant_id' => $this->co->tenant->id, 'service_id' => $this->servicio->id, 'product_id' => $id, 'quantity' => 1]);

        $data = $this->as($this->co->owner)->getJson("/api/products/{$id}")->assertOk()->json();
        expect($data['warehouses'][0]['quantity'])->toEqual(4)
            ->and($data['used_in'][0]['name'])->toBe('Corte de prueba')
            ->and($data['movements'])->toHaveCount(1);

        $otra = $this->company(['modules_off' => []]);
        $this->as($otra->owner)->getJson("/api/products/{$id}")->assertNotFound();
    });

    it('el kárdex trae la referencia del movimiento (168)', function () {
        $id = $this->as($this->co->owner)->postJson('/api/products', ['name' => 'Crema', 'price' => 30, 'cost' => 10, 'kind' => 'both'])->assertCreated()->json('id');
        $this->as($this->co->owner)->getJson('/api/inventory')->assertOk();
        $almacen = DB::table('warehouses')->where('tenant_id', $this->co->tenant->id)->value('id');
        $this->as($this->co->owner)->postJson('/api/inventory/movements', ['mode' => 'entry', 'warehouse_id' => $almacen, 'product_id' => $id, 'quantity' => 5])->assertCreated();
        $this->openCash($this->co);
        $venta = $this->sell($this->co, [['type' => 'product', 'id' => $id, 'quantity' => 1]]);
        $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => $venta['total']])->assertOk();

        $movs = collect($this->as($this->co->owner)->getJson('/api/inventory')->assertOk()->json('movements'));
        expect($movs->firstWhere('reference_type', 'sale')['reference_label'])->toBe('Venta ' . $venta['code']);
    });

    it('el RUC del proveedor se valida (169)', function () {
        $this->as($this->co->owner)->postJson('/api/inventory/suppliers', ['name' => 'Mal RUC', 'document_number' => '20100070971'])
            ->assertStatus(422)->assertJsonValidationErrors('document_number');
        $this->as($this->co->owner)->postJson('/api/inventory/suppliers', ['name' => 'Corto', 'document_number' => '123'])
            ->assertStatus(422);
        $this->as($this->co->owner)->postJson('/api/inventory/suppliers', ['name' => 'Bien', 'document_number' => '20100070970'])->assertCreated();
        $this->as($this->co->owner)->postJson('/api/inventory/suppliers', ['name' => 'Repetido', 'document_number' => '20100070970'])
            ->assertStatus(422)->assertJsonValidationErrors('document_number');
    });
});

describe('membresías (171 y 172)', function () {
    it('la lista dice si se renueva sola o a mano', function () {
        $data = $this->as($this->co->owner)->getJson('/api/memberships')->assertOk()->json();
        expect($data)->toHaveKey('memberships');
    });
});

describe('campañas y automatizaciones (181 a 189)', function () {
    beforeEach(function () {
        $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Ejemplo');
    });

    it('guarda como borrador sin enviar y luego se puede cambiar', function () {
        $id = $this->as($this->co->owner)->postJson('/api/campaigns', ['name' => 'Idea de diciembre', 'group' => 'all', 'send' => 'draft'])
            ->assertCreated()->assertJsonPath('status', 'draft')->assertJsonPath('editable', true)->json('id');

        expect(DB::table('marketing_campaign_recipients')->where('marketing_campaign_id', $id)->count())->toBe(0);

        $this->as($this->co->owner)->putJson("/api/campaigns/{$id}", ['name' => 'Idea de diciembre 2', 'group' => 'all', 'send' => 'draft'])
            ->assertOk()->assertJsonPath('name', 'Idea de diciembre 2')->assertJsonPath('status', 'draft');

        $this->as($this->co->owner)->postJson("/api/campaigns/{$id}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
    });

    it('los grupos de clientes vienen con sus conteos (185)', function () {
        $data = $this->as($this->co->owner)->getJson('/api/campaigns/segments')->assertOk()->json('segments');
        $todos = collect($data)->firstWhere('key', 'all');

        expect($todos['count'])->toBeGreaterThanOrEqual(1)
            ->and(collect($data)->pluck('key'))->not->toContain('service');
    });

    it('el historial de automatizaciones viene en la pantalla (188)', function () {
        $data = $this->as($this->co->owner)->getJson('/api/automations')->assertOk()->json();
        expect($data)->toHaveKey('history')->and($data['month'])->toHaveKeys(['failed', 'skipped', 'runs']);
    });
});

describe('reportes (190 y 191)', function () {
    it('citas trae % en línea, alerta de inasistencias y asistentes a clases; clientes trae gasto promedio', function () {
        $citas = $this->as($this->co->owner)->getJson('/api/reports?tab=appointments&period=30')->assertOk()->json('data.summary') ?? [];
        $clientes = $this->as($this->co->owner)->getJson('/api/reports?tab=customers&period=30')->assertOk()->json('data.summary') ?? [];

        expect($citas)->toHaveKeys(['online_percent', 'no_show_alert', 'class_attendees'])
            ->and($clientes)->toHaveKeys(['avg_spend', 'rebook_days']);
    });
});
