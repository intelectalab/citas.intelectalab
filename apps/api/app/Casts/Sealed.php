<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Sealed implements CastsAttributes, ComparesCastableAttributes
{
    public function __construct(private string $kind = 'text')
    {
    }

    public static function open(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return $stored;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return $stored;
        }
    }

    public static function seal(?string $plain): ?string
    {
        return $plain === null ? null : Crypt::encryptString($plain);
    }

    public static function isSealed(?string $stored): bool
    {
        if ($stored === null || $stored === '') {
            return false;
        }

        try {
            Crypt::decryptString($stored);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        $plain = self::open($value);

        if ($this->kind !== 'json') {
            return $plain;
        }

        if ($plain === null || $plain === '') {
            return [];
        }

        $decoded = json_decode($plain, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
    {
        return $this->get($model, $key, $firstValue, []) === $this->get($model, $key, $secondValue, []);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        $plain = $this->kind === 'json'
            ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)
            : (string) $value;

        return self::seal($plain);
    }
}
