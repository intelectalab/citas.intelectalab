<?php

use App\Models\Tenant;
use App\Models\User;
use App\Support\SignupGuard;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

function registroDatos(array $extra = []): array
{
    return $extra + [
        'ruc' => '20123456786', 'company_name' => 'Spa Registro ' . Str::upper(Str::random(4)), 'department' => 'Lima',
        'first_name' => 'Lucía', 'last_name' => 'Flores', 'email' => 'lucia.' . Str::lower(Str::random(6)) . '@correo.test',
        'password' => 'clave-segura-123', 'plan_id' => TestCase::PLAN_PROFESIONAL,
        'form_token' => SignupGuard::token(now()->subMinute()),
    ];
}

function registrar($test, array $extra = [], string $ip = '10.0.0.1')
{
    TestCase::forgetCaches();

    return $test->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/register-tenant', registroDatos($extra));
}

beforeEach(function () {
    config([
        'services.turnstile.site_key' => null,
        'services.turnstile.secret_key' => null,
        'services.signup.min_seconds' => 4,
        'services.signup.max_hours' => 12,
        'services.signup.per_minute_ip' => 5,
        'services.signup.per_hour_ip' => 20,
        'services.signup.per_hour_email' => 5,
    ]);
});

describe('Formulario de registro', function () {
    it('entrega una ficha del formulario y no pide CAPTCHA si no hay llaves', function () {
        $res = $this->getJson('/api/register-tenant/start')->assertOk();

        expect($res->json('form_token'))->toBeString()->not->toBe('')
            ->and($res->json('turnstile_site_key'))->toBeNull();
    });

    it('registra la empresa con datos correctos', function () {
        $res = registrar($this, ['email' => 'duena.nueva@correo.test'])->assertCreated();

        expect($res->json('access_token'))->toBeString()
            ->and(User::where('email', 'duena.nueva@correo.test')->where('is_owner', true)->count())->toBe(1);
    });
});

describe('Protección contra robots', function () {
    it('rechaza si el campo trampa viene lleno y no crea la empresa', function () {
        $antes = Tenant::count();

        registrar($this, [SignupGuard::TRAP_FIELD => 'https://spam.test'])
            ->assertStatus(422)->assertJsonPath('code', 'SIGNUP_BLOCKED');

        expect(Tenant::count())->toBe($antes);
    });

    it('rechaza si falta la ficha del formulario', function () {
        registrar($this, ['form_token' => ''])->assertStatus(422)->assertJsonPath('code', 'SIGNUP_FORM_EXPIRED');
    });

    it('rechaza una ficha alterada', function () {
        registrar($this, ['form_token' => 'no-es-una-ficha'])->assertStatus(422)->assertJsonPath('code', 'SIGNUP_FORM_EXPIRED');
    });

    it('rechaza una ficha vencida', function () {
        registrar($this, ['form_token' => SignupGuard::token(now()->subHours(13))])
            ->assertStatus(422)->assertJsonPath('code', 'SIGNUP_FORM_EXPIRED');
    });

    it('rechaza si el formulario se llenó demasiado rápido', function () {
        $res = registrar($this, ['form_token' => SignupGuard::token(now()->subSecond())])->assertStatus(422);

        expect($res->json('code'))->toBe('SIGNUP_TOO_FAST')
            ->and($res->json('message'))->toContain('demasiado rápido');
    });

    it('acepta la ficha cuando pasó el tiempo mínimo', function () {
        registrar($this, ['form_token' => SignupGuard::token(now()->subSeconds(5))])->assertCreated();
    });
});

