<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company();
    $this->servicio = $this->servicePriced($this->co->tenant, 50, 'Masaje de prueba');
    $this->clienta = $this->customer($this->co->tenant);
    $this->pro = $this->professional($this->co, 'Ana Paquete');

    $plan = $this->as($this->co->owner)->postJson('/api/package-plans', [
        'name' => 'Dos masajes', 'price' => 90, 'validity_days' => 90,
        'booking_mode' => 'free', 'frequency' => 'weekly', 'professional_mode' => 'any',
        'late_policy' => 'lose', 'refund_policy' => 'unused_normal',
        'items' => [['service_id' => $this->servicio->id, 'sessions' => 2]],
    ])->assertCreated()->json();

    $venta = $this->sell($this->co, [['type' => 'package', 'id' => $plan['id']]], ['customer_id' => $this->clienta->id]);
    $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => $venta['total']])->assertOk();
    $this->paquete = DB::table('customer_packages')->where('sale_id', $venta['id'])->first();
});

it('una cita que se paga con paquete no se puede cobrar aparte', function () {
    $cita = $this->book($this->co, $this->clienta, $this->servicio, $this->pro, $this->at($this->co->tenant, '10:00'))->assertCreated()->json();
    DB::table('appointments')->where('id', $cita['id'])->update(['customer_package_id' => $this->paquete->id]);

    $this->as($this->co->owner)->postJson('/api/pos/sales', ['branch_id' => $this->co->branch->id, 'items' => [['type' => 'appointment', 'id' => $cita['id']]]])
        ->assertStatus(422);
});

it('anular la venta de una cita devuelve la sesión que había usado del paquete', function () {
    $cita = $this->book($this->co, $this->clienta, $this->servicio, $this->pro, $this->at($this->co->tenant, '10:00'))->assertCreated()->json();
    $venta = $this->sell($this->co, [['type' => 'appointment', 'id' => $cita['id']]]);

    DB::table('package_usages')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'customer_package_id' => $this->paquete->id,
        'service_id' => $this->servicio->id, 'appointment_id' => $cita['id'], 'professional_id' => $this->pro->id,
        'sessions' => 1, 'kind' => 'attended', 'used_at' => now(),
    ]);
    DB::table('customer_package_items')->where('customer_package_id', $this->paquete->id)->increment('sessions_used');

    $this->as($this->co->owner)->postJson("/api/sales/{$venta['id']}/void", ['reason' => 'Se cobró por error'])->assertOk();

    expect((int) DB::table('customer_package_items')->where('customer_package_id', $this->paquete->id)->value('sessions_used'))->toBe(0)
        ->and(DB::table('package_usages')->where('appointment_id', $cita['id'])->exists())->toBeFalse()
        ->and(DB::table('customer_package_events')->where('customer_package_id', $this->paquete->id)->where('detail', 'like', '%vuelve al paquete%')->exists())->toBeTrue();
});
