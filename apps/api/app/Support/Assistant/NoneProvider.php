<?php

namespace App\Support\Assistant;

class NoneProvider implements Provider
{
    public function key(): string
    {
        return 'none';
    }

    public function answer(array $context): array
    {
        return ['action' => 'none', 'reason' => 'Aún no hay un proveedor de inteligencia artificial conectado.'];
    }
}
