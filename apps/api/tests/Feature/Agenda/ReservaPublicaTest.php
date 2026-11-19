<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company();
    $this->slug = DB::table('tenants')->where('id', $this->co->tenant->id)->value('slug');
    $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Manicure');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 60, 'online_booking' => true, 'requires_deposit' => false]);
    $this->ana = $this->professional($this->co, 'Ana Torres');
    RateLimiter::clear('write|' . $this->slug . '|127.0.0.1');

    $this->datos = fn (array $extra = []) => $extra + [
        'service_id'      => $this->servicio->id,
        'branch_id'       => $this->co->branch->id,
        'professional_id' => $this->ana->id,
        'starts_at'       => $this->at($this->co->tenant, '10:00'),
        'first_name'      => 'Rosa',
        'last_name'       => 'Huamán',
        'document_number' => '45678912',
        'phone'           => '987 654 321',
        'email'           => 'rosa.huaman@cliente.test',
        'accept_privacy'  => true,
        'request_key'     => (string) Str::uuid(),
    ];

    $this->reservar = fn (array $extra = []) => $this->postJson("/api/public/booking/{$this->slug}/appointments", ($this->datos)($extra));
});

describe('la página pública', function () {
    it('muestra los datos de la empresa sin iniciar sesión', function () {
        $this->getJson("/api/public/booking/{$this->slug}")
            ->assertOk()
            ->assertJsonPath('open', true)
            ->assertJsonPath('page.title', $this->co->tenant->name);
    });

    it('lista solo los servicios en línea y deja ver horarios', function () {
        $catalogo = $this->getJson("/api/public/booking/{$this->slug}/catalog")->assertOk()->json();
        expect(collect($catalogo['services'])->pluck('id'))->toContain($this->servicio->id);

        $dia = substr($this->at($this->co->tenant, '10:00'), 0, 10);
        $this->getJson("/api/public/booking/{$this->slug}/availability?service_id={$this->servicio->id}&branch_id={$this->co->branch->id}&date={$dia}")
            ->assertOk()
            ->assertJsonFragment(['10:00']);
    });

    it('con la reserva en línea apagada, la página dice que no está disponible', function () {
        $this->setRules($this->co->tenant, ['booking.enabled' => false]);

        $this->getJson("/api/public/booking/{$this->slug}")->assertOk()->assertJsonPath('open', false);
        ($this->reservar)()->assertStatus(403);
    });

    it('una dirección que no existe responde 404', function () {
        $this->getJson('/api/public/booking/no-existe-esta-empresa')->assertNotFound();
    });

    it('un profesional fuera de la reserva en línea no aparece ni se reserva', function () {
        DB::table('professionals')->where('id', $this->ana->id)->update(['online_booking' => false]);

        $catalogo = $this->getJson("/api/public/booking/{$this->slug}/catalog")->assertOk()->json();
        expect(collect($catalogo['professionals'])->pluck('id'))->not->toContain($this->ana->id);

        ($this->reservar)()->assertStatus(409);
    });
});

