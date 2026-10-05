<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids; // Si usas Laravel 9+

class ServiceCategory extends Model
{
    use HasUuids; // Esto le dice a Laravel que maneje automáticamente los UUIDs

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'tenant_id',
        'name',
    ];
}