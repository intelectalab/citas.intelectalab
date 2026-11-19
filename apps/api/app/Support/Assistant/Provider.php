<?php

namespace App\Support\Assistant;

interface Provider
{
    public function key(): string;

    public function answer(array $context): array;
}
