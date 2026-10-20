<?php

namespace App\Support;

class Person
{
    public static function name(?object $person): string
    {
        return $person ? trim(($person->first_name ?? '') . ' ' . ($person->last_name ?? '')) : '';
    }
}
