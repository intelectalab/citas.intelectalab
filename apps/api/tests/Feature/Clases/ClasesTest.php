<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);
    $this->yoga = $this->servicePriced($this->co->tenant, 35, 'Yoga');
    DB::table('services')->where('id', $this->yoga->id)->update(['kind' => 'group', 'capacity' => 3, 'duration_minutes' => 60, 'online_booking' => true]);
    $this->corte = $this->servicePriced($this->co->tenant, 50, 'Corte de prueba');
    DB::table('services')->where('id', $this->corte->id)->update(['duration_minutes' => 30]);
    $this->ana = $this->professional($this->co, 'Ana Instructora');
    $this->sala = (string) Str::uuid();
    DB::table('rooms')->insert(['id' => $this->sala, 'tenant_id' => $this->co->tenant->id, 'branch_id' => $this->co->branch->id, 'name' => 'Sala principal', 'kind' => 'room', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

    $this->programar = fn (array $extra = [], $quien = null) => $this->as($quien ?? $this->co->owner)->postJson('/api/classes', $extra + [
        'service_id' => $this->yoga->id, 'professional_id' => $this->ana->id, 'branch_id' => $this->co->branch->id,
        'room_id' => $this->sala, 'date' => $this->workday($this->co->tenant)->format('Y-m-d'), 'time' => '18:00',
    ]);

    $this->clase = fn (array $extra = []) => ($this->programar)($extra)->assertCreated()->json('class');

    $this->inscribir = fn (array $clase, $cliente, $quien = null) => $this->as($quien ?? $this->co->owner)
        ->postJson("/api/classes/{$clase['id']}/enrollments", ['customer_id' => $cliente->id]);

    $this->inscripcion = fn (array $clase, $cliente) => DB::table('class_enrollments')
        ->where('class_session_id', $clase['id'])->where('customer_id', $cliente->id)->orderByDesc('created_at')->first();
});

describe('programar', function () {
    it('programa una clase con el cupo del servicio y se ve en la lista', function () {
        $clase = ($this->clase)();

        expect($clase['capacity'])->toBe(3)
            ->and($clase['free'])->toBe(3)
            ->and($clase['time'])->toBe('18:00')
            ->and($clase['end_time'])->toBe('19:00')
            ->and($clase['room']['name'])->toBe('Sala principal');

        $lista = $this->as($this->co->owner)->getJson('/api/classes')->assertOk()->json('classes');
        expect(collect($lista)->pluck('id'))->toContain($clase['id']);
    });

    it('solo acepta servicios marcados como clase grupal', function () {
        ($this->programar)(['service_id' => $this->corte->id])->assertStatus(422)->assertJsonValidationErrors('service_id');
    });

    it('no programa una clase encima de una cita del instructor', function () {
        $clienta = $this->customer($this->co->tenant);
        $this->book($this->co, $clienta, $this->corte, $this->ana, $this->at($this->co->tenant, '18:30'))->assertCreated();

        ($this->programar)()->assertStatus(409)->assertJsonValidationErrors('time');
    });

    it('la agenda no deja poner una cita encima de una clase', function () {
        ($this->clase)();
        $clienta = $this->customer($this->co->tenant);

        $respuesta = $this->book($this->co, $clienta, $this->corte, $this->ana, $this->at($this->co->tenant, '18:30'))->assertStatus(409);
        expect(collect($respuesta->json('reasons'))->pluck('code'))->toContain('CLASS_BUSY');
    });

    it('los horarios libres de la agenda saltan la hora de la clase', function () {
        ($this->clase)();
        $dia = $this->workday($this->co->tenant)->format('Y-m-d');

        $libres = $this->as($this->co->owner)->getJson("/api/appointments/availability?service_id={$this->corte->id}&branch_id={$this->co->branch->id}&date={$dia}&professional_id={$this->ana->id}")
            ->assertOk()->json();
        $horas = collect($libres['professionals'] ?? $libres)->flatMap(fn ($p) => $p['slots'] ?? [])->all();

        expect($horas)->toContain('17:30')->not->toContain('18:00')->not->toContain('18:30')->toContain('19:00');
    });

    it('no usa la misma sala para dos clases a la misma hora', function () {
        ($this->clase)();
        $beto = $this->professional($this->co, 'Beto Instructor');

        ($this->programar)(['professional_id' => $beto->id, 'time' => '18:30'])->assertStatus(409);
        ($this->programar)(['professional_id' => $beto->id, 'time' => '18:30', 'room_id' => null])->assertCreated();
    });

    it('repite la clase cada semana y salta las fechas que chocan', function () {
        $clienta = $this->customer($this->co->tenant);
        $tercera = $this->workday($this->co->tenant)->addWeeks(2)->format('Y-m-d');
        $this->book($this->co, $clienta, $this->corte, $this->ana, "{$tercera}T18:00")->assertCreated();

        $respuesta = ($this->programar)(['repeat_weeks' => 4])->assertCreated()->json();

        expect($respuesta['created'])->toBe(3)
            ->and($respuesta['skipped'])->toHaveCount(1)
            ->and($respuesta['skipped'][0]['date'])->toBe($tercera)
            ->and(DB::table('class_sessions')->where('series_id', DB::table('class_sessions')->where('id', $respuesta['class']['id'])->value('series_id'))->count())->toBe(3);
    });

    it('la clase aparece en la agenda del día ocupando al instructor', function () {
        $clase = ($this->clase)();
        $dia = $this->workday($this->co->tenant)->format('Y-m-d');

        $agenda = $this->as($this->co->owner)->getJson("/api/appointments/day?date={$dia}&branch_id={$this->co->branch->id}")->assertOk()->json('professionals');
        $bloques = collect($agenda)->firstWhere('id', $this->ana->id)['blocks'];

        expect(collect($bloques)->firstWhere('kind', 'class'))->toMatchArray(['starts' => '18:00', 'ends' => '19:00', 'class_id' => $clase['id']]);
    });
});

