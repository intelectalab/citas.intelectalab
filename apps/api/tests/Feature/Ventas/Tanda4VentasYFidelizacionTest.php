<?php

use App\Support\Loyalty;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);
    $this->clienta = $this->customer($this->co->tenant);
    $this->openCash($this->co);
    $this->servicio = $this->servicePriced($this->co->tenant, 200);
    $this->puntos = fn (string $id) => Loyalty::customerPoints($this->co->tenant->id, $id);
    $this->cobrar = function (object $cliente, float $total = 200) {
        $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]], ['customer_id' => $cliente->id]);
        $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => $venta['total']])->assertOk();

        return $venta;
    };
});

describe('puntos con multiplicador VIP (163 y 170)', function () {
    it('el cliente VIP gana el doble y el resto lo normal', function () {
        $this->setRules($this->co->tenant, ['crm.vip_spend' => 150, 'loyalty.amount_per_point' => 10]);
        ($this->cobrar)($this->clienta);

        expect(($this->puntos)($this->clienta->id))->toBe(40)
            ->and(DB::table('loyalty_transactions')->where('customer_id', $this->clienta->id)->value('reason'))->toContain('VIP x2');

        $otra = $this->customer($this->co->tenant, 'Otra', 'Clienta');
        $this->setRules($this->co->tenant, ['crm.vip_spend' => 100000, 'loyalty.amount_per_point' => 10]);
        ($this->cobrar)($otra);
        expect(($this->puntos)($otra->id))->toBe(20);
    });

    it('con multiplicador 1 nadie gana de más', function () {
        $this->setRules($this->co->tenant, ['crm.vip_spend' => 1, 'loyalty.amount_per_point' => 10, 'loyalty.vip_multiplier' => 1]);
        ($this->cobrar)($this->clienta);
        expect(($this->puntos)($this->clienta->id))->toBe(20);
    });

    it('la lista de puntos trae nivel, VIP, números y las reglas', function () {
        $this->setRules($this->co->tenant, ['crm.vip_spend' => 150]);
        ($this->cobrar)($this->clienta);
        $data = $this->as($this->co->owner)->getJson('/api/loyalty')->assertOk()->json();
        $fila = collect($data['customers'])->firstWhere('id', $this->clienta->id);

        expect($fila['vip'])->toBeTrue()
            ->and($fila['tier']['label'])->toBe('Bronce')
            ->and($data['summary']['points_in_circulation'])->toBe($fila['points'])
            ->and($data['summary']['issued_30d'])->toBe($fila['points'])
            ->and($data['rules']['vip_multiplier'])->toEqual(2);
    });
});

describe('referidos entre clientes (177)', function () {
    it('el bono espera la primera atención del referido y no se repite', function () {
        $nueva = $this->customer($this->co->tenant, 'Nueva', 'Referida');
        $this->as($this->co->owner)->postJson('/api/loyalty/referrals', ['referrer_id' => $this->clienta->id, 'referred_id' => $nueva->id, 'bonus_points' => 30])
            ->assertOk()->assertHeader('X-Referral-Credited', '0');
        expect(($this->puntos)($this->clienta->id))->toBe(0);

        ($this->cobrar)($nueva);
        expect(($this->puntos)($this->clienta->id))->toBe(30)
            ->and(DB::table('customer_referrals')->where('referred_customer_id', $nueva->id)->value('status'))->toBe('rewarded');

        ($this->cobrar)($nueva);
        expect(($this->puntos)($this->clienta->id))->toBe(30);

        $this->as($this->co->owner)->postJson('/api/loyalty/referrals', ['referrer_id' => $this->clienta->id, 'referred_id' => $nueva->id, 'bonus_points' => 30])
            ->assertStatus(422)->assertJsonValidationErrors('referred_id');
    });

    it('si el referido ya fue atendido el bono entra al registrar; no deja el mismo cliente ni uno de otra empresa', function () {
        $nueva = $this->customer($this->co->tenant, 'Ya', 'Atendida');
        ($this->cobrar)($nueva);
        $this->as($this->co->owner)->postJson('/api/loyalty/referrals', ['referrer_id' => $this->clienta->id, 'referred_id' => $nueva->id, 'bonus_points' => 25])
            ->assertOk()->assertHeader('X-Referral-Credited', '1');
        expect(($this->puntos)($this->clienta->id))->toBe(25);

        $this->as($this->co->owner)->postJson('/api/loyalty/referrals', ['referrer_id' => $this->clienta->id, 'referred_id' => $this->clienta->id, 'bonus_points' => 5])
            ->assertStatus(422);

        $otra = $this->company(['modules_off' => []]);
        $ajena = $this->customer($otra->tenant, 'De', 'Otra');
        $this->as($this->co->owner)->postJson('/api/loyalty/referrals', ['referrer_id' => $this->clienta->id, 'referred_id' => $ajena->id, 'bonus_points' => 5])
            ->assertStatus(422);
    });
});

