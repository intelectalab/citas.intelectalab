<?php

require_once __DIR__ . '/Ayudantes.php';

use App\Support\PlatformMail;

beforeEach(function () {
    segCorreoListo();
    $this->co = $this->company();
    $this->email = $this->co->owner->email;
});

function segPedirEnlace(string $email): string
{
    test()->postJson('/api/password/forgot', ['email' => $email])->assertOk();

    return segEnlace();
}

it('envía un enlace al correo y con él se crea la contraseña nueva', function () {
    $enlace = segPedirEnlace($this->email);

    $this->getJson("/api/password/reset/{$enlace}")->assertOk()->assertJsonPath('account', 'staff');

    $this->postJson('/api/password/reset', ['token' => $enlace, 'password' => 'otra-clave-456', 'password_confirmation' => 'otra-clave-456'])
        ->assertOk()->assertJsonPath('email', $this->email);

    segEntrar($this->email)->assertStatus(401);
    segEntrar($this->email, 'otra-clave-456')->assertOk()->assertJsonStructure(['access_token']);
});

it('responde lo mismo aunque el correo no tenga cuenta', function () {
    $this->postJson('/api/password/forgot', ['email' => 'nadie@prueba.test'])->assertOk();
    expect(PlatformMail::$outbox)->toBeEmpty();
});

it('el enlace sirve una sola vez', function () {
    $enlace = segPedirEnlace($this->email);
    $datos = ['token' => $enlace, 'password' => 'otra-clave-456', 'password_confirmation' => 'otra-clave-456'];

    $this->postJson('/api/password/reset', $datos)->assertOk();

    $this->getJson("/api/password/reset/{$enlace}")->assertNotFound();
    $this->postJson('/api/password/reset', ['password' => 'tercera-clave-789', 'password_confirmation' => 'tercera-clave-789'] + $datos)->assertStatus(422);
});

it('el enlace vence a los 60 minutos', function () {
    $enlace = segPedirEnlace($this->email);

    $this->travel(61)->minutes();

    $this->getJson("/api/password/reset/{$enlace}")->assertNotFound();
    $this->postJson('/api/password/reset', ['token' => $enlace, 'password' => 'otra-clave-456', 'password_confirmation' => 'otra-clave-456'])->assertStatus(422);
    segEntrar($this->email)->assertOk();
});

it('pedir un enlace nuevo anula el anterior', function () {
    $primero = segPedirEnlace($this->email);
    $this->travel(2)->minutes();
    $segundo = segPedirEnlace($this->email);

    expect($segundo)->not->toBe($primero);
    $this->getJson("/api/password/reset/{$primero}")->assertNotFound();
    $this->getJson("/api/password/reset/{$segundo}")->assertOk();
});

it('cambiar la contraseña así desbloquea la cuenta y cierra las sesiones abiertas', function () {
    $sesion = segEntrar($this->email)->json('access_token');

    foreach (range(1, 5) as $intento) {
        segEntrar($this->email, 'clave-equivocada');
    }

    $this->travel(2)->minutes();
    $enlace = segPedirEnlace($this->email);
    $this->postJson('/api/password/reset', ['token' => $enlace, 'password' => 'otra-clave-456', 'password_confirmation' => 'otra-clave-456'])->assertOk();

    segEntrar($this->email, 'otra-clave-456')->assertOk();
    segCon($sesion, 'GET', '/api/user')->assertUnauthorized();
});

it('sin correo de la plataforma avisa que pidan una contraseña al administrador', function () {
    Illuminate\Support\Facades\DB::table('platform_integrations')->where('kind', 'email')->delete();
    PlatformMail::forget();

    $this->postJson('/api/password/forgot', ['email' => $this->email])->assertStatus(503)->assertJsonPath('code', 'MAIL_NOT_READY');
});
