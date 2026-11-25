<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);
    $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Paredes');
    $this->servicio = $this->servicePriced($this->co->tenant, 118, 'Tratamiento en línea');
    $this->pagarEnLinea = function (): array {
        $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]], ['customer_id' => $this->clienta->id]);
        $link = $this->as($this->co->owner)->postJson("/api/sales/{$venta['id']}/links")->assertCreated()->json();
        $token = DB::table('payment_links')->where('id', $link['id'])->value('token');
        $this->postJson("/api/pay/{$token}/simulate", ['result' => 'approved'])->assertOk()->assertJsonPath('result.status', 'paid');

        return $venta;
    };
});

it('el pago en línea no se suma al efectivo ni al cuadre del cajero', function () {
    $turno = $this->openCash($this->co, 100);
    ($this->pagarEnLinea)();

    $caja = $this->cashSession($turno['id']);

    expect(money($caja['expected_amount']))->toBe(100.0)
        ->and(collect($caja['by_method'])->pluck('method'))->not->toContain('link')
        ->and(money($caja['online']['total']))->toBe(118.0)
        ->and($caja['online']['count'])->toBe(1);
});

it('la pantalla de Caja muestra la caja En línea de cada sede con lo cobrado hoy', function () {
    ($this->pagarEnLinea)();
    ($this->pagarEnLinea)();

    $online = $this->as($this->co->owner)->getJson('/api/cash')->assertOk()->json('online');
    $sede = collect($online['branches'])->firstWhere('branch_id', $this->co->branch->id);

    expect($online['date'])->toBe(now($this->co->tenant->zone())->format('Y-m-d'))
        ->and(money($sede['net']))->toBe(236.0)
        ->and($sede['count'])->toBe(2);
});

it('lista los pagos en línea del día con su venta y su cliente', function () {
    $venta = ($this->pagarEnLinea)();

    $dia = $this->as($this->co->owner)->getJson('/api/cash/online?' . http_build_query(['branch_id' => $this->co->branch->id]))->assertOk()->json();

    expect(money($dia['totals']['received']))->toBe(118.0)
        ->and(money($dia['totals']['net']))->toBe(118.0)
        ->and($dia['payments'])->toHaveCount(1)
        ->and($dia['payments'][0]['sale_code'])->toBe($venta['code'])
        ->and($dia['payments'][0]['method_label'])->toBe('Link de pago')
        ->and($dia['payments'][0]['customer'])->toBe('Lucía Paredes');

    $ayer = $this->as($this->co->owner)->getJson('/api/cash/online?' . http_build_query(['date' => now($this->co->tenant->zone())->subDay()->format('Y-m-d')]))->assertOk()->json();
    expect($ayer['payments'])->toBe([])->and(money($ayer['totals']['net']))->toBe(0.0);
});

it('la devolución por la pasarela se resta de la caja En línea', function () {
    $pagado = ($this->pagarEnLinea)();
    DB::table('payments')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'branch_id' => $this->co->branch->id,
        'sale_id' => $pagado['id'], 'method' => 'gateway', 'status' => 'refunded', 'amount' => -18, 'currency' => 'PEN',
        'confirmed_at' => now(), 'created_at' => now(),
    ]);

    $dia = $this->as($this->co->owner)->getJson('/api/cash/online')->assertOk()->json();

    expect(money($dia['totals']['received']))->toBe(118.0)
        ->and(money($dia['totals']['refunded']))->toBe(18.0)
        ->and(money($dia['totals']['net']))->toBe(100.0);
});

it('el cajero limitado a su sede no ve la caja En línea de otra sede', function () {
    $otra = $this->branch($this->co, 'Sede Norte');
    ($this->pagarEnLinea)();
    $cajero = $this->staff($this->co, 'cashier', $otra);

    $dia = $this->as($cajero)->getJson('/api/cash/online?' . http_build_query(['branch_id' => $this->co->branch->id]))->assertOk()->json();

    expect($dia['branch_id'])->toBe($otra->id)->and($dia['payments'])->toBe([]);
});

it('Reportes → Caja suma lo cobrado en línea aparte, como una caja más de la sede', function () {
    $turno = $this->openCash($this->co, 100);
    $efectivo = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]]);
    $this->pay($this->co, $efectivo['id'], ['method' => 'cash', 'amount' => 118])->assertOk();
    ($this->pagarEnLinea)();

    $datos = $this->as($this->co->owner)->getJson('/api/reports?' . http_build_query(['tab' => 'cash']))->assertOk()->json('data');
    $enLinea = collect($datos['registers'])->firstWhere('online', true);

    expect(money($datos['summary']['sales']))->toBe(118.0)
        ->and(money($datos['summary']['online']))->toBe(118.0)
        ->and($enLinea['name'])->toBe('En línea')
        ->and(money($enLinea['sales']))->toBe(118.0)
        ->and($enLinea['sessions'])->toBe(0)
        ->and(money($this->cashSession($turno['id'])['expected_amount']))->toBe(218.0);
});
