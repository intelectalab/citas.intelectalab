<?php

use App\Support\Automations;
use App\Support\PlatformMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    PlatformMail::$outbox = [];
    $this->co = $this->company(['modules_off' => []]);
    DB::table('tenant_integrations')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'kind' => 'email', 'provider' => 'simulated', 'mode' => 'simulated',
        'settings' => json_encode(['from_address' => 'citas@empresa.test', 'from_name' => 'Empresa']), 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Terapia');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 60]);
    $this->ana = $this->professional($this->co, 'Ana Torres');
    $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Paredes');
    $this->dia = $this->workday($this->co->tenant, 2);
    DB::table('holidays')->where('tenant_id', $this->co->tenant->id)->delete();

    $this->mensajes = fn (string $kind, ?string $customerId = null) => DB::table('messages')->where('tenant_id', $this->co->tenant->id)->where('kind', $kind)
        ->when($customerId, fn ($q, $id) => $q->where('customer_id', $id))->get();

    $this->serie = fn (int $veces) => $this->as($this->co->owner)->postJson('/api/appointments/series', [
        'customer_id' => $this->clienta->id, 'service_id' => $this->servicio->id, 'professional_id' => $this->ana->id,
        'branch_id' => $this->co->branch->id, 'starts_at' => $this->dia->format('Y-m-d') . 'T10:00',
        'frequency' => 'weekly', 'occurrences' => $veces,
    ])->assertCreated();

    $this->espera = function (object $cliente, array $extra = []) {
        $id = (string) Str::uuid();
        DB::table('waitlist_entries')->insert($extra + [
            'id' => $id, 'tenant_id' => $this->co->tenant->id, 'branch_id' => $this->co->branch->id, 'customer_id' => $cliente->id,
            'service_id' => $this->servicio->id, 'date_from' => now('America/Lima')->format('Y-m-d'), 'status' => 'waiting',
        ]);

        return $id;
    };
});

describe('M2: una serie manda un solo mensaje', function () {
    it('al agendar 6 fechas llega una sola confirmación con todas las fechas', function () {
        ($this->serie)(6);

        $confirmaciones = ($this->mensajes)('booking_confirmation', $this->clienta->id);

        expect($confirmaciones)->toHaveCount(1)
            ->and($confirmaciones->first()->body)->toContain('Quedaron agendadas 6 fechas')
            ->and($confirmaciones->first()->body)->toContain('cada semana');
    });

    it('al cancelar toda la serie llega un solo aviso de cancelación', function () {
        $serie = ($this->serie)(5);

        $this->as($this->co->owner)->postJson("/api/appointments/{$serie->json('created.0.id')}/status", [
            'status' => 'cancelled', 'reason' => 'Ya no seguirá la terapia', 'series_scope' => 'all',
        ])->assertOk()->assertJsonPath('series_cancelled', 4);

        $avisos = ($this->mensajes)('appointment_cancelled', $this->clienta->id);

        expect($avisos)->toHaveCount(1)
            ->and($avisos->first()->body)->toContain('En total, 5 citas')
            ->and(DB::table('appointments')->where('series_id', $serie->json('series_id'))->where('status', 'cancelled')->count())->toBe(5);
    });

    it('una cita sola sigue mandando su confirmación de siempre', function () {
        $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->dia->format('Y-m-d') . 'T11:00')->assertCreated();

        $confirmaciones = ($this->mensajes)('booking_confirmation', $this->clienta->id);

        expect($confirmaciones)->toHaveCount(1)
            ->and($confirmaciones->first()->body)->not->toContain('Quedaron agendadas');
    });
});

describe('M4: deshacer «No asistió»', function () {
    beforeEach(function () {
        $this->setRules($this->co->tenant, ['penalties.enabled' => true, 'noshow.fee_percent' => 50]);
        $this->cita = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->dia->format('Y-m-d') . 'T10:00')->assertCreated()->json('id');
        $this->travelTo(Carbon::parse(DB::table('appointments')->where('id', $this->cita)->value('starts_at'))->addHours(2));
        $this->faltó = fn () => $this->as($this->co->owner)->postJson("/api/appointments/{$this->cita}/status", ['status' => 'no_show'])->assertOk();
    });

    it('antes de marcar avisa cuánto será la penalidad', function () {
        $this->as($this->co->owner)->getJson("/api/appointments/{$this->cita}")->assertOk()
            ->assertJsonPath('penalty_preview.no_show', 40);
    });

    it('deshace la falta, vuelve a la fase anterior y perdona la penalidad', function () {
        $antes = DB::table('appointments')->where('id', $this->cita)->value('status');
        $penalidad = ($this->faltó)()->assertJsonPath('can_undo_no_show', true)->json('penalty');

        $respuesta = $this->as($this->co->owner)->postJson("/api/appointments/{$this->cita}/undo-no-show")->assertOk();

        expect($respuesta->json('status'))->toBe($antes)
            ->and($respuesta->json('penalty_waived.code'))->toBe($penalidad['code'])
            ->and($respuesta->json('can_undo_no_show'))->toBeFalse()
            ->and(DB::table('sales')->where('id', $penalidad['sale_id'])->value('status'))->toBe('void')
            ->and(DB::table('appointment_status_history')->where('appointment_id', $this->cita)->where('from_status', 'no_show')->value('reason'))->toContain('Se deshizo');
    });

    it('no deshace si la penalidad ya tiene un pago', function () {
        $penalidad = ($this->faltó)()->json('penalty');
        DB::table('sales')->where('id', $penalidad['sale_id'])->update(['paid' => 10, 'balance' => 30, 'status' => 'partial']);

        $this->as($this->co->owner)->postJson("/api/appointments/{$this->cita}/undo-no-show")->assertStatus(422)->assertJsonPath('code', 'POLICY');

        expect(DB::table('appointments')->where('id', $this->cita)->value('status'))->toBe('no_show');
    });

    it('solo se deshace una cita marcada como que no asistió', function () {
        $this->as($this->co->owner)->postJson("/api/appointments/{$this->cita}/undo-no-show")->assertStatus(422)->assertJsonPath('code', 'INVALID_TRANSITION');
    });

    it('quien no puede marcar faltas tampoco las deshace', function () {
        ($this->faltó)();
        $recepcion = $this->staff($this->co, 'professional');

        $this->as($recepcion)->postJson("/api/appointments/{$this->cita}/undo-no-show")->assertForbidden();
    });
});

