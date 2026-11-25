<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company();
    $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Manicure');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 60, 'online_booking' => true, 'requires_deposit' => true, 'deposit_type' => 'percent', 'deposit_value' => 30]);
    $this->ana = $this->professional($this->co, 'Ana Torres');
    $this->lucia = $this->customer($this->co->tenant, 'Lucía', 'Paredes');
    $this->marta = $this->customer($this->co->tenant, 'Marta', 'Quispe');
    $this->dia = substr($this->at($this->co->tenant, '10:00'), 0, 10);

    $this->reservar = fn ($quien, string $hora = '10:00') => $this->as($quien)->postJson('/api/portal/appointments', [
        'service_id' => $this->servicio->id, 'branch_id' => $this->co->branch->id, 'professional_id' => $this->ana->id, 'starts_at' => "{$this->dia}T{$hora}",
    ]);
    $this->libres = fn ($quien) => $this->as($quien)->getJson("/api/portal/availability?service_id={$this->servicio->id}&branch_id={$this->co->branch->id}&date={$this->dia}&professional_id={$this->ana->id}")->assertOk()->json('times');
});

it('al pedir el adelanto, el horario queda apartado 10 minutos para los demás', function () {
    $token = ($this->reservar)($this->lucia)->assertStatus(202)->json('deposit.token');
    $link = DB::table('payment_links')->where('token', $token)->first();

    expect(abs(now()->diffInSeconds(\Illuminate\Support\Carbon::parse($link->held_until)) - 600))->toBeLessThan(5);

    expect(($this->libres)($this->marta))->not->toContain('10:00')->toContain('12:00');
    ($this->reservar)($this->marta)->assertStatus(409);

    $this->book($this->co, $this->marta, $this->servicio, $this->ana, "{$this->dia}T10:00")
        ->assertStatus(409)
        ->assertJsonPath('reasons.0.code', 'DOUBLE_BOOKING');
});

it('quien apartó puede volver a elegir su mismo horario', function () {
    ($this->reservar)($this->lucia)->assertStatus(202);

    expect(($this->libres)($this->lucia))->toContain('10:00');
    ($this->reservar)($this->lucia)->assertStatus(202);

    expect(DB::table('payment_links')->where('customer_id', $this->lucia->id)->where('status', 'pending')->count())->toBe(1);
});

it('pagar dentro de los 10 minutos confirma la cita aunque el horario esté apartado', function () {
    $token = ($this->reservar)($this->lucia)->assertStatus(202)->json('deposit.token');
    $this->travelTo(now()->addMinutes(5));

    $this->postJson("/api/pay/{$token}/simulate", ['result' => 'approved'])->assertOk()->assertJsonPath('result.status', 'paid');

    expect(DB::table('appointments')->where('customer_id', $this->lucia->id)->value('status'))->toBe('confirmed');
});

it('pasados los 10 minutos el horario se libera; si otra persona lo toma, el link se anula sin cobrar', function () {
    $token = ($this->reservar)($this->lucia)->assertStatus(202)->json('deposit.token');
    $this->travelTo(now()->addMinutes(11));

    expect(($this->libres)($this->marta))->toContain('10:00');
    ($this->reservar)($this->marta)->assertStatus(202);

    $this->postJson("/api/pay/{$token}/simulate", ['result' => 'approved'])->assertStatus(422);

    expect(DB::table('payment_links')->where('token', $token)->value('status'))->toBe('cancelled')
        ->and(DB::table('payments')->where('tenant_id', $this->co->tenant->id)->exists())->toBeFalse();
});

it('pasados los 10 minutos, si nadie lo tomó, todavía se puede pagar', function () {
    $token = ($this->reservar)($this->lucia)->assertStatus(202)->json('deposit.token');
    $this->travelTo(now()->addMinutes(15));

    $this->postJson("/api/pay/{$token}/simulate", ['result' => 'approved'])->assertOk()->assertJsonPath('result.status', 'paid');
});

it('el link de pago dice hasta cuándo está apartado y la agenda lo muestra', function () {
    $token = ($this->reservar)($this->lucia)->assertStatus(202)->json('deposit.token');

    expect($this->getJson("/api/pay/{$token}")->assertOk()->json('held_until'))->not->toBeNull();

    $dia = $this->as($this->co->owner)->getJson("/api/appointments/day?date={$this->dia}&branch_id={$this->co->branch->id}")->assertOk()->json('professionals');
    $bloques = collect(collect($dia)->firstWhere('id', $this->ana->id)['blocks']);

    expect($bloques->where('kind', 'held')->pluck('starts')->all())->toBe(['10:00']);
});

it('si el adelanto vence, el horario ya no figura apartado', function () {
    ($this->reservar)($this->lucia)->assertStatus(202);
    DB::table('payment_links')->where('customer_id', $this->lucia->id)->update(['expires_at' => now()->subMinute()]);

    expect(($this->libres)($this->marta))->toContain('10:00');
});
