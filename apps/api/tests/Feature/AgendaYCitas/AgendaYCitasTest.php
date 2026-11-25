<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);
    $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Manicure');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 60, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0]);
    $this->ana = $this->professional($this->co, 'Ana Torres');
    $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Paredes');
    $this->dia = fn (string $hora) => $this->at($this->co->tenant, $hora);
});

it('la lista de citas dice el origen y filtra por él; «todas» trae citas sin rango de fechas', function () {
    $propia = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('10:00'))->assertCreated()->json('id');
    $online = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('12:00'))->assertCreated()->json('id');
    DB::table('appointments')->where('id', $online)->update(['source' => 'online']);

    $dia = substr(($this->dia)('10:00'), 0, 10);
    $todas = $this->as($this->co->owner)->getJson("/api/appointments?from={$dia}&to={$dia}")->assertOk()->json();
    expect(collect($todas)->firstWhere('id', $propia)['source_label'])->toBe('Recepción')
        ->and(collect($todas)->firstWhere('id', $online)['source_label'])->toBe('En línea')
        ->and(collect($todas)->firstWhere('id', $propia)['branch']['id'])->toBe($this->co->branch->id);

    $filtradas = $this->as($this->co->owner)->getJson("/api/appointments?from={$dia}&to={$dia}&source=online")->assertOk()->json();
    expect(collect($filtradas)->pluck('id')->all())->toBe([$online]);

    expect($this->as($this->co->owner)->getJson('/api/appointments?all=1')->assertOk()->json())->toHaveCount(2)
        ->and($this->as($this->co->owner)->getJson('/api/appointments')->assertOk()->json())->toHaveCount(0);
});

it('los horarios dicen por qué están ocupados y, si el día está lleno, sugieren los próximos días', function () {
    $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('10:00'))->assertCreated();
    $dia = substr(($this->dia)('10:00'), 0, 10);

    $respuesta = $this->as($this->co->owner)->getJson('/api/appointments/availability?' . http_build_query([
        'service_id' => $this->servicio->id, 'branch_id' => $this->co->branch->id, 'professional_id' => $this->ana->id, 'date' => $dia, 'with_busy' => 1,
    ]))->assertOk();

    $ocupado = collect($respuesta->json('professionals.0.busy'))->firstWhere('time', '10:00');
    expect($ocupado['reason'])->toContain('ya tiene una cita')
        ->and($respuesta->json('professionals.0.slots'))->not->toContain('10:00')
        ->and($respuesta->json('next_days'))->toBe([]);

    $inicio = Carbon::parse("{$dia} 00:00", 'America/Lima');
    DB::table('availability_blocks')->insert([
        'tenant_id' => $this->co->tenant->id, 'professional_id' => $this->ana->id, 'kind' => 'vacation', 'reason' => 'Vacaciones',
        'starts_at' => $inicio->copy()->utc(), 'ends_at' => $inicio->copy()->addDay()->utc(),
    ]);

    $lleno = $this->as($this->co->owner)->getJson('/api/appointments/availability?' . http_build_query([
        'service_id' => $this->servicio->id, 'branch_id' => $this->co->branch->id, 'professional_id' => $this->ana->id, 'date' => $dia, 'with_busy' => 1,
    ]))->assertOk();

    expect($lleno->json('professionals.0.slots'))->toBe([])
        ->and($lleno->json('next_days.0.date'))->toBe($inicio->copy()->addDay()->format('Y-m-d'))
        ->and($lleno->json('next_days.0.count'))->toBeGreaterThan(0);
});

it('el detalle de la cita trae visitas, faltas, saldo, origen y los mensajes enviados', function () {
    $cita = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('10:00'))->assertCreated()->json('id');
    DB::table('customers')->where('id', $this->clienta->id)->update(['alert' => 'Alérgica al acetona']);

    foreach ([5, 4] as $dias) {
        DB::table('appointments')->insert([
            'tenant_id' => $this->co->tenant->id, 'code' => 'CIT-F' . $dias, 'branch_id' => $this->co->branch->id, 'customer_id' => $this->clienta->id,
            'service_id' => $this->servicio->id, 'professional_id' => $this->ana->id, 'status' => 'no_show', 'source' => 'staff', 'price' => 80,
            'starts_at' => now()->subDays($dias), 'ends_at' => now()->subDays($dias)->addHour(),
        ]);
    }
    DB::table('messages')->insert([
        'tenant_id' => $this->co->tenant->id, 'customer_id' => $this->clienta->id, 'channel' => 'email', 'kind' => 'reminder', 'status' => 'sent',
        'body' => 'Te esperamos', 'entity_type' => 'appointment', 'entity_id' => $cita, 'sent_at' => now(),
    ]);

    $detalle = $this->as($this->co->owner)->getJson("/api/appointments/{$cita}")->assertOk();

    expect($detalle->json('customer_profile.no_shows'))->toBe(2)
        ->and($detalle->json('customer_profile.visits'))->toBe(0)
        ->and($detalle->json('customer_profile.alert'))->toBe('Alérgica al acetona')
        ->and($detalle->json('source_label'))->toBe('Recepción')
        ->and(collect($detalle->json('messages'))->firstWhere('kind', 'Recordatorio de cita')['status_label'])->toBe('Enviado')
        ->and($detalle->json('cancel_reasons'))->toContain('Se agendó por error');
});

