<?php

require_once __DIR__ . '/Ayudantes.php';

use App\Support\PlatformMail;

beforeEach(function () {
    segCorreoListo();
    $this->co = $this->company(['rules' => ['security.mfa_admin' => true]]);
    $this->email = $this->co->owner->email;
});

it('pide el código por correo al administrador cuando la regla está encendida', function () {
    $res = segEntrar($this->email)->assertOk()
        ->assertJsonPath('requires_code', true)
        ->assertJsonMissingPath('access_token');

    expect($res->json('challenge'))->toBeString()
        ->and(segUltimoCorreo()['to'])->toBe($this->email)
        ->and(segCodigo())->toMatch('/^\d{6}$/');
});

it('no pide código si la regla está apagada', function () {
    $this->setRules($this->co->tenant, ['security.mfa_admin' => false]);

    segEntrar($this->email)->assertOk()->assertJsonStructure(['access_token']);
    expect(PlatformMail::$outbox)->toBeEmpty();
});

it('no pide código a quien no es administrador', function () {
    $recepcion = $this->staff($this->co, 'reception');

    segEntrar($recepcion->email)->assertOk()->assertJsonStructure(['access_token']);
});

it('no pide código mientras el correo de la plataforma no funciona', function () {
    Illuminate\Support\Facades\DB::table('platform_integrations')->where('kind', 'email')->delete();
    PlatformMail::forget();

    segEntrar($this->email)->assertOk()->assertJsonStructure(['access_token']);
});

it('con el código correcto entra', function () {
    $challenge = segEntrar($this->email)->json('challenge');

    $this->postJson('/api/login/verify', ['challenge' => $challenge, 'code' => segCodigo()])
        ->assertOk()->assertJsonStructure(['access_token', 'user'])->assertJsonMissingPath('device_token');
});

it('con un código incorrecto no entra y dice cuántos intentos quedan', function () {
    $challenge = segEntrar($this->email)->json('challenge');
    $malo = segCodigo() === '000000' ? '111111' : '000000';

    $res = $this->postJson('/api/login/verify', ['challenge' => $challenge, 'code' => $malo])->assertStatus(422);
    expect($res->json('errors.code.0'))->toContain('Te quedan 4 intentos');
});

it('el código no sirve dos veces', function () {
    $challenge = segEntrar($this->email)->json('challenge');
    $codigo = segCodigo();

    $this->postJson('/api/login/verify', ['challenge' => $challenge, 'code' => $codigo])->assertOk();
    $this->postJson('/api/login/verify', ['challenge' => $challenge, 'code' => $codigo])->assertStatus(422);
});

it('el código vence a los 10 minutos', function () {
    $challenge = segEntrar($this->email)->json('challenge');
    $codigo = segCodigo();

    $this->travel(11)->minutes();

    $res = $this->postJson('/api/login/verify', ['challenge' => $challenge, 'code' => $codigo])->assertStatus(422);
    expect($res->json('errors.code.0'))->toContain('venció');
});

it('cinco códigos equivocados cortan el ingreso', function () {
    $challenge = segEntrar($this->email)->json('challenge');
    $codigo = segCodigo();
    $malo = $codigo === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $intento) {
        $this->postJson('/api/login/verify', ['challenge' => $challenge, 'code' => $malo])->assertStatus(422);
    }

    $this->postJson('/api/login/verify', ['challenge' => $challenge, 'code' => $codigo])->assertStatus(422);
});

it('«confiar en este equipo» evita el código la próxima vez', function () {
    $challenge = segEntrar($this->email)->json('challenge');

    $equipo = $this->postJson('/api/login/verify', ['challenge' => $challenge, 'code' => segCodigo(), 'trust' => true])
        ->assertOk()->json('device_token');

    expect($equipo)->toBeString();
    PlatformMail::$outbox = [];

    segEntrar($this->email, extra: ['device_token' => $equipo])->assertOk()->assertJsonStructure(['access_token']);
    expect(PlatformMail::$outbox)->toBeEmpty();

    segEntrar($this->email, extra: ['device_token' => 'otro-equipo'])->assertOk()->assertJsonPath('requires_code', true);
});

it('el equipo de confianza vence a los 30 días', function () {
    $challenge = segEntrar($this->email)->json('challenge');
    $equipo = $this->postJson('/api/login/verify', ['challenge' => $challenge, 'code' => segCodigo(), 'trust' => true])->json('device_token');

    $this->travel(31)->days();

    segEntrar($this->email, extra: ['device_token' => $equipo])->assertOk()->assertJsonPath('requires_code', true);
});