describe('inscribir', function () {
    it('inscribe hasta el cupo y luego anota en la lista de espera', function () {
        $clase = ($this->clase)();
        foreach (['Uno', 'Dos', 'Tres'] as $nombre) {
            ($this->inscribir)($clase, $this->customer($this->co->tenant, $nombre))->assertCreated()->assertJsonPath('result.status', 'enrolled');
        }

        $cuarta = $this->customer($this->co->tenant, 'Cuatro');
        $detalle = ($this->inscribir)($clase, $cuarta)->assertCreated()->assertJsonPath('result.status', 'waitlist')->json();

        expect($detalle['taken'])->toBe(3)->and($detalle['waitlist'])->toBe(1)->and($detalle['state'])->toBe('full');
    });

    it('no inscribe dos veces a la misma persona', function () {
        $clase = ($this->clase)();
        $clienta = $this->customer($this->co->tenant);
        ($this->inscribir)($clase, $clienta)->assertCreated();
        ($this->inscribir)($clase, $clienta)->assertStatus(422)->assertJsonValidationErrors('customer_id');
    });

    it('sin lista de espera, una clase llena no recibe a nadie más', function () {
        $this->setRules($this->co->tenant, ['classes.waitlist' => false]);
        $clase = ($this->clase)(['capacity' => 1]);
        ($this->inscribir)($clase, $this->customer($this->co->tenant, 'Uno'))->assertCreated();
        ($this->inscribir)($clase, $this->customer($this->co->tenant, 'Dos'))->assertStatus(422)->assertJsonValidationErrors('class');
    });

    it('cuando alguien sale, el primero en espera toma el cupo y se le avisa', function () {
        $clase = ($this->clase)(['capacity' => 1]);
        $primera = $this->customer($this->co->tenant, 'Primera');
        $espera = $this->customer($this->co->tenant, 'Espera');
        ($this->inscribir)($clase, $primera)->assertCreated();
        ($this->inscribir)($clase, $espera)->assertCreated();

        $this->as($this->co->owner)->postJson('/api/class-enrollments/' . ($this->inscripcion)($clase, $primera)->id . '/leave')
            ->assertOk()->assertJsonPath('result.promoted', 'Espera De Prueba');

        expect(($this->inscripcion)($clase, $espera)->status)->toBe('enrolled')
            ->and(DB::table('messages')->where('kind', 'class_spot')->where('customer_id', $espera->id)->exists())->toBeTrue();
    });

    it('si la empresa no da el cupo sola, se pasa a mano desde la espera', function () {
        $this->setRules($this->co->tenant, ['classes.auto_promote' => false]);
        $clase = ($this->clase)(['capacity' => 1]);
        $primera = $this->customer($this->co->tenant, 'Primera');
        $espera = $this->customer($this->co->tenant, 'Espera');
        ($this->inscribir)($clase, $primera)->assertCreated();
        ($this->inscribir)($clase, $espera)->assertCreated();

        $this->as($this->co->owner)->postJson('/api/class-enrollments/' . ($this->inscripcion)($clase, $primera)->id . '/leave')->assertOk();
        expect(($this->inscripcion)($clase, $espera)->status)->toBe('waitlist');

        $this->as($this->co->owner)->postJson('/api/class-enrollments/' . ($this->inscripcion)($clase, $espera)->id . '/promote')->assertOk();
        expect(($this->inscripcion)($clase, $espera)->status)->toBe('enrolled');
    });

    it('subir el cupo hace entrar a quien estaba en espera', function () {
        $clase = ($this->clase)(['capacity' => 1]);
        ($this->inscribir)($clase, $this->customer($this->co->tenant, 'Uno'))->assertCreated();
        $espera = $this->customer($this->co->tenant, 'Dos');
        ($this->inscribir)($clase, $espera)->assertCreated();

        $this->as($this->co->owner)->putJson("/api/classes/{$clase['id']}", ['capacity' => 2])->assertOk()->assertJsonPath('result.promoted', 1);
        expect(($this->inscripcion)($clase, $espera)->status)->toBe('enrolled');

        $this->as($this->co->owner)->putJson("/api/classes/{$clase['id']}", ['capacity' => 1])->assertStatus(422)->assertJsonValidationErrors('capacity');
    });
});