describe('M5: lista de espera', function () {
    it('reprogramar una cita avisa a quien espera ese horario', function () {
        $cita = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->dia->format('Y-m-d') . 'T10:00')->assertCreated()->json('id');
        $otra = $this->customer($this->co->tenant, 'Rosa', 'Vega');
        $entrada = ($this->espera)($otra);

        $this->as($this->co->owner)->postJson("/api/appointments/{$cita}/reschedule", ['starts_at' => $this->dia->format('Y-m-d') . 'T15:00'])->assertOk();

        $avisos = ($this->mensajes)('waitlist_slot', $otra->id);

        expect($avisos)->toHaveCount(1)
            ->and($avisos->first()->entity_id)->toBe($entrada)
            ->and($avisos->first()->body)->toContain('10:00');
    });

    it('si la cita se mueve dentro del mismo horario no avisa', function () {
        $cita = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->dia->format('Y-m-d') . 'T10:00')->assertCreated()->json('id');
        $otra = $this->customer($this->co->tenant, 'Rosa', 'Vega');
        ($this->espera)($otra);

        $this->as($this->co->owner)->postJson("/api/appointments/{$cita}/reschedule", ['starts_at' => $this->dia->format('Y-m-d') . 'T10:30'])->assertOk();

        expect(($this->mensajes)('waitlist_slot', $otra->id))->toHaveCount(0);
    });

    it('«Avisar» en la lista de espera manda el mensaje con el primer horario libre', function () {
        $entrada = ($this->espera)($this->clienta);

        $respuesta = $this->as($this->co->owner)->postJson("/api/waitlist/{$entrada}/status", ['status' => 'notified'])->assertOk();

        expect($respuesta->json('status'))->toBe('notified')
            ->and($respuesta->json('notice.sent'))->toBeTrue()
            ->and(($this->mensajes)('waitlist_slot', $this->clienta->id))->toHaveCount(1);
    });

    it('«Avisar» no marca ni envía si no hay horarios que le sirvan', function () {
        DB::table('professional_schedules')->where('professional_id', $this->ana->id)->delete();
        $entrada = ($this->espera)($this->clienta);

        $this->as($this->co->owner)->postJson("/api/waitlist/{$entrada}/status", ['status' => 'notified'])->assertStatus(422)->assertJsonPath('code', 'NOT_SENT');

        expect(DB::table('waitlist_entries')->where('id', $entrada)->value('status'))->toBe('waiting')
            ->and(($this->mensajes)('waitlist_slot'))->toHaveCount(0);
    });

    it('«Avisar» en Controles manda el aviso del control', function () {
        $control = (string) Str::uuid();
        DB::table('recalls')->insert([
            'id' => $control, 'tenant_id' => $this->co->tenant->id, 'customer_id' => $this->clienta->id,
            'label' => 'Control anual', 'due_date' => now('America/Lima')->addDays(10)->format('Y-m-d'), 'status' => 'pending',
        ]);

        $respuesta = $this->as($this->co->owner)->postJson("/api/recalls/{$control}/status", ['status' => 'notified'])->assertOk();

        $avisos = ($this->mensajes)('recall_due', $this->clienta->id);

        expect($respuesta->json('notice.sent'))->toBeTrue()
            ->and($avisos)->toHaveCount(1)
            ->and($avisos->first()->body)->toContain('Control anual');
    });

    it('quitar la marca de avisado no manda nada', function () {
        $control = (string) Str::uuid();
        DB::table('recalls')->insert([
            'id' => $control, 'tenant_id' => $this->co->tenant->id, 'customer_id' => $this->clienta->id,
            'label' => 'Control anual', 'due_date' => now('America/Lima')->addDays(10)->format('Y-m-d'), 'status' => 'notified',
        ]);

        $this->as($this->co->owner)->postJson("/api/recalls/{$control}/status", ['status' => 'pending'])->assertOk()->assertJsonMissingPath('notice');

        expect(($this->mensajes)('recall_due'))->toHaveCount(0);
    });
});

