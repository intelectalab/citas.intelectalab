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

    private function client(array $config)
    {
        return Http::withToken((string) ($config['token'] ?? ''))->acceptJson()->timeout(15)->connectTimeout(5);
    }

    private function post(array $config, array $payload): array
    {
        try {
            $response = $this->client($config)->post(self::base() . '/' . $config['phone_number_id'] . '/messages', ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual'] + $payload);
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
