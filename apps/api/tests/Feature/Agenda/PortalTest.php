<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company();
    $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Manicure');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 60, 'online_booking' => true, 'requires_deposit' => false]);
    $this->ana = $this->professional($this->co, 'Ana Torres');
    $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Paredes');

    $this->reservar = fn (string $inicio, array $extra = [], $quien = null) => $this->as($quien ?? $this->clienta)->postJson('/api/portal/appointments', $extra + [
        'service_id' => $this->servicio->id, 'branch_id' => $this->co->branch->id, 'professional_id' => $this->ana->id, 'starts_at' => $inicio,
    ]);
});

describe('reservar en línea', function () {
    it('la clienta reserva y la cita queda pendiente, marcada como reserva en línea', function () {
        $cita = ($this->reservar)($this->at($this->co->tenant, '10:00'))->assertCreated()->json();
        $fila = DB::table('appointments')->where('id', $cita['id'])->first();

        expect($fila->status)->toBe('pending')
            ->and($fila->source)->toBe('online')
            ->and($fila->customer_id)->toBe($this->clienta->id);
    });

    it('si la empresa confirma sola las reservas en línea, nace confirmada', function () {
        $this->setRules($this->co->tenant, ['booking.auto_confirm' => true]);

        $cita = ($this->reservar)($this->at($this->co->tenant, '10:00'))->assertCreated()->json();

        expect(DB::table('appointments')->where('id', $cita['id'])->value('status'))->toBe('confirmed');
    });

    it('no reserva un horario que ya está ocupado', function () {
        $otra = $this->customer($this->co->tenant, 'Marta', 'Quispe');
        $this->book($this->co, $otra, $this->servicio, $this->ana, $this->at($this->co->tenant, '10:00'))->assertCreated();

        ($this->reservar)($this->at($this->co->tenant, '10:30'))->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');
    });

    it('no reserva con muy poca anticipación', function () {
        $this->setRules($this->co->tenant, ['booking.min_lead_minutes' => 4320]);

        ($this->reservar)($this->at($this->co->tenant, '10:00', 1))
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'Ese horario está demasiado cerca para reservarlo en línea. Elige uno más adelante o comunícate con la sede.']);
    });

    it('no reserva con demasiada anticipación', function () {
        ($this->reservar)($this->at($this->co->tenant, '10:00', 60))
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'Aún no se puede reservar con tanta anticipación. Elige una fecha más cercana.']);
    });

    it('un servicio que no se ofrece en línea no se reserva', function () {
        DB::table('services')->where('id', $this->servicio->id)->update(['online_booking' => false]);

        ($this->reservar)($this->at($this->co->tenant, '10:00'))->assertStatus(422)->assertJsonValidationErrors('service_id');
    });

    it('si la empresa apaga la reserva en línea, no se puede reservar', function () {
        $this->setRules($this->co->tenant, ['booking.enabled' => false]);

        ($this->reservar)($this->at($this->co->tenant, '10:00'))->assertForbidden();
    });
});

describe('adelanto', function () {
    beforeEach(function () {
        DB::table('services')->where('id', $this->servicio->id)->update(['requires_deposit' => true, 'deposit_type' => 'percent', 'deposit_value' => 30]);
    });

    it('pide el adelanto y no crea la cita hasta que se pague', function () {
        $respuesta = ($this->reservar)($this->at($this->co->tenant, '10:00'))->assertStatus(202)->json('deposit');

        expect(money($respuesta['amount']))->toBe(24.0)
            ->and(DB::table('appointments')->where('tenant_id', $this->co->tenant->id)->exists())->toBeFalse();
    });

    it('al pagar el adelanto, la cita queda confirmada y la venta con el saldo por cobrar', function () {
        $token = ($this->reservar)($this->at($this->co->tenant, '10:00'))->assertStatus(202)->json('deposit.token');

        $this->postJson("/api/pay/{$token}/simulate", ['result' => 'approved'])->assertOk()->assertJsonPath('result.status', 'paid');

        $cita = DB::table('appointments')->where('tenant_id', $this->co->tenant->id)->first();
        $venta = DB::table('sales')->where('appointment_id', $cita->id)->first();

        expect($cita->status)->toBe('confirmed')
            ->and(money($venta->paid))->toBe(24.0)
            ->and(money($venta->balance))->toBe(56.0)
            ->and($venta->status)->toBe('partial');
    });

    it('si otra persona toma el horario antes del pago, el link se anula y no se cobra', function () {
        $token = ($this->reservar)($this->at($this->co->tenant, '10:00'))->assertStatus(202)->json('deposit.token');
        $otra = $this->customer($this->co->tenant, 'Marta', 'Quispe');
        $this->book($this->co, $otra, $this->servicio, $this->ana, $this->at($this->co->tenant, '10:00'))->assertCreated();

        $this->postJson("/api/pay/{$token}/simulate", ['result' => 'approved'])->assertStatus(422);

        expect(DB::table('payment_links')->where('token', $token)->value('status'))->toBe('cancelled')
            ->and(DB::table('appointments')->where('customer_id', $this->clienta->id)->exists())->toBeFalse()
            ->and(DB::table('payments')->where('tenant_id', $this->co->tenant->id)->exists())->toBeFalse();
    });

    it('con un paquete vigente para ese servicio, no pide adelanto', function () {
        $plan = $this->as($this->co->owner)->postJson('/api/package-plans', [
            'name' => 'Cinco manicures', 'price' => 300, 'validity_days' => 90, 'booking_mode' => 'free', 'frequency' => 'weekly',
            'professional_mode' => 'any', 'late_policy' => 'lose', 'refund_policy' => 'none',
            'items' => [['service_id' => $this->servicio->id, 'sessions' => 5]],
        ])->assertCreated()->json();
        $venta = $this->sell($this->co, [['type' => 'package', 'id' => $plan['id']]], ['customer_id' => $this->clienta->id]);
        $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => 300])->assertOk();

        ($this->reservar)($this->at($this->co->tenant, '10:00'))->assertCreated();
    });
});

