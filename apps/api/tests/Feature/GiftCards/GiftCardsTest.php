<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);
    $this->clienta = $this->customer($this->co->tenant);

    $this->emitir = function (array $datos = [], $user = null) {
        return $this->as($user ?? $this->co->owner)->postJson('/api/loyalty/gift-cards', $datos + ['initial_amount' => 100]);
    };

    $this->tarjeta = fn () => DB::table('gift_cards')->where('tenant_id', $this->co->tenant->id)->orderByDesc('created_at')->first();
});

it('emite una gift card con código, saldo completo y vencimiento según la regla', function () {
    ($this->emitir)(['recipient_customer_id' => $this->clienta->id])->assertOk();
    $gc = ($this->tarjeta)();

    expect($gc->status)->toBe('active')
        ->and(money($gc->balance))->toBe(100.0)
        ->and($gc->code)->not->toBeEmpty()
        ->and($gc->recipient_email)->toBe($this->clienta->email)
        ->and(now()->diffInDays($gc->expires_at, false))->toBeGreaterThan(363)
        ->and(DB::table('gift_card_transactions')->where('gift_card_id', $gc->id)->value('type'))->toBe('issue');
});

it('no repite códigos aunque cambien mayúsculas', function () {
    ($this->emitir)(['code' => 'REGALO-1'])->assertOk();
    ($this->emitir)(['code' => 'regalo-1'])->assertStatus(422)->assertJsonValidationErrors('code');
});

it('descontar baja el saldo y al llegar a cero queda canjeada', function () {
    ($this->emitir)()->assertOk();
    $gc = ($this->tarjeta)();

    $this->as($this->co->owner)->postJson("/api/loyalty/gift-cards/{$gc->id}/redeem", ['amount' => 30])->assertOk();
    expect(money(($this->tarjeta)()->balance))->toBe(70.0);

    $this->as($this->co->owner)->postJson("/api/loyalty/gift-cards/{$gc->id}/redeem", ['amount' => 80])->assertStatus(422);
    $this->as($this->co->owner)->postJson("/api/loyalty/gift-cards/{$gc->id}/redeem", ['amount' => 70])->assertOk();

    expect(($this->tarjeta)()->status)->toBe('redeemed');
    $this->as($this->co->owner)->postJson("/api/loyalty/gift-cards/{$gc->id}/redeem", ['amount' => 1])->assertStatus(422);
});

it('vence sola al pasar la fecha y ampliarla la reactiva si le queda saldo', function () {
    ($this->emitir)(['validity_days' => 10])->assertOk();
    $gc = ($this->tarjeta)();

    $this->travelTo(now()->addDays(11));
    $this->as($this->co->owner)->getJson('/api/loyalty')->assertOk();
    expect(($this->tarjeta)()->status)->toBe('expired');

    $this->as($this->co->owner)->postJson("/api/loyalty/gift-cards/{$gc->id}/extend", ['days' => 30, 'reason' => 'Motivo de prueba'])->assertOk();
    expect(($this->tarjeta)()->status)->toBe('active');
});

it('anular deja el saldo en cero, no se anula dos veces ni se amplía después', function () {
    ($this->emitir)()->assertOk();
    $gc = ($this->tarjeta)();

    $this->as($this->co->owner)->postJson("/api/loyalty/gift-cards/{$gc->id}/cancel", ['reason' => 'Motivo de prueba'])->assertOk();
    $fila = ($this->tarjeta)();

    expect($fila->status)->toBe('cancelled')->and(money($fila->balance))->toBe(0.0);
    $this->as($this->co->owner)->postJson("/api/loyalty/gift-cards/{$gc->id}/cancel", ['reason' => 'Motivo de prueba'])->assertStatus(422);
    $this->as($this->co->owner)->postJson("/api/loyalty/gift-cards/{$gc->id}/extend", ['days' => 5, 'reason' => 'Motivo de prueba'])->assertStatus(422);
});

