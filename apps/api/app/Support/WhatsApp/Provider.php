<?php

namespace App\Support\WhatsApp;

interface Provider
{
    public function key(): string;

    public function sendText(array $config, string $to, string $body): array;

    public function sendTemplate(array $config, string $to, string $template, string $language, array $params): array;

    public function sendMedia(array $config, string $to, string $type, string $path, string $mime, ?string $caption, ?string $filename): array;

    public function templates(array $config): array;

    public function createTemplate(array $config, array $payload): array;

    public function download(array $config, string $mediaId): ?array;

    public function check(array $config): array;
}
