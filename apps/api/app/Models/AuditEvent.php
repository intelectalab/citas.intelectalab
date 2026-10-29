<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class AuditEvent extends Model
{
    use HasUuids;

    protected $table = 'audit_events';

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'actor_user_id',
        'action',
        'entity_type',
        'entity_id',
        'ip_address',
        'user_agent',
        'before_data',
        'after_data',
        'actor_type',
        'actor_customer_id',
        'actor_name',
        'impersonator_user_id',
        'branch_id',
        'entity_label',
    ];

    protected $casts = [
        'before_data' => 'array',
        'after_data'  => 'array',
    ];

    public static function record(Request $request, string $action, ?Tenant $tenant = null, ?array $before = null, ?array $after = null): ?self
    {
        return \App\Support\Audit::log($request, $action, [
            'tenant'       => $tenant,
            'entity_type'  => $tenant ? 'tenant' : null,
            'entity_id'    => $tenant?->id,
            'entity_label' => $tenant?->name,
            'before'       => $before,
            'after'        => $after,
        ]);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