it('se puede pagar una venta con la gift card y devolver la venta repone el saldo', function () {
    $this->openCash($this->co);
    ($this->emitir)(['code' => 'PAGA-50', 'initial_amount' => 50])->assertOk();
    $servicio = $this->servicePriced($this->co->tenant, 100);

    $venta = $this->sell($this->co, [['type' => 'service', 'id' => $servicio->id, 'quantity' => 1]], ['customer_id' => $this->clienta->id]);
    $this->pay($this->co, $venta['id'], ['method' => 'gift_card', 'amount' => 60, 'reference' => 'paga-50'])->assertStatus(422);
    $this->pay($this->co, $venta['id'], ['method' => 'gift_card', 'amount' => 50, 'reference' => 'paga-50'])->assertOk();
    $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => 50])->assertOk();

    expect(($this->tarjeta)()->status)->toBe('redeemed')
        ->and((int) DB::table('loyalty_transactions')->where('sale_id', $venta['id'])->value('points'))->toBe(5);

    $this->as($this->co->owner)->postJson("/api/sales/{$venta['id']}/refunds", ['amount' => 100, 'method' => 'cash', 'reason' => 'Error'])->assertOk();

    $fila = ($this->tarjeta)();
    expect($fila->status)->toBe('active')->and(money($fila->balance))->toBe(50.0);
});

it('permisos: recepción emite y descuenta pero no anula; el profesional no entra', function () {
    $recep = $this->staff($this->co, 'reception');
    ($this->emitir)([], $recep)->assertOk();
    $gc = ($this->tarjeta)();

    $this->as($recep)->postJson("/api/loyalty/gift-cards/{$gc->id}/redeem", ['amount' => 10])->assertOk();
    $this->as($recep)->postJson("/api/loyalty/gift-cards/{$gc->id}/cancel", ['reason' => 'Motivo de prueba'])->assertForbidden();

    $pro = $this->professional($this->co);
    ($this->emitir)([], $pro->user)->assertForbidden();
    expect(($this->tarjeta)()->status)->toBe('active');
});

it('una empresa no puede usar, ampliar ni anular gift cards de otra', function () {
    $otra = $this->company(['modules_off' => []]);
    $this->as($otra->owner)->postJson('/api/loyalty/gift-cards', ['initial_amount' => 100, 'code' => 'AJENA-1'])->assertOk();
    $ajena = DB::table('gift_cards')->where('tenant_id', $otra->tenant->id)->first();

    $this->as($this->co->owner)->postJson("/api/loyalty/gift-cards/{$ajena->id}/redeem", ['amount' => 10])->assertNotFound();
    $this->as($this->co->owner)->postJson("/api/loyalty/gift-cards/{$ajena->id}/extend", ['days' => 10, 'reason' => 'Motivo de prueba'])->assertNotFound();
    $this->as($this->co->owner)->postJson("/api/loyalty/gift-cards/{$ajena->id}/cancel", ['reason' => 'Motivo de prueba'])->assertNotFound();

    $this->openCash($this->co);
    $servicio = $this->servicePriced($this->co->tenant, 100);
    $venta = $this->sell($this->co, [['type' => 'service', 'id' => $servicio->id, 'quantity' => 1]]);
    $this->pay($this->co, $venta['id'], ['method' => 'gift_card', 'amount' => 10, 'reference' => 'AJENA-1'])->assertStatus(422);

    expect(money(DB::table('gift_cards')->where('id', $ajena->id)->value('balance')))->toBe(100.0)
        ->and(collect($this->as($this->co->owner)->getJson('/api/loyalty')->json('gift_cards'))->pluck('id'))->not->toContain($ajena->id);
});

it('no deja asignar como compradora o destinataria a una clienta de otra empresa', function () {
    $otra = $this->company(['modules_off' => []]);
    $ajena = $this->customer($otra->tenant, 'Clienta', 'Ajena');

    ($this->emitir)(['recipient_customer_id' => $ajena->id])->assertStatus(422)->assertJsonValidationErrors('recipient_customer_id');
    ($this->emitir)(['purchaser_customer_id' => $ajena->id])->assertStatus(422)->assertJsonValidationErrors('purchaser_customer_id');
    ($this->emitir)(['purchaser_customer_id' => (string) Str::uuid()])->assertStatus(422);

    expect(DB::table('gift_cards')->where('tenant_id', $this->co->tenant->id)->count())->toBe(0);
});
