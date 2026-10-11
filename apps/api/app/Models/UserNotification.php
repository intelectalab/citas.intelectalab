<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Aviso dentro del sistema para una persona del equipo: lo ve en la campana.
 */
class UserNotification extends Model
{
    use HasUuids;

    protected $table = 'user_notifications';
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'type',
        'title',
        'body',
        'link',
        'entity_type',
        'entity_id',
        'dedupe_key',
        'read_at',
    ];

    protected $casts = [
        'read_at'    => 'datetime',
        'created_at' => 'datetime',
    ];
}
