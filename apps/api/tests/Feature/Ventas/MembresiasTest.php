<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company();
    $this->servicio = $this->servicePriced($this->co->tenant, 50, 'Masaje de prueba');
    $this->otro = $this->servicePriced($this->co->tenant, 80, 'Facial de prueba');
    $this->clienta = $this->customer($this->co->tenant);

    $this->crearPlan = function (array $datos = []) {
        return $this->as($this->co->owner)->postJson('/api/membership-plans', $datos + [
            'name' => 'Plan Bienestar', 'price' => 100, 'billing_period' => 'monthly',
            'discount_percent' => 10, 'discount_scope' => 'services',
            'items' => [['service_id' => $this->servicio->id, 'sessions' => 1]],
        ])->assertCreated()->json();
    };

    $this->venderMembresia = function (array $plan) {
        $venta = $this->sell($this->co, [['type' => 'membership', 'id' => $plan['id']]], ['customer_id' => $this->clienta->id]);
        $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => $venta['total']])->assertOk();

        return [$venta, DB::table('memberships')->where('sale_id', $venta['id'])->first()];
    };

    $this->vistaPrevia = fn (array $items) => $this->as($this->co->owner)->postJson('/api/pos/preview', [
        'branch_id' => $this->co->branch->id, 'customer_id' => $this->clienta->id, 'items' => $items,
    ])->assertOk()->json();
});

it('crea un plan con descuento y servicios incluidos', function () {
    $plan = ($this->crearPlan)();

    expect($plan['name'])->toBe('Plan Bienestar')
        ->and($plan['discount_percent'])->toEqual(10)
        ->and($plan['items'])->toHaveCount(1)
        ->and($plan['benefits_text'])->toContain('10 % de descuento en servicios')
        ->and($plan['benefits_text'])->toContain('1 × Masaje de prueba al mes');
});

it('no deja crear un plan sin ningún beneficio ni con el nombre repetido', function () {
    $this->as($this->co->owner)->postJson('/api/membership-plans', ['name' => 'Vacío', 'price' => 50, 'billing_period' => 'monthly'])
        ->assertStatus(422)->assertJsonValidationErrors('discount_percent');

    ($this->crearPlan)();
    $this->as($this->co->owner)->postJson('/api/membership-plans', ['name' => 'plan bienestar', 'price' => 50, 'billing_period' => 'monthly', 'discount_percent' => 5])
        ->assertStatus(422)->assertJsonValidationErrors('name');
});

it('pide al cliente para vender una membresía', function () {
    $plan = ($this->crearPlan)();

    $this->as($this->co->owner)->postJson('/api/pos/sales', ['branch_id' => $this->co->branch->id, 'items' => [['type' => 'membership', 'id' => $plan['id']]]])
        ->assertStatus(422)->assertJsonValidationErrors('customer_id');
});

it('la membresía queda vigente al cobrar la venta, no antes', function () {
    $plan = ($this->crearPlan)();
    $venta = $this->sell($this->co, [['type' => 'membership', 'id' => $plan['id']]], ['customer_id' => $this->clienta->id]);

    expect(DB::table('memberships')->where('sale_id', $venta['id'])->exists())->toBeFalse();

    $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => $venta['total']])->assertOk()->assertJsonPath('memberships.0.renewed', false);
    $membresia = DB::table('memberships')->where('sale_id', $venta['id'])->first();
    $hoy = now($this->co->tenant->zone())->startOfDay();

    expect($membresia->status)->toBe('active')
        ->and($membresia->customer_id)->toBe($this->clienta->id)
        ->and($membresia->code)->toStartWith('MEM-')
        ->and(Carbon::parse($membresia->starts_at)->toDateString())->toBe($hoy->toDateString())
        ->and(Carbon::parse($membresia->ends_at)->toDateString())->toBe($hoy->copy()->addMonthNoOverflow()->subDay()->toDateString());
});

it('al cobrar, el servicio incluido sale gratis la primera vez y con descuento después', function () {
    ($this->venderMembresia)(($this->crearPlan)());

    $vista = ($this->vistaPrevia)([['type' => 'service', 'id' => $this->servicio->id, 'quantity' => 2]]);

    expect($vista['membership']['code'])->toStartWith('MEM-')
        ->and(money($vista['lines'][0]['discount']))->toBe(55.0)
        ->and(money($vista['lines'][0]['total']))->toBe(45.0)
        ->and($vista['lines'][0]['membership']['included'])->toBe(1);
});

