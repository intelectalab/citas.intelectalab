<?php

use App\Support\Loyalty;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);
    $this->clienta = $this->customer($this->co->tenant);
    $this->servicio = $this->servicePriced($this->co->tenant, 100, 'Corte de prueba');
    $this->puntos = fn () => Loyalty::customerPoints($this->co->tenant->id, $this->clienta->id);

    $this->ajustar = fn (array $datos, $user = null) => $this->as($user ?? $this->co->owner)->postJson('/api/loyalty/points/adjust', $datos + [
        'customer_id' => $this->clienta->id, 'type' => 'adjust', 'points' => 10, 'reason' => 'Prueba',
    ]);

    $this->venderCobrar = function (array $pago = []) {
        $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id, 'quantity' => 1]], ['customer_id' => $this->clienta->id]);
        $this->pay($this->co, $venta['id'], $pago + ['method' => 'cash', 'amount' => $venta['total']])->assertOk();

        return $venta;
    };
});

it('cada venta cobrada suma un punto por cada 10 soles pagados', function () {
    $this->servicio = $this->servicePriced($this->co->tenant, 118, 'Corte caro');
    $this->openCash($this->co);
    $venta = ($this->venderCobrar)();

    $mov = DB::table('loyalty_transactions')->where('sale_id', $venta['id'])->first();

    expect(money($venta['total']))->toBe(118.0)
        ->and($mov->type)->toBe('earn')
        ->and((int) $mov->points)->toBe(11)
        ->and(($this->puntos)())->toBe(11)
        ->and($mov->expires_at)->not->toBeNull();
});

it('una venta sin clienta o con los puntos apagados no suma puntos', function () {
    $this->openCash($this->co);
    $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id, 'quantity' => 1]]);
    $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => $venta['total']])->assertOk();
    expect(DB::table('loyalty_transactions')->count())->toBe(0);

    $this->setRules($this->co->tenant, ['loyalty.points_enabled' => false]);
    ($this->venderCobrar)();
    expect(DB::table('loyalty_transactions')->count())->toBe(0);
});

it('pagar con puntos descuenta los puntos necesarios y esa parte no vuelve a sumar puntos', function () {
    $this->openCash($this->co);
    ($this->ajustar)(['points' => 40])->assertOk();

    $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id, 'quantity' => 1]], ['customer_id' => $this->clienta->id]);
    $this->pay($this->co, $venta['id'], ['method' => 'points', 'amount' => 10])->assertOk();
    expect(($this->puntos)())->toBe(20);

    $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => 90])->assertOk();

    expect($this->sale($venta['id'])->status)->toBe('paid')
        ->and((int) DB::table('loyalty_transactions')->where('sale_id', $venta['id'])->where('type', 'earn')->value('points'))->toBe(9)
        ->and(($this->puntos)())->toBe(29);
});

it('no deja canjear por debajo del mínimo ni más puntos de los que tiene', function () {
    $this->openCash($this->co);
    ($this->ajustar)(['points' => 15])->assertOk();
    $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id, 'quantity' => 1]], ['customer_id' => $this->clienta->id]);

    $this->pay($this->co, $venta['id'], ['method' => 'points', 'amount' => 5])->assertStatus(422);

    ($this->ajustar)(['points' => 10])->assertOk();
    $this->pay($this->co, $venta['id'], ['method' => 'points', 'amount' => 50])->assertStatus(422);
    expect(($this->puntos)())->toBe(25);
});

it('el ajuste manual suma o resta y no deja el saldo en negativo', function () {
    ($this->ajustar)(['points' => 30])->assertOk();
    ($this->ajustar)(['points' => 5, 'direction' => 'subtract'])->assertOk();
    expect(($this->puntos)())->toBe(25);

    ($this->ajustar)(['points' => 26, 'direction' => 'subtract'])->assertStatus(422)->assertJsonValidationErrors('points');
    ($this->ajustar)(['type' => 'redeem', 'points' => 20])->assertOk();
    ($this->ajustar)(['reason' => ''])->assertStatus(422)->assertJsonValidationErrors('reason');

    $fila = collect($this->as($this->co->owner)->getJson('/api/loyalty')->assertOk()->json('customers'))->firstWhere('id', $this->clienta->id);
    expect($fila['points'])->toBe(5)->and($fila['redeemed'])->toBe(20)->and($fila['can_redeem'])->toBeFalse();
});

it('los puntos vencen al pasar su plazo y se usan primero los que vencen antes', function () {
    $this->setRules($this->co->tenant, ['loyalty.points_validity_days' => 30]);
    ($this->ajustar)(['points' => 20])->assertOk();

    $this->travelTo(now()->addDays(20));
    ($this->ajustar)(['points' => 10])->assertOk();
    ($this->ajustar)(['points' => 15, 'direction' => 'subtract'])->assertOk();

    $this->travelTo(now()->addDays(15));
    expect(($this->puntos)())->toBe(10)
        ->and((int) DB::table('loyalty_transactions')->where('type', 'expire')->sum('points'))->toBe(-5);

    $this->travelTo(now()->addDays(30));
    expect(($this->puntos)())->toBe(0);
});

it('al devolver la venta se quitan los puntos que había ganado', function () {
    $this->openCash($this->co);
    $venta = ($this->venderCobrar)();
    expect(($this->puntos)())->toBe(10);

    $this->as($this->co->owner)->postJson("/api/sales/{$venta['id']}/refunds", ['amount' => 100, 'method' => 'cash', 'reason' => 'No le gustó'])->assertOk();

    expect(($this->puntos)())->toBe(0)
        ->and(DB::table('loyalty_transactions')->where('sale_id', $venta['id'])->where('type', 'reversal')->value('points'))->toBe(-10);
});

it('el profesional no ve fidelización y recepción sí puede sumar puntos', function () {
    $pro = $this->professional($this->co);
    $this->as($pro->user)->getJson('/api/loyalty')->assertForbidden();
    ($this->ajustar)([], $pro->user)->assertForbidden();

    $recep = $this->staff($this->co, 'reception');
    $this->as($recep)->getJson('/api/loyalty')->assertOk();
    ($this->ajustar)([], $recep)->assertOk();
});

it('no se dan puntos a clientas de otra empresa ni aparecen en la lista', function () {
    $otra = $this->company(['modules_off' => []]);
    $ajena = $this->customer($otra->tenant, 'Clienta', 'Ajena');

    ($this->ajustar)(['customer_id' => $ajena->id])->assertStatus(422)->assertJsonValidationErrors('customer_id');
    $this->as($otra->owner)->postJson('/api/loyalty/points/adjust', ['customer_id' => $ajena->id, 'type' => 'adjust', 'points' => 50, 'reason' => 'x'])->assertOk();

    $ids = collect($this->as($this->co->owner)->getJson('/api/loyalty')->json('customers'))->pluck('id');
    expect($ids)->not->toContain($ajena->id)
        ->and(DB::table('loyalty_transactions')->where('tenant_id', $this->co->tenant->id)->count())->toBe(0);
});

it('con el módulo apagado no se entra a fidelización', function () {
    DB::table('tenant_settings')->where('tenant_id', $this->co->tenant->id)->update(['modules_off' => json_encode(['loyalty'])]);
    $this->as($this->co->owner)->getJson('/api/loyalty')->assertForbidden();
});
