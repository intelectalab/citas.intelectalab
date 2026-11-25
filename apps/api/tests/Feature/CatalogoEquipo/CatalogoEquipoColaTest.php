<?php

use App\Models\Appointment;
use App\Support\AppointmentFlow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);
    $this->travelTo($this->workday($this->co->tenant, 1)->setTime(9, 0));
    $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Limpieza facial');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 60]);
    $this->ana = $this->professional($this->co, 'Ana Catálogo');
    $this->beto = $this->professional($this->co, 'Beto Catálogo');
    $this->listado = fn () => collect($this->as($this->co->owner)->getJson('/api/services')->assertOk()->json())->keyBy('id');
});

it('la lista de servicios dice quiénes lo hacen, en qué salas y en qué sedes', function () {
    $sala = $this->as($this->co->owner)->postJson("/api/branches/{$this->co->branch->id}/rooms", ['name' => 'Cabina azul'])->assertCreated()->json('id');
    $sede2 = $this->branch($this->co, 'Sede Norte');

    $this->as($this->co->owner)->putJson("/api/services/{$this->servicio->id}", [
        'name' => 'Limpieza facial', 'duration_minutes' => 60, 'price' => 80, 'status_id' => $this->servicio->status_id,
        'professional_ids' => [$this->ana->id], 'room_ids' => [$sala], 'branch_ids' => [$sede2->id],
    ])->assertOk();

    $fila = ($this->listado)()[$this->servicio->id];

    expect($fila['professional_names'])->toBe(['Ana Catálogo'])
        ->and($fila['room_names'])->toBe(['Cabina azul'])
        ->and($fila['branch_names'])->toBe(['Sede Norte']);

    $otro = collect(($this->listado)())->first(fn ($s) => $s['id'] !== $this->servicio->id && $s['professional_ids'] === []);
    expect($otro['professional_names'])->toContain('Ana Catálogo', 'Beto Catálogo');
});

it('el código del servicio no se repite en la empresa aunque cambien mayúsculas', function () {
    $datos = fn (string $code) => ['name' => 'Nuevo ' . Str::random(4), 'code' => $code, 'duration_minutes' => 30, 'price' => 10];

    $this->as($this->co->owner)->postJson('/api/services', $datos('ZQX-91'))->assertCreated();
    $this->as($this->co->owner)->postJson('/api/services', $datos(' zqx-91 '))->assertStatus(422)->assertJsonValidationErrors('code');

    $otra = $this->company(['modules_off' => []]);
    $this->as($otra->owner)->postJson('/api/services', $datos('ZQX-91'))->assertCreated();
});

it('guarda la descripción para el cliente y si exige ficha', function () {
    $this->as($this->co->owner)->putJson("/api/services/{$this->servicio->id}", [
        'name' => 'Limpieza facial', 'duration_minutes' => 60, 'price' => 80, 'status_id' => $this->servicio->status_id,
        'description' => '  Limpieza profunda con extracción.  ', 'requires_record' => true,
    ])->assertOk()->assertJsonPath('description', 'Limpieza profunda con extracción.')->assertJsonPath('requires_record', true);

    $log = DB::table('audit_events')->where('entity_id', $this->servicio->id)->where('action', 'service.updated')->first();
    expect($log)->not->toBeNull();
});

it('un servicio que exige ficha la pide aunque la regla general esté apagada', function () {
    $this->setRules($this->co->tenant, ['records.require_on_complete' => false]);
    $clienta = $this->customer($this->co->tenant);
    $id = $this->book($this->co, $clienta, $this->servicio, $this->ana, $this->at($this->co->tenant, '11:00', 0))->assertCreated()->json('id');
    $cita = Appointment::with('service')->find($id);

    expect(AppointmentFlow::recordRequired($this->co->tenant, $cita))->toBeFalse();

    DB::table('services')->where('id', $this->servicio->id)->update(['requires_record' => true]);
    $cita = Appointment::with('service')->find($id);

    expect(AppointmentFlow::recordRequired($this->co->tenant, $cita))->toBeTrue();
});