it('una sesión incluida usada no se vuelve a regalar en el mismo periodo', function () {
    ($this->venderMembresia)(($this->crearPlan)());

    $primera = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]], ['customer_id' => $this->clienta->id]);
    expect($primera['status'])->toBe('paid')->and(money($primera['total']))->toBe(0.0);

    $vista = ($this->vistaPrevia)([['type' => 'service', 'id' => $this->servicio->id]]);
    expect(money($vista['lines'][0]['total']))->toBe(45.0);
});

it('la sesión incluida vuelve al periodo siguiente', function () {
    [, $membresia] = ($this->venderMembresia)(($this->crearPlan)(['billing_period' => 'quarterly']));
    $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]], ['customer_id' => $this->clienta->id]);

    $this->travel(32)->days();
    $vista = ($this->vistaPrevia)([['type' => 'service', 'id' => $this->servicio->id]]);
    expect(money($vista['lines'][0]['total']))->toBe(45.0);

    $hoy = now($this->co->tenant->zone())->startOfDay();
    DB::table('memberships')->where('id', $membresia->id)->update([
        'starts_at' => $hoy->copy()->subMonthsNoOverflow(3)->subDays(2)->toDateString(),
        'ends_at'   => $hoy->copy()->addMonth()->toDateString(),
    ]);
    $vista = ($this->vistaPrevia)([['type' => 'service', 'id' => $this->servicio->id]]);
    expect(money($vista['lines'][0]['total']))->toBe(0.0);
});

it('sin servicios incluidos, todo servicio lleva el descuento', function () {
    ($this->venderMembresia)(($this->crearPlan)(['items' => [], 'discount_scope' => 'services']));

    $vista = ($this->vistaPrevia)([['type' => 'service', 'id' => $this->otro->id]]);
    expect(money($vista['lines'][0]['total']))->toBe(72.0);
});

it('se puede cobrar una línea sin la membresía', function () {
    ($this->venderMembresia)(($this->crearPlan)());

    $vista = ($this->vistaPrevia)([['type' => 'service', 'id' => $this->servicio->id, 'no_membership' => true]]);

    expect(money($vista['lines'][0]['total']))->toBe(50.0)
        ->and($vista['lines'][0]['membership'])->toBeNull()
        ->and($vista['lines'][0]['no_membership'])->toBeTrue();
});

it('sin membresía vigente no hay beneficio', function () {
    [, $membresia] = ($this->venderMembresia)(($this->crearPlan)());

    $this->travel(40)->days();
    $vista = ($this->vistaPrevia)([['type' => 'service', 'id' => $this->servicio->id]]);

    expect($vista['membership'])->toBeNull()
        ->and(money($vista['lines'][0]['total']))->toBe(50.0);

    $this->as($this->co->owner)->getJson('/api/memberships')->assertOk();
    expect(DB::table('memberships')->where('id', $membresia->id)->value('status'))->toBe('expired');
});

it('vender el mismo plan a quien ya lo tiene lo renueva un periodo más', function () {
    $plan = ($this->crearPlan)();
    [, $membresia] = ($this->venderMembresia)($plan);

    $vista = ($this->vistaPrevia)([['type' => 'membership', 'id' => $plan['id']]]);
    expect($vista['lines'][0]['description'])->toBe('Renovación: Plan Bienestar');

    $venta = $this->sell($this->co, [['type' => 'membership', 'id' => $plan['id']]], ['customer_id' => $this->clienta->id]);
    $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => $venta['total']])->assertOk()->assertJsonPath('memberships.0.renewed', true);

    $antes = Carbon::parse($membresia->ends_at);
    $despues = Carbon::parse(DB::table('memberships')->where('id', $membresia->id)->value('ends_at'));

    expect(DB::table('memberships')->where('customer_id', $this->clienta->id)->count())->toBe(1)
        ->and($despues->toDateString())->toBe($antes->copy()->addDay()->addMonthNoOverflow()->subDay()->toDateString());
});

