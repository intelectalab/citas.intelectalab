<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);
    $this->clienta = $this->customer($this->co->tenant, 'Paciente', 'Plan');
    $this->servicio = $this->servicePriced($this->co->tenant, 100, 'Limpieza dental');

    $this->crear = function (array $datos = [], $user = null) {
        $this->as($user ?? $this->co->owner)->postJson('/api/quotes', $datos + [
            'customer_id' => $this->clienta->id, 'branch_id' => $this->co->branch->id, 'title' => 'Plan de limpieza',
            'items' => [['item_type' => 'service', 'reference_id' => $this->servicio->id, 'description' => 'Limpieza', 'quantity' => 2, 'unit_price' => 100, 'discount' => 20]],
        ])->assertOk();

        return DB::table('quotes')->where('tenant_id', $this->co->tenant->id)->orderByDesc('code')->first();
    };

    $this->estado = fn (object $q, string $status, array $extra = [], $user = null) => $this->as($user ?? $this->co->owner)
        ->postJson("/api/quotes/{$q->id}/status", ['status' => $status] + $extra);
    $this->fila = fn (object $q) => DB::table('quotes')->where('id', $q->id)->first();
});

it('crea el presupuesto en borrador con código, totales con descuento y validez por defecto', function () {
    $q = ($this->crear)();
    $item = DB::table('quote_items')->where('quote_id', $q->id)->first();

    expect($q->status)->toBe('draft')
        ->and($q->code)->toStartWith('PRE-')
        ->and(money($q->total))->toBe(180.0)
        ->and(money($item->total))->toBe(180.0)
        ->and((int) $item->sessions_total)->toBe(2)
        ->and($q->valid_until)->toBe(now()->addDays(30)->toDateString())
        ->and(DB::table('quote_status_history')->where('quote_id', $q->id)->value('to_status'))->toBe('draft');
});

it('las cuotas deben sumar el total', function () {
    $this->as($this->co->owner)->postJson('/api/quotes', [
        'customer_id' => $this->clienta->id, 'title' => 'Con cuotas',
        'items' => [['item_type' => 'other', 'description' => 'Ortodoncia', 'quantity' => 1, 'unit_price' => 300]],
        'installments' => [['due_date' => now()->addMonth()->toDateString(), 'amount' => 100], ['due_date' => now()->addMonths(2)->toDateString(), 'amount' => 100]],
    ])->assertStatus(422)->assertJsonValidationErrors('installments');

    $q = ($this->crear)([
        'items' => [['item_type' => 'other', 'description' => 'Ortodoncia', 'quantity' => 1, 'unit_price' => 300]],
        'installments' => [['due_date' => now()->addMonth()->toDateString(), 'amount' => 150], ['due_date' => now()->addMonths(2)->toDateString(), 'amount' => 150]],
    ]);

    expect(DB::table('quote_installments')->where('quote_id', $q->id)->count())->toBe(2);
});

it('al aprobar crea la venta por cobrar y el plan de tratamiento con sus sesiones', function () {
    $q = ($this->crear)();
    ($this->estado)($q, 'sent')->assertOk();
    ($this->estado)($q, 'accepted')->assertOk();

    $fila = ($this->fila)($q);
    $venta = $this->sale($fila->sale_id);
    $plan = DB::table('customer_packages')->where('quote_id', $q->id)->first();

    expect($fila->status)->toBe('accepted')
        ->and($venta->status)->toBe('pending')
        ->and(money($venta->total))->toBe(180.0)
        ->and($plan->status)->toBe('active')
        ->and((int) DB::table('customer_package_items')->where('customer_package_id', $plan->id)->value('sessions_total'))->toBe(2);
});

it('un aprobado no se edita ni cambia de estado; se descuentan sesiones del plan', function () {
    $q = ($this->crear)();
    ($this->estado)($q, 'accepted')->assertOk();

    $this->as($this->co->owner)->putJson("/api/quotes/{$q->id}", [
        'customer_id' => $this->clienta->id, 'title' => 'Otro', 'items' => [['item_type' => 'other', 'description' => 'x', 'quantity' => 1, 'unit_price' => 1]],
    ])->assertStatus(422);
    ($this->estado)($q, 'rejected')->assertStatus(422);

    $item = DB::table('quote_items')->where('quote_id', $q->id)->value('id');
    $this->as($this->co->owner)->postJson("/api/quotes/{$q->id}/items/{$item}/sessions", ['note' => 'Atendida sin cita'])->assertOk();
    $this->as($this->co->owner)->postJson("/api/quotes/{$q->id}/items/{$item}/sessions", ['note' => 'Atendida sin cita'])->assertOk();
    $this->as($this->co->owner)->postJson("/api/quotes/{$q->id}/items/{$item}/sessions", ['note' => 'Una más'])->assertStatus(422);

    $plan = DB::table('customer_packages')->where('quote_id', $q->id)->first();
    expect($plan->status)->toBe('consumed');
});

it('rechazado se reabre como borrador; anulado no se reabre', function () {
    $q = ($this->crear)();
    ($this->estado)($q, 'rejected')->assertOk();
    ($this->estado)($q, 'accepted')->assertStatus(422);
    ($this->estado)($q, 'draft')->assertOk();
    ($this->estado)($q, 'cancelled')->assertOk();
    ($this->estado)($q, 'draft')->assertStatus(422);

    expect(($this->fila)($q)->status)->toBe('cancelled');
});

it('vence solo al pasar su fecha y vencido no se aprueba', function () {
    $q = ($this->crear)(['valid_until' => now()->addDays(2)->toDateString(), 'status' => 'sent']);
    $this->travelTo(now()->addDays(4));

    $this->as($this->co->owner)->getJson('/api/quotes')->assertOk();
    expect(($this->fila)($q)->status)->toBe('expired');
    ($this->estado)($q, 'accepted')->assertStatus(422);
});

