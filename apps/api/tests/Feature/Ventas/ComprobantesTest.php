<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company();
    $this->openCash($this->co);
    $this->servicio = $this->servicePriced($this->co->tenant, 118, 'Limpieza');

    $this->ventaConBoleta = function () {
        $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]]);
        $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => 118, 'doc_type' => 'boleta'])->assertOk();

        return [$venta['id'], DB::table('invoices')->where('sale_id', $venta['id'])->first()];
    };
});

it('lista las boletas y facturas de la empresa', function () {
    [, $boleta] = ($this->ventaConBoleta)();

    $lista = $this->as($this->co->owner)->getJson('/api/invoices')->assertOk()->json('invoices');

    expect(collect($lista)->pluck('id'))->toContain($boleta->id)
        ->and(collect($lista)->firstWhere('id', $boleta->id)['can_void'])->toBeTrue();
});

it('anular un comprobante pide motivo, lo deja anulado y permite emitir otro para la venta', function () {
    [$ventaId, $boleta] = ($this->ventaConBoleta)();

    $this->as($this->co->owner)->postJson("/api/invoices/{$boleta->id}/void", [])->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->as($this->co->owner)->postJson("/api/invoices/{$boleta->id}/void", ['reason' => 'Nombre del cliente mal escrito'])->assertOk()->assertJsonPath('status', 'voided');

    expect(DB::table('invoices')->where('id', $boleta->id)->value('void_reason'))->toBe('Nombre del cliente mal escrito');

    $this->as($this->co->owner)->postJson("/api/sales/{$ventaId}/invoice", ['doc_type' => 'boleta'])->assertOk();

    expect(DB::table('invoices')->where('sale_id', $ventaId)->where('status', 'issued')->count())->toBe(1)
        ->and(DB::table('invoices')->where('sale_id', $ventaId)->count())->toBe(2);
});

it('no anula dos veces el mismo comprobante', function () {
    [, $boleta] = ($this->ventaConBoleta)();

    $this->as($this->co->owner)->postJson("/api/invoices/{$boleta->id}/void", ['reason' => 'Primera anulación'])->assertOk();
    $this->as($this->co->owner)->postJson("/api/invoices/{$boleta->id}/void", ['reason' => 'Segunda anulación'])->assertStatus(422);
});

it('no anula el comprobante de una venta con devolución', function () {
    [$ventaId, $boleta] = ($this->ventaConBoleta)();
    DB::table('sale_refunds')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'sale_id' => $ventaId, 'amount' => 10, 'method' => 'cash', 'reason' => 'Prueba', 'created_at' => now()]);

    $this->as($this->co->owner)->postJson("/api/invoices/{$boleta->id}/void", ['reason' => 'Corregir datos'])->assertStatus(422);
});

it('recepción ve la lista pero no puede anular', function () {
    [, $boleta] = ($this->ventaConBoleta)();
    $recepcion = $this->staff($this->co, 'reception', $this->co->branch);

    $this->as($recepcion)->postJson("/api/invoices/{$boleta->id}/void", ['reason' => 'Intento sin permiso'])->assertForbidden();
});

it('reenviar un comprobante pendiente lo vuelve a mandar al proveedor', function () {
    [, $boleta] = ($this->ventaConBoleta)();
    DB::table('invoices')->where('id', $boleta->id)->update(['sunat_status' => 'pending']);

    $this->as($this->co->owner)->postJson("/api/invoices/{$boleta->id}/resend")->assertOk()->assertJsonPath('sunat_status', 'accepted');
    $this->as($this->co->owner)->postJson("/api/invoices/{$boleta->id}/resend")->assertStatus(422);
});
