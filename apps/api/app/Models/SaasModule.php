<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de módulos del producto. Los módulos base (is_core) están en todos
 * los planes; el resto se activa por plan y se apaga por rubro o por empresa.
 */
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

    /**
     * Claves de los módulos base.
     */
    public static function coreKeys(): array
    {
        return static::where('is_core', true)->orderBy('sort_order')->pluck('key')->all();
    }
}
