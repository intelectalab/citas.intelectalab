<?php

require_once __DIR__ . '/Ayudantes.php';

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->co = $this->company();
    $this->token = segEntrar($this->co->owner->email)->assertOk()->json('access_token');
});

it('la sesión sirve mientras se usa', function () {
    segCon($this->token, 'GET', '/api/user')->assertOk()->assertJsonPath('idle_minutes', 30);

    $this->travel(20)->minutes();
    segCon($this->token, 'GET', '/api/user')->assertOk();

    $this->travel(20)->minutes();
    segCon($this->token, 'GET', '/api/user')->assertOk();
});

it('se cierra en el servidor tras el tiempo sin actividad de la empresa', function () {
    $this->travel(31)->minutes();

    $res = segCon($this->token, 'GET', '/api/user')->assertUnauthorized()
        ->assertJsonPath('code', 'SESSION_EXPIRED')
        ->assertJsonPath('reason', 'idle')
        ->assertJsonPath('minutes', 30);

    expect($res->json('message'))->toBe('Tu sesión se cerró porque pasaron 30 minutos sin actividad. Vuelve a ingresar para continuar.');
});

it('respeta el tiempo sin actividad que la empresa eligió', function () {
    $this->setRules($this->co->tenant, ['session.idle_minutes' => 60]);

    $this->travel(45)->minutes();
    segCon($this->token, 'GET', '/api/user')->assertOk();

    $this->travel(61)->minutes();
    $res = segCon($this->token, 'GET', '/api/user')->assertUnauthorized()->assertJsonPath('minutes', 60);
    expect($res->json('message'))->toContain('pasó 1 hora');
});

it('aplica el tiempo sin actividad de cada rol', function () {
    $recepcion = $this->staff($this->co, 'reception');
    $rol = DB::table('roles')->where('tenant_id', $this->co->tenant->id)->where('slug', 'reception')->value('id');
    $this->setRules($this->co->tenant, ['session.idle_by_role' => [$rol => 10]]);
    $suyo = segEntrar($recepcion->email)->json('access_token');

    $this->travel(11)->minutes();

    segCon($suyo, 'GET', '/api/user')->assertUnauthorized()->assertJsonPath('minutes', 10);
    segCon($this->token, 'GET', '/api/user')->assertOk();
});

it('el aviso de «sigo aquí» mantiene viva la sesión', function () {
    $this->travel(25)->minutes();
    segCon($this->token, 'GET', '/api/session')->assertNoContent();

    $this->travel(25)->minutes();
    segCon($this->token, 'GET', '/api/user')->assertOk();
});

it('la sesión del equipo vence a las 12 horas aunque se use', function () {
    expect(config('sanctum.staff_expiration'))->toBe(720);

    foreach (range(1, 35) as $vuelta) {
        $this->travel(20)->minutes();
        segCon($this->token, 'GET', '/api/session')->assertNoContent();
    }

    $this->travel(21)->minutes();

    segCon($this->token, 'GET', '/api/user')->assertUnauthorized()
        ->assertJsonPath('code', 'SESSION_EXPIRED')
        ->assertJsonPath('reason', 'expired')
        ->assertJsonPath('message', 'Tu sesión venció por seguridad. Vuelve a ingresar para continuar.');
});

it('el tiempo de vida se puede cambiar en la configuración', function () {
    config(['sanctum.staff_expiration' => 60]);

    $this->travel(25)->minutes();
    segCon($this->token, 'GET', '/api/user')->assertOk();
    $this->travel(25)->minutes();
    segCon($this->token, 'GET', '/api/user')->assertOk();
    $this->travel(15)->minutes();
    segCon($this->token, 'GET', '/api/user')->assertUnauthorized()->assertJsonPath('reason', 'expired');
});

it('las cuentas de la plataforma también se cierran sin actividad', function () {
    $id = (string) Illuminate\Support\Str::uuid();
    App\Models\User::forceCreate([
        'id' => $id, 'tenant_id' => null, 'first_name' => 'Soporte', 'last_name' => 'Prueba', 'email' => 'soporte.sesion@prueba.test',
        'password_hash' => bcrypt(SEG_CLAVE), 'is_owner' => false, 'status' => 'active',
    ]);
    $token = App\Models\User::find($id)->createToken('auth_token')->plainTextToken;

    $this->travel(20)->minutes();
    segCon($token, 'GET', '/api/user')->assertOk();

    $this->travel(31)->minutes();
    segCon($token, 'GET', '/api/user')->assertUnauthorized()->assertJsonPath('reason', 'idle');
});

it('el portal del cliente no se cierra por inactividad salvo que la empresa lo pida', function () {
    $clienta = $this->customer($this->co->tenant);
    $token = $clienta->createToken('auth_token')->plainTextToken;

    $this->travel(3)->days();
    segCon($token, 'GET', '/api/user')->assertOk();

    $this->setRules($this->co->tenant, ['session.portal_idle_minutes' => 15]);
    $this->travel(16)->minutes();
    segCon($token, 'GET', '/api/user')->assertUnauthorized()->assertJsonPath('minutes', 15);
});

it('la sesión del portal vence a los 30 días', function () {
    $clienta = $this->customer($this->co->tenant);
    $token = $clienta->createToken('auth_token')->plainTextToken;

    foreach (range(1, 4) as $semana) {
        $this->travel(7)->days();
        segCon($token, 'GET', '/api/user')->assertOk();
    }

    $this->travel(3)->days();
    segCon($token, 'GET', '/api/user')->assertUnauthorized()->assertJsonPath('reason', 'expired');
});

it('un token borrado responde la sesión terminada sin el código de vencida', function () {
    segCon($this->token, 'POST', '/api/logout')->assertOk();

    segCon($this->token, 'GET', '/api/user')->assertUnauthorized()->assertJsonMissingPath('code');
});

it('la tarea diaria borra las sesiones vencidas y deja las vigentes', function () {
    $clienta = $this->customer($this->co->tenant);
    $clienta->createToken('auth_token');
    $this->travel(13)->hours();
    $nueva = segEntrar($this->co->owner->email)->json('access_token');

    $this->artisan('sessions:prune')->expectsOutputToContain('Sesiones vencidas borradas: 1.')->assertSuccessful();

    expect(PersonalAccessToken::count())->toBe(2);
    segCon($nueva, 'GET', '/api/user')->assertOk();

    $this->travel(31)->days();
    $this->artisan('sessions:prune')->assertSuccessful();
    expect(PersonalAccessToken::count())->toBe(0);
});

it('la tarea de limpieza está programada', function () {
    $tareas = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => $e->command);

    expect($tareas->contains(fn ($c) => str_contains((string) $c, 'sessions:prune')))->toBeTrue();
});
