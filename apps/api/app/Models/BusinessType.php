<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    protected $hidden = [
        'template',
    ];

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class, 'business_type_id');
    }
}
