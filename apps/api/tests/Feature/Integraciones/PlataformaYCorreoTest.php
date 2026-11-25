<?php

use App\Models\User;
use App\Support\Mail\SesSigner;
use App\Support\PlatformMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

const TURNSTILE = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

beforeEach(function () {
    $this->admin = User::forceCreate([
        'id' => (string) Str::uuid(), 'first_name' => 'Admin', 'last_name' => 'Plataforma', 'email' => 'saas.' . Str::lower(Str::random(6)) . '@plataforma.test',
        'password_hash' => bcrypt('clave-de-prueba-123'), 'status' => 'active', 'tenant_id' => null,
    ]);
    $this->reclamo = [
        'name' => 'Rosa Quispe Huamán', 'document_type' => 'DNI', 'document_number' => '45678912', 'address' => 'Av. Arequipa 1234, Lince', 'phone' => '987654321',
        'email' => 'rosa.quispe@correo.test', 'is_minor' => false, 'good_type' => 'servicio', 'good_description' => 'Corte de cabello', 'amount' => 35,
        'kind' => 'reclamo', 'detail' => 'No me atendieron a la hora.', 'request' => 'Que me devuelvan el adelanto.', 'accepted' => true,
    ];
});

describe('Turnstile por detrás', function () {
    it('está apagado por defecto: el libro de reclamaciones no pide verificación', function () {
        Http::fake();
        $this->getJson('/api/complaints-book')->assertOk()->assertJsonPath('captcha_site_key', null);
        $this->postJson('/api/complaints-book', $this->reclamo)->assertCreated();
        Http::assertNothingSent();
    });

    it('la plataforma lo enciende con su clave probada y solo en los lugares elegidos', function () {
        Http::fake([TURNSTILE => Http::sequence()
            ->push(['success' => false, 'error-codes' => ['invalid-input-secret']])
            ->push(['success' => false, 'error-codes' => ['invalid-input-response']])
            ->push(['success' => false, 'error-codes' => ['invalid-input-response']])
            ->push(['success' => true])]);

        $this->as($this->admin)->putJson('/api/admin/integrations/captcha', ['site_key' => '0x4AAA', 'secret_key' => 'mala', 'places' => ['complaints']])->assertStatus(422)->assertJsonValidationErrors('secret_key');
        $this->as($this->admin)->putJson('/api/admin/integrations/captcha', ['site_key' => '0x4AAA', 'secret_key' => '0x4AAA-secreta', 'places' => ['complaints']])->assertOk()
            ->assertJsonPath('captcha.on', true)->assertJsonPath('captcha.places', ['complaints']);
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/complaints-book')->assertOk()->assertJsonPath('captcha_site_key', '0x4AAA');
        $this->postJson('/api/complaints-book', $this->reclamo + ['turnstile_token' => 'robot'])->assertStatus(422)->assertJsonValidationErrors('turnstile_token');
        $this->postJson('/api/complaints-book', $this->reclamo + ['turnstile_token' => 'humano'])->assertCreated();

        Http::assertSent(fn ($r) => $r->url() === TURNSTILE && $r['secret'] === '0x4AAA-secreta' && $r['response'] === 'humano');
    });

    it('una empresa no puede tocar las integraciones de la plataforma', function () {
        $co = $this->company();
        $this->as($co->owner)->getJson('/api/admin/integrations')->assertForbidden();
        $this->as($co->owner)->putJson('/api/admin/integrations/ai', ['provider' => 'anthropic', 'api_key' => 'x'])->assertForbidden();
    });
});

describe('correo por API', function () {

    it('la plataforma envía con Resend y el error de la clave se explica con palabras', function () {
        Http::fake(['https://api.resend.com/emails' => Http::sequence()
            ->push(['id' => 'em_1'])
            ->push(['statusCode' => 401, 'message' => 'API key is invalid', 'name' => 'validation_error'], 401)]);

        $this->as($this->admin)->putJson('/api/admin/platform-mail', ['transport' => 'api', 'service' => 'resend', 'password' => 're_clave', 'from_address' => 'hola@plataforma.test', 'from_name' => 'Agenda'])->assertOk()
            ->assertJsonPath('transport', 'api')->assertJsonPath('configured', true);

        PlatformMail::$outbox = null;
        PlatformMail::forget();
        $ok = PlatformMail::test('yo@correo.test');
        $bad = PlatformMail::test('yo@correo.test');

        expect($ok['ok'])->toBeTrue()->and($bad['ok'])->toBeFalse()->and($bad['message'])->toContain('Resend no aceptó la clave');
        Http::assertSent(fn ($r) => $r->url() === 'https://api.resend.com/emails' && $r->hasHeader('Authorization', 'Bearer re_clave') && $r['to'] === ['yo@correo.test'] && str_contains($r['html'], 'correo de prueba') && $r['from'] === 'Agenda <hola@plataforma.test>');
        expect(DB::table('platform_integration_logs')->where('kind', 'email')->count())->toBe(2);
    });

    it('con Brevo usa su cabecera api-key y su formato', function () {
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<1@brevo>'], 201)]);
        $this->as($this->admin)->putJson('/api/admin/platform-mail', ['transport' => 'api', 'service' => 'brevo', 'password' => 'xkeysib-1', 'from_address' => 'hola@plataforma.test'])->assertOk();
        PlatformMail::forget();
        PlatformMail::$outbox = null;

        PlatformMail::send('yo@correo.test', 'Hola', 'Texto del correo');

        Http::assertSent(fn ($r) => $r->hasHeader('api-key', 'xkeysib-1') && $r['sender']['email'] === 'hola@plataforma.test' && $r['to'][0]['email'] === 'yo@correo.test' && $r['subject'] === 'Hola');
    });

    it('con Amazon SES firma la solicitud como pide AWS', function () {
        Http::fake(['https://email.us-east-1.amazonaws.com/v2/email/outbound-emails' => Http::response(['MessageId' => 'ses-1'])]);
        $this->as($this->admin)->putJson('/api/admin/platform-mail', ['transport' => 'api', 'service' => 'ses', 'username' => 'AKIAEJEMPLO', 'password' => 'secreto', 'region' => 'us-east-1', 'from_address' => 'hola@plataforma.test'])->assertOk();
        PlatformMail::forget();
        PlatformMail::$outbox = null;

        PlatformMail::send('yo@correo.test', 'Hola', 'Texto');

        Http::assertSent(fn ($r) => str_starts_with($r->header('Authorization')[0] ?? '', 'AWS4-HMAC-SHA256 Credential=AKIAEJEMPLO/') && str_contains($r->header('Authorization')[0], '/us-east-1/ses/aws4_request') && isset($r['Content']['Raw']['Data']));
    });

    it('la firma de AWS es estable para los mismos datos', function () {
        $a = SesSigner::headers('POST', 'https://email.us-east-1.amazonaws.com/v2/email/outbound-emails', '{}', 'AKID', 'SECRET', 'us-east-1', 1700000000);
        $b = SesSigner::headers('POST', 'https://email.us-east-1.amazonaws.com/v2/email/outbound-emails', '{}', 'AKID', 'SECRET', 'us-east-1', 1700000000);
        $c = SesSigner::headers('POST', 'https://email.us-east-1.amazonaws.com/v2/email/outbound-emails', '{"a":1}', 'AKID', 'SECRET', 'us-east-1', 1700000000);

        expect($a)->toBe($b)->and($a['Authorization'])->not->toBe($c['Authorization'])->and($a['X-Amz-Date'])->toBe('20231114T221320Z');
    });
});
