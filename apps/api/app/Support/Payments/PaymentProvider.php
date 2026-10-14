<?php

namespace App\Support\Payments;

use Illuminate\Http\Request;

interface PaymentProvider
{
    public function key(): string;

    public function simulated(): bool;

    public function checkout(object $link): array;

    public function refund(object $payment, float $amount): array;

    public function parse(Request $request): ?array;
}