it('la penalidad se perdona desde la cita sin deshacer «No asistió», y solo quien puede anular ventas', function () {
    $this->setRules($this->co->tenant, ['penalties.enabled' => true, 'noshow.fee_percent' => 50]);
    $cita = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('10:00'))->assertCreated()->json('id');
    $this->travelTo(Carbon::parse(DB::table('appointments')->where('id', $cita)->value('starts_at'))->addHours(2));
    $penalidad = $this->as($this->co->owner)->postJson("/api/appointments/{$cita}/status", ['status' => 'no_show'])->assertOk()->json('penalty');

    $this->as($this->ana->user)->postJson("/api/appointments/{$cita}/waive-penalty", ['reason' => 'Primera vez'])->assertForbidden();
    $this->as($this->co->owner)->postJson("/api/appointments/{$cita}/waive-penalty", [])->assertStatus(422);

    $respuesta = $this->as($this->co->owner)->postJson("/api/appointments/{$cita}/waive-penalty", ['reason' => 'Primera vez'])->assertOk();

    expect($respuesta->json('status'))->toBe('no_show')
        ->and($respuesta->json('penalty.status'))->toBe('void')
        ->and(DB::table('sales')->where('id', $penalidad['sale_id'])->value('status'))->toBe('void')
        ->and(DB::table('audit_events')->where('action', 'appointment.penalty_waived')->where('entity_id', $cita)->exists())->toBeTrue();

    $this->as($this->co->owner)->postJson("/api/appointments/{$cita}/waive-penalty", ['reason' => 'Otra vez'])->assertStatus(422);
});

it('el alta rápida avisa si ya existe alguien con ese celular o correo', function () {
    DB::table('customers')->where('id', $this->clienta->id)->update(['phone' => '+51 987 654 321', 'email' => 'lucia@correo.test']);

    $this->as($this->co->owner)->getJson('/api/appointments/customer-duplicates?phone=987654321')->assertOk()
        ->assertJsonPath('matches.0.id', $this->clienta->id)
        ->assertJsonPath('matches.0.by', ['celular']);
    $this->as($this->co->owner)->getJson('/api/appointments/customer-duplicates?email=LUCIA@correo.test')->assertOk()
        ->assertJsonPath('matches.0.by', ['correo']);
    $this->as($this->co->owner)->getJson('/api/appointments/customer-duplicates?phone=911111111')->assertOk()
        ->assertJsonPath('matches', []);

    $otra = $this->company();
    $this->as($otra->owner)->getJson('/api/appointments/customer-duplicates?phone=987654321')->assertOk()->assertJsonPath('matches', []);
});

it('el permiso de promociones del alta rápida solo se da al registrar, no a una ficha antigua', function () {
    $nueva = $this->customer($this->co->tenant, 'Rosa', 'Nueva');

    $this->as($this->co->owner)->postJson('/api/appointments/customer-consent', ['customer_id' => $nueva->id])->assertOk()
        ->assertJsonPath('consents.email.granted', true)
        ->assertJsonPath('consents.whatsapp.granted', true);

    DB::table('customers')->where('id', $this->clienta->id)->update(['created_at' => now()->subDays(3)]);
    $this->as($this->co->owner)->postJson('/api/appointments/customer-consent', ['customer_id' => $this->clienta->id])->assertForbidden();
});

