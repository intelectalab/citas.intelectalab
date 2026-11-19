<?php

use App\Support\Loyalty;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company();
    $this->servicio = $this->servicePriced($this->co->tenant, 100, 'Corte de prueba');
    $this->cerrar = function (float $contado, ?string $nota = 'Conteo revisado'): array {
        $turno = $this->openCash($this->co, 100);

        return $this->as($this->co->owner)
            ->postJson("/api/cash/sessions/{$turno['id']}/close", ['counted_amount' => $contado, 'notes' => $nota])
            ->assertOk()
            ->json();
    };
});

it('por defecto un cierre con diferencia no queda en revisión', function () {
    $cierre = ($this->cerrar)(50);

    expect($cierre['review_status'])->toBeNull()
        ->and(money($cierre['difference']))->toBe(-50.0);
});

it('con la regla, un cierre con diferencia mayor queda en revisión y avisa a quien aprueba', function () {
    $this->setRules($this->co->tenant, ['cash.review_over' => 20]);
    $gerente = $this->staff($this->co, 'manager', null, 'Gerente Uno');

    $cierre = ($this->cerrar)(50);

    expect($cierre['review_status'])->toBe('pending')
        ->and(DB::table('user_notifications')->where('user_id', $gerente->id)->where('type', 'cash.review')->exists())->toBeTrue();

    $lista = $this->as($gerente)->getJson('/api/cash')->assertOk()->json();
    expect(collect($lista['sessions'])->firstWhere('id', $cierre['id'])['review_label'])->toBe('En revisión')
        ->and($lista['can_approve'])->toBeTrue();
});

it('una diferencia dentro del monto no pasa a revisión', function () {
    $this->setRules($this->co->tenant, ['cash.review_over' => 20]);

    expect(($this->cerrar)(90)['review_status'])->toBeNull();
});

it('quien tiene el permiso aprueba u observa el cierre con nota y avisa a quien cerró', function () {
    $this->setRules($this->co->tenant, ['cash.review_over' => 20]);
    $cierre = ($this->cerrar)(50);
    $gerente = $this->staff($this->co, 'manager', null, 'Gerente Uno');

    $this->as($gerente)->postJson("/api/cash/sessions/{$cierre['id']}/review", ['decision' => 'observed'])
        ->assertStatus(422)->assertJsonValidationErrors('notes');

    $revisado = $this->as($gerente)->postJson("/api/cash/sessions/{$cierre['id']}/review", ['decision' => 'observed', 'notes' => 'Falta el vale de gasto'])
        ->assertOk()->json();

    expect($revisado['review_status'])->toBe('observed')
        ->and($revisado['review_notes'])->toBe('Falta el vale de gasto')
        ->and(DB::table('user_notifications')->where('user_id', $this->co->owner->id)->where('type', 'cash.reviewed')->exists())->toBeTrue();

    $this->as($gerente)->postJson("/api/cash/sessions/{$cierre['id']}/review", ['decision' => 'approved'])->assertStatus(409);
});

it('sin el permiso de aprobar cierres no se puede revisar', function () {
    $this->setRules($this->co->tenant, ['cash.review_over' => 20]);
    $cierre = ($this->cerrar)(50);
    $cajera = $this->staff($this->co, 'cashier', null, 'Cajera Uno');

    $this->as($cajera)->postJson("/api/cash/sessions/{$cierre['id']}/review", ['decision' => 'approved'])->assertForbidden();
});

it('por defecto una devolución grande se hace directo', function () {
    $this->openCash($this->co);
    $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]]);
    $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => 100])->assertOk();

    $this->as($this->co->owner)->postJson("/api/sales/{$venta['id']}/refunds", ['amount' => 100, 'method' => 'card', 'reason' => 'No vino'])->assertOk();

    expect($this->sale($venta['id'])->status)->toBe('refunded');
});

