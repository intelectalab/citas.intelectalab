<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company();
    $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Manicure');
    DB::table('services')->where('id', $this->servicio->id)->update([
        'duration_minutes' => 60, 'online_booking' => true, 'requires_deposit' => true, 'deposit_type' => 'percent', 'deposit_value' => 30,
    ]);
    $this->ana = $this->professional($this->co, 'Ana Torres');
    $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Paredes');

    $this->reservarYPagar = function () {
        $token = $this->as($this->clienta)->postJson('/api/portal/appointments', [
            'service_id' => $this->servicio->id, 'branch_id' => $this->co->branch->id, 'professional_id' => $this->ana->id,
            'starts_at' => $this->at($this->co->tenant, '10:00'),
        ])->assertStatus(202)->json('deposit.token');
        $this->postJson("/api/pay/{$token}/simulate", ['result' => 'approved'])->assertOk();

        return DB::table('appointments')->where('tenant_id', $this->co->tenant->id)->where('customer_id', $this->clienta->id)->latest('created_at')->first();
    };

    $this->ventaDe = fn ($cita) => DB::table('sales')->where('appointment_id', $cita->id)->first();
    $this->saldo = fn () => \App\Support\CustomerCredit::balance($this->co->tenant->id, $this->clienta->id);
    $this->cancelarTarde = function ($cita) {
        $this->travelTo(Carbon::parse($cita->starts_at)->subHours(2));
        $this->as($this->co->owner)->postJson("/api/appointments/{$cita->id}/status", ['status' => 'cancelled', 'reason' => 'Se le cruzó otra cosa'])->assertOk();
    };
});

describe('regla de fábrica: queda como saldo a favor', function () {
    it('de fábrica la regla es saldo a favor y así se ve en Configuración', function () {
        $reglas = $this->as($this->co->owner)->getJson('/api/tenant-settings')->assertOk()->json('payment_rules');

        expect($reglas['deposit_on_cancel'])->toBe('credit');
    });

    it('al cancelar a tiempo, el adelanto queda como saldo a favor y la venta queda pagada con ese monto', function () {
        $cita = ($this->reservarYPagar)();

        $this->as($this->clienta)->postJson("/api/portal/appointments/{$cita->id}/cancel")->assertOk();

        $venta = ($this->ventaDe)($cita);
        expect($venta->deposit_outcome)->toBe('credited')
            ->and($venta->status)->toBe('paid')
            ->and(money($venta->total))->toBe(24.0)
            ->and(money($venta->balance))->toBe(0.0)
            ->and(($this->saldo)())->toBe(24.0)
            ->and(DB::table('user_notifications')->where('type', 'payment.deposit_refund_due')->exists())->toBeFalse();
    });

    it('la clienta ve su saldo a favor en el portal y el texto de la cita lo dice', function () {
        $cita = ($this->reservarYPagar)();
        $antes = $this->as($this->clienta)->getJson('/api/portal/overview')->assertOk()->json();
        $pendiente = collect($antes['appointments'])->firstWhere('id', $cita->id);

        expect($antes['policies']['deposit_on_cancel'])->toBe('credit')
            ->and($pendiente['deposit']['on_cancel'])->toBe('credit');

        $this->as($this->clienta)->postJson("/api/portal/appointments/{$cita->id}/cancel")->assertOk();

        $beneficios = $this->as($this->clienta)->getJson('/api/portal/benefits')->assertOk()->json();
        $despues = collect($this->as($this->clienta)->getJson('/api/portal/overview')->json('appointments'))->firstWhere('id', $cita->id);

        expect(money($beneficios['summary']['credit_balance']))->toBe(24.0)
            ->and($beneficios['gift_cards'] ?? [])->toBe([])
            ->and($despues['deposit']['outcome'] ?? null)->toBe('credited');
    });

    it('el equipo ve el saldo en la ficha del cliente y en el cobro, y lo usa para pagar', function () {
        $cita = ($this->reservarYPagar)();
        $this->as($this->clienta)->postJson("/api/portal/appointments/{$cita->id}/cancel")->assertOk();

        $ficha = $this->as($this->co->owner)->getJson("/api/customers/{$this->clienta->id}/profile")->assertOk()->json('credit');
        expect(money($ficha['balance']))->toBe(24.0);

        $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]], ['customer_id' => $this->clienta->id]);
        $cobro = $this->as($this->co->owner)->getJson("/api/sales/{$venta['id']}/charge")->assertOk()->json('credit');
        expect(money($cobro['balance']))->toBe(24.0);

        $this->pay($this->co, $venta['id'], ['method' => 'credit', 'amount' => 30])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->pay($this->co, $venta['id'], ['method' => 'credit', 'amount' => 24])->assertOk();
        $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => 56])->assertOk();

        $fila = DB::table('sales')->where('id', $venta['id'])->first();
        expect($fila->status)->toBe('paid')
            ->and(($this->saldo)())->toBe(0.0)
            ->and(DB::table('payments')->where('sale_id', $venta['id'])->where('method', 'credit')->value('amount'))->not->toBeNull();
    });

    it('si se devuelve la venta pagada con saldo a favor, el saldo vuelve a la clienta', function () {
        $cita = ($this->reservarYPagar)();
        $this->as($this->clienta)->postJson("/api/portal/appointments/{$cita->id}/cancel")->assertOk();
        $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]], ['customer_id' => $this->clienta->id]);
        $this->pay($this->co, $venta['id'], ['method' => 'credit', 'amount' => 24])->assertOk();
        $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => 56])->assertOk();

        $this->as($this->co->owner)->postJson("/api/sales/{$venta['id']}/refunds", ['amount' => 80, 'method' => 'card', 'reason' => 'No quedó conforme'])->assertOk();

        expect(($this->saldo)())->toBe(24.0)
            ->and(DB::table('sale_refunds')->where('sale_id', $venta['id'])->value('method'))->toBe('card');
    });

    it('el saldo no sirve para pagar la compra de otro cliente', function () {
        $cita = ($this->reservarYPagar)();
        $this->as($this->clienta)->postJson("/api/portal/appointments/{$cita->id}/cancel")->assertOk();
        $otra = $this->customer($this->co->tenant, 'Marta', 'Quispe');
        $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]], ['customer_id' => $otra->id]);

        $this->pay($this->co, $venta['id'], ['method' => 'credit', 'amount' => 10])->assertStatus(422);
        expect(($this->saldo)())->toBe(24.0);
    });

    it('el código del saldo a favor no se puede usar como gift card', function () {
        $this->setRules($this->co->tenant, ['loyalty.gift_cards_enabled' => true]);
        $cita = ($this->reservarYPagar)();
        $this->as($this->clienta)->postJson("/api/portal/appointments/{$cita->id}/cancel")->assertOk();
        $codigo = DB::table('gift_cards')->where('tenant_id', $this->co->tenant->id)->value('code');
        $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]], ['customer_id' => $this->clienta->id]);

        expect($codigo)->toStartWith('SAF-');
        $this->pay($this->co, $venta['id'], ['method' => 'gift_card', 'amount' => 10, 'reference' => $codigo])->assertStatus(422);
    });

    it('al cancelar tarde, el adelanto queda para la empresa y no hay saldo a favor', function () {
        $cita = ($this->reservarYPagar)();

        ($this->cancelarTarde)($cita);

        expect(($this->ventaDe)($cita)->deposit_outcome)->toBe('retained')
            ->and(($this->saldo)())->toBe(0.0);
    });
});

