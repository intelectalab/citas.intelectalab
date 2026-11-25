<?php

require_once __DIR__ . '/Ayudantes.php';

beforeEach(function () {
    $this->co = $this->company();
    $this->recep = $this->staff($this->co, 'reception', null, 'Ana Recepción');
});

it('el personal entra aunque escriba el correo con mayúsculas o espacios', function () {
    $escrito = '  ' . ucfirst(mb_strtoupper($this->recep->email)) . ' ';

    segEntrar($escrito)->assertOk();
});

it('con mayúsculas y la contraseña equivocada sigue sin entrar', function () {
    segEntrar(mb_strtoupper($this->recep->email), 'otra-clave-123')->assertStatus(401);
});
