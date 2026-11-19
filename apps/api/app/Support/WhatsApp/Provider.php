<?php

namespace App\Support\WhatsApp;

interface Provider
{
    public function key(): string;

    public function sendText(array $config, string $to, string $body): array;

    public function sendTemplate(array $config, string $to, string $template, string $language, array $params): array;

    public function check(array $config): array;
}
