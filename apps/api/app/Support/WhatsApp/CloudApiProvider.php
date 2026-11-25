<?php

namespace App\Support\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class CloudApiProvider implements Provider
{
    private const FINAL_CODES = ['190', '100', '131047', '131026', '131030', '131051', '132000', '132001', '132005', '132007', '132012', '133010', '368'];

    public function key(): string
    {
        return 'cloud';
    }

    public static function base(): string
    {
        return rtrim((string) config('services.whatsapp.graph_url', 'https://graph.facebook.com'), '/') . '/' . config('services.whatsapp.graph_version', 'v21.0');
    }

    protected function client(array $config)
    {
        return Http::withToken((string) ($config['token'] ?? ''))->acceptJson()->timeout(15)->connectTimeout(5);
    }

    protected function messagesUrl(array $config): string
    {
        return self::base() . '/' . $config['phone_number_id'] . '/messages';
    }

    protected function mediaUrl(array $config): string
    {
        return self::base() . '/' . $config['phone_number_id'] . '/media';
    }

    protected function post(array $config, array $payload): array
    {
        try {
            $response = $this->client($config)->post($this->messagesUrl($config), ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual'] + $payload);
        } catch (ConnectionException $e) {
            throw new SendFailed('No se pudo conectar con WhatsApp. Se volverá a intentar en unos minutos.');
        }

        if ($response->failed()) {
            throw self::failure($response);
        }

        $id = $response->json('messages.0.id');

        if (!$id) {
            throw new SendFailed('WhatsApp no devolvió el número del mensaje. Se volverá a intentar.');
        }

        return ['id' => $id, 'simulated' => false];
    }

    public static function failure(Response $response): SendFailed
    {
        $code = (string) ($response->json('error.code') ?? $response->status());
        $detail = (string) ($response->json('error.error_data.details') ?? $response->json('error.message') ?? '');
        $final = in_array($code, self::FINAL_CODES, true) || in_array($response->status(), [400, 401, 403, 404], true);

        $message = match ($code) {
            '190'    => 'WhatsApp no aceptó el token de acceso: venció o es incorrecto. Genera uno nuevo en Meta y guárdalo en Configuración → Mensajes → WhatsApp.',
            '131047' => 'Pasaron más de 24 horas desde el último mensaje del cliente: WhatsApp solo deja enviar una plantilla aprobada.',
            '131026' => 'WhatsApp no pudo entregar el mensaje a ese número (puede que no tenga WhatsApp).',
            '131030' => 'Ese número no está en la lista de números de prueba de tu cuenta de Meta.',
            '132000', '132001', '132005', '132007', '132012' => 'La plantilla no existe, no está aprobada o sus datos no coinciden con lo aprobado en Meta.',
            '130429', '131056', '80007' => 'WhatsApp pidió esperar porque se enviaron muchos mensajes seguidos. Se volverá a intentar.',
            default  => 'WhatsApp respondió con un error' . ($detail !== '' ? ': ' . mb_substr($detail, 0, 250) : '.'),
        };

        return new SendFailed($message, $final, $code);
    }

    public function sendText(array $config, string $to, string $body): array
    {
        return $this->post($config, ['to' => $to, 'type' => 'text', 'text' => ['preview_url' => true, 'body' => mb_substr($body, 0, 4096)]]);
    }

    public function sendTemplate(array $config, string $to, string $template, string $language, array $params): array
    {
        $components = $params ? [['type' => 'body', 'parameters' => array_map(fn ($value) => ['type' => 'text', 'text' => mb_substr((string) $value, 0, 1000) ?: '-'], array_values($params))]] : [];

        return $this->post($config, ['to' => $to, 'type' => 'template', 'template' => array_filter([
            'name'       => $template,
            'language'   => ['code' => $language ?: 'es'],
            'components' => $components ?: null,
        ])]);
    }

    public function sendMedia(array $config, string $to, string $type, string $path, string $mime, ?string $caption, ?string $filename): array
    {
        try {
            $upload = $this->client($config)->attach('file', file_get_contents($path), $filename ?: basename($path), ['Content-Type' => $mime])
                ->post($this->mediaUrl($config), ['messaging_product' => 'whatsapp', 'type' => $mime]);
        } catch (ConnectionException $e) {
            throw new SendFailed('No se pudo conectar con WhatsApp para subir el archivo. Se volverá a intentar en unos minutos.');
        }

        if ($upload->failed() || !$upload->json('id')) {
            throw $upload->failed() ? self::failure($upload) : new SendFailed('WhatsApp no recibió el archivo.');
        }

        $media = array_filter(['id' => (string) $upload->json('id'), 'caption' => $caption ? mb_substr($caption, 0, 1024) : null, 'filename' => $type === 'document' ? ($filename ?: basename($path)) : null]);

        return $this->post($config, ['to' => $to, 'type' => $type, $type => $media]);
    }

    protected function templatesRequest(array $config)
    {
        if (empty($config['business_account_id'])) {
            return null;
        }

        return $this->client($config)->get(self::base() . '/' . $config['business_account_id'] . '/message_templates', ['fields' => 'id,name,language,status,category,components,rejected_reason', 'limit' => 200]);
    }

    public function templates(array $config): array
    {
        try {
            $response = $this->templatesRequest($config);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'message' => 'No se pudo conectar con WhatsApp para leer las plantillas.', 'templates' => []];
        }

        if (!$response) {
            return ['ok' => false, 'message' => 'Para leer las plantillas falta el identificador de la cuenta de WhatsApp Business (WABA ID).', 'templates' => []];
        }

        if ($response->failed()) {
            return ['ok' => false, 'message' => self::failure($response)->getMessage(), 'templates' => []];
        }

        $json = (array) $response->json();
        $listed = array_key_exists('data', $json) || array_key_exists('waba_templates', $json);

        return ['ok' => true, 'message' => null, 'listed' => $listed, 'templates' => self::normalizeTemplates((array) ($json['data'] ?? $json['waba_templates'] ?? []))];
    }

