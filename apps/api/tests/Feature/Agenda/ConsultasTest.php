<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company();
    $this->servicio = $this->servicePriced($this->co->tenant, 60, 'Pedicure');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 60, 'online_booking' => true, 'requires_deposit' => false]);
    $this->servicio = DB::table('services')->where('id', $this->servicio->id)->first();
    $this->ana = $this->professional($this->co, 'Ana Torres');
    $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Paredes');
    $this->dia = $this->workday($this->co->tenant)->format('Y-m-d');
    $this->cita = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, "{$this->dia}T10:00")->assertCreated()->json();
});

describe('agenda del personal', function () {
    it('el día muestra a cada profesional con su horario y sus citas', function () {
        $datos = $this->as($this->co->owner)->getJson("/api/appointments/day?date={$this->dia}&branch_id={$this->co->branch->id}")->assertOk()->json();

        $ana = collect($datos['professionals'])->firstWhere('id', $this->ana->id);

        expect($ana['schedules'][0])->toBe(['starts' => '08:00', 'ends' => '20:00'])
            ->and(collect($datos['appointments'])->pluck('id')->all())->toBe([$this->cita['id']]);
    });

    it('la semana trae las citas de esos días', function () {
        $hasta = \Illuminate\Support\Carbon::parse($this->dia)->addDays(6)->format('Y-m-d');

        $datos = $this->as($this->co->owner)->getJson("/api/appointments/range?from={$this->dia}&to={$hasta}&branch_id={$this->co->branch->id}")->assertOk()->json();

        expect(json_encode($datos))->toContain($this->cita['id']);
    });

    it('los horarios libres no incluyen los que ya están ocupados', function () {
        $datos = $this->as($this->co->owner)
            ->getJson("/api/appointments/availability?service_id={$this->servicio->id}&branch_id={$this->co->branch->id}&date={$this->dia}&professional_id={$this->ana->id}")
            ->assertOk()->json();

        $libres = $datos['professionals'][0]['slots'];

        expect($libres)->toContain('08:00')->toContain('11:00')->toContain('19:00')
            ->not->toContain('10:00')->not->toContain('10:30')->not->toContain('09:15')
            ->not->toContain('19:15');
    });

    it('al mover una cita, su propio horario cuenta como libre', function () {
        $datos = $this->as($this->co->owner)
            ->getJson("/api/appointments/availability?service_id={$this->servicio->id}&branch_id={$this->co->branch->id}&date={$this->dia}&professional_id={$this->ana->id}&ignore_id={$this->cita['id']}")
            ->assertOk()->json();

        expect($datos['professionals'][0]['slots'])->toContain('10:00');
    });

    it('el contexto trae sedes, profesionales y servicios de la empresa', function () {
        $datos = $this->as($this->co->owner)->getJson('/api/appointments/context')->assertOk()->json();

        expect(collect($datos['professionals'])->pluck('id'))->toContain($this->ana->id)
            ->and(collect($datos['services'])->pluck('id'))->toContain($this->servicio->id)
            ->and(collect($datos['branches'])->pluck('id')->all())->toBe([$this->co->branch->id])
            ->and($datos['can_override'])->toBeTrue();
    });

    it('el detalle de la cita trae su historial', function () {
        $this->as($this->co->owner)->postJson("/api/appointments/{$this->cita['id']}/status", ['status' => 'confirmed'])->assertOk();

        $datos = $this->as($this->co->owner)->getJson("/api/appointments/{$this->cita['id']}")->assertOk()->json();

        expect($datos['status'])->toBe('confirmed')
            ->and(count($datos['history']))->toBe(2);
    });

    it('la lista se puede filtrar por fase y por clienta', function () {
        $otra = $this->customer($this->co->tenant, 'Marta', 'Quispe');
        $segunda = $this->book($this->co, $otra, $this->servicio, $this->ana, "{$this->dia}T15:00")->json('id');
        $this->as($this->co->owner)->postJson("/api/appointments/{$segunda}/status", ['status' => 'confirmed'])->assertOk();

        $confirmadas = collect($this->as($this->co->owner)->getJson("/api/appointments?date={$this->dia}&status=confirmed")->json())->pluck('id');
        $deLucia = collect($this->as($this->co->owner)->getJson("/api/appointments?customer_id={$this->clienta->id}")->json())->pluck('id');

        expect($confirmadas->all())->toBe([$segunda])
            ->and($deLucia->all())->toBe([$this->cita['id']]);
    });

    it('los números de atención responden', function () {
        $this->as($this->co->owner)->getJson('/api/appointments/metrics')->assertOk();
    });
});

