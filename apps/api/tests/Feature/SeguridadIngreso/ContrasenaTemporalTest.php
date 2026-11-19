<?php

require_once __DIR__ . '/Ayudantes.php';

beforeEach(function () {
    $this->co = $this->company();
    $this->recepcion = $this->staff($this->co, 'reception');
    $this->temporal = $this->as($this->co->owner)
        ->postJson("/api/access/users/{$this->recepcion->id}/password")
        ->assertOk()->json('temporary_password');
});

it('la contraseña temporal deja entrar pero obliga a crear una propia', function () {
    $res = segEntrar($this->recepcion->email, $this->temporal)->assertOk()->assertJsonPath('user.must_change_password', true);

    segCon($res->json('access_token'), 'GET', '/api/account/devices')
        ->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
});

it('la contraseña anterior deja de servir', function () {
    segEntrar($this->recepcion->email)->assertStatus(401);
});

it('no acepta repetir la contraseña temporal como la nueva', function () {
    $token = segEntrar($this->recepcion->email, $this->temporal)->json('access_token');

    segCon($token, 'PUT', '/api/account/password', ['current_password' => $this->temporal, 'password' => $this->temporal, 'password_confirmation' => $this->temporal])
        ->assertStatus(422)->assertJsonValidationErrors('password');
});

it('al crear su contraseña ya puede usar el sistema', function () {
    $token = segEntrar($this->recepcion->email, $this->temporal)->json('access_token');

    segCon($token, 'PUT', '/api/account/password', ['current_password' => $this->temporal, 'password' => 'mi-clave-propia-1', 'password_confirmation' => 'mi-clave-propia-1'])
        ->assertOk();

    segCon($token, 'GET', '/api/account/devices')->assertOk();
    expect((bool) $this->recepcion->refresh()->must_change_password)->toBeFalse();
    segEntrar($this->recepcion->email, 'mi-clave-propia-1')->assertOk()->assertJsonPath('user.must_change_password', false);
});

it('pide la contraseña temporal correcta para cambiarla', function () {
    $token = segEntrar($this->recepcion->email, $this->temporal)->json('access_token');

    segCon($token, 'PUT', '/api/account/password', ['current_password' => 'no-es-esta', 'password' => 'mi-clave-propia-1', 'password_confirmation' => 'mi-clave-propia-1'])
        ->assertStatus(422)->assertJsonValidationErrors('current_password');
});