it('al devolver la venta de la membresía, se cancela', function () {
    [$venta, $membresia] = ($this->venderMembresia)(($this->crearPlan)());

    $this->as($this->co->owner)
        ->postJson("/api/sales/{$venta['id']}/refunds", ['amount' => 100, 'method' => 'card', 'reason' => 'Se arrepintió', 'reference' => 'OP-1'])
        ->assertSuccessful();

    expect(DB::table('memberships')->where('id', $membresia->id)->value('status'))->toBe('cancelled');
});

it('una venta anulada libera la sesión incluida', function () {
    ($this->venderMembresia)(($this->crearPlan)());
    $pendiente = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id], ['type' => 'service', 'id' => $this->otro->id]], ['customer_id' => $this->clienta->id]);

    $vista = ($this->vistaPrevia)([['type' => 'service', 'id' => $this->servicio->id]]);
    expect(money($vista['lines'][0]['total']))->toBe(45.0);

    DB::table('sales')->where('id', $pendiente['id'])->update(['status' => 'void']);
    $vista = ($this->vistaPrevia)([['type' => 'service', 'id' => $this->servicio->id]]);
    expect(money($vista['lines'][0]['total']))->toBe(0.0);
});

it('quitar un plan vendido lo saca del catálogo sin tocar lo vendido', function () {
    $plan = ($this->crearPlan)();
    [, $membresia] = ($this->venderMembresia)($plan);

    $this->as($this->co->owner)->deleteJson("/api/membership-plans/{$plan['id']}")->assertOk()->assertJsonPath('kept', true);

    expect(DB::table('membership_plans')->where('id', $plan['id'])->value('deleted_at'))->not->toBeNull()
        ->and(DB::table('memberships')->where('id', $membresia->id)->value('status'))->toBe('active');
});

it('otra empresa no ve ni cambia los planes', function () {
    $plan = ($this->crearPlan)();
    $otra = $this->company();

    $this->as($otra->owner)->putJson("/api/membership-plans/{$plan['id']}", ['name' => 'X', 'price' => 1, 'billing_period' => 'monthly', 'discount_percent' => 1])->assertNotFound();
    expect($this->as($otra->owner)->getJson('/api/memberships')->assertOk()->json('plans'))->toBeEmpty();
});

it('al vencer queda en gracia con sus beneficios y después vence', function () {
    [, $membresia] = ($this->venderMembresia)(($this->crearPlan)());
    $fin = Carbon::parse($membresia->ends_at);

    $this->travelTo($fin->copy()->addDays(2)->setTime(12, 0));
    $vista = ($this->vistaPrevia)([['type' => 'service', 'id' => $this->servicio->id]]);
    expect(money($vista['lines'][0]['total']))->toBe(0.0)
        ->and($vista['membership']['status'])->toBe('grace')
        ->and($vista['membership']['grace_until'])->toBe($fin->copy()->addDays(5)->toDateString());

    $this->as($this->co->owner)->getJson('/api/memberships')->assertOk()->assertJsonPath('memberships.0.status', 'grace');

    $this->travelTo($fin->copy()->addDays(6)->setTime(12, 0));
    $vista = ($this->vistaPrevia)([['type' => 'service', 'id' => $this->servicio->id]]);
    expect($vista['membership'])->toBeNull()->and(money($vista['lines'][0]['total']))->toBe(50.0);
    $this->as($this->co->owner)->getJson('/api/memberships')->assertOk()->assertJsonPath('memberships.0.status', 'expired');
});

it('renovarla en gracia cuenta desde su vencimiento', function () {
    $plan = ($this->crearPlan)();
    [, $membresia] = ($this->venderMembresia)($plan);
    $fin = Carbon::parse($membresia->ends_at);

    $this->travelTo($fin->copy()->addDays(3)->setTime(12, 0));
    expect(($this->vistaPrevia)([['type' => 'membership', 'id' => $plan['id']]])['lines'][0]['description'])->toBe('Renovación: Plan Bienestar');
    ($this->venderMembresia)($plan);

    $fila = DB::table('memberships')->where('id', $membresia->id)->first();
    expect($fila->status)->toBe('active')
        ->and(Carbon::parse($fila->ends_at)->toDateString())->toBe($fin->copy()->addDay()->addMonthNoOverflow()->subDay()->toDateString())
        ->and(DB::table('memberships')->where('customer_id', $this->clienta->id)->count())->toBe(1);
});

