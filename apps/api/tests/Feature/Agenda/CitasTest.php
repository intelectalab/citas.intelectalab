<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company();
    $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Limpieza facial');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 60, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0]);
    $this->servicio = DB::table('services')->where('id', $this->servicio->id)->first();
    $this->ana = $this->professional($this->co, 'Ana Torres');
    $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Paredes');
    $this->otraClienta = $this->customer($this->co->tenant, 'Marta', 'Quispe');
    $this->dia = fn (string $hora) => $this->at($this->co->tenant, $hora);
});

describe('crear', function () {
    it('crea la cita pendiente de confirmar, con su código, precio y duración', function () {
        $cita = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('10:00'))->assertCreated()->json();
        $fila = DB::table('appointments')->where('id', $cita['id'])->first();

        expect($fila->status)->toBe('pending')
            ->and($fila->code)->not->toBeEmpty()
            ->and(money($fila->price))->toBe(80.0)
            ->and(\Illuminate\Support\Carbon::parse($fila->starts_at)->diffInMinutes(\Illuminate\Support\Carbon::parse($fila->ends_at)))->toEqual(60)
            ->and(DB::table('appointment_status_history')->where('appointment_id', $cita['id'])->count())->toBe(1);
    });

    it('si la empresa no pide confirmar, nace confirmada', function () {
        $this->setRules($this->co->tenant, ['agenda.require_confirmation' => false]);

        $cita = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('10:00'))->assertCreated()->json();

        expect(DB::table('appointments')->where('id', $cita['id'])->value('status'))->toBe('confirmed');
    });

    it('cada cita recibe un código distinto', function () {
        $a = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('10:00'))->json('id');
        $b = $this->book($this->co, $this->otraClienta, $this->servicio, $this->ana, ($this->dia)('11:00'))->json('id');

        expect(DB::table('appointments')->whereIn('id', [$a, $b])->distinct()->count('code'))->toBe(2);
    });
});

