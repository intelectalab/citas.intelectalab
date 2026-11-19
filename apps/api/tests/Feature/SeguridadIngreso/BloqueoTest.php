<?php

require_once __DIR__ . '/Ayudantes.php';

use App\Support\PlatformMail;

beforeEach(function () {
    $this->co = $this->company();
    $this->email = $this->co->owner->email;
});

it('bloquea la cuenta 15 minutos al quinto error y avisa por correo', function () {
    foreach (range(1, 4) as $intento) {
        segEntrar($this->email, 'clave-equivocada')->assertStatus(401);
    }

    segEntrar($this->email, 'clave-equivocada')->assertStatus(423)->assertJsonPath('code', 'ACCOUNT_LOCKED');

    $this->co->owner->refresh();
    expect($this->co->owner->locked_until)->not->toBeNull()
        ->and(collect(PlatformMail::$outbox)->pluck('subject'))->toContain('Bloqueamos tu cuenta por 15 minutos');
});

it('con la cuenta bloqueada tampoco entra con la contraseña correcta', function () {
    foreach (range(1, 5) as $intento) {
        segEntrar($this->email, 'clave-equivocada');
    }

    $res = segEntrar($this->email)->assertStatus(423);
    expect($res->json('message'))->toContain('15 minutos');
});

it('se desbloquea sola a los 15 minutos', function () {
    foreach (range(1, 5) as $intento) {
        segEntrar($this->email, 'clave-equivocada');
    }

    $this->travel(14)->minutes();
    segEntrar($this->email)->assertStatus(423);

    $this->travel(2)->minutes();
    segEntrar($this->email)->assertOk()->assertJsonStructure(['access_token']);

    $this->co->owner->refresh();
    expect($this->co->owner->locked_until)->toBeNull()
        ->and((int) $this->co->owner->failed_logins)->toBe(0);
});

it('entrar bien antes del quinto error reinicia la cuenta de errores', function () {
    foreach (range(1, 4) as $intento) {
        segEntrar($this->email, 'clave-equivocada');
    }

    segEntrar($this->email)->assertOk();

    foreach (range(1, 4) as $intento) {
        segEntrar($this->email, 'clave-equivocada')->assertStatus(401);
    }

    expect($this->co->owner->refresh()->locked_until)->toBeNull();
});
