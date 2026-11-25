<?php

namespace App\Support\WhatsApp;

use Illuminate\Support\Str;

class SimulatedProvider implements Provider
{
    public function key(): string
    {
        return 'simulated';
    }

    public function sendText(array $config, string $to, string $body): array
    {
        return ['id' => 'sim.' . Str::uuid(), 'simulated' => true];
    }

    public function sendTemplate(array $config, string $to, string $template, string $language, array $params): array
    {
        return ['id' => 'sim.' . Str::uuid(), 'simulated' => true];
    }

    public function sendMedia(array $config, string $to, string $type, string $path, string $mime, ?string $caption, ?string $filename): array
    {
        return ['id' => 'sim.' . Str::uuid(), 'simulated' => true];
    }

    public function templates(array $config): array
    {
        return ['ok' => false, 'message' => 'En "Probar sin enviar" no hay plantillas que leer: escríbelas a mano.', 'templates' => []];
    }

    public function createTemplate(array $config, array $payload): array
    {
        return ['ok' => true, 'message' => null, 'id' => 'sim-' . Str::lower(Str::random(10)), 'status' => 'APPROVED'];
    }

    public function download(array $config, string $mediaId): ?array
    {
        return null;
    }

    public function check(array $config): array
    {
        return ['ok' => true, 'message' => 'Estás en "Probar sin enviar": los mensajes de WhatsApp se arman y se guardan, pero no salen.'];
    }
}
