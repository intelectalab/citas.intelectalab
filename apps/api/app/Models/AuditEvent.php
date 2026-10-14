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
    ];

    protected $casts = [
        'before_data' => 'array',
        'after_data'  => 'array',
    ];

    public static function record(Request $request, string $action, ?Tenant $tenant = null, ?array $before = null, ?array $after = null): self
    {
        return static::create([
            'tenant_id'     => $tenant?->id,
            'actor_user_id' => $request->user()?->id,
            'action'        => $action,
            'entity_type'   => $tenant ? 'tenant' : null,
            'entity_id'     => $tenant?->id,
            'ip_address'    => $request->ip(),
            'user_agent'    => substr((string) $request->userAgent(), 0, 500),
            'before_data'   => $before,
            'after_data'    => $after,
        ]);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
