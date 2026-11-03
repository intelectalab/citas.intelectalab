<?php

namespace App\Support;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PlatformMail
{
    public static ?array $outbox = null;

    private static ?array $loaded = null;

    public static function row(): ?object
    {
        self::$loaded ??= [Schema::hasTable('platform_integrations') ? DB::table('platform_integrations')->where('kind', 'email')->first() : null];

        return self::$loaded[0];
    }

    public static function forget(): void
    {
        self::$loaded = null;
    }

    public static function config(): ?array
    {
        $row = self::row();

        if (!$row) {
            return null;
        }

        $settings = json_decode($row->settings ?? '{}', true) ?: [];
        $secret = $row->credentials ? (json_decode(Crypt::decryptString($row->credentials), true) ?: []) : [];

        return $settings + ['password' => $secret['password'] ?? null, 'updated_at' => $row->updated_at];
    }

    public static function configured(): bool
    {
        $config = self::config();

        return $config && !empty($config['host']) && !empty($config['from_address']);
    }

    public static function ready(): bool
    {
        return self::configured() && (bool) (self::config()['test_ok'] ?? false);
    }

    public static function productName(): string
    {
        return (string) (PlatformSetting::value('product_name') ?: config('app.name'));
    }

    public static function mask(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $shown = mb_substr($name, 0, min(2, max(1, mb_strlen($name) - 1)));

        return $shown . str_repeat('•', max(3, mb_strlen($name) - mb_strlen($shown))) . '@' . $domain;
    }

    public static function render(string $body, ?Tenant $tenant = null): string
    {
        if ($tenant) {
            $tenant->loadMissing(['settings', 'businessType']);

            return EmailDesign::render($tenant, $body, null, '');
        }

        $color = (string) (PlatformSetting::value('accent_color') ?: '#10B981');
        $name = e(self::productName());

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>'
            . '<body style="margin:0;padding:24px 12px;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#1f2937">'
            . '<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0">'
            . '<div style="height:6px;background:' . e($color) . ';font-size:0;line-height:0">&nbsp;</div>'
            . '<div style="padding:24px 28px 0;font-size:17px;font-weight:bold;color:#0f172a">' . $name . '</div>'
            . '<div style="padding:20px 28px 10px;font-size:15px;line-height:1.6">' . RichText::email($body, $color) . '</div>'
            . '</div></body></html>';
    }

    public static function send(string $to, string $subject, string $body, ?Tenant $tenant = null, ?array $config = null): void
    {
        $config ??= self::config();
        $html = self::render($body, $tenant);

        if (self::$outbox !== null) {
            self::$outbox[] = ['to' => $to, 'subject' => $subject, 'body' => $body, 'html' => $html];

            return;
        }

        abort_unless($config && !empty($config['host']), 503, 'El correo de la plataforma no está configurado.');

        $mailer = Mail::build([
            'transport' => 'smtp',
            'scheme'    => ($config['security'] ?? 'ssl') === 'ssl' ? 'smtps' : 'smtp',
            'host'      => $config['host'],
            'port'      => (int) ($config['port'] ?? 465),
            'username'  => $config['username'] ?? null,
            'password'  => $config['password'] ?? null,
            'timeout'   => 20,
        ]);

        $mailer->html($html, function ($mail) use ($config, $to, $subject, $body) {
            $mail->to($to)->from($config['from_address'], $config['from_name'] ?: self::productName())->subject($subject);
            $mail->text(RichText::toText($body));
        });
    }

    public static function trySend(string $to, string $subject, string $body, ?Tenant $tenant = null): bool
    {
        if (self::$outbox === null && !self::ready()) {
            return false;
        }

        try {
            self::send($to, $subject, $body, $tenant);

            return true;
        } catch (\Throwable $e) {
            report($e);
            self::markFailed($e, 'un correo');

            return false;
        }
    }