it('las categorías llevan color, se renombran y cuentan sus servicios', function () {
    $cat = $this->as($this->co->owner)->postJson('/api/service-categories', ['name' => 'Rostro', 'color' => '#3C7DC4'])->assertCreated()->json('id');
    $this->as($this->co->owner)->postJson('/api/service-categories', ['name' => ' rostro '])->assertStatus(422);
    $this->as($this->co->owner)->postJson('/api/service-categories', ['name' => 'Malo', 'color' => 'rojo'])->assertStatus(422);
    DB::table('services')->where('id', $this->servicio->id)->update(['category_id' => $cat]);

    $this->as($this->co->owner)->putJson("/api/service-categories/{$cat}", ['name' => 'Rostro y cuello', 'color' => '#AA3355'])->assertOk()
        ->assertJsonPath('color', '#AA3355');

    $fila = collect($this->as($this->co->owner)->getJson('/api/service-categories')->assertOk()->json())->firstWhere('id', $cat);
    expect($fila['name'])->toBe('Rostro y cuello')->and($fila['services_count'])->toBe(1);

    $recepcion = $this->staff($this->co, 'reception');
    $this->as($recepcion)->putJson("/api/service-categories/{$cat}", ['name' => 'X'])->assertForbidden();

    $otra = $this->company(['modules_off' => []]);
    $this->as($otra->owner)->putJson("/api/service-categories/{$cat}", ['name' => 'Ajena'])->assertNotFound();
});

it('las salas tienen capacidad, servicios que la usan, uso de hoy y mantenimiento', function () {
    $sala = $this->as($this->co->owner)->postJson("/api/branches/{$this->co->branch->id}/rooms", ['name' => 'Sala grupal', 'capacity' => 8])->assertCreated()->json('id');
    DB::table('service_rooms')->insert(['tenant_id' => $this->co->tenant->id, 'service_id' => $this->servicio->id, 'room_id' => $sala]);
    $clienta = $this->customer($this->co->tenant);
    $cita = $this->book($this->co, $clienta, $this->servicio, $this->ana, $this->at($this->co->tenant, '11:00', 0))->assertCreated()->json('id');
    DB::table('appointments')->where('id', $cita)->update(['room_id' => $sala]);

    $fila = collect($this->as($this->co->owner)->getJson('/api/rooms')->assertOk()->json('rooms'))->firstWhere('id', $sala);
    expect($fila['capacity'])->toBe(8)
        ->and($fila['services'])->toBe(['Limpieza facial'])
        ->and($fila['minutes_today'])->toBe(60);

    $this->as($this->co->owner)->postJson("/api/rooms/{$sala}/maintenance", ['action' => 'start'])->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->as($this->co->owner)->postJson("/api/rooms/{$sala}/maintenance", ['action' => 'start', 'reason' => 'Cambio de piso'])->assertOk()
        ->assertJsonPath('status', 'inactive')->assertJsonPath('maintenance_reason', 'Cambio de piso');
    expect(DB::table('audit_events')->where('entity_id', $sala)->where('action', 'room.maintenance_started')->exists())->toBeTrue();

    $recepcion = $this->staff($this->co, 'reception');
    $this->as($recepcion)->postJson("/api/rooms/{$sala}/maintenance", ['action' => 'end'])->assertForbidden();

    $this->as($this->co->owner)->postJson("/api/rooms/{$sala}/maintenance", ['action' => 'end'])->assertOk()
        ->assertJsonPath('status', 'active')->assertJsonPath('maintenance_reason', null);

    $this->as($this->co->owner)->putJson("/api/rooms/{$sala}", ['name' => 'Sala grupal', 'capacity' => 0])->assertStatus(422)->assertJsonValidationErrors('capacity');

    $otra = $this->company(['modules_off' => []]);
    $this->as($otra->owner)->postJson("/api/rooms/{$sala}/maintenance", ['action' => 'end'])->assertNotFound();
});

it('el profesional guarda su color, su presentación y los servicios que hace', function () {
    $otro = $this->servicePriced($this->co->tenant, 30, 'Cejas');

    $this->as($this->co->owner)->putJson("/api/professionals/{$this->ana->id}", [
        'color_code' => '#aa3355', 'bio' => 'Cosmetóloga con 10 años.', 'service_ids' => [$otro->id],
    ])->assertOk()->assertJsonPath('color_code', '#AA3355')->assertJsonPath('bio', 'Cosmetóloga con 10 años.');

    $lista = ($this->listado)();
    expect($lista[$this->servicio->id]['professional_ids'])->not->toContain($this->ana->id)
        ->and($lista[$this->servicio->id]['professional_ids'])->toContain($this->beto->id)
        ->and($lista[$otro->id]['professional_ids'])->toBe([]);

    $this->as($this->co->owner)->putJson("/api/professionals/{$this->beto->id}", ['service_ids' => [$otro->id]])
        ->assertStatus(422)->assertJsonValidationErrors('service_ids');
});

