<?php

namespace App\Support;

use App\Models\User;
use App\Support\WhatsApp\SendFailed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PlatformWhatsApp
{
    public const PROVIDERS = ['cloud' => 'WhatsApp Cloud API (Meta)', '360dialog' => '360dialog', 'simulated' => 'Probar sin enviar'];

    public const KINDS = [
        'overdue'  => 'Plan vencido (recordatorio)',
        'readonly' => 'Empresa en solo lectura',
        'canceled' => 'Plan cancelado',
        'purge'    => 'Aviso de borrado de datos',
    ];

    private static ?array $loaded = null;

    public static function forget(): void
    {
        self::$loaded = null;
    }

    public static function row(): ?object
    {
        self::$loaded ??= [Schema::hasTable('platform_integrations') ? DB::table('platform_integrations')->where('kind', 'whatsapp')->first() : null];

        return self::$loaded[0];
    }

    public static function config(): ?array
    {
        $row = self::row();

        if (!$row) {
            return null;
        }

        $settings = is_string($row->settings) ? (json_decode($row->settings, true) ?: []) : (array) $row->settings;
        $secret = [];

        if ($row->credentials) {
            try {
                $secret = json_decode(Crypt::decryptString($row->credentials), true) ?: [];
            } catch (\Throwable $e) {
                $secret = [];
            }
        }

        return $settings + ['provider' => $row->provider, 'token' => $secret['token'] ?? null, 'updated_at' => $row->updated_at];
    }

    public static function enabled(): bool
    {
        $config = self::config();

        if (!$config || ($config['active'] ?? true) === false) {
            return false;
        }

        if ($config['provider'] === 'simulated') {
            return !app()->isProduction();
        }

        return !empty($config['token']) && ($config['provider'] === '360dialog' || !empty($config['phone_number_id']));
    }

    public static function recipients(string $tenantId): array
    {
        return User::where('tenant_id', $tenantId)->where('status', 'active')
            ->where(fn ($q) => $q->where('is_owner', true)->orWhereIn('id', Notifier::adminIds($tenantId)))
            ->pluck('phone')->map(fn ($phone) => WhatsApp::phone($phone))->filter()->unique()->values()->all();
    }

    public static function text(string $kind, array $params): string
    {
        [$a, $b, $c, $d] = array_pad(array_map('strval', $params), 4, '');

        return match ($kind) {
            'overdue'  => "Hola. El plan {$b} de {$a} venció y no está pagado ({$c}). Si no se paga antes del {$d}, tu empresa quedará en solo lectura. Págalo en Mi empresa → Plan.",
            'readonly' => "Hola. {$a} quedó en solo lectura porque el plan no está pagado. Puedes ver y descargar tus datos; para volver a agendar y vender, paga el plan en Mi empresa → Plan.",
            'canceled' => "Hola. El plan de {$a} se canceló por falta de pago. Tus datos se conservan: si pagas el plan, tu empresa vuelve a tener todo.",
            'purge'    => "Hola. {$a} está dada de baja y el {$b} se borrarán los datos personales de tus clientes y de tu equipo. Si quieres conservarlos, descárgalos en Mi empresa → Plan o paga tu plan para volver.",
            default    => $a,
        };
    }

    public static function notify(string $tenantId, string $kind, array $params): int
    {
        if (!self::enabled()) {
            return 0;
        }

        $config = self::config();
        $phones = self::recipients($tenantId);

        if (!$phones) {
            return 0;
        }

        $provider = WhatsApp::provider($config);
        $template = trim((string) ($config['templates'][$kind] ?? ''));
        $sent = 0;
        $error = null;

        foreach ($phones as $phone) {
            try {
                $template !== ''
                    ? $provider->sendTemplate($config, $phone, $template, (string) ($config['language'] ?? 'es'), array_values(array_map('strval', $params)))
                    : $provider->sendText($config, $phone, self::text($kind, $params));
                $sent++;
            } catch (SendFailed $e) {
                $error = $e->getMessage();
            } catch (\Throwable $e) {
                report($e);
                $error = 'No se pudo enviar el WhatsApp.';
            }
        }

        IntegrationLog::write(null, 'whatsapp', $provider->key(), 'send', $error === null, $error ?? 'Aviso del plan enviado por WhatsApp (' . (self::KINDS[$kind] ?? $kind) . ").");
        PlatformLog::system('subscription.overdue_whatsapp', $tenantId, ['tipo' => self::KINDS[$kind] ?? $kind, 'enviados' => $sent, 'resultado' => $error ?? 'Enviado']);

        return $sent;
    }

    public static function save(array $values): array
    {
        $old = self::config();
        $token = trim((string) ($values['token'] ?? '')) ?: (($old['provider'] ?? null) === $values['provider'] ? ($old['token'] ?? null) : null);
        $settings = [
            'phone_number_id' => trim((string) ($values['phone_number_id'] ?? '')) ?: null,
            'language'        => trim((string) ($values['language'] ?? '')) ?: 'es',
            'templates'       => collect(array_keys(self::KINDS))->mapWithKeys(fn ($kind) => [$kind => trim((string) ($values['templates'][$kind] ?? '')) ?: null])->all(),
            'active'          => (bool) ($values['active'] ?? true),
        ];
        $data = [
            'provider'    => $values['provider'],
            'mode'        => 'production',
            'status'      => 'active',
            'settings'    => json_encode($settings),
            'credentials' => Crypt::encryptString(json_encode(['token' => $token])),
            'updated_at'  => now(),
        ];
        $row = self::row();

        $row
            ? DB::table('platform_integrations')->where('id', $row->id)->update($data)
            : DB::table('platform_integrations')->insert($data + ['id' => (string) Str::uuid(), 'kind' => 'whatsapp', 'created_at' => now()]);

        self::forget();

        return self::present();
    }

    public static function remove(): void
    {
        if (Schema::hasTable('platform_integrations')) {
            DB::table('platform_integrations')->where('kind', 'whatsapp')->delete();
        }

        self::forget();
    }

    public static function test(): array
    {
        $config = self::config();

        if (!$config) {
            return ['ok' => false, 'message' => 'Todavía no hay datos de WhatsApp guardados.'];
        }

        $result = WhatsApp::provider($config)->check($config);
        IntegrationLog::write(null, 'whatsapp', (string) $config['provider'], 'test', (bool) $result['ok'], (string) $result['message']);

        return ['ok' => (bool) $result['ok'], 'message' => (string) $result['message']];
    }

    public static function present(): array
    {
        $config = self::config();
        $token = $config['token'] ?? null;

        return [
            'enabled'         => self::enabled(),
            'saved'           => (bool) $config,
            'provider'        => $config['provider'] ?? null,
            'phone_number_id' => $config['phone_number_id'] ?? null,
            'language'        => $config['language'] ?? 'es',
            'templates'       => $config['templates'] ?? array_fill_keys(array_keys(self::KINDS), null),
            'active'          => (bool) ($config['active'] ?? true),
            'token_saved'     => $token ? str_repeat('•', 8) . mb_substr($token, -4) : null,
            'saved_at'        => !empty($config['updated_at']) ? Carbon::parse($config['updated_at'])->toIso8601String() : null,
            'providers'       => collect(self::PROVIDERS)->except(app()->isProduction() ? ['simulated'] : [])->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'kinds'           => collect(self::KINDS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
        ];
    }
}