describe('reservar sin cuenta', function () {
    it('crea la clienta, la cita en línea y guarda la aceptación de privacidad', function () {
        $cita = ($this->reservar)()->assertCreated()->json();

        $clienta = DB::table('customers')->where('tenant_id', $this->co->tenant->id)->where('document_number', '45678912')->first();
        $fila = DB::table('appointments')->where('id', $cita['id'])->first();

        expect($clienta)->not->toBeNull()
            ->and($fila->customer_id)->toBe($clienta->id)
            ->and($fila->source)->toBe('online')
            ->and($fila->status)->toBe('pending')
            ->and($cita['portal']['first_use'])->toBeTrue()
            ->and(DB::table('customer_consents')->where('customer_id', $clienta->id)->where('channel', 'data_processing')->value('granted'))->toBeTrue();
    });

    it('sin aceptar la privacidad no reserva', function () {
        ($this->reservar)(['accept_privacy' => false])->assertStatus(422)->assertJsonValidationErrors('accept_privacy');
    });

    it('reconoce a una clienta que ya existe por su documento y no la duplica', function () {
        $existente = $this->customer($this->co->tenant, 'Rosa', 'Huamán');
        DB::table('customers')->where('id', $existente->id)->update(['document_number' => '45678912', 'phone' => '987654321', 'email' => 'rosa.huaman@cliente.test']);

        $cita = ($this->reservar)()->assertCreated()->json();

        expect(DB::table('customers')->where('tenant_id', $this->co->tenant->id)->where('document_number', '45678912')->count())->toBe(1)
            ->and(DB::table('appointments')->where('id', $cita['id'])->value('customer_id'))->toBe($existente->id)
            ->and(DB::table('appointments')->where('id', $cita['id'])->value('notes'))->toBeNull();
    });

    it('la reconoce por el celular y anota en la cita lo que no coincide', function () {
        $existente = $this->customer($this->co->tenant, 'Rosa', 'Huamán');
        DB::table('customers')->where('id', $existente->id)->update(['document_number' => '11112222', 'phone' => '+51 987654321', 'email' => 'rosa.huaman@cliente.test']);

        $cita = ($this->reservar)()->assertCreated()->json();
        $nota = DB::table('appointments')->where('id', $cita['id'])->value('notes');

        expect(DB::table('appointments')->where('id', $cita['id'])->value('customer_id'))->toBe($existente->id)
            ->and($nota)->toContain('el documento 45678912')
            ->and(DB::table('customers')->where('id', $existente->id)->value('document_number'))->toBe('11112222');
    });

    it('dos envíos con la misma clave crean una sola cita', function () {
        $clave = (string) Str::uuid();
        $primera = ($this->reservar)(['request_key' => $clave])->assertCreated()->json('id');
        $segunda = ($this->reservar)(['request_key' => $clave])->assertCreated()->json('id');

        expect($segunda)->toBe($primera)
            ->and(DB::table('appointments')->where('tenant_id', $this->co->tenant->id)->where('source', 'online')->count())->toBe(1);
    });

    it('un horario ocupado no crea la clienta nueva', function () {
        $otra = $this->customer($this->co->tenant, 'Marta', 'Quispe');
        $this->book($this->co, $otra, $this->servicio, $this->ana, $this->at($this->co->tenant, '10:00'))->assertCreated();

        ($this->reservar)()->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');

        expect(DB::table('customers')->where('tenant_id', $this->co->tenant->id)->where('document_number', '45678912')->exists())->toBeFalse();
    });

    it('la trampa para robots no deja reservar', function () {
        ($this->reservar)(['website' => 'http://spam.test'])->assertStatus(422);
    });

    it('quien hace demasiadas reservas desde la misma conexión recibe 429', function () {
        foreach (range(1, 10) as $i) {
            ($this->reservar)(['accept_privacy' => false]);
        }

        ($this->reservar)()->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
    });

    it('si el servicio pide adelanto, devuelve el link de pago y no crea la cita todavía', function () {
        DB::table('services')->where('id', $this->servicio->id)->update(['requires_deposit' => true, 'deposit_type' => 'percent', 'deposit_value' => 30]);

        $respuesta = ($this->reservar)()->assertStatus(202)->json('deposit');

        expect($respuesta['token'])->not->toBeEmpty()
            ->and(DB::table('appointments')->where('tenant_id', $this->co->tenant->id)->where('source', 'online')->count())->toBe(0);
    });
});

describe('configurar la página', function () {
    it('el administrador guarda título, redes y ve el enlace', function () {
        $this->as($this->co->owner)->putJson('/api/booking-page', [
            'title' => 'Reserva en Bella', 'description' => 'Elige tu horario', 'show_policies' => true,
            'socials' => ['instagram' => 'bella.salon', 'whatsapp' => '987654321'],
        ])->assertOk()
            ->assertJsonPath('page.title', 'Reserva en Bella')
            ->assertJsonPath('page.socials.instagram', 'bella.salon')
            ->assertJsonPath('url', rtrim(config('app.frontend_url'), '/') . '/reservar/' . $this->slug);

        $this->getJson("/api/public/booking/{$this->slug}")->assertJsonPath('page.description', 'Elige tu horario');
    });

    it('recepción no puede cambiarla', function () {
        $recepcion = $this->staff($this->co, 'reception');

        $this->as($recepcion)->putJson('/api/booking-page', ['title' => 'Otra'])->assertForbidden();
    });
});
