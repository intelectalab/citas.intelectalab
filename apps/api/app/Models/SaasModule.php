<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaasModule extends Model
{
    protected $table = 'saas_modules';

    protected $primaryKey = 'key';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'key',
        'name',
        'is_core',
        'sort_order',
    ];

    protected $casts = [
        'is_core' => 'boolean',
    ];

    public static function coreKeys(): array
    {
        return static::where('is_core', true)->orderBy('sort_order')->pluck('key')->all();
    }
}