it('M9: abrir Automatizaciones no envía correos', function () {
    $this->travelTo(Carbon::parse('2026-11-20 10:30', 'America/Lima'));
    $id = collect($this->as($this->co->owner)->getJson('/api/automations')->assertOk()->json('automations'))->firstWhere('key', 'first_visit')['id'];
    $this->as($this->co->owner)->putJson("/api/automations/{$id}", ['active' => true])->assertOk();
    DB::table('customer_consents')->insert(['tenant_id' => $this->co->tenant->id, 'customer_id' => $this->clienta->id, 'channel' => 'email', 'granted' => true, 'granted_at' => now()]);

    $this->travelTo(now()->addMinutes(5));
    $cita = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant, '12:00', 5))->assertCreated()->json('id');
    DB::table('appointments')->where('id', $cita)->update(['status' => 'completed', 'starts_at' => now()->subHour(), 'ends_at' => now(), 'completed_at' => now()]);
    $this->travelTo(now()->addDay()->addMinutes(5));
    \Tests\TestCase::forgetCaches();

    $this->as($this->co->owner)->getJson('/api/automations')->assertOk();
    expect(($this->mensajes)('automation', $this->clienta->id))->toHaveCount(0);

    Automations::run($this->co->tenant, true);
    expect(($this->mensajes)('automation', $this->clienta->id))->toHaveCount(1);
});

describe('gravedad baja', function () {
    it('el portal cancela con la acción común: avisa y libera la lista de espera', function () {
        $this->setRules($this->co->tenant, ['cancel.window_hours' => 1, 'cancel.client_allowed' => true]);
        $cita = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->dia->format('Y-m-d') . 'T10:00')->assertCreated()->json('id');
        $otra = $this->customer($this->co->tenant, 'Rosa', 'Vega');
        ($this->espera)($otra);

        $this->as($this->clienta)->postJson("/api/portal/appointments/{$cita}/cancel", ['reason' => 'Me surgió un viaje'])->assertOk()->assertJsonPath('status', 'cancelled');

        expect(DB::table('appointments')->where('id', $cita)->value('cancelled_by'))->toBe('customer')
            ->and(($this->mensajes)('appointment_cancelled', $this->clienta->id))->toHaveCount(1)
            ->and(($this->mensajes)('waitlist_slot', $otra->id))->toHaveCount(1)
            ->and(DB::table('appointment_status_history')->where('appointment_id', $cita)->where('to_status', 'cancelled')->value('actor_customer_id'))->toBe($this->clienta->id);
    });

    it('el portal no cancela la cita de otra persona', function () {
        $otra = $this->customer($this->co->tenant, 'Rosa', 'Vega');
        $cita = $this->book($this->co, $otra, $this->servicio, $this->ana, $this->dia->format('Y-m-d') . 'T10:00')->assertCreated()->json('id');

        $this->as($this->clienta)->postJson("/api/portal/appointments/{$cita}/cancel")->assertNotFound();
    });

    it('se ven las clases programadas a más de 60 días', function () {
        $grupal = $this->servicePriced($this->co->tenant, 35, 'Yoga');
        DB::table('services')->where('id', $grupal->id)->update(['kind' => 'group', 'capacity' => 5, 'duration_minutes' => 60]);
        $lejos = $this->workday($this->co->tenant, 90)->format('Y-m-d');

        $clase = $this->as($this->co->owner)->postJson('/api/classes', [
            'service_id' => $grupal->id, 'professional_id' => $this->ana->id, 'branch_id' => $this->co->branch->id, 'date' => $lejos, 'time' => '18:00',
        ])->assertCreated()->json('class');

        expect(collect($this->as($this->co->owner)->getJson('/api/classes')->assertOk()->json('classes'))->pluck('id'))->toContain($clase['id']);
    });

    it('el kiosko anota en la sede de la tableta', function () {
        DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 30]);
        $this->travelTo($this->workday($this->co->tenant, 1)->setTime(10, 0));
        $sede = $this->branch($this->co, 'San Isidro');
        $beto = $this->professional($this->co, 'Beto Ruiz', 0, $sede);
        \Tests\TestCase::forgetCaches();
        $code = $this->co->tenant->code;

        $info = $this->getJson("/api/kiosk/{$code}?branch_id={$sede->id}")->assertOk();
        expect($info->json('branch.id'))->toBe($sede->id)->and($info->json('branches'))->toHaveCount(2);

        $this->postJson("/api/kiosk/{$code}/queue", ['branch_id' => $sede->id, 'phone' => '955444333', 'name' => 'Kiosko Persona', 'service_id' => $this->servicio->id])->assertSuccessful();

        expect(DB::table('walkin_entries')->where('tenant_id', $this->co->tenant->id)->value('branch_id'))->toBe($sede->id);
    });
});