describe('portal de la clienta', function () {
    it('su inicio muestra sus citas y las reglas de la empresa', function () {
        $datos = $this->as($this->clienta)->getJson('/api/portal/overview')->assertOk()->json();

        expect($datos['customer']['id'])->toBe($this->clienta->id)
            ->and(collect($datos['appointments'])->pluck('id')->all())->toBe([$this->cita['id']])
            ->and($datos['policies']['booking_enabled'])->toBeTrue();
    });

    it('el catálogo solo muestra lo que se reserva en línea', function () {
        $oculto = $this->servicePriced($this->co->tenant, 99, 'Solo en sede');
        DB::table('services')->where('id', $oculto->id)->update(['online_booking' => false]);

        $texto = json_encode($this->as($this->clienta)->getJson('/api/portal/catalog')->assertOk()->json());

        expect($texto)->toContain($this->servicio->id)->not->toContain($oculto->id);
    });

    it('ve los horarios libres del día sin los ocupados', function () {
        $datos = $this->as($this->clienta)
            ->getJson("/api/portal/availability?service_id={$this->servicio->id}&branch_id={$this->co->branch->id}&date={$this->dia}&professional_id={$this->ana->id}")
            ->assertOk()->json();

        expect(json_encode($datos))->toContain('11:00')->not->toContain('"10:00"');
    });

    it('confirma su cita', function () {
        $this->as($this->clienta)->postJson("/api/portal/appointments/{$this->cita['id']}/confirm")->assertOk();
        $this->as($this->clienta)->postJson("/api/portal/appointments/{$this->cita['id']}/confirm")->assertStatus(422);

        expect(DB::table('appointments')->where('id', $this->cita['id'])->value('status'))->toBe('confirmed');
    });

    it('reprograma su cita hasta el máximo de veces que permite la empresa', function () {
        $this->setRules($this->co->tenant, ['reschedule.max_times' => 1]);
        $url = "/api/portal/appointments/{$this->cita['id']}/reschedule";

        $this->as($this->clienta)->postJson($url, ['starts_at' => "{$this->dia}T15:00"])->assertOk();
        $this->as($this->clienta)->postJson($url, ['starts_at' => "{$this->dia}T16:00"])->assertForbidden();

        expect(\Illuminate\Support\Carbon::parse(DB::table('appointments')->where('id', $this->cita['id'])->value('starts_at'))->setTimezone('America/Lima')->format('H:i'))->toBe('15:00');
    });

    it('no reprograma a un horario ocupado', function () {
        $otra = $this->customer($this->co->tenant, 'Marta', 'Quispe');
        $this->book($this->co, $otra, $this->servicio, $this->ana, "{$this->dia}T15:00")->assertCreated();

        $this->as($this->clienta)->postJson("/api/portal/appointments/{$this->cita['id']}/reschedule", ['starts_at' => "{$this->dia}T15:30"])->assertStatus(409);
    });

    it('se anota en la lista de espera, sin repetirse, y puede salir', function () {
        $datos = ['service_id' => $this->servicio->id, 'branch_id' => $this->co->branch->id, 'time_of_day' => 'morning', 'within_days' => 7];

        $entrada = $this->as($this->clienta)->postJson('/api/portal/waitlist', $datos)->assertSuccessful()->json();
        $this->as($this->clienta)->postJson('/api/portal/waitlist', $datos)->assertStatus(409)->assertJsonPath('code', 'DUPLICATE');

        $id = $entrada['id'] ?? DB::table('waitlist_entries')->where('customer_id', $this->clienta->id)->value('id');
        expect(json_encode($this->as($this->clienta)->getJson('/api/portal/waitlist')->assertOk()->json()))->toContain($id);

        $this->as($this->clienta)->deleteJson("/api/portal/waitlist/{$id}")->assertOk();
        $this->as($this->clienta)->postJson('/api/portal/waitlist', $datos)->assertSuccessful();
    });

    it('ve sus links de pago, pero no anula uno que creó la empresa', function () {
        $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]], ['customer_id' => $this->clienta->id]);
        $link = $this->as($this->co->owner)->postJson("/api/sales/{$venta['id']}/links")->assertCreated()->json();

        $links = $this->as($this->clienta)->getJson('/api/portal/payment-links')->assertOk()->json('links');
        expect(collect($links)->pluck('id'))->toContain($link['id']);

        $this->as($this->clienta)->postJson("/api/portal/payment-links/{$link['id']}/cancel")->assertNotFound();
        expect(DB::table('payment_links')->where('id', $link['id'])->value('status'))->toBe('pending');
    });

    it('desiste de una reserva con adelanto y el horario queda libre', function () {
        DB::table('services')->where('id', $this->servicio->id)->update(['requires_deposit' => true, 'deposit_type' => 'percent', 'deposit_value' => 50]);
        $adelanto = $this->as($this->clienta)->postJson('/api/portal/appointments', ['service_id' => $this->servicio->id, 'branch_id' => $this->co->branch->id, 'professional_id' => $this->ana->id, 'starts_at' => "{$this->dia}T15:00"])
            ->assertStatus(202)->json('deposit');

        $this->as($this->clienta)->postJson("/api/portal/payment-links/{$adelanto['id']}/cancel")->assertOk();

        expect(DB::table('payment_links')->where('id', $adelanto['id'])->value('status'))->toBe('cancelled');
        $this->postJson("/api/pay/{$adelanto['token']}/simulate", ['result' => 'approved'])->assertStatus(422);
    });

    it('actualiza sus datos', function () {
        $this->as($this->clienta)->putJson('/api/portal/profile', ['first_name' => 'Lucía', 'last_name' => 'Paredes', 'phone' => '987654321', 'email' => $this->clienta->email])->assertOk();

        expect(DB::table('customers')->where('id', $this->clienta->id)->value('phone'))->toBe('987654321');
    });
});

