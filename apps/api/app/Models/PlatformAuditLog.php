<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformAuditLog extends Model
{
    protected $table = 'platform_audit_logs';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = [
        'before_data' => 'array',
        'after_data'  => 'array',
        'number'      => 'integer',
    ];
}
