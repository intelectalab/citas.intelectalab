<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rubro de negocio (catálogo global del SaaS). Define la presentación y el
 * vocabulario por defecto de los tenants que lo usan.
 */
class BusinessType extends Model
{
    use HasUuids;

    protected $table = 'business_types';

    protected $fillable = [
        'slug',
        'name',
        'short_name',
        'description',
        'icon',
        'color',
        'vocabulary',
        'modules_off',
        'default_rules',
        'dependents',
        'template',
        'sort_order',
        'status',
    ];

    protected $casts = [
        'vocabulary'    => 'array',
        'modules_off'   => 'array',
        'default_rules' => 'array',
        'dependents'    => 'array',
        'template'      => 'array',
    ];

    // El catálogo inicial solo se usa al crear el tenant; no viaja en las respuestas.
    protected $hidden = [
        'template',
    ];

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class, 'business_type_id');
    }
}