describe('página de pago', function () {
    it('muestra el monto y el estado del link sin iniciar sesión', function () {
        $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]], ['customer_id' => $this->clienta->id]);
        $link = $this->as($this->co->owner)->postJson("/api/sales/{$venta['id']}/links")->assertCreated()->json();
        $token = DB::table('payment_links')->where('id', $link['id'])->value('token');

        $this->app['auth']->forgetGuards();
        $vista = $this->getJson("/api/pay/{$token}")->assertOk()->json();

        expect(money($vista['amount']))->toBe(60.0)->and($vista['status'])->toBe('pending');
        $this->getJson('/api/pay/no-existe')->assertNotFound();
    });

    it('el personal anula el link y ya no se puede pagar', function () {
        $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]]);
        $link = $this->as($this->co->owner)->postJson("/api/sales/{$venta['id']}/links")->assertCreated()->json();
        $token = DB::table('payment_links')->where('id', $link['id'])->value('token');

        $this->as($this->co->owner)->postJson("/api/payment-links/{$link['id']}/cancel")->assertOk();

        $this->postJson("/api/pay/{$token}/simulate", ['result' => 'approved'])->assertStatus(422);
        expect($this->sale($venta['id'])->status)->toBe('pending');
    });
});

describe('punto de venta', function () {
    it('el tablero responde con lo que se puede vender', function () {
        $this->as($this->co->owner)->getJson("/api/pos?branch_id={$this->co->branch->id}")->assertOk();
    });

    it('la vista previa calcula el total sin guardar la venta', function () {
        $datos = $this->as($this->co->owner)->postJson('/api/pos/preview', ['branch_id' => $this->co->branch->id, 'items' => [['type' => 'service', 'id' => $this->servicio->id, 'quantity' => 2]]])
            ->assertOk()->json();

        expect(json_encode($datos))->toContain('120')
            ->and(DB::table('sales')->where('tenant_id', $this->co->tenant->id)->exists())->toBeFalse();
    });

    it('una venta por cobrar se puede corregir, una cobrada no', function () {
        $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]]);
        $url = "/api/pos/sales/{$venta['id']}";

        $this->as($this->co->owner)->putJson($url, ['branch_id' => $this->co->branch->id, 'items' => [['type' => 'service', 'id' => $this->servicio->id, 'quantity' => 3]]])->assertOk();
        expect(money($this->sale($venta['id'])->total))->toBe(180.0);

        $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => 180])->assertOk();
        $this->as($this->co->owner)->putJson($url, ['branch_id' => $this->co->branch->id, 'items' => [['type' => 'service', 'id' => $this->servicio->id]]])->assertStatus(422);

        expect(money($this->sale($venta['id'])->total))->toBe(180.0);
    });

    it('una venta se puede cargar para cobrarla', function () {
        $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]]);

        $this->as($this->co->owner)->getJson("/api/pos/sales/{$venta['id']}")->assertOk();
        $this->as($this->co->owner)->getJson("/api/sales/{$venta['id']}/charge")->assertOk();
    });

    it('"agendar para ahora" crea la cita como que ya llegó', function () {
        $this->travelTo($this->workday($this->co->tenant, 2)->setTime(11, 0));

        $this->as($this->co->owner)->getJson("/api/pos/walk-in?branch_id={$this->co->branch->id}&service_id={$this->servicio->id}")->assertOk();

        $cita = $this->as($this->co->owner)->postJson('/api/pos/walk-in', [
            'customer_id' => $this->customer($this->co->tenant)->id, 'service_id' => $this->servicio->id,
            'professional_id' => $this->ana->id, 'branch_id' => $this->co->branch->id, 'at' => 'now',
        ])->assertCreated()->json();

        expect(DB::table('appointments')->where('id', $cita['id'])->value('status'))->toBe('checked_in');
    });

    it('"agendar para ahora" no acepta una hora que ya pasó', function () {
        $this->travelTo($this->workday($this->co->tenant, 2)->setTime(15, 0));

        $this->as($this->co->owner)->postJson('/api/pos/walk-in', [
            'customer_id' => $this->clienta->id, 'service_id' => $this->servicio->id,
            'professional_id' => $this->ana->id, 'branch_id' => $this->co->branch->id, 'at' => '09:00',
        ])->assertStatus(422)->assertJsonValidationErrors('at');
    });
});
