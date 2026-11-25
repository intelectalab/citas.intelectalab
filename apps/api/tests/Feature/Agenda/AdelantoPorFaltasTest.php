<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company();
    $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Manicure');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 60, 'online_booking' => true, 'requires_deposit' => false]);
    $this->ana = $this->professional($this->co, 'Ana Torres');
    $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Paredes');

    $this->reservar = fn (string $inicio) => $this->as($this->clienta)->postJson('/api/portal/appointments', [
        'service_id' => $this->servicio->id, 'branch_id' => $this->co->branch->id, 'professional_id' => $this->ana->id, 'starts_at' => $inicio,
    ]);

    $this->faltar = function (int $diasAtras) {
        $inicio = now()->subDays($diasAtras);
        DB::table('appointments')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'code' => 'CITA-' . Str::upper(Str::random(5)),
            'branch_id' => $this->co->branch->id, 'customer_id' => $this->clienta->id, 'service_id' => $this->servicio->id,
            'professional_id' => $this->ana->id, 'starts_at' => $inicio, 'ends_at' => $inicio->copy()->addHour(),
            'status' => 'no_show', 'source' => 'online', 'price' => 80, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
});

it('con la regla de adelanto, quien llegó al límite de faltas reserva pagando adelanto', function () {
    $this->setRules($this->co->tenant, ['booking.miss_action' => 'deposit', 'booking.miss_limit' => 2, 'booking.block_days' => 30, 'booking.miss_deposit_percent' => 50]);
    ($this->faltar)(5);
    ($this->faltar)(3);

    $adelanto = ($this->reservar)($this->at($this->co->tenant, '10:00'))->assertStatus(202)->json('deposit');

    expect(money($adelanto['amount']))->toBe(40.0)
        ->and(DB::table('appointments')->where('tenant_id', $this->co->tenant->id)->where('status', 'pending')->exists())->toBeFalse();

    $this->as($this->clienta)->getJson('/api/portal/overview')->assertOk()
        ->assertJsonPath('booking_block.deposit_required', true)
        ->assertJsonPath('booking_block.blocked', false);
});

it('el catálogo del portal ya avisa el adelanto por faltas y trae el precio de cada sede', function () {
    $this->setRules($this->co->tenant, ['booking.miss_action' => 'deposit', 'booking.miss_limit' => 1, 'booking.block_days' => 30, 'booking.miss_deposit_percent' => 50]);
    ($this->faltar)(3);
    DB::table('service_branch_prices')->insert(['tenant_id' => $this->co->tenant->id, 'service_id' => $this->servicio->id, 'branch_id' => $this->co->branch->id, 'price' => 95]);

    $servicio = collect($this->as($this->clienta)->getJson('/api/portal/catalog')->assertOk()->json('services'))->firstWhere('id', $this->servicio->id);

    expect($servicio['needs_deposit'])->toBeTrue()
        ->and($servicio['deposit_for_misses'])->toBeTrue()
        ->and(money($servicio['deposit_amount']))->toBe(40.0)
        ->and(money($servicio['branch_prices'][$this->co->branch->id]))->toBe(95.0);
});

it('si el servicio ya pide adelanto, se cobra ese mismo', function () {
    DB::table('services')->where('id', $this->servicio->id)->update(['requires_deposit' => true, 'deposit_type' => 'amount', 'deposit_value' => 15]);
    $this->setRules($this->co->tenant, ['booking.miss_action' => 'deposit', 'booking.miss_limit' => 1, 'booking.require_deposit' => false]);
    ($this->faltar)(3);

    expect(money(($this->reservar)($this->at($this->co->tenant, '10:00'))->assertStatus(202)->json('deposit.amount')))->toBe(15.0);
});

it('antes del límite reserva normal, sin adelanto', function () {
    $this->setRules($this->co->tenant, ['booking.miss_action' => 'deposit', 'booking.miss_limit' => 2]);
    ($this->faltar)(3);

    ($this->reservar)($this->at($this->co->tenant, '10:00'))->assertCreated();
});

it('por defecto se mantiene como antes: las faltas solo se anotan', function () {
    ($this->faltar)(5);
    ($this->faltar)(3);

    ($this->reservar)($this->at($this->co->tenant, '10:00'))->assertCreated();
});