    public static function save(array $values): void
    {
        $row = self::row();
        $old = self::config() ?? [];
        $password = trim((string) ($values['password'] ?? '')) ?: ($old['password'] ?? null);
        $settings = [
            'host'         => trim($values['host']),
            'port'         => (int) $values['port'],
            'security'     => $values['security'],
            'username'     => trim((string) ($values['username'] ?? '')) ?: null,
            'from_address' => trim($values['from_address']),
            'from_name'    => trim((string) ($values['from_name'] ?? '')) ?: null,
        ];
        $changed = collect(['host', 'port', 'security', 'username', 'from_address'])->contains(fn ($key) => ($old[$key] ?? null) != $settings[$key])
            || trim((string) ($values['password'] ?? '')) !== '';

        $settings += $changed
            ? ['test_ok' => false, 'tested_at' => null, 'test_message' => null]
            : array_intersect_key($old, array_flip(['test_ok', 'tested_at', 'test_message']));

        $data = [
            'provider'    => 'smtp',
            'mode'        => 'production',
            'credentials' => $password ? Crypt::encryptString(json_encode(['password' => $password])) : null,
            'settings'    => json_encode($settings),
            'status'      => 'active',
            'updated_at'  => now(),
        ];

        $row
            ? DB::table('platform_integrations')->where('id', $row->id)->update($data)
            : DB::table('platform_integrations')->insert($data + ['id' => (string) Str::uuid(), 'kind' => 'email', 'created_at' => now()]);

        self::forget();
    }

    public static function remove(): void
    {
        DB::table('platform_integrations')->where('kind', 'email')->delete();
        self::forget();
    }

    public static function test(string $to): array
    {
        $ok = true;

        try {
            self::send($to, 'Correo de prueba de ' . self::productName(), '<p>Hola:</p><p>Este es un correo de prueba. Si lo recibiste, la plataforma ya puede enviar los enlaces para recuperar la contraseña y los códigos de ingreso.</p><p>' . e(self::productName()) . '</p>');
            $message = "Listo: se envió un correo de prueba a {$to}. Revisa su bandeja de entrada (y la de spam).";
        } catch (\Throwable $e) {
            $ok = false;
            $message = Messaging::explain($e);
        }

        $row = self::row();

        if ($row) {
            $settings = json_decode($row->settings ?? '{}', true) ?: [];
            $settings['test_ok'] = $ok;
            $settings['tested_at'] = now()->toIso8601String();
            $settings['test_message'] = $message;
            $settings['failed_at'] = $ok ? null : now()->toIso8601String();
            DB::table('platform_integrations')->where('id', $row->id)->update(['settings' => json_encode($settings), 'updated_at' => now()]);
            self::forget();
        }

        return ['ok' => $ok, 'message' => $message];
    }

    public static function markFailed(\Throwable $e, string $what): void
    {
        $row = self::row();

        if (!$row) {
            return;
        }

        $settings = json_decode($row->settings ?? '{}', true) ?: [];
        $settings['test_ok'] = false;
        $settings['failed_at'] = now()->toIso8601String();
        $settings['test_message'] = "Falló al enviar {$what}: " . Messaging::explain($e);
        DB::table('platform_integrations')->where('id', $row->id)->update(['settings' => json_encode($settings), 'updated_at' => now()]);
        self::forget();
    }

    public static function present(): array
    {
        $config = self::config();

        return [
            'configured'   => self::configured(),
            'ready'        => self::ready(),
            'host'         => $config['host'] ?? null,
            'port'         => $config['port'] ?? 465,
            'security'     => $config['security'] ?? 'ssl',
            'username'     => $config['username'] ?? null,
            'from_address' => $config['from_address'] ?? null,
            'from_name'    => $config['from_name'] ?? null,
            'has_password' => !empty($config['password']),
            'tested_at'    => $config['tested_at'] ?? null,
            'test_ok'      => (bool) ($config['test_ok'] ?? false),
            'test_message' => $config['test_message'] ?? null,
            'failed_at'    => !($config['test_ok'] ?? false) ? ($config['failed_at'] ?? null) : null,
        ];
    }
}