describe('regla: se le devuelve el dinero', function () {
    beforeEach(function () {
        $this->as($this->co->owner)->putJson('/api/tenant-settings', ['payment_rules' => [
            'require_deposit' => true, 'deposit_minutes' => 30, 'deposit_refund_hours' => 24, 'link_hours' => 24, 'deposit_on_cancel' => 'refund',
        ]])->assertOk();
    });

    it('se guarda la regla y queda en el historial de cambios', function () {
        expect($this->as($this->co->owner)->getJson('/api/tenant-settings')->json('payment_rules.deposit_on_cancel'))->toBe('refund')
            ->and(DB::table('audit_events')->where('tenant_id', $this->co->tenant->id)->whereRaw('after_data::text like ?', ['%deposit_on_cancel%'])->exists())->toBeTrue();
    });

    it('al cancelar a tiempo, queda como adelanto por devolver, como antes', function () {
        $cita = ($this->reservarYPagar)();

        $this->as($this->clienta)->postJson("/api/portal/appointments/{$cita->id}/cancel")->assertOk();

        expect(($this->ventaDe)($cita)->deposit_outcome)->toBe('refund_due')
            ->and(($this->saldo)())->toBe(0.0)
            ->and($this->as($this->clienta)->getJson('/api/portal/overview')->json('policies.deposit_on_cancel'))->toBe('refund');
    });

    it('al cancelar tarde, el adelanto queda para la empresa', function () {
        $cita = ($this->reservarYPagar)();

        ($this->cancelarTarde)($cita);

        expect(($this->ventaDe)($cita)->deposit_outcome)->toBe('retained')
            ->and(($this->saldo)())->toBe(0.0);
    });
});

describe('permisos y otra empresa', function () {
    it('quien no puede editar la configuración no cambia la regla', function () {
        $recepcion = $this->staff($this->co, 'reception');

        $this->as($recepcion)->putJson('/api/tenant-settings', ['payment_rules' => [
            'require_deposit' => true, 'deposit_minutes' => 30, 'deposit_refund_hours' => 24, 'link_hours' => 24, 'deposit_on_cancel' => 'refund',
        ]])->assertForbidden();

        expect(\App\Support\PaymentLinks::onCancel($this->co->tenant->fresh()))->toBe('credit');
    });

    it('un valor que no existe no se guarda', function () {
        $this->as($this->co->owner)->putJson('/api/tenant-settings', ['payment_rules' => [
            'require_deposit' => true, 'deposit_minutes' => 30, 'deposit_refund_hours' => 24, 'link_hours' => 24, 'deposit_on_cancel' => 'regalo',
        ]])->assertStatus(422)->assertJsonValidationErrors('payment_rules.deposit_on_cancel');
    });

    it('otra empresa no ve ni usa el saldo a favor de la clienta', function () {
        $cita = ($this->reservarYPagar)();
        $this->as($this->clienta)->postJson("/api/portal/appointments/{$cita->id}/cancel")->assertOk();
        $otra = $this->company();

        $this->as($otra->owner)->getJson("/api/customers/{$this->clienta->id}/profile")->assertNotFound();
        expect(\App\Support\CustomerCredit::balance($otra->tenant->id, $this->clienta->id))->toBe(0.0)
            ->and(($this->saldo)())->toBe(24.0);
    });
});
