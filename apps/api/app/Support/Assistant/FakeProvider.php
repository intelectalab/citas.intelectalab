<?php

namespace App\Support\Assistant;

class FakeProvider implements Provider
{
    public static array $answers = [];

    public static array $received = [];

    public function key(): string
    {
        return 'fake';
    }

    public function answer(array $context): array
    {
        self::$received[] = $context;

        return array_shift(self::$answers) ?? ['action' => 'reply', 'text' => 'Hola, soy el asistente de prueba.'];
    }
}