describe('bloqueo de salas y equipos', function () {
    beforeEach(function () {
        $this->sala = (string) Str::uuid();
        DB::table('rooms')->insert([
            'id' => $this->sala, 'tenant_id' => $this->co->tenant->id, 'branch_id' => $this->co->branch->id, 'name' => 'Cabina 1',
            'kind' => 'room', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('service_rooms')->insert(['tenant_id' => $this->co->tenant->id, 'service_id' => $this->servicio->id, 'room_id' => $this->sala]);
        $dia = substr(($this->dia)('10:00'), 0, 10);
        $this->bloqueo = ['room_id' => $this->sala, 'starts_at' => "{$dia}T09:00", 'ends_at' => "{$dia}T12:00", 'reason' => 'Mantenimiento del sillón'];
    });

    it('bloquear la sala impide agendar en ella y se ve en la lista con quién lo registró', function () {
        $id = $this->as($this->co->owner)->postJson('/api/availability-blocks', $this->bloqueo)->assertCreated()
            ->assertJsonPath('scope', 'room')
            ->assertJsonPath('room.name', 'Cabina 1')
            ->json('id');

        $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('10:00'))
            ->assertStatus(409)->assertJsonPath('reasons.0.code', 'ROOM_BUSY');
        $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('13:00'))->assertCreated();

        $fila = collect($this->as($this->co->owner)->getJson('/api/availability-blocks')->assertOk()->json())->firstWhere('id', $id);
        expect($fila['created_by_name'])->toBe('Dueña Prueba')
            ->and($fila['kind_label'])->toBe('Sala bloqueada');

        $dia = substr(($this->dia)('10:00'), 0, 10);
        $agenda = $this->as($this->co->owner)->getJson("/api/appointments/day?date={$dia}&branch_id={$this->co->branch->id}")->assertOk();
        expect($agenda->json('rooms.0.name'))->toBe('Cabina 1')
            ->and($agenda->json('rooms.0.blocks.0.reason'))->toBe('Mantenimiento del sillón');

        $this->as($this->co->owner)->deleteJson("/api/availability-blocks/{$id}")->assertOk();
        $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('10:00'))->assertCreated();
    });

    it('un profesional no bloquea salas y otra empresa no ve el bloqueo', function () {
        $this->as($this->ana->user)->postJson('/api/availability-blocks', $this->bloqueo)->assertForbidden();

        $id = $this->as($this->co->owner)->postJson('/api/availability-blocks', $this->bloqueo)->assertCreated()->json('id');
        $otra = $this->company();

        $this->as($otra->owner)->getJson("/api/availability-blocks/{$id}")->assertNotFound();
        $this->as($otra->owner)->deleteJson("/api/availability-blocks/{$id}")->assertNotFound();
        expect(DB::table('room_blocks')->where('id', $id)->exists())->toBeTrue();
    });

    it('sin el módulo de salas no se puede bloquear una sala', function () {
        DB::table('tenant_settings')->where('tenant_id', $this->co->tenant->id)->update(['modules_off' => json_encode(['resources'])]);
        self::forgetCaches();

        $this->as($this->co->owner)->postJson('/api/availability-blocks', $this->bloqueo)->assertForbidden();
    });
});

it('la agenda del día trae la preparación y limpieza de cada cita y si pagó adelanto', function () {
    DB::table('services')->where('id', $this->servicio->id)->update(['buffer_before_minutes' => 5, 'buffer_after_minutes' => 10]);
    $this->setRules($this->co->tenant, ['agenda.respect_buffers' => true]);
    $cita = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, ($this->dia)('10:00'))->assertCreated()->json('id');
    DB::table('payment_links')->insert([
        'tenant_id' => $this->co->tenant->id, 'token' => Str::random(40), 'purpose' => 'deposit', 'status' => 'paid', 'amount' => 24,
        'description' => 'Adelanto', 'appointment_id' => $cita, 'expires_at' => now()->addDay(), 'paid_at' => now(),
    ]);
    $dia = substr(($this->dia)('10:00'), 0, 10);

    $agenda = $this->as($this->co->owner)->getJson("/api/appointments/day?date={$dia}&branch_id={$this->co->branch->id}")->assertOk();

    expect($agenda->json('respect_buffers'))->toBeTrue()
        ->and($agenda->json('appointments.0.buffers'))->toBe(['before' => 5, 'after' => 10])
        ->and($agenda->json('appointments.0.deposit_paid'))->toBeTrue()
        ->and($this->as($this->co->owner)->getJson("/api/appointments/{$cita}")->json('deposit.status'))->toBe('paid');
});

it('el contexto para agendar trae categoría, preparación, adelanto, orígenes y motivos de cancelación', function () {
    DB::table('services')->where('id', $this->servicio->id)->update(['requires_deposit' => true, 'deposit_type' => 'percent', 'deposit_value' => 30, 'buffer_after_minutes' => 10]);
    $this->setRules($this->co->tenant, ['booking.require_deposit' => true]);

    $contexto = $this->as($this->co->owner)->getJson('/api/appointments/context')->assertOk();
    $servicio = collect($contexto->json('services'))->firstWhere('id', $this->servicio->id);

    expect($servicio['buffer_after'])->toBe(10)
        ->and($servicio['deposit']['label'])->toBe('30 %')
        ->and(collect($contexto->json('origins'))->pluck('value'))->toContain('online')
        ->and($contexto->json('cancel_reasons'))->not->toBeEmpty();
});