it('anular un aprobado y pagado devuelve lo no usado según la regla', function () {
    $this->openCash($this->co);
    $q = ($this->crear)();
    ($this->estado)($q, 'accepted')->assertOk();
    $ventaId = ($this->fila)($q)->sale_id;
    $this->pay($this->co, $ventaId, ['method' => 'cash', 'amount' => 180])->assertOk();

    $item = DB::table('quote_items')->where('quote_id', $q->id)->value('id');
    $this->as($this->co->owner)->postJson("/api/quotes/{$q->id}/items/{$item}/sessions", ['note' => 'Primera sesión'])->assertOk();

    $previa = $this->as($this->co->owner)->getJson("/api/quotes/{$q->id}/cancel")->assertOk()->json();
    expect(money($previa['refund']))->toBe(90.0)->and($previa['used_sessions'])->toBe(1);

    $this->as($this->co->owner)->postJson("/api/quotes/{$q->id}/cancel", ['reason' => 'Se muda', 'refund_amount' => 90, 'method' => 'cash'])->assertOk();

    expect(($this->fila)($q)->status)->toBe('cancelled')
        ->and(money($this->sale($ventaId)->paid))->toBe(90.0)
        ->and(DB::table('customer_packages')->where('quote_id', $q->id)->value('status'))->toBe('cancelled');
});

it('la clienta aprueba o rechaza desde el portal solo sus presupuestos enviados', function () {
    $borrador = ($this->crear)();
    $enviado = ($this->crear)(['status' => 'sent']);
    $otraClienta = $this->customer($this->co->tenant, 'Otra', 'Clienta');
    $deOtra = ($this->crear)(['status' => 'sent', 'customer_id' => $otraClienta->id]);

    $ids = collect($this->as($this->clienta)->getJson('/api/portal/quotes')->assertOk()->json('quotes'))->pluck('id');
    expect($ids)->toContain($enviado->id)->not->toContain($borrador->id)->not->toContain($deOtra->id);

    $this->as($this->clienta)->postJson("/api/portal/quotes/{$borrador->id}/accept")->assertNotFound();
    $this->as($this->clienta)->postJson("/api/portal/quotes/{$deOtra->id}/accept")->assertNotFound();
    $this->as($this->clienta)->postJson("/api/portal/quotes/{$enviado->id}/reject")->assertStatus(422);
    $this->as($this->clienta)->postJson("/api/portal/quotes/{$enviado->id}/accept")->assertOk();

    expect(($this->fila)($enviado)->status)->toBe('accepted')
        ->and(($this->fila)($enviado)->accepted_via)->toBe('portal')
        ->and(($this->fila)($deOtra)->status)->toBe('sent');
});

it('si la empresa no deja responder por el portal, la clienta no puede aprobar', function () {
    $this->setRules($this->co->tenant, ['quotes.portal_accept' => false]);
    $q = ($this->crear)(['status' => 'sent']);

    $this->as($this->clienta)->postJson("/api/portal/quotes/{$q->id}/accept")->assertStatus(422);
    expect(($this->fila)($q)->status)->toBe('sent');
});

it('cajera solo mira y el profesional no anula', function () {
    $q = ($this->crear)();
    $cajera = $this->staff($this->co, 'cashier');
    $pro = $this->professional($this->co);

    $this->as($cajera)->getJson('/api/quotes')->assertOk();
    $this->as($cajera)->postJson('/api/quotes', ['customer_id' => $this->clienta->id, 'title' => 'x', 'items' => [['item_type' => 'other', 'description' => 'x', 'quantity' => 1, 'unit_price' => 1]]])->assertForbidden();
    ($this->estado)($q, 'cancelled', [], $pro->user)->assertForbidden();

    expect(($this->fila)($q)->status)->toBe('draft');
});

it('no se usan clientas, servicios ni presupuestos de otra empresa', function () {
    $otra = $this->company(['modules_off' => []]);
    $ajena = $this->customer($otra->tenant, 'Clienta', 'Ajena');
    $servicioAjeno = $this->servicePriced($otra->tenant, 70, 'Servicio ajeno');

    $this->as($this->co->owner)->postJson('/api/quotes', ['customer_id' => $ajena->id, 'title' => 'x', 'items' => [['item_type' => 'other', 'description' => 'x', 'quantity' => 1, 'unit_price' => 1]]])
        ->assertStatus(422)->assertJsonValidationErrors('customer_id');
    $this->as($this->co->owner)->postJson('/api/quotes', ['customer_id' => $this->clienta->id, 'title' => 'x', 'items' => [['item_type' => 'service', 'reference_id' => $servicioAjeno->id, 'description' => 'x', 'quantity' => 1, 'unit_price' => 1]]])
        ->assertStatus(422)->assertJsonValidationErrors('items.0.reference_id');

    $this->as($otra->owner)->postJson('/api/quotes', ['customer_id' => $ajena->id, 'title' => 'Ajeno', 'items' => [['item_type' => 'other', 'description' => 'x', 'quantity' => 1, 'unit_price' => 1]]])->assertOk();
    $ajeno = DB::table('quotes')->where('tenant_id', $otra->tenant->id)->first();

    ($this->estado)($ajeno, 'cancelled')->assertNotFound();
    $this->as($this->co->owner)->getJson("/api/quotes/{$ajeno->id}/pdf")->assertNotFound();
    $this->as($this->clienta)->postJson("/api/portal/quotes/{$ajeno->id}/accept")->assertNotFound();

    expect(DB::table('quotes')->where('tenant_id', $this->co->tenant->id)->count())->toBe(0)
        ->and(($this->fila)($ajeno)->status)->toBe('draft');
});
