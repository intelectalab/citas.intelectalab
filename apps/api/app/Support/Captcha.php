<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class Captcha
{
    public const FIELD = 'turnstile_token';

    public const PLACES = ['signup' => 'Registro de empresas', 'booking' => 'Página pública de reservas', 'complaints' => 'Libro de Reclamaciones'];

    public static function config(): array
    {
        $row = Schema::hasTable('platform_integrations') ? DB::table('platform_integrations')->where('kind', 'captcha')->first() : null;
        $settings = $row ? (is_string($row->settings) ? (json_decode($row->settings, true) ?: []) : (array) $row->settings) : [];

        try {
            $secret = $row && $row->credentials ? (json_decode(Crypt::decryptString($row->credentials), true) ?: []) : [];
        } catch (\Throwable $e) {
            $secret = [];
        }

        return [
            'site_key'   => $row ? ($settings['site_key'] ?? null) : config('services.turnstile.site_key'),
            'secret_key' => $row ? ($secret['secret_key'] ?? null) : config('services.turnstile.secret_key'),
            'places'     => $row ? (array) ($settings['places'] ?? array_keys(self::PLACES)) : ['signup'],
            'source'     => $row ? 'platform' : 'env',
            'row'        => $row,
        ];
    }

    public static function on(string $place = 'signup'): bool
    {
        $config = self::config();

        return filled($config['site_key']) && filled($config['secret_key']) && in_array($place, $config['places'], true);
    }

    public static function siteKey(string $place = 'signup'): ?string
    {
        return self::on($place) ? self::config()['site_key'] : null;
    }

    public static function verify(string $token, ?string $ip, ?string $secret = null): array
    {
        try {
            $result = Http::asForm()->timeout(8)->post((string) config('services.turnstile.verify_url'), array_filter([
                'secret'   => $secret ?? self::config()['secret_key'],
                'response' => $token,
                'remoteip' => $ip,
            ]));
        } catch (\Throwable) {
            return ['ok' => false, 'codes' => ['unreachable']];
        }

        if ($result->serverError() || !is_array($result->json())) {
            return ['ok' => false, 'codes' => ['unreachable']];
        }

        return ['ok' => $result->ok() && $result->json('success') === true, 'codes' => (array) $result->json('error-codes', [])];
    }

    public static function assert(Request $request, string $place): void
    {
        if (!self::on($place)) {
            return;
        }

        $result = self::verify(trim((string) $request->input(self::FIELD, '')), $request->ip());

        if ($result['ok']) {
            return;
        }

        if (in_array('unreachable', $result['codes'], true)) {
            IntegrationLog::write(null, 'captcha', 'turnstile', 'verify', false, 'No se pudo conectar con Cloudflare para revisar la verificación; se dejó pasar en ' . (self::PLACES[$place] ?? $place) . '.');
            ErrorLogs::capture(new \RuntimeException('Cloudflare Turnstile no respondió; se dejó pasar sin verificar en ' . (self::PLACES[$place] ?? $place) . '.'), $request);

            return;
        }

        if (array_intersect($result['codes'], ['invalid-input-secret', 'missing-input-secret'])) {
            IntegrationLog::write(null, 'captcha', 'turnstile', 'verify', false, 'Cloudflare dice que la clave secreta de Turnstile no es válida.');
        }

        throw ValidationException::withMessages([self::FIELD => 'Confirma que no eres un robot antes de continuar.']);
    }
}
