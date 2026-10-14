<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSetting extends Model
{
    protected $table = 'tenant_settings';

    protected $primaryKey = 'tenant_id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'vocabulary',
        'modules_off',
        'rules',
        'brand_color',
        'logo_url',
        'allow_customer_signup',
        'appointment_statuses',
    ];

    protected $casts = [
        'vocabulary'            => 'array',
        'modules_off'           => 'array',
        'rules'                 => 'array',
        'allow_customer_signup' => 'boolean',
        'appointment_statuses'  => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