describe('faltas', function () {
    function faltaEnLinea(object $test, int $diasAtras): void
    {
        $inicio = now()->subDays($diasAtras);
        DB::table('appointments')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $test->co->tenant->id, 'code' => 'CITA-' . Str::upper(Str::random(5)),
            'branch_id' => $test->co->branch->id, 'customer_id' => $test->clienta->id, 'service_id' => $test->servicio->id,
            'professional_id' => $test->ana->id, 'starts_at' => $inicio, 'ends_at' => $inicio->copy()->addHour(),
            'status' => 'no_show', 'source' => 'online', 'price' => 80, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    it('si la empresa bloquea por faltas, quien llegó al límite no puede reservar en línea', function () {
        $this->setRules($this->co->tenant, ['booking.miss_action' => 'block', 'booking.miss_limit' => 2, 'booking.block_days' => 30]);
        faltaEnLinea($this, 5);
        faltaEnLinea($this, 3);

        ($this->reservar)($this->at($this->co->tenant, '10:00'))->assertForbidden()->assertJsonPath('code', 'BOOKING_BLOCKED');
    });

    it('con una sola falta todavía puede reservar', function () {
        $this->setRules($this->co->tenant, ['booking.miss_action' => 'block', 'booking.miss_limit' => 2]);
        faltaEnLinea($this, 3);

        ($this->reservar)($this->at($this->co->tenant, '10:00'))->assertCreated();
    });

    it('pasados los días de bloqueo, vuelve a poder reservar', function () {
        $this->setRules($this->co->tenant, ['booking.miss_action' => 'block', 'booking.miss_limit' => 1, 'booking.block_days' => 30]);
        faltaEnLinea($this, 40);

        ($this->reservar)($this->at($this->co->tenant, '10:00'))->assertCreated();
    });

    it('por defecto, las faltas solo se anotan y no bloquean', function () {
        faltaEnLinea($this, 5);
        faltaEnLinea($this, 3);
        faltaEnLinea($this, 1);

        ($this->reservar)($this->at($this->co->tenant, '10:00'))->assertCreated();
    });
});

describe('sus citas', function () {
    beforeEach(function () {
        $this->cita = ($this->reservar)($this->at($this->co->tenant, '10:00'))->assertCreated()->json();
    });

    it('cancela su propia cita con anticipación', function () {
        $this->as($this->clienta)->postJson("/api/portal/appointments/{$this->cita['id']}/cancel")->assertOk();

        $fila = DB::table('appointments')->where('id', $this->cita['id'])->first();

        expect($fila->status)->toBe('cancelled')->and($fila->cancelled_by)->toBe('customer');
    });

    it('a pocas horas ya no puede cancelar en línea', function () {
        $this->travelTo(\Illuminate\Support\Carbon::parse(DB::table('appointments')->where('id', $this->cita['id'])->value('starts_at'))->subHours(2));

        $this->as($this->clienta)->postJson("/api/portal/appointments/{$this->cita['id']}/cancel")->assertForbidden()->assertJsonPath('code', 'POLICY');
    });

    it('no puede tocar la cita de otra persona', function () {
        $otra = $this->customer($this->co->tenant, 'Marta', 'Quispe');

        $this->as($otra)->postJson("/api/portal/appointments/{$this->cita['id']}/cancel")->assertNotFound();
        expect(DB::table('appointments')->where('id', $this->cita['id'])->value('status'))->toBe('pending');
    });

    it('no puede reservar en la sede de otra empresa', function () {
        $otra = $this->company();

        ($this->reservar)($this->at($this->co->tenant, '10:00'), ['branch_id' => $otra->branch->id])->assertNotFound();
    });
});

describe('quién entra al portal', function () {
    it('el personal no usa el portal de clientes', function () {
        $this->as($this->co->owner)->getJson('/api/portal/overview')->assertForbidden();
    });

    it('una clienta no entra a las pantallas del personal', function () {
        $this->as($this->clienta)->getJson('/api/appointments')->assertForbidden();
        $this->as($this->clienta)->getJson('/api/sales')->assertForbidden();
    });

    it('sin iniciar sesión no entra nadie', function () {
        $this->getJson('/api/portal/overview')->assertUnauthorized();
        $this->getJson('/api/appointments')->assertUnauthorized();
    });

    it('quien debe crear su contraseña no puede reservar hasta hacerlo', function () {
        DB::table('customers')->where('id', $this->clienta->id)->update(['must_change_password' => true]);

        ($this->reservar)($this->at($this->co->tenant, '10:00'), [], $this->clienta->fresh())
            ->assertForbidden()
            ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    });
});
