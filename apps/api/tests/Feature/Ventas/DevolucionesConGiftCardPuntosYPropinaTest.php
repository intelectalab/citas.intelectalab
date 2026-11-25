<?php

use App\Support\Loyalty;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);
    $this->clienta = $this->customer($this->co->tenant);
    $this->servicio = $this->servicePriced($this->co->tenant, 100, 'Corte de prueba');
    $this->openCash($this->co);

    $this->vender = fn (array $extra = []) => $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id, 'quantity' => 1]], ['customer_id' => $this->clienta->id] + $extra);
    $this->devolver = fn (string $ventaId, float $monto) => $this->as($this->co->owner)->postJson("/api/sales/{$ventaId}/refunds", ['amount' => $monto, 'method' => 'cash', 'reason' => 'No se hizo el servicio']);
    $this->efectivoDevuelto = fn (string $ventaId) => round(-(float) DB::table('payments')->where('sale_id', $ventaId)->where('status', 'refunded')->where('method', 'cash')->sum('amount'), 2);
    $this->tarjeta = fn () => DB::table('gift_cards')->where('tenant_id', $this->co->tenant->id)->where('code', 'REGALO-40')->first();
});

it('devolver todo lo pagado con efectivo y gift card saca en efectivo solo lo cobrado en efectivo y repone la tarjeta una vez', function () {
    $this->as($this->co->owner)->postJson('/api/loyalty/gift-cards', ['code' => 'REGALO-40', 'initial_amount' => 40])->assertOk();

    $venta = ($this->vender)();
    $this->pay($this->co, $venta['id'], ['method' => 'gift_card', 'amount' => 40, 'reference' => 'REGALO-40'])->assertOk();
    $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => 60])->assertOk();

    ($this->devolver)($venta['id'], 100)->assertOk();

    expect(($this->efectivoDevuelto)($venta['id']))->toBe(60.0)
        ->and(money(($this->tarjeta)()->balance))->toBe(40.0)
        ->and(($this->tarjeta)()->status)->toBe('active')
        ->and($this->sale($venta['id'])->status)->toBe('refunded');
});

it('una devolución parcial reparte lo devuelto entre el efectivo y la gift card, y al terminar no devuelve de más', function () {
    $this->as($this->co->owner)->postJson('/api/loyalty/gift-cards', ['code' => 'REGALO-40', 'initial_amount' => 40])->assertOk();

    $venta = ($this->vender)();
    $this->pay($this->co, $venta['id'], ['method' => 'gift_card', 'amount' => 40, 'reference' => 'REGALO-40'])->assertOk();
    $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => 60])->assertOk();

    ($this->devolver)($venta['id'], 50)->assertOk();

    expect(($this->efectivoDevuelto)($venta['id']))->toBe(30.0)
        ->and(money(($this->tarjeta)()->balance))->toBe(20.0);

    ($this->devolver)($venta['id'], 50.01)->assertStatus(422);
    ($this->devolver)($venta['id'], 50)->assertOk();

    expect(($this->efectivoDevuelto)($venta['id']))->toBe(60.0)
        ->and(money(($this->tarjeta)()->balance))->toBe(40.0)
        ->and($this->sale($venta['id'])->status)->toBe('refunded');
});

it('lo pagado con puntos vuelve como puntos y no sale en efectivo', function () {
    $this->as($this->co->owner)->postJson('/api/loyalty/points/adjust', [
        'customer_id' => $this->clienta->id, 'type' => 'adjust', 'points' => 80, 'reason' => 'Regalo',
    ])->assertOk();
    $puntos = fn () => Loyalty::customerPoints($this->co->tenant->id, $this->clienta->id);

    $venta = ($this->vender)();
    $this->pay($this->co, $venta['id'], ['method' => 'points', 'amount' => 40])->assertOk();
    $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => 60])->assertOk();

    $antes = $puntos();
    expect($antes)->toBe(6);

    ($this->devolver)($venta['id'], 100)->assertOk();

    expect(($this->efectivoDevuelto)($venta['id']))->toBe(60.0)
        ->and($puntos())->toBe(80)
        ->and($this->sale($venta['id'])->status)->toBe('refunded');
});

it('la ficha de la venta avisa cuánto vuelve a la gift card o a los puntos', function () {
    $this->as($this->co->owner)->postJson('/api/loyalty/gift-cards', ['code' => 'REGALO-40', 'initial_amount' => 40])->assertOk();

    $venta = ($this->vender)();
    $this->pay($this->co, $venta['id'], ['method' => 'gift_card', 'amount' => 40, 'reference' => 'REGALO-40'])->assertOk();
    $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => 60])->assertOk();

    $this->as($this->co->owner)->getJson("/api/sales/{$venta['id']}")
        ->assertOk()
        ->assertJsonPath('refund_available', 100)
        ->assertJsonPath('refund_to_loyalty', 40);
});

it('la nota de crédito de una venta con propina no pasa de la boleta', function () {
    $this->setRules($this->co->tenant, ['pos.tips_enabled' => true]);

    $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id, 'quantity' => 1]], ['tip' => 10]);
    $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => 110, 'doc_type' => 'boleta'])->assertOk();

    $boleta = DB::table('invoices')->where('sale_id', $venta['id'])->where('doc_type', '!=', 'nota_credito')->first();
    expect(money($boleta->total))->toBe(100.0);

    ($this->devolver)($venta['id'], 55)->assertOk();
    ($this->devolver)($venta['id'], 55)->assertOk();

    $notas = DB::table('invoices')->where('sale_id', $venta['id'])->where('doc_type', 'nota_credito')->get();

    expect($notas)->toHaveCount(2)
        ->and(round($notas->sum(fn ($n) => (float) $n->total), 2))->toBe(100.0)
        ->and(round($notas->sum(fn ($n) => (float) $n->tax), 2))->toBe(money($boleta->tax))
        ->and(($this->efectivoDevuelto)($venta['id']))->toBe(110.0);
});