it('con la regla, una devolución mayor al monto la pide uno y la aprueba otro', function () {
    $this->setRules($this->co->tenant, ['pos.refund_approval_over' => 50]);
    $gerente = $this->staff($this->co, 'manager', null, 'Gerente Uno');
    $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]]);
    $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => 100])->assertOk();

    $pedido = $this->as($this->co->owner)->postJson("/api/sales/{$venta['id']}/refunds", ['amount' => 80, 'method' => 'card', 'reason' => 'Cliente insatisfecho'])
        ->assertStatus(202)->json();

    expect($this->sale($venta['id'])->status)->toBe('paid')
        ->and($pedido['refund_requests'][0]['status'])->toBe('pending')
        ->and($pedido['refund_requests'][0]['can_review'])->toBeFalse();

    $id = $pedido['refund_requests'][0]['id'];
    $this->as($this->co->owner)->postJson("/api/refund-requests/{$id}/approve")->assertForbidden();

    $this->as($this->co->owner)->postJson("/api/sales/{$venta['id']}/refunds", ['amount' => 10, 'method' => 'card', 'reason' => 'Otra'])
        ->assertStatus(422);

    expect(DB::table('user_notifications')->where('user_id', $gerente->id)->where('type', 'refund.requested')->exists())->toBeTrue();

    $this->as($gerente)->postJson("/api/refund-requests/{$id}/approve")->assertOk();

    expect($this->sale($venta['id'])->status)->toBe('partially_refunded')
        ->and(DB::table('refund_requests')->where('id', $id)->value('status'))->toBe('approved')
        ->and(DB::table('sale_refunds')->where('sale_id', $venta['id'])->count())->toBe(1);
});

it('una devolución pedida se puede rechazar con motivo', function () {
    $this->setRules($this->co->tenant, ['pos.refund_approval_over' => 50]);
    $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]]);
    $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => 100])->assertOk();
    $id = $this->as($this->co->owner)->postJson("/api/sales/{$venta['id']}/refunds", ['amount' => 100, 'method' => 'card', 'reason' => 'Error'])
        ->assertStatus(202)->json('refund_requests.0.id');
    $gerente = $this->staff($this->co, 'manager', null, 'Gerente Uno');

    $this->as($gerente)->postJson("/api/refund-requests/{$id}/reject", [])->assertStatus(422);
    $this->as($gerente)->postJson("/api/refund-requests/{$id}/reject", ['notes' => 'Ya se atendió'])->assertOk();

    expect($this->sale($venta['id'])->status)->toBe('paid')
        ->and(DB::table('refund_requests')->where('id', $id)->value('status'))->toBe('rejected');
});

it('una devolución bajo el monto sigue siendo directa', function () {
    $this->setRules($this->co->tenant, ['pos.refund_approval_over' => 50]);
    $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]]);
    $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => 100])->assertOk();

    $this->as($this->co->owner)->postJson("/api/sales/{$venta['id']}/refunds", ['amount' => 30, 'method' => 'card', 'reason' => 'Ajuste'])->assertOk();

    expect($this->sale($venta['id'])->status)->toBe('partially_refunded');
});

function puntos(object $test, string $clienteId, int $puntos, string $creado, ?string $vence, string $tipo = 'earn'): void
{
    DB::table('loyalty_transactions')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $test->co->tenant->id, 'customer_id' => $clienteId, 'type' => $tipo,
        'points' => $puntos, 'balance_after' => 0, 'expires_at' => $vence, 'created_at' => $creado,
    ]);
}

it('los puntos vencidos dejan de sumar y quedan como vencidos en el historial', function () {
    $clienta = $this->customer($this->co->tenant);
    puntos($this, $clienta->id, 100, now()->subDays(40)->toIso8601String(), now()->subDays(5)->toIso8601String());
    puntos($this, $clienta->id, 30, now()->subDays(10)->toIso8601String(), now()->addDays(300)->toIso8601String());
    puntos($this, $clienta->id, -40, now()->subDays(20)->toIso8601String(), null, 'redeem');

    expect(Loyalty::customerPoints($this->co->tenant->id, $clienta->id))->toBe(30)
        ->and((int) DB::table('loyalty_transactions')->where('customer_id', $clienta->id)->where('type', 'expire')->sum('points'))->toBe(-60)
        ->and(Loyalty::customerPoints($this->co->tenant->id, $clienta->id))->toBe(30);

    $tablero = $this->as($this->co->owner)->getJson('/api/loyalty')->assertOk()->json();
    $lote = collect($tablero['point_movements'])->first(fn ($m) => $m['points'] === 100);

    expect($lote['expired_points'])->toBe(60)
        ->and(collect($tablero['customers'])->firstWhere('id', $clienta->id)['points'])->toBe(30);
});

