<?php

use App\Support\Messaging;
use App\Support\OnlineBooking;
use App\Support\PaymentLinks;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);
});

it('configuración marca lo distinto al valor de fábrica del rubro', function () {
    $antes = $this->as($this->co->owner)->getJson('/api/tenant-settings')->assertOk()->json();
    $fabrica = $antes['factory']['sales_rules']['tax_rate'];

    expect($antes['sales_rules']['tax_rate'])->toEqual($fabrica);

    $this->as($this->co->owner)->putJson('/api/tenant-settings', ['sales_rules' => array_merge($antes['sales_rules'], ['tax_rate' => 10])])->assertOk();
    $despues = $this->as($this->co->owner)->getJson('/api/tenant-settings')->assertOk()->json();

    expect($despues['sales_rules']['tax_rate'])->toEqual(10)
        ->and($despues['factory']['sales_rules']['tax_rate'])->toEqual($fabrica)
        ->and($despues['factory'])->not->toHaveKey('modules');
});

it('confirma y cancela varias citas a la vez con las mismas reglas que de a una', function () {
    $servicio = $this->service($this->co->tenant);
    $pro = $this->professional($this->co);
    $clienta = $this->customer($this->co->tenant);
    $ids = collect(['10:00', '11:00', '12:00'])->map(fn ($hora) => $this->book($this->co, $clienta, $servicio, $pro, $this->at($this->co->tenant, $hora))->assertCreated()->json('id'))->all();
    DB::table('appointments')->whereIn('id', $ids)->update(['status' => 'pending']);

    $this->as($this->co->owner)->postJson('/api/appointments/bulk-status', ['ids' => [$ids[0], $ids[1]], 'status' => 'confirmed'])
        ->assertOk()->assertJson(['done' => 2, 'failed' => []]);

    $this->as($this->co->owner)->postJson('/api/appointments/bulk-status', ['ids' => $ids, 'status' => 'cancelled'])
        ->assertStatus(422)->assertJsonValidationErrors('reason');

    $otra = $this->company();
    $ajena = $this->book($otra, $this->customer($otra->tenant), $this->service($otra->tenant), $this->professional($otra), $this->at($otra->tenant, '10:00'))->assertCreated()->json('id');

    $this->as($this->co->owner)->postJson('/api/appointments/bulk-status', ['ids' => [...$ids, $ajena], 'status' => 'cancelled', 'reason' => 'Feriado'])
        ->assertOk()->assertJson(['done' => 3])->assertJsonCount(1, 'failed')->assertJsonPath('failed.0.id', $ajena);

    expect(DB::table('appointments')->whereIn('id', $ids)->pluck('status')->unique()->all())->toBe(['cancelled'])
        ->and(DB::table('appointments')->where('id', $ajena)->value('status'))->not->toBe('cancelled');

    $this->as($this->co->owner)->postJson('/api/appointments/bulk-status', ['ids' => [$ids[0]], 'status' => 'confirmed'])
        ->assertOk()->assertJson(['done' => 0])->assertJsonCount(1, 'failed');
});

it('quien no puede cancelar no cancela en lote', function () {
    $servicio = $this->service($this->co->tenant);
    $pro = $this->professional($this->co);
    $id = $this->book($this->co, $this->customer($this->co->tenant), $servicio, $pro, $this->at($this->co->tenant, '10:00'))->assertCreated()->json('id');

    $recepcion = $this->staff($this->co, 'reception');
    $role = DB::table('user_roles')->where('user_id', $recepcion->id)->value('role_id');
    DB::table('role_permissions')->where('role_id', $role)->where('permission_id', \App\Support\Access::permissionIds()['agenda.cancel'])->delete();
    $recepcion = \App\Models\User::find($recepcion->id);

    $this->as($recepcion)->postJson('/api/appointments/bulk-status', ['ids' => [$id], 'status' => 'cancelled', 'reason' => 'Feriado'])
        ->assertOk()->assertJson(['done' => 0])->assertJsonCount(1, 'failed');

    expect(DB::table('appointments')->where('id', $id)->value('status'))->not->toBe('cancelled');
});

it('los días de aviso de los controles salen de la regla del rubro', function () {
    expect(Messaging::settings($this->co->tenant->id)['recall_due']['options']['days'])->toBe(3);

    $this->setRules($this->co->tenant, ['recalls.notify_days' => 7]);

    expect(Messaging::settings($this->co->tenant->id)['recall_due']['options']['days'])->toBe(7)
        ->and(\App\Support\Recalls::noticeDays($this->co->tenant->fresh()))->toBe(7);
});

it('el adelanto en porcentaje usa el precio de la sede', function () {
    $servicio = $this->servicePriced($this->co->tenant, 100, 'Limpieza con adelanto');
    DB::table('services')->where('id', $servicio->id)->update(['requires_deposit' => true, 'deposit_type' => 'percent', 'deposit_value' => 50]);
    DB::table('service_branch_prices')->insert(['tenant_id' => $this->co->tenant->id, 'service_id' => $servicio->id, 'branch_id' => $this->co->branch->id, 'price' => 60]);
    $fila = DB::table('services')->where('id', $servicio->id)->first();
    $otra = $this->branch($this->co);

    expect(PaymentLinks::depositAmount($fila, $this->co->branch->id))->toBe(30.0)
        ->and(PaymentLinks::depositAmount($fila, $otra->id))->toBe(50.0)
        ->and(PaymentLinks::depositAmount($fila))->toBe(50.0)
        ->and(PaymentLinks::priceAt($fila, $this->co->branch->id))->toBe(60.0)
        ->and(PaymentLinks::priceAt($fila, $otra->id))->toBe(100.0);
});