    protected function createTemplateUrl(array $config): ?string
    {
        return empty($config['business_account_id']) ? null : self::base() . '/' . $config['business_account_id'] . '/message_templates';
    }

    public function createTemplate(array $config, array $payload): array
    {
        $url = $this->createTemplateUrl($config);

        if (!$url) {
            return ['ok' => false, 'message' => 'Para enviar plantillas a revisión falta el identificador de la cuenta de WhatsApp Business (WABA ID).', 'id' => null, 'status' => null];
        }

        try {
            $response = $this->client($config)->post($url, $payload);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'message' => 'No se pudo conectar con WhatsApp para enviar la plantilla.', 'id' => null, 'status' => null];
        }

        if ($response->failed()) {
            $detail = (string) ($response->json('error.error_user_msg') ?? $response->json('error.error_data.details') ?? $response->json('error.message') ?? '');

            return ['ok' => false, 'message' => $response->json('error.code') == 190 ? self::failure($response)->getMessage() : 'Meta no aceptó la plantilla' . ($detail !== '' ? ': ' . mb_substr($detail, 0, 300) : '.'), 'id' => null, 'status' => null];
        }

        return ['ok' => true, 'message' => null, 'id' => $response->json('id'), 'status' => $response->json('status') ?? 'PENDING'];
    }

    public static function normalizeTemplates(array $rows): array
    {
        return collect($rows)->map(function ($row) {
            $body = collect((array) ($row['components'] ?? []))->first(fn ($c) => strtoupper((string) ($c['type'] ?? '')) === 'BODY');
            $text = (string) ($body['text'] ?? '');
            preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $text, $matches);

            return [
                'id'       => isset($row['id']) ? (string) $row['id'] : null,
                'reason'   => $row['rejected_reason'] ?? null,
                'name'     => (string) ($row['name'] ?? ''),
                'language' => (string) ($row['language'] ?? 'es'),
                'status'   => strtoupper((string) ($row['status'] ?? '')),
                'category' => $row['category'] ?? null,
                'body'     => mb_substr($text, 0, 1024),
                'params'   => $matches[1] ? max(array_map('intval', $matches[1])) : 0,
            ];
        })->filter(fn ($t) => $t['name'] !== '')->values()->all();
    }

    protected function mediaInfoUrl(array $config, string $mediaId): string
    {
        return self::base() . '/' . rawurlencode($mediaId);
    }

    protected function downloadUrl(string $url): string
    {
        return $url;
    }

    public function download(array $config, string $mediaId): ?array
    {
        try {
            $info = $this->client($config)->get($this->mediaInfoUrl($config, $mediaId));

            if ($info->failed() || !$info->json('url')) {
                return null;
            }

            $file = $this->client($config)->accept('*/*')->timeout(30)->get($this->downloadUrl((string) $info->json('url')));
        } catch (ConnectionException $e) {
            return null;
        }

        return $file->successful() ? ['body' => $file->body(), 'mime' => (string) ($info->json('mime_type') ?? $file->header('Content-Type') ?: 'application/octet-stream')] : null;
    }

    public function check(array $config): array
    {
        try {
            $response = $this->client($config)->get(self::base() . '/' . $config['phone_number_id'], ['fields' => 'display_phone_number,verified_name']);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'message' => 'No se pudo conectar con WhatsApp. Revisa que haya internet e inténtalo de nuevo.'];
        }

        if ($response->failed()) {
            return ['ok' => false, 'message' => self::failure($response)->getMessage()];
        }

        return [
            'ok'      => true,
            'message' => 'Conectado: WhatsApp reconoce el número ' . ($response->json('display_phone_number') ?? $config['phone_number_id']) . ($response->json('verified_name') ? ' (' . $response->json('verified_name') . ')' : '') . '.',
            'display' => $response->json('display_phone_number'),
            'name'    => $response->json('verified_name'),
        ];
    }
}
