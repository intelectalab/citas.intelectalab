<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Support\Assistant;
use App\Support\Captcha;
use App\Support\IntegrationLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlatformIntegrationController extends Controller
{
    private static function mask(?string $value): ?string
    {
        return $value ? str_repeat('•', 8) . mb_substr($value, -4) : null;
    }

    private static function row(string $kind): ?object
    {
        return DB::table('platform_integrations')->where('kind', $kind)->first();
    }

    private static function store(string $kind, string $provider, array $settings, array $secret): void
    {
        $values = [
            'provider'    => $provider,
            'mode'        => 'production',
            'status'      => 'active',
            'settings'    => json_encode($settings),
            'credentials' => Crypt::encryptString(json_encode($secret)),
            'updated_at'  => now(),
        ];
        $row = self::row($kind);

        $row
            ? DB::table('platform_integrations')->where('id', $row->id)->update($values)
            : DB::table('platform_integrations')->insert($values + ['id' => (string) Str::uuid(), 'kind' => $kind, 'created_at' => now()]);
    }

    public function show()
    {
        $ai = Assistant::platformConfig();
        $captcha = Captcha::config();

        return response()->json([
            'ai' => [
                'own'       => $ai ? [
                    'provider'  => $ai['provider'],
                    'label'     => Assistant::AI_PROVIDERS[$ai['provider']]['label'],
                    'model'     => $ai['model'] ?? null,
                    'key_saved' => self::mask($ai['api_key']),
                    'saved_at'  => $ai['updated_at'] ? Carbon::parse($ai['updated_at'])->toIso8601String() : null,
                ] : null,
                'providers' => AssistantController::providers(),
            ],
            'captcha' => [
                'on'         => filled($captcha['site_key']) && filled($captcha['secret_key']),
                'source'     => $captcha['source'],
                'site_key'   => $captcha['site_key'],
                'secret_saved' => self::mask($captcha['secret_key']),
                'places'     => $captcha['places'],
                'all_places' => collect(Captcha::PLACES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            ],
            'log' => IntegrationLog::recent(null, null, 40),
        ]);
    }

    public function saveAi(Request $request)
    {
        $validated = $request->validate([
            'provider' => ['required', Rule::in(array_keys(Assistant::AI_PROVIDERS))],
            'api_key'  => 'nullable|string|max:400',
            'model'    => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);

        $old = Assistant::platformConfig();
        $key = trim((string) ($validated['api_key'] ?? '')) ?: (($old['provider'] ?? null) === $validated['provider'] ? ($old['api_key'] ?? null) : null);

        if (!$key) {
            throw ValidationException::withMessages(['api_key' => 'Pega la clave (API key) del proveedor.']);
        }

        $config = ['provider' => $validated['provider'], 'api_key' => $key, 'model' => trim((string) ($validated['model'] ?? '')) ?: null];

        try {
            $check = Assistant::build($config)->ping();
        } catch (\RuntimeException $e) {
            $check = ['ok' => false, 'message' => $e->getMessage()];
        }

        IntegrationLog::write(null, 'ai', $validated['provider'], 'test', $check['ok'], $check['message']);

        if (!$check['ok']) {
            throw ValidationException::withMessages(['api_key' => $check['message']]);
        }

        self::store('ai', $validated['provider'], ['model' => $config['model']], ['api_key' => $key]);
        AuditEvent::record($request, 'platform.integration_changed', null, ['assistant_provider' => $old ? Assistant::AI_PROVIDERS[$old['provider']]['label'] : 'Sin clave'], ['assistant_provider' => Assistant::AI_PROVIDERS[$validated['provider']]['label']]);

        return response()->json(['message' => $check['message']] + $this->show()->getData(true));
    }

    public function saveCaptcha(Request $request)
    {
        $validated = $request->validate([
            'site_key'   => 'required|string|max:120',
            'secret_key' => 'nullable|string|max:200',
            'places'     => 'present|array',
            'places.*'   => [Rule::in(array_keys(Captcha::PLACES))],
        ], ['site_key.required' => 'Pega la clave del sitio (site key) de Cloudflare Turnstile.']);

        $old = Captcha::config();
        $secret = trim((string) ($validated['secret_key'] ?? '')) ?: ($old['source'] === 'platform' ? $old['secret_key'] : null);

        if (!$secret) {
            throw ValidationException::withMessages(['secret_key' => 'Pega la clave secreta (secret key) de Cloudflare Turnstile.']);
        }

        $check = Captcha::verify('prueba-de-conexion', null, $secret);
        $badSecret = (bool) array_intersect($check['codes'], ['invalid-input-secret', 'missing-input-secret']);
        IntegrationLog::write(null, 'captcha', 'turnstile', 'test', !$badSecret && !in_array('unreachable', $check['codes'], true), $badSecret ? 'Cloudflare dice que la clave secreta no es válida.' : (in_array('unreachable', $check['codes'], true) ? 'No se pudo conectar con Cloudflare.' : 'Cloudflare reconoce la clave secreta.'));

        if ($badSecret) {
            throw ValidationException::withMessages(['secret_key' => 'Cloudflare dice que la clave secreta no es válida: cópiala de nuevo desde Turnstile.']);
        }

        self::store('captcha', 'turnstile', ['site_key' => trim($validated['site_key']), 'places' => array_values(array_unique($validated['places']))], ['secret_key' => $secret]);
        AuditEvent::record($request, 'platform.integration_changed', null, ['captcha' => $old['source'] === 'platform' ? 'Configurado' : 'Sin configurar'], ['captcha' => 'Turnstile en: ' . (collect($validated['places'])->map(fn ($p) => Captcha::PLACES[$p])->implode(', ') ?: 'ningún lugar')]);

        return response()->json($this->show()->getData(true));
    }

    public function destroyAi(Request $request)
    {
        return $this->destroy($request, 'ai');
    }

    public function destroyCaptcha(Request $request)
    {
        return $this->destroy($request, 'captcha');
    }

    private function destroy(Request $request, string $kind)
    {
        $deleted = DB::table('platform_integrations')->where('kind', $kind)->delete();

        if ($deleted) {
            AuditEvent::record($request, 'platform.integration_changed', null, [$kind => 'Configurado'], [$kind => 'Quitado']);
        }

        return response()->json($this->show()->getData(true));
    }

    public function testAi()
    {
        $config = Assistant::platformConfig();

        if (!$config) {
            return response()->json(['ok' => false, 'message' => 'Todavía no hay una clave guardada.']);
        }

        try {
            $result = Assistant::build($config)->ping();
        } catch (\RuntimeException $e) {
            $result = ['ok' => false, 'message' => $e->getMessage()];
        }

        IntegrationLog::write(null, 'ai', $config['provider'], 'test', $result['ok'], $result['message']);

        return response()->json($result);
    }
}
