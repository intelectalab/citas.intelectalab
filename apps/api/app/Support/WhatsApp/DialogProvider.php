<?php

namespace App\Support\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class DialogProvider extends CloudApiProvider
{
    public function key(): string
    {
        return '360dialog';
    }

    public static function dialogBase(): string
    {
        return rtrim((string) config('services.whatsapp.dialog_url', 'https://waba-v2.360dialog.io'), '/');
    }

    protected function client(array $config)
    {
        return Http::withHeaders(['D360-API-KEY' => (string) ($config['token'] ?? '')])->acceptJson()->timeout(15)->connectTimeout(5);
    }

    protected function messagesUrl(array $config): string
    {
        return self::dialogBase() . '/messages';
    }

    protected function mediaUrl(array $config): string
    {
        return self::dialogBase() . '/media';
    }

    protected function templatesRequest(array $config)
    {
        return $this->client($config)->get(self::dialogBase() . '/message_templates', ['limit' => 200]);
    }

    protected function createTemplateUrl(array $config): ?string
    {
        return self::dialogBase() . '/v1/configs/templates';
    }

    protected function mediaInfoUrl(array $config, string $mediaId): string
    {
        return self::dialogBase() . '/' . rawurlencode($mediaId);
    }

    protected function downloadUrl(string $url): string
    {
        $parts = parse_url(str_replace('\\', '', $url));

        return self::dialogBase() . ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    public function registerWebhook(array $config, string $url): array
    {
        try {
            $response = $this->client($config)->post(self::dialogBase() . '/v1/configs/webhook', [
                'url'     => $url,
                'headers' => ['X-Webhook-Token' => (string) ($config['verify_token'] ?? '')],
            ]);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'message' => 'No se pudo conectar con 360dialog.'];
        }

        return $response->successful()
            ? ['ok' => true, 'message' => 'La dirección de avisos quedó registrada en 360dialog.']
            : ['ok' => false, 'message' => $response->status() === 401 ? '360dialog no aceptó la clave (API key).' : self::failure($response)->getMessage()];
    }

    public function check(array $config): array
    {
        try {
            $response = $this->client($config)->get(self::dialogBase() . '/v1/configs/webhook');
        } catch (ConnectionException $e) {
            return ['ok' => false, 'message' => 'No se pudo conectar con 360dialog. Revisa que haya internet e inténtalo de nuevo.'];
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return ['ok' => false, 'message' => '360dialog no aceptó la clave (API key): cópiala de nuevo desde tu panel de 360dialog.'];
        }

        if ($response->failed()) {
            return ['ok' => false, 'message' => self::failure($response)->getMessage()];
        }

        return ['ok' => true, 'message' => 'Conectado: 360dialog reconoce la clave' . ($config['display_phone'] ?? null ? ' del número ' . $config['display_phone'] : '') . '.', 'display' => null, 'name' => null];
    }
}