describe('Correo del dueño único', function () {
    it('no deja registrar otra empresa con el mismo correo de dueño, sin importar mayúsculas', function () {
        registrar($this, ['email' => 'misma.duena@correo.test'])->assertCreated();

        $res = registrar($this, ['email' => 'Misma.Duena@Correo.test'], '10.0.0.2')->assertStatus(422);

        expect($res->json('errors.email.0'))->toBe(SignupGuard::takenMessage())
            ->and(User::whereRaw('lower(email) = ?', ['misma.duena@correo.test'])->count())->toBe(1);
    });

    it('rechaza el correo de un dueño ya creado por la plataforma', function () {
        $co = $this->company();
        $correo = User::where('tenant_id', $co->tenant->id)->where('is_owner', true)->value('email');

        registrar($this, ['email' => $correo])->assertStatus(422)->assertJsonValidationErrors('email');
    });

    it('permite registrarse con el correo de alguien que solo es parte del equipo de otra empresa', function () {
        $co = $this->company();
        $persona = $this->staff($co, 'reception');

        registrar($this, ['email' => $persona->email])->assertCreated();
    });

    it('el bloqueo dentro de la transacción también rechaza el correo repetido', function () {
        registrar($this, ['email' => 'carrera@correo.test'])->assertCreated();

        expect(fn () => \Illuminate\Support\Facades\DB::transaction(fn () => SignupGuard::lockEmail('CARRERA@correo.test')))
            ->toThrow(\Illuminate\Validation\ValidationException::class);
    });
});

describe('Límite de intentos', function () {
    it('frena el sexto intento por minuto desde la misma conexión con un mensaje claro', function () {
        foreach (range(1, 5) as $i) {
            registrar($this, ['ruc' => ''], '10.1.1.1')->assertStatus(422);
        }

        $res = registrar($this, ['ruc' => ''], '10.1.1.1')->assertStatus(429);

        expect($res->json('message'))->toContain('Espera un minuto')
            ->and($res->json('code'))->toBe('TOO_MANY_REQUESTS');

        registrar($this, ['ruc' => ''], '10.1.1.2')->assertStatus(422);
    });

    it('frena los intentos repetidos con el mismo correo aunque cambie la conexión', function () {
        foreach (range(1, 5) as $i) {
            registrar($this, ['ruc' => '', 'email' => 'insistente@correo.test'], '10.2.0.' . $i)->assertStatus(422);
        }

        $res = registrar($this, ['ruc' => '', 'email' => 'INSISTENTE@correo.test'], '10.2.0.9')->assertStatus(429);

        expect($res->json('message'))->toContain('con este correo');

        registrar($this, ['ruc' => '', 'email' => 'otra@correo.test'], '10.2.0.9')->assertStatus(422);
    });

    it('frena muchos registros por hora desde la misma conexión', function () {
        config(['services.signup.per_minute_ip' => 100, 'services.signup.per_hour_ip' => 3]);

        foreach (range(1, 3) as $i) {
            registrar($this, ['ruc' => ''], '10.3.3.3')->assertStatus(422);
        }

        expect(registrar($this, ['ruc' => ''], '10.3.3.3')->assertStatus(429)->json('message'))->toContain('Espera una hora');
    });
});

describe('CAPTCHA de Cloudflare (opcional)', function () {
    beforeEach(function () {
        config([
            'services.turnstile.site_key' => 'llave-publica-prueba',
            'services.turnstile.secret_key' => 'llave-secreta-prueba',
            'services.turnstile.verify_url' => 'https://turnstile.test/siteverify',
        ]);
    });

    it('con llaves, el formulario recibe la llave pública', function () {
        expect($this->getJson('/api/register-tenant/start')->json('turnstile_site_key'))->toBe('llave-publica-prueba');
    });

    it('con llaves, pide confirmar que no es un robot', function () {
        Http::fake(['turnstile.test/*' => Http::response(['success' => false, 'error-codes' => ['missing-input-response']])]);

        $res = registrar($this)->assertStatus(422);

        expect($res->json('errors.turnstile_token.0'))->toContain('no eres un robot');
    });

    it('rechaza si Cloudflare dice que no pasó', function () {
        Http::fake(['turnstile.test/*' => Http::response(['success' => false])]);

        registrar($this, ['turnstile_token' => 'respuesta-mala'])->assertStatus(422)->assertJsonValidationErrors('turnstile_token');
    });

    it('registra si Cloudflare confirma y le envía la llave secreta', function () {
        Http::fake(['turnstile.test/*' => Http::response(['success' => true])]);

        registrar($this, ['turnstile_token' => 'respuesta-buena'])->assertCreated();

        Http::assertSent(fn (HttpRequest $request) => $request['secret'] === 'llave-secreta-prueba' && $request['response'] === 'respuesta-buena');
    });

    it('si Cloudflare no responde, deja pasar y lo anota', function () {
        Http::fake(['turnstile.test/*' => Http::response('caído', 500)]);

        registrar($this, ['turnstile_token' => 'respuesta'])->assertCreated();
    });
});
