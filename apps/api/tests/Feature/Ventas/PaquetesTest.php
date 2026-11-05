<?php

use App\Support\Packages;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company();
    $this->servicio = $this->servicePriced($this->co->tenant, 50, 'Masaje de prueba');
    $this->clienta = $this->customer($this->co->tenant);

    $this->crearPlan = function (array $datos = []) {
        return $this->as($this->co->owner)->postJson('/api/package-plans', $datos + [
            'name' => 'Cuatro masajes', 'price' => 160, 'validity_days' => 90,
            'booking_mode' => 'free', 'frequency' => 'weekly', 'professional_mode' => 'any',
            'late_policy' => 'lose', 'refund_policy' => 'unused_normal',
            'items' => [['service_id' => $this->servicio->id, 'sessions' => 4]],
        ])->assertCreated()->json();
    };

    $this->venderPaquete = function (array $plan) {
        $venta = $this->sell($this->co, [['type' => 'package', 'id' => $plan['id']]], ['customer_id' => $this->clienta->id]);
        $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => $venta['total']])->assertOk();

        return [$venta, DB::table('customer_packages')->where('sale_id', $venta['id'])->first()];
    };

    $this->usar = fn (object $paquete) => $this->as($this->co->owner)
        ->postJson("/api/packages/{$paquete->id}/usages", ['service_id' => $this->servicio->id, 'note' => 'Sesión registrada a mano en la prueba']);
});

it('al cobrar la venta, la clienta recibe su paquete con todas sus sesiones', function () {
    [$venta, $paquete] = ($this->venderPaquete)(($this->crearPlan)());

    $items = DB::table('customer_package_items')->where('customer_package_id', $paquete->id)->get();

    expect($paquete->status)->toBe('active')
        ->and($paquete->customer_id)->toBe($this->clienta->id)
        ->and(money($paquete->price))->toBe(160.0)
        ->and($items)->toHaveCount(1)
        ->and((int) $items[0]->sessions_total)->toBe(4)
        ->and((int) $items[0]->sessions_used)->toBe(0);
});

it('no entrega el paquete hasta que la venta esté pagada', function () {
    $plan = ($this->crearPlan)();
    $venta = $this->sell($this->co, [['type' => 'package', 'id' => $plan['id']]], ['customer_id' => $this->clienta->id]);

    expect(DB::table('customer_packages')->where('sale_id', $venta['id'])->exists())->toBeFalse();
});

it('cada sesión usada se descuenta y al terminar el paquete queda consumido', function () {
    [, $paquete] = ($this->venderPaquete)(($this->crearPlan)(['items' => [['service_id' => $this->servicio->id, 'sessions' => 2]]]));

    ($this->usar)($paquete)->assertSuccessful();
    expect((int) DB::table('customer_package_items')->where('customer_package_id', $paquete->id)->value('sessions_used'))->toBe(1);

    ($this->usar)($paquete)->assertSuccessful();
    expect(DB::table('customer_packages')->where('id', $paquete->id)->value('status'))->toBe('consumed');

    ($this->usar)($paquete)->assertStatus(422);
    expect(DB::table('package_usages')->where('customer_package_id', $paquete->id)->count())->toBe(2);
});

it('no deja devolver por la venta el dinero de un paquete vigente: se hace al anularlo', function () {
    [$venta] = ($this->venderPaquete)(($this->crearPlan)());

    $this->as($this->co->owner)
        ->postJson("/api/sales/{$venta['id']}/refunds", ['amount' => 50, 'method' => 'card', 'reason' => 'Quiere su dinero'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('amount');
});

it('al anular, devuelve lo no usado cobrando lo usado a precio normal', function () {
    [$venta, $paquete] = ($this->venderPaquete)(($this->crearPlan)());
    ($this->usar)($paquete)->assertSuccessful();

    $cuenta = Packages::refundQuote($this->co->tenant, DB::table('customer_packages')->where('id', $paquete->id)->first());
    expect(money($cuenta['amount']))->toBe(110.0);

    $this->as($this->co->owner)
        ->postJson("/api/packages/{$paquete->id}/cancel", ['refund' => true, 'amount' => 200, 'method' => 'card', 'reason' => 'Se mudó de ciudad'])
        ->assertStatus(422);

    $this->as($this->co->owner)
        ->postJson("/api/packages/{$paquete->id}/cancel", ['refund' => true, 'amount' => 110, 'method' => 'card', 'reason' => 'Se mudó de ciudad'])
        ->assertSuccessful();

    expect(DB::table('customer_packages')->where('id', $paquete->id)->value('status'))->toBe('cancelled')
        ->and(money(DB::table('sale_refunds')->where('sale_id', $venta['id'])->sum('amount')))->toBe(110.0)
        ->and($this->sale($venta['id'])->status)->toBe('partially_refunded');
});

it('a precio del paquete, devuelve la parte proporcional de las sesiones que faltan', function () {
    [, $paquete] = ($this->venderPaquete)(($this->crearPlan)(['refund_policy' => 'unused_package']));
    ($this->usar)($paquete)->assertSuccessful();

    $cuenta = Packages::refundQuote($this->co->tenant, DB::table('customer_packages')->where('id', $paquete->id)->first());

    expect(money($cuenta['amount']))->toBe(120.0);
});

it('un paquete vendido sin devolución no devuelve dinero', function () {
    [, $paquete] = ($this->venderPaquete)(($this->crearPlan)(['refund_policy' => 'none']));

    expect(money(Packages::refundQuote($this->co->tenant, $paquete)['amount']))->toBe(0.0);
});

it('un paquete anulado ya no se puede usar', function () {
    [, $paquete] = ($this->venderPaquete)(($this->crearPlan)());

    $this->as($this->co->owner)->postJson("/api/packages/{$paquete->id}/cancel", ['refund' => false, 'reason' => 'Lo pidió la clienta'])->assertSuccessful();

    ($this->usar)($paquete)->assertStatus(422);
});

it('el paquete de una empresa no lo ve ni lo usa otra', function () {
    [, $paquete] = ($this->venderPaquete)(($this->crearPlan)());
    $otra = $this->company();

    $this->as($otra->owner)->getJson("/api/packages/{$paquete->id}")->assertNotFound();
    $this->as($otra->owner)->postJson("/api/packages/{$paquete->id}/usages", ['service_id' => $this->servicio->id, 'note' => 'Intento desde otra empresa'])->assertNotFound();
});

it('no se repite el nombre de un paquete', function () {
    ($this->crearPlan)();

    $this->as($this->co->owner)->postJson('/api/package-plans', [
        'name' => 'cuatro MASAJES', 'price' => 10, 'validity_days' => 30, 'booking_mode' => 'free', 'frequency' => 'weekly',
        'professional_mode' => 'any', 'late_policy' => 'lose', 'refund_policy' => 'none',
        'items' => [['service_id' => $this->servicio->id, 'sessions' => 1]],
    ])->assertStatus(422)->assertJsonValidationErrors('name');
});
