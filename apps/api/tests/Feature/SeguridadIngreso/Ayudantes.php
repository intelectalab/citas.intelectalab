<?php

use App\Support\PlatformMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

const SEG_CLAVE = 'clave-de-prueba-123';

function segCorreoListo(): void
{
    DB::table('platform_integrations')->insert([
        'id' => (string) Str::uuid(), 'kind' => 'email', 'provider' => 'smtp', 'mode' => 'production',
        'settings' => json_encode(['host' => 'smtp.prueba.test', 'port' => 587, 'security' => 'tls', 'from_address' => 'avisos@prueba.test', 'test_ok' => true]),
        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    Tests\TestCase::forgetCaches();
    PlatformMail::forget();
}

function segEntrar(string $email, string $password = SEG_CLAVE, array $extra = []): TestResponse
{
    return test()->postJson('/api/login', ['email' => $email, 'password' => $password] + $extra);
}

function segCon(string $token, string $method, string $uri, array $data = []): TestResponse
{
    app('auth')->forgetGuards();
    Tests\TestCase::forgetCaches();

    return test()->withHeader('Authorization', 'Bearer ' . $token)->json($method, $uri, $data);
}

function segUltimoCorreo(): array
{
    expect(PlatformMail::$outbox)->not->toBeEmpty();

    return end(PlatformMail::$outbox);
}

function segCodigo(): string
{
    preg_match('/^(\d{6}) es tu código/', segUltimoCorreo()['subject'], $m);

    return $m[1];
}

function segEnlace(): string
{
    preg_match('#/restablecer/([A-Za-z0-9]+)#', segUltimoCorreo()['body'], $m);

    return $m[1];
}