describe('asistencia y cobro', function () {
    it('la asistencia se marca cuando la clase ya empezó', function () {
        $clase = ($this->clase)();
        $clienta = $this->customer($this->co->tenant);
        ($this->inscribir)($clase, $clienta)->assertCreated();
        $id = ($this->inscripcion)($clase, $clienta)->id;

        $this->as($this->co->owner)->postJson("/api/class-enrollments/{$id}/attendance", ['status' => 'attended'])->assertStatus(422);

        $this->travelTo(\Illuminate\Support\Carbon::parse(DB::table('class_sessions')->where('id', $clase['id'])->value('starts_at'))->addMinutes(10));
        $detalle = $this->as($this->co->owner)->postJson("/api/class-enrollments/{$id}/attendance", ['status' => 'attended'])->assertOk()->json();

        expect($detalle['attended'])->toBe(1)->and($detalle['enrollments'][0]['status'])->toBe('attended');
    });

    it('cobra la clase en una venta por cobrar con el instructor, y no la cobra dos veces', function () {
        $clase = ($this->clase)();
        $clienta = $this->customer($this->co->tenant);
        ($this->inscribir)($clase, $clienta)->assertCreated();
        $id = ($this->inscripcion)($clase, $clienta)->id;

        $venta = $this->as($this->co->owner)->postJson("/api/class-enrollments/{$id}/charge")->assertOk()->json('result.sale');
        $linea = DB::table('sale_items')->where('sale_id', $venta['id'])->first();

        expect($venta['status'])->toBe('pending')
            ->and((float) $venta['total'])->toBe(35.0)
            ->and($linea->reference_id)->toBe($this->yoga->id)
            ->and($linea->professional_id)->toBe($this->ana->id);

        $this->as($this->co->owner)->postJson("/api/class-enrollments/{$id}/charge")->assertStatus(422);

        $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => 35])->assertOk();
        $detalle = $this->as($this->co->owner)->getJson("/api/classes/{$clase['id']}")->assertOk()->json();
        expect($detalle['enrollments'][0]['paid'])->toBeTrue()->and($detalle['enrollments'][0]['paid_label'])->toBe('Venta');
    });

    it('si su membresía incluye la clase, el cobro sale pagado y cuenta como membresía', function () {
        $plan = $this->as($this->co->owner)->postJson('/api/membership-plans', [
            'name' => 'Ilimitado', 'price' => 200, 'billing_period' => 'monthly', 'discount_percent' => 0, 'discount_scope' => 'services',
            'items' => [['service_id' => $this->yoga->id, 'sessions' => 8]],
        ])->assertCreated()->json();
        $clienta = $this->customer($this->co->tenant);
        $venta = $this->sell($this->co, [['type' => 'membership', 'id' => $plan['id']]], ['customer_id' => $clienta->id]);
        $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => $venta['total']])->assertOk();

        $clase = ($this->clase)();
        ($this->inscribir)($clase, $clienta)->assertCreated();
        $id = ($this->inscripcion)($clase, $clienta)->id;

        $cobro = $this->as($this->co->owner)->postJson("/api/class-enrollments/{$id}/charge")->assertOk()->json('result.sale');

        expect($cobro['status'])->toBe('paid')->and(($this->inscripcion)($clase, $clienta)->paid_with)->toBe('membership');
    });

    it('descuenta la clase de un paquete y la devuelve si la clase se cancela', function () {
        $plan = $this->as($this->co->owner)->postJson('/api/package-plans', [
            'name' => 'Ocho clases', 'price' => 240, 'validity_days' => 60,
            'booking_mode' => 'free', 'frequency' => 'weekly', 'professional_mode' => 'any',
            'late_policy' => 'lose', 'refund_policy' => 'unused_normal',
            'items' => [['service_id' => $this->yoga->id, 'sessions' => 8]],
        ])->assertCreated()->json();
        $clienta = $this->customer($this->co->tenant);
        $venta = $this->sell($this->co, [['type' => 'package', 'id' => $plan['id']]], ['customer_id' => $clienta->id]);
        $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => $venta['total']])->assertOk();
        $paquete = DB::table('customer_packages')->where('sale_id', $venta['id'])->first();

        $clase = ($this->clase)();
        ($this->inscribir)($clase, $clienta)->assertCreated();
        $detalle = $this->as($this->co->owner)->getJson("/api/classes/{$clase['id']}")->json();
        expect($detalle['enrollments'][0]['has_package'])->toBeTrue();

        $id = ($this->inscripcion)($clase, $clienta)->id;
        $this->as($this->co->owner)->postJson("/api/class-enrollments/{$id}/package")->assertOk();
        expect((int) DB::table('customer_package_items')->where('customer_package_id', $paquete->id)->value('sessions_used'))->toBe(1);

        $this->as($this->co->owner)->postJson("/api/classes/{$clase['id']}/cancel", ['reason' => 'La instructora está enferma'])->assertOk()->assertJsonPath('result.notified', 1);

        expect((int) DB::table('customer_package_items')->where('customer_package_id', $paquete->id)->value('sessions_used'))->toBe(0)
            ->and(($this->inscripcion)($clase, $clienta)->status)->toBe('cancelled')
            ->and(DB::table('messages')->where('kind', 'class_cancelled')->where('customer_id', $clienta->id)->exists())->toBeTrue();
    });
});