it('la empresa cambia los días de gracia en Configuración', function () {
    [, $membresia] = ($this->venderMembresia)(($this->crearPlan)());

    $this->as($this->co->owner)->putJson('/api/tenant-settings', ['membership_rules' => ['grace_days' => 61]])->assertStatus(422);
    $this->as($this->co->owner)->putJson('/api/tenant-settings', ['membership_rules' => ['grace_days' => 0]])->assertOk();
    $this->as($this->co->owner)->getJson('/api/tenant-settings')->assertOk()->assertJsonPath('membership_rules.grace_days', 0);

    $this->travelTo(Carbon::parse($membresia->ends_at)->addDay()->setTime(12, 0));
    expect(($this->vistaPrevia)([['type' => 'service', 'id' => $this->servicio->id]])['membership'])->toBeNull();
});

it('pausada no da beneficios y al reactivarla corre el vencimiento', function () {
    $plan = ($this->crearPlan)();
    [, $membresia] = ($this->venderMembresia)($plan);
    $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]], ['customer_id' => $this->clienta->id]);

    $this->as($this->co->owner)->postJson("/api/memberships/{$membresia->id}/pause", [])->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->as($this->co->owner)->postJson("/api/memberships/{$membresia->id}/pause", ['reason' => 'Viaja por un mes'])->assertOk()->assertJsonPath('status', 'paused');

    $vista = ($this->vistaPrevia)([['type' => 'service', 'id' => $this->servicio->id]]);
    expect($vista['membership'])->toBeNull()->and(money($vista['lines'][0]['total']))->toBe(50.0);

    $this->as($this->co->owner)->postJson('/api/pos/preview', [
        'branch_id' => $this->co->branch->id, 'customer_id' => $this->clienta->id, 'items' => [['type' => 'membership', 'id' => $plan['id']]],
    ])->assertStatus(422)->assertJsonValidationErrors('items.0');

    $this->travel(10)->days();
    $detalle = $this->as($this->co->owner)->postJson("/api/memberships/{$membresia->id}/resume")->assertOk()->json();

    expect($detalle['status'])->toBe('active')
        ->and($detalle['result']['days'])->toBe(10)
        ->and($detalle['ends_at'])->toBe(Carbon::parse($membresia->ends_at)->addDays(10)->toDateString())
        ->and($detalle['paused_days'])->toBe(10)
        ->and(collect($detalle['events'])->pluck('kind')->all())->toContain('paused', 'resumed');

    $vista = ($this->vistaPrevia)([['type' => 'service', 'id' => $this->servicio->id]]);
    expect(money($vista['lines'][0]['total']))->toBe(45.0);
});

it('cancelada deja de dar beneficios y no se puede pausar ni renovar', function () {
    $plan = ($this->crearPlan)();
    [, $membresia] = ($this->venderMembresia)($plan);

    $this->as($this->co->owner)->postJson("/api/memberships/{$membresia->id}/cancel", ['reason' => 'Se mudó de ciudad'])->assertOk()
        ->assertJsonPath('status', 'cancelled')->assertJsonPath('cancel_reason', 'Se mudó de ciudad');

    expect(($this->vistaPrevia)([['type' => 'service', 'id' => $this->servicio->id]])['membership'])->toBeNull();
    $this->as($this->co->owner)->postJson("/api/memberships/{$membresia->id}/pause", ['reason' => 'Viaje'])->assertStatus(422);

    $vista = ($this->vistaPrevia)([['type' => 'membership', 'id' => $plan['id']]]);
    expect($vista['lines'][0]['description'])->toBe('Membresía Plan Bienestar');
});