describe('cruces', function () {
    beforeEach(function () {
        $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('10:00'))->assertCreated();
    });

    it('la misma profesional no puede tener dos citas que se crucen', function () {
        $this->book($this->co, $this->otraClienta, $this->servicio, $this->ana, ($this->dia)('10:30'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'SLOT_UNAVAILABLE')
            ->assertJsonPath('reasons.0.code', 'DOUBLE_BOOKING')
            ->assertJsonPath('overridable', false);
    });

    it('el cruce no se puede forzar ni escribiendo un motivo', function () {
        $this->book($this->co, $this->otraClienta, $this->servicio, $this->ana, ($this->dia)('10:15'), ['override_reason' => 'Es urgente'])
            ->assertStatus(409);

        expect(DB::table('appointments')->where('tenant_id', $this->co->tenant->id)->count())->toBe(1);
    });

    it('una cita que empieza justo cuando termina la otra sí se puede', function () {
        $this->book($this->co, $this->otraClienta, $this->servicio, $this->ana, ($this->dia)('11:00'))->assertCreated();
    });

    it('otra profesional sí puede atender a la misma hora', function () {
        $beto = $this->professional($this->co, 'Beto Ruiz');

        $this->book($this->co, $this->otraClienta, $this->servicio, $beto, ($this->dia)('10:00'))->assertCreated();
    });

    it('la misma clienta no puede estar en dos citas a la vez, aunque sean con otra persona', function () {
        $beto = $this->professional($this->co, 'Beto Ruiz');

        $this->book($this->co, $this->clienta, $this->servicio, $beto, ($this->dia)('10:30'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'CLIENT_OVERLAP');
    });

    it('al cancelar una cita, su horario queda libre', function () {
        $id = DB::table('appointments')->where('tenant_id', $this->co->tenant->id)->value('id');
        $this->as($this->co->owner)->postJson("/api/appointments/{$id}/status", ['status' => 'cancelled', 'reason' => 'Viaje'])->assertOk();

        $this->book($this->co, $this->otraClienta, $this->servicio, $this->ana, ($this->dia)('10:00'))->assertCreated();
    });

    it('respeta el tiempo de limpieza entre citas', function () {
        DB::table('services')->where('id', $this->servicio->id)->update(['buffer_after_minutes' => 15]);

        $this->book($this->co, $this->otraClienta, $this->servicio, $this->ana, ($this->dia)('11:00'))
            ->assertStatus(409)
            ->assertJsonPath('reasons.0.code', 'BUFFER');

        $this->book($this->co, $this->otraClienta, $this->servicio, $this->ana, ($this->dia)('11:15'))->assertCreated();
    });
});

describe('horarios', function () {
    it('no agenda en el pasado', function () {
        $ayer = now('America/Lima')->subDay()->format('Y-m-d') . 'T10:00';

        $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $ayer)
            ->assertStatus(409)
            ->assertJsonPath('reasons.0.code', 'PAST');
    });

    it('no agenda en un feriado', function () {
        $dia = $this->workday($this->co->tenant);
        DB::table('holidays')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'date' => $dia->format('Y-m-d'),
            'name' => 'Feriado de prueba', 'closed' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $dia->format('Y-m-d') . 'T10:00')
            ->assertStatus(409)
            ->assertJson(fn ($json) => $json->where('code', 'SLOT_UNAVAILABLE')->etc())
            ->assertJsonFragment(['code' => 'HOLIDAY']);
    });

    it('no agenda en un horario bloqueado', function () {
        $dia = $this->workday($this->co->tenant);
        DB::table('availability_blocks')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'professional_id' => $this->ana->id,
            'starts_at' => $dia->copy()->setTime(9, 0)->utc(), 'ends_at' => $dia->copy()->setTime(12, 0)->utc(),
            'reason' => 'Capacitación', 'created_at' => now(),
        ]);

        $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('10:00'))
            ->assertStatus(409)
            ->assertJsonFragment(['detail' => 'Capacitación']);
    });

    it('fuera del horario de la profesional se puede forzar con un motivo, y queda registrado', function () {
        $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('20:30'))
            ->assertStatus(409)
            ->assertJsonPath('reasons.0.code', 'OUT_OF_SCHEDULE')
            ->assertJsonPath('overridable', true);

        $cita = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('20:30'), ['override_reason' => 'La clienta solo puede de noche'])
            ->assertCreated()
            ->json();

        expect(DB::table('appointments')->where('id', $cita['id'])->value('override_reason'))->toBe('La clienta solo puede de noche')
            ->and(DB::table('audit_events')->where('entity_id', $cita['id'])->where('action', 'appointment.forced')->exists())->toBeTrue();
    });

    it('si la empresa no permite sobre-agendar, ni con motivo se fuerza', function () {
        $this->setRules($this->co->tenant, ['agenda.allow_override' => false]);

        $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('20:30'), ['override_reason' => 'Urgente'])
            ->assertStatus(409);
    });

    it('al llegar al tope de citas del plan, no deja agendar más ese mes', function () {
        $plan = DB::table('saas_plans')->where('id', self::PLAN_EMPRESARIAL)->first();
        $limites = json_decode($plan->limits, true);
        DB::table('saas_plans')->where('id', $plan->id)->update(['limits' => json_encode(['appointments_month' => 1] + $limites)]);
        $this->forgetCaches();

        $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('10:00'))->assertCreated();

        $this->book($this->co, $this->otraClienta, $this->servicio, $this->ana, ($this->dia)('12:00'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PLAN_LIMIT');
    });
});

describe('reprogramar', function () {
    beforeEach(function () {
        $this->cita = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('10:00'))->assertCreated()->json();
        $this->mover = fn (string $hora, array $extra = []) => $this->as($this->co->owner)
            ->postJson("/api/appointments/{$this->cita['id']}/reschedule", ['starts_at' => ($this->dia)($hora)] + $extra);
    });

    it('mueve la cita y cuenta cuántas veces se reprogramó', function () {
        ($this->mover)('15:00')->assertOk();

        $fila = DB::table('appointments')->where('id', $this->cita['id'])->first();

        expect(\Illuminate\Support\Carbon::parse($fila->starts_at)->setTimezone('America/Lima')->format('H:i'))->toBe('15:00')
            ->and((int) $fila->reschedule_count)->toBe(1);
    });

    it('no la mueve a un horario ocupado y la deja donde estaba', function () {
        $this->book($this->co, $this->otraClienta, $this->servicio, $this->ana, ($this->dia)('15:00'))->assertCreated();

        ($this->mover)('15:30')->assertStatus(409);

        expect(\Illuminate\Support\Carbon::parse(DB::table('appointments')->where('id', $this->cita['id'])->value('starts_at'))->setTimezone('America/Lima')->format('H:i'))->toBe('10:00');
    });

    it('puede moverse sobre su propio horario sin chocar consigo misma', function () {
        ($this->mover)('10:30')->assertOk();
    });

    it('una cita cancelada ya no se reprograma', function () {
        $this->as($this->co->owner)->postJson("/api/appointments/{$this->cita['id']}/status", ['status' => 'cancelled', 'reason' => 'x'])->assertOk();

        ($this->mover)('15:00')->assertStatus(422)->assertJsonPath('code', 'INVALID_TRANSITION');
    });
});
