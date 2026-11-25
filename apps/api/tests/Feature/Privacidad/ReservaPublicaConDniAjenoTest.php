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

    $this->reservar = fn (array $extra = []) => $this->postJson("/api/public/booking/{$this->slug}/appointments", $extra + [
        'service_id'      => $this->servicio->id,
        'branch_id'       => $this->co->branch->id,
        'professional_id' => $this->ana->id,
        'starts_at'       => $this->at($this->co->tenant, '10:00'),
        'first_name'      => 'Rosa',
        'last_name'       => 'Huamán',
        'document_number' => '45678912',
        'phone'           => '912 000 111',
        'email'           => 'otra.persona@extrana.test',
        'accept_privacy'  => true,
        'request_key'     => (string) Str::uuid(),
    ]);
});

it('a quien reserva con el DNI de otra clienta no le muestra su correo ni le dice que la contraseña es el DNI', function () {
    $duena = $this->customer($this->co->tenant, 'Rosa', 'Huamán');
    DB::table('customers')->where('id', $duena->id)->update(['document_number' => '45678912', 'email' => 'rosa.verdadera@cliente.test', 'must_change_password' => true]);

    $respuesta = ($this->reservar)()->assertCreated();
    $texto = $respuesta->getContent();

    expect($texto)->not->toContain('rosa.verdadera@cliente.test')
        ->and($respuesta->json('portal.first_use'))->toBeFalse()
        ->and($respuesta->json('portal.known'))->toBeTrue()
        ->and($respuesta->json('portal.email'))->toBe('ro•••@cl•••.test');
});

it('si quien reserva escribió el mismo correo, se lo repite, pero sin la pista de la contraseña', function () {
    $duena = $this->customer($this->co->tenant, 'Rosa', 'Huamán');
    DB::table('customers')->where('id', $duena->id)->update(['document_number' => '45678912', 'email' => 'rosa.verdadera@cliente.test', 'must_change_password' => true]);

    $respuesta = ($this->reservar)(['email' => 'Rosa.Verdadera@cliente.test'])->assertCreated();

    expect($respuesta->json('portal.email'))->toBe('rosa.verdadera@cliente.test')
        ->and($respuesta->json('portal.first_use'))->toBeFalse();
});

it('a una clienta nueva le muestra su correo y que la primera vez entra con su documento', function () {
    $respuesta = ($this->reservar)()->assertCreated();

    expect($respuesta->json('portal.email'))->toBe('otra.persona@extrana.test')
        ->and($respuesta->json('portal.first_use'))->toBeTrue()
        ->and($respuesta->json('portal.known'))->toBeFalse();
});