it('vender otro plan cambia de plan y la devolución lo deshace', function () {
    [, $vieja] = ($this->venderMembresia)(($this->crearPlan)());
    $otro = ($this->crearPlan)(['name' => 'Plan Premium', 'discount_percent' => 20, 'items' => []]);

    $vista = ($this->vistaPrevia)([['type' => 'membership', 'id' => $otro['id']]]);
    expect($vista['lines'][0]['description'])->toBe('Cambio de plan: Plan Premium')
        ->and($vista['lines'][0]['membership_plan']['replaces']['code'])->toBe($vieja->code);

    $venta = $this->sell($this->co, [['type' => 'membership', 'id' => $otro['id']]], ['customer_id' => $this->clienta->id]);
    $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => $venta['total']])->assertOk()->assertJsonPath('memberships.0.replaced', $vieja->code);

    $nueva = DB::table('memberships')->where('sale_id', $venta['id'])->first();
    expect(DB::table('memberships')->where('id', $vieja->id)->value('status'))->toBe('cancelled')
        ->and($nueva->status)->toBe('active')
        ->and(money(($this->vistaPrevia)([['type' => 'service', 'id' => $this->otro->id]])['lines'][0]['total']))->toBe(64.0);

    $this->as($this->co->owner)
        ->postJson("/api/sales/{$venta['id']}/refunds", ['amount' => 100, 'method' => 'card', 'reason' => 'Se arrepintió', 'reference' => 'OP-2'])
        ->assertSuccessful();

    expect(DB::table('memberships')->where('id', $nueva->id)->value('status'))->toBe('cancelled')
        ->and(DB::table('memberships')->where('id', $vieja->id)->value('status'))->toBe('active');
});

it('el detalle muestra usos e historial y respeta los permisos', function () {
    [, $membresia] = ($this->venderMembresia)(($this->crearPlan)());
    $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]], ['customer_id' => $this->clienta->id]);

    $detalle = $this->as($this->co->owner)->getJson("/api/memberships/{$membresia->id}")->assertOk()->json();
    expect($detalle['usages'])->toHaveCount(1)
        ->and($detalle['usages'][0]['service'])->toBe('Masaje de prueba')
        ->and($detalle['events'][0]['kind'])->toBe('sold')
        ->and($detalle['can']['edit'])->toBeTrue();

    $caja = $this->staff($this->co, 'cashier');
    $this->as($caja)->getJson("/api/memberships/{$membresia->id}")->assertOk()->assertJsonPath('can.edit', false);
    $this->as($caja)->postJson("/api/memberships/{$membresia->id}/pause", ['reason' => 'Viaje'])->assertForbidden();
    $this->as($caja)->postJson("/api/memberships/{$membresia->id}/cancel", ['reason' => 'Viaje'])->assertForbidden();

    $otra = $this->company();
    $this->as($otra->owner)->getJson("/api/memberships/{$membresia->id}")->assertNotFound();

    $this->as($this->co->owner)->postJson("/api/memberships/{$membresia->id}/pause", ['reason' => 'Viaja'])->assertOk();
    expect(DB::table('audit_events')->where('action', 'membership.paused')->where('entity_id', $membresia->id)->exists())->toBeTrue();
});

it('el cliente ve su membresía y lo que le queda en el portal', function () {
    ($this->venderMembresia)(($this->crearPlan)());
    $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]], ['customer_id' => $this->clienta->id]);

    $lista = $this->as($this->clienta)->getJson('/api/portal/memberships')->assertOk()->json('memberships');

    expect($lista)->toHaveCount(1)
        ->and($lista[0]['status'])->toBe('active')
        ->and($lista[0]['included'][0]['left'])->toBe(0)
        ->and($lista[0])->not->toHaveKey('uses');
});

it('avisa al personal cuando una membresía está por vencer o en gracia', function () {
    [, $membresia] = ($this->venderMembresia)(($this->crearPlan)());
    $fin = Carbon::parse($membresia->ends_at);

    $this->travelTo($fin->copy()->subDays(3)->setTime(12, 0));
    \App\Support\DueNotices::run($this->co->tenant->fresh(), true);
    \App\Support\DueNotices::run($this->co->tenant->fresh(), true);

    $avisos = DB::table('user_notifications')->where('user_id', $this->co->owner->id)->where('type', 'membership.due')->get();
    expect($avisos)->toHaveCount(1)
        ->and($avisos[0]->link)->toBe("/memberships/{$membresia->id}");

    $this->travelTo($fin->copy()->addDays(1)->setTime(12, 0));
    \App\Support\DueNotices::run($this->co->tenant->fresh(), true);
    expect(DB::table('user_notifications')->where('user_id', $this->co->owner->id)->where('type', 'membership.grace')->count())->toBe(1);
});
