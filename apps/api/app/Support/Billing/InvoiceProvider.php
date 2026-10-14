<?php

namespace App\Support\Billing;

interface InvoiceProvider
{
    public function key(): string;

    public function send(array $document): array;
}