it('el resumen del equipo trae citas de hoy, ocupación, sedes, servicios y vacaciones', function () {
    $clienta = $this->customer($this->co->tenant);
    $this->book($this->co, $clienta, $this->servicio, $this->ana, $this->at($this->co->tenant, '10:00', 0))->assertCreated();
    DB::table('availability_blocks')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'professional_id' => $this->beto->id, 'kind' => 'vacation',
        'reason' => 'Vacaciones', 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(6),
    ]);

    $datos = $this->as($this->co->owner)->getJson('/api/team-overview')->assertOk()->json();

    expect($datos[$this->ana->id]['today_count'])->toBe(1)
        ->and($datos[$this->ana->id]['occupancy'])->toBe(8)
        ->and($datos[$this->ana->id]['branches'])->toBe([$this->co->branch->name])
        ->and($datos[$this->ana->id]['services_count'])->toBeGreaterThan(0)
        ->and($datos[$this->ana->id]['vacation'])->toBeNull()
        ->and($datos[$this->beto->id]['vacation']['now'])->toBeFalse();

    $otra = $this->company(['modules_off' => []]);
    expect($this->as($otra->owner)->getJson('/api/team-overview')->assertOk()->json())->not->toHaveKey($this->ana->id);
});

it('la producción de 30 días y los servicios realizados respetan quién mira', function () {
    $clienta = $this->customer($this->co->tenant);
    $id = $this->book($this->co, $clienta, $this->servicio, $this->ana, $this->at($this->co->tenant, '15:00', 0))->assertCreated()->json('id');
    DB::table('appointments')->where('id', $id)->update(['status' => AppointmentFlow::COMPLETED]);

    $datos = $this->as($this->co->owner)->getJson("/api/professionals/{$this->ana->id}/performance")->assertOk()->json();
    expect($datos['days'])->toHaveCount(30)
        ->and($datos['total'])->toEqual(80)
        ->and(end($datos['days'])['amount'])->toEqual(80)
        ->and($datos['services'])->toBe([['name' => 'Limpieza facial', 'count' => 1]]);

    $this->as($this->ana->user)->getJson("/api/professionals/{$this->ana->id}/performance")->assertOk()->assertJsonPath('total', 80);
    $this->as($this->beto->user)->getJson("/api/professionals/{$this->ana->id}/performance")->assertForbidden();

    $otra = $this->company(['modules_off' => []]);
    $this->as($otra->owner)->getJson("/api/professionals/{$this->ana->id}/performance")->assertNotFound();
});

it('el horario trae el horario de cada sede', function () {
    DB::table('branch_hours')->where('branch_id', $this->co->branch->id)->delete();
    DB::table('branch_hours')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'branch_id' => $this->co->branch->id, 'weekday' => 1, 'opens_local' => '09:00', 'closes_local' => '19:00']);

    $horas = $this->as($this->co->owner)->getJson("/api/professionals/{$this->ana->id}/schedule")->assertOk()->json('branch_hours');

    expect($horas)->toBe([['branch_id' => $this->co->branch->id, 'weekday' => 1, 'starts' => '09:00', 'ends' => '19:00']]);
});

it('el kiosko ofrece reservar una cita si la espera es larga y la reserva en línea está abierta', function () {
    $code = $this->co->tenant->code;
    $slug = $this->co->tenant->slug;

    $this->getJson("/api/kiosk/{$code}")->assertOk()->assertJsonPath('booking_url', "/reservar/{$slug}");

    $this->setRules($this->co->tenant, ['walkin.max_wait' => 10]);
    foreach (['Uno', 'Dos', 'Tres'] as $nombre) {
        $this->as($this->co->owner)->postJson('/api/walkins', ['branch_id' => $this->co->branch->id, 'service_id' => $this->servicio->id, 'first_name' => $nombre, 'last_name' => 'Espera'])->assertSuccessful();
    }
    $this->postJson("/api/kiosk/{$code}/queue", ['phone' => '955000111', 'name' => 'Muy Tarde', 'service_id' => $this->servicio->id])
        ->assertStatus(409)->assertJsonPath('code', 'WAIT_TOO_LONG')->assertJsonPath('booking_url', "/reservar/{$slug}");

    $this->setRules($this->co->tenant, ['walkin.max_wait' => 0, 'booking.enabled' => false]);
    $this->getJson("/api/kiosk/{$code}")->assertOk()->assertJsonPath('booking_url', null);
});