describe('cancelar', function () {
    it('cancela la clase, avisa a inscritos y en espera, y libera la agenda', function () {
        $clase = ($this->clase)(['capacity' => 1]);
        $uno = $this->customer($this->co->tenant, 'Uno');
        $dos = $this->customer($this->co->tenant, 'Dos');
        ($this->inscribir)($clase, $uno)->assertCreated();
        ($this->inscribir)($clase, $dos)->assertCreated();

        $this->as($this->co->owner)->postJson("/api/classes/{$clase['id']}/cancel", ['reason' => ''])->assertStatus(422);
        $detalle = $this->as($this->co->owner)->postJson("/api/classes/{$clase['id']}/cancel", ['reason' => 'Corte de luz'])->assertOk()->json();

        expect($detalle['state'])->toBe('cancelled')
            ->and($detalle['result']['notified'])->toBe(2)
            ->and(DB::table('messages')->where('kind', 'class_cancelled')->count())->toBe(2);

        $this->book($this->co, $this->customer($this->co->tenant), $this->corte, $this->ana, $this->at($this->co->tenant, '18:00'))->assertCreated();
        ($this->inscribir)($clase, $this->customer($this->co->tenant, 'Tres'))->assertStatus(422);
    });
});

describe('portal y reserva en línea', function () {
    it('la clienta ve la clase en su portal, se inscribe y sale', function () {
        $clase = ($this->clase)();
        $clienta = $this->customer($this->co->tenant);

        $lista = $this->as($clienta)->getJson('/api/portal/classes')->assertOk()->json('classes');
        expect(collect($lista)->pluck('id'))->toContain($clase['id']);

        $this->as($clienta)->postJson("/api/portal/classes/{$clase['id']}/enroll")->assertCreated()->assertJsonPath('status', 'enrolled');
        expect(($this->inscripcion)($clase, $clienta)->source)->toBe('portal')
            ->and(DB::table('user_notifications')->where('user_id', $this->ana->user->id)->where('type', 'class_enrolled')->exists())->toBeTrue();

        $mias = $this->as($clienta)->getJson('/api/portal/classes')->json('mine');
        expect($mias[0]['my_status'])->toBe('enrolled')->and($mias[0]['can_leave'])->toBeTrue();

        $this->as($clienta)->postJson("/api/portal/classes/{$clase['id']}/leave")->assertOk();
        expect(($this->inscripcion)($clase, $clienta)->status)->toBe('cancelled');
    });

    it('desde el portal no sale de la clase pasado el plazo', function () {
        $this->setRules($this->co->tenant, ['classes.portal_leave_hours' => 2]);
        $clase = ($this->clase)();
        $clienta = $this->customer($this->co->tenant);
        $this->as($clienta)->postJson("/api/portal/classes/{$clase['id']}/enroll")->assertCreated();

        $this->travelTo(\Illuminate\Support\Carbon::parse(DB::table('class_sessions')->where('id', $clase['id'])->value('starts_at'))->subHour());
        $this->as($clienta)->postJson("/api/portal/classes/{$clase['id']}/leave")->assertStatus(422);
    });

    it('una clase que no se reserva en línea no aparece en el portal', function () {
        $clase = ($this->clase)(['online_booking' => false]);
        $clienta = $this->customer($this->co->tenant);

        expect(collect($this->as($clienta)->getJson('/api/portal/classes')->json('classes'))->pluck('id'))->not->toContain($clase['id']);
        $this->as($clienta)->postJson("/api/portal/classes/{$clase['id']}/enroll")->assertNotFound();
    });

    it('en la página pública se inscribe con sus datos y queda registrada como clienta', function () {
        $clase = ($this->clase)();
        $slug = DB::table('tenants')->where('id', $this->co->tenant->id)->value('slug');
        \Illuminate\Support\Facades\RateLimiter::clear('write|' . $slug . '|127.0.0.1');

        $this->getJson("/api/public/booking/{$slug}/classes")->assertOk()->assertJsonPath('classes.0.id', $clase['id']);

        $this->postJson("/api/public/booking/{$slug}/classes/{$clase['id']}/enroll", [
            'first_name' => 'Rosa', 'last_name' => 'Nueva', 'document_number' => '44556677', 'phone' => '987654321',
            'email' => 'rosa.nueva@cliente.test', 'accept_privacy' => true, 'request_key' => (string) Str::uuid(),
        ])->assertCreated()->assertJsonPath('status', 'enrolled');

        $rosa = DB::table('customers')->where('tenant_id', $this->co->tenant->id)->where('email', 'rosa.nueva@cliente.test')->first();
        expect($rosa)->not->toBeNull()
            ->and(DB::table('class_enrollments')->where('customer_id', $rosa->id)->value('source'))->toBe('online');
    });
});

