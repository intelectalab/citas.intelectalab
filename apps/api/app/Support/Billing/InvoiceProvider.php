<?php

namespace App\Support\Billing;

interface InvoiceProvider
{
    public function key(): string;

    public function real(): bool;

    public function send(array $document): array;

    public function status(array $document): array;

    public function void(array $document, string $reason): array;

    public function voidStatus(array $document): array;

    public function test(array $series): array;
}
