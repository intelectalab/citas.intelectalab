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

    public function check(array $config): array
    {
        return ['ok' => true, 'message' => 'Estás en "Probar sin enviar": los mensajes de WhatsApp se arman y se guardan, pero no salen.'];
    }
}