describe('configuración', function () {
    it('la empresa ve y cambia las reglas de clases en Configuración', function () {
        $this->as($this->co->owner)->getJson('/api/tenant-settings')->assertOk()
            ->assertJsonPath('classes_rules', ['waitlist' => true, 'auto_promote' => true, 'portal_leave_hours' => 2]);

        $this->as($this->co->owner)->putJson('/api/tenant-settings', ['classes_rules' => ['waitlist' => true, 'auto_promote' => false, 'portal_leave_hours' => 6]])->assertOk();
        \Tests\TestCase::forgetCaches();

        $this->as($this->co->owner)->getJson('/api/tenant-settings')->assertJsonPath('classes_rules.auto_promote', false)->assertJsonPath('classes_rules.portal_leave_hours', 6);
    });
});

describe('permisos y módulo', function () {
    it('recepción programa e inscribe; caja no entra a las clases', function () {
        $recepcion = $this->staff($this->co, 'reception');
        $caja = $this->staff($this->co, 'cashier');

        $clase = ($this->programar)([], $recepcion)->assertCreated()->json('class');
        ($this->inscribir)($clase, $this->customer($this->co->tenant), $recepcion)->assertCreated();

        $this->as($caja)->getJson('/api/classes')->assertForbidden();
    });

    it('el instructor solo ve y programa sus propias clases', function () {
        $beto = $this->professional($this->co, 'Beto Instructor');
        $clase = ($this->clase)();

        $this->as($beto->user)->getJson("/api/classes/{$clase['id']}")->assertForbidden();
        expect($this->as($beto->user)->getJson('/api/classes')->assertOk()->json('classes'))->toBe([]);
        ($this->programar)(['professional_id' => $this->ana->id, 'time' => '10:00'], $beto->user)->assertForbidden();
    });

    it('sin el módulo de clases las rutas se cierran', function () {
        $otra = $this->company(['modules_off' => ['classes']]);
        $this->as($otra->owner)->getJson('/api/classes')->assertForbidden();
    });
});