describe('gift cards (178 a 180)', function () {
    it('ampliar y anular piden el motivo y lo guardan', function () {
        $this->as($this->co->owner)->postJson('/api/loyalty/gift-cards', ['initial_amount' => 100, 'code' => 'MOTIVO-1'])->assertOk();
        $gc = DB::table('gift_cards')->where('tenant_id', $this->co->tenant->id)->first();

        $this->as($this->co->owner)->postJson("/api/loyalty/gift-cards/{$gc->id}/extend", ['days' => 30])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->as($this->co->owner)->postJson("/api/loyalty/gift-cards/{$gc->id}/cancel")->assertStatus(422)->assertJsonValidationErrors('reason');

        $data = $this->as($this->co->owner)->postJson("/api/loyalty/gift-cards/{$gc->id}/cancel", ['reason' => 'Se emitió por error'])->assertOk()->json();
        expect(DB::table('gift_card_transactions')->where('gift_card_id', $gc->id)->where('type', 'cancel')->value('reason'))->toBe('Se emitió por error')
            ->and($data['summary']['gift_cards_sold'])->toEqual(0)
            ->and($data['summary']['gift_cards_pending'])->toEqual(0);
    });

    it('en caja la gift card se vende desde 10', function () {
        $this->as($this->co->owner)->postJson('/api/pos/preview', ['branch_id' => $this->co->branch->id, 'items' => [['type' => 'gift_card', 'id' => (string) \Illuminate\Support\Str::uuid(), 'details' => ['amount' => 5]]]])
            ->assertStatus(422);
        $this->as($this->co->owner)->postJson('/api/pos/preview', ['branch_id' => $this->co->branch->id, 'items' => [['type' => 'gift_card', 'id' => (string) \Illuminate\Support\Str::uuid(), 'details' => ['amount' => 50]]]])
            ->assertOk();
    });
});

describe('punto de venta y ventas', function () {
    it('al elegir el cliente muestra sus grupos y sus puntos (150)', function () {
        $this->setRules($this->co->tenant, ['crm.vip_spend' => 150]);
        ($this->cobrar)($this->clienta);
        $data = $this->as($this->co->owner)->getJson("/api/pos/customers/{$this->clienta->id}/summary")->assertOk()->json();

        expect(collect($data['segments'])->pluck('key'))->toContain('vip')
            ->and($data['loyalty']['points'])->toBe(40);

        $otra = $this->company(['modules_off' => []]);
        $this->as($otra->owner)->getJson("/api/pos/customers/{$this->clienta->id}/summary")->assertNotFound();
    });

    it('la próxima visita propone la fecha según el servicio (153)', function () {
        DB::table('services')->where('id', $this->servicio->id)->update(['recall_days' => 21, 'recall_label' => 'Retoque']);
        $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]], ['customer_id' => $this->clienta->id]);
        $visit = $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => $venta['total']])->assertOk()->json('next_visit');

        expect($visit['suggested_days'])->toBe(21)
            ->and($visit['suggested_date'])->toBe(now($this->co->tenant->zone())->addDays(21)->format('Y-m-d'));
    });

    it('la lista trae las devoluciones de 30 días (154)', function () {
        $venta = ($this->cobrar)($this->clienta);
        $this->as($this->co->owner)->postJson("/api/sales/{$venta['id']}/refunds", ['amount' => 50, 'method' => 'cash', 'reason' => 'Prueba'])->assertOk();

        $data = $this->as($this->co->owner)->getJson('/api/sales')->assertOk()->json('refunded_30d');
        expect(money($data['total']))->toBe(50.0)->and($data['count'])->toBe(1);
    });

    it('el detalle de la venta trae sus comisiones (157)', function () {
        $pro = $this->professional($this->co, 'Valeria Comisión', 10);
        $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id, 'professional_id' => $pro->id]], ['customer_id' => $this->clienta->id]);
        $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => $venta['total']])->assertOk();

        $detail = $this->as($this->co->owner)->getJson("/api/sales/{$venta['id']}")->assertOk()->json();
        expect($detail['commissions'])->toHaveCount(1)
            ->and($detail['commissions'][0]['professional'])->toBe('Valeria Comisión')
            ->and($detail['penalty_notice'])->toBeNull();
    });
});

describe('caja y comprobantes', function () {
    it('el cierre con billetes y monedas guarda el conteo y revisa que sume (160)', function () {
        $sesion = DB::table('cash_sessions')->where('tenant_id', $this->co->tenant->id)->where('status', 'open')->first();

        $this->as($this->co->owner)->postJson("/api/cash/sessions/{$sesion->id}/close", ['counted_amount' => 100, 'count' => [['value' => 50, 'quantity' => 1], ['value' => 20, 'quantity' => 2]]])
            ->assertStatus(422)->assertJsonValidationErrors('counted_amount');

        $this->as($this->co->owner)->postJson("/api/cash/sessions/{$sesion->id}/close", ['counted_amount' => 100, 'count' => [['value' => 50, 'quantity' => 1], ['value' => 20, 'quantity' => 2], ['value' => 10, 'quantity' => 1], ['value' => 1, 'quantity' => 0]]])
            ->assertOk();

        $count = json_decode(DB::table('cash_sessions')->where('id', $sesion->id)->value('closing_count'), true);
        expect($count)->toHaveCount(3);
    });

    it('una boleta desde 700 pide el DNI del cliente (162)', function () {
        $caro = $this->servicePriced($this->co->tenant, 800, 'Tratamiento largo');
        $sinDoc = $this->customer($this->co->tenant, 'Sin', 'Documento');
        DB::table('customers')->where('id', $sinDoc->id)->update(['document_number' => null]);

        $venta = $this->sell($this->co, [['type' => 'service', 'id' => $caro->id]], ['customer_id' => $sinDoc->id]);
        $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => $venta['total'], 'doc_type' => 'boleta'])
            ->assertStatus(422)->assertJsonValidationErrors('doc_type');
        expect($this->sale($venta['id'])->status)->toBe('pending');

        DB::table('customers')->where('id', $sinDoc->id)->update(['document_number' => '45678912']);
        $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => $venta['total'], 'doc_type' => 'boleta'])->assertOk();

        $barato = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]]);
        $this->pay($this->co, $barato['id'], ['method' => 'cash', 'amount' => $barato['total'], 'doc_type' => 'boleta'])->assertOk();
    });
});