it('vende una gift card en el punto de venta: se activa al pagarse', function () {
    $clienta = $this->customer($this->co->tenant);
    $linea = (string) Str::uuid();

    $venta = $this->sell($this->co, [['type' => 'gift_card', 'id' => $linea, 'details' => ['amount' => 150, 'recipient_name' => 'Ana Regalo']]], ['customer_id' => $clienta->id]);

    expect(money($venta['total']))->toBe(150.0)
        ->and(DB::table('gift_cards')->where('sale_id', $venta['id'])->exists())->toBeFalse();

    $pago = $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => 150])->assertOk();
    $tarjeta = DB::table('gift_cards')->where('sale_id', $venta['id'])->first();

    expect($tarjeta->status)->toBe('active')
        ->and(money($tarjeta->balance))->toBe(150.0)
        ->and($tarjeta->recipient_name)->toBe('Ana Regalo')
        ->and($pago->json('gift_cards.0.code'))->toBe($tarjeta->code)
        ->and(DB::table('invoices')->where('sale_id', $venta['id'])->exists())->toBeTrue();
});

it('devolver la venta de una gift card sin usar la anula', function () {
    $venta = $this->sell($this->co, [['type' => 'gift_card', 'id' => (string) Str::uuid(), 'details' => ['amount' => 80]]]);
    $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => 80])->assertOk();

    $this->as($this->co->owner)->postJson("/api/sales/{$venta['id']}/refunds", ['amount' => 80, 'method' => 'card', 'reason' => 'Se arrepintió'])->assertOk();

    expect(DB::table('gift_cards')->where('sale_id', $venta['id'])->value('status'))->toBe('cancelled');
});

it('una gift card ya usada no se devuelve', function () {
    $venta = $this->sell($this->co, [['type' => 'gift_card', 'id' => (string) Str::uuid(), 'details' => ['amount' => 80]]]);
    $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => 80])->assertOk();
    $tarjeta = DB::table('gift_cards')->where('sale_id', $venta['id'])->first();

    $otra = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]]);
    $this->pay($this->co, $otra['id'], ['method' => 'gift_card', 'amount' => 30, 'reference' => $tarjeta->code])->assertOk();

    $this->as($this->co->owner)->postJson("/api/sales/{$venta['id']}/refunds", ['amount' => 80, 'method' => 'card', 'reason' => 'Error'])
        ->assertStatus(422)->assertJsonValidationErrors('amount');
});

it('después de cobrar un servicio ofrece la próxima cita con cliente, servicio y profesional', function () {
    $clienta = $this->customer($this->co->tenant);
    $pro = $this->professional($this->co, 'Pro Uno');
    $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id, 'professional_id' => $pro->id]], ['customer_id' => $clienta->id]);

    $pago = $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => 100])->assertOk();

    expect($pago->json('next_visit.customer_id'))->toBe($clienta->id)
        ->and($pago->json('next_visit.service_id'))->toBe($this->servicio->id)
        ->and($pago->json('next_visit.professional_id'))->toBe($pro->id)
        ->and($pago->json('next_visit.branch_id'))->toBe($this->co->branch->id);
});

it('no ofrece la próxima cita si solo se vendió una gift card', function () {
    $clienta = $this->customer($this->co->tenant);
    $venta = $this->sell($this->co, [['type' => 'gift_card', 'id' => (string) Str::uuid(), 'details' => ['amount' => 50]]], ['customer_id' => $clienta->id]);

    expect($this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => 50])->assertOk()->json('next_visit'))->toBeNull();
});

it('el portal muestra puntos, gift cards y compras con su comprobante, solo de quien entra', function () {
    $clienta = $this->customer($this->co->tenant);
    $otra = $this->customer($this->co->tenant, 'Otra', 'Persona');
    puntos($this, $clienta->id, 20, now()->subDay()->toIso8601String(), now()->addDays(10)->toIso8601String());
    $venta = $this->sell($this->co, [['type' => 'gift_card', 'id' => (string) Str::uuid(), 'details' => ['amount' => 60]]], ['customer_id' => $clienta->id]);
    $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => 60])->assertOk();

    $datos = $this->as($clienta)->getJson('/api/portal/benefits')->assertOk()->json();

    expect($datos['points']['balance'])->toBeGreaterThanOrEqual(20)
        ->and($datos['points']['expiring'])->not->toBeEmpty()
        ->and($datos['gift_cards'][0]['balance'])->toEqual(60)
        ->and($datos['purchases'][0]['id'])->toBe($venta['id']);

    $this->as($clienta)->getJson("/api/portal/purchases/{$venta['id']}")->assertOk()->assertJsonPath('code', $venta['code']);
    $this->as($otra)->getJson("/api/portal/purchases/{$venta['id']}")->assertNotFound();
    expect($this->as($otra)->getJson('/api/portal/benefits')->json('purchases'))->toBe([]);
});
