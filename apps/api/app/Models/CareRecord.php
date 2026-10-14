<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CareRecord extends Model
{
    use HasUuids;

    public const DRAFT = 'draft';
    public const SIGNED = 'signed';
    public const LOCKED = 'locked';

    protected $table = 'care_records';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'record_template_id',
        'template_snapshot',
        'template_name',
        'consent_text',
        'require_consent',
        'customer_id',
        'customer_dependent_id',
        'appointment_id',
        'professional_id',
        'data',
        'consent_accepted',
        'consent_accepted_at',
        'is_private',
        'status',
        'signed_at',
        'signed_by',
        'locked_at',
        'created_by',
    ];

    protected $casts = [
        'template_snapshot'   => 'array',
        'data'                => 'array',
        'consent_accepted'    => 'boolean',
        'require_consent'     => 'boolean',
        'is_private'          => 'boolean',
        'consent_accepted_at' => 'datetime',
        'signed_at'           => 'datetime',
        'locked_at'           => 'datetime',
    ];

    protected $dateFormat = 'Y-m-d H:i:sP';

    public function currentStatus(): string
    {
        if ($this->status === self::SIGNED && $this->locked_at && $this->locked_at->isPast()) {
            return self::LOCKED;
        }

        return $this->status;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id')->withTrashed();
    }

    public function dependent(): BelongsTo
    {
        return $this->belongsTo(CustomerDependent::class, 'customer_dependent_id')->withTrashed();
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'professional_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function addenda(): HasMany
    {
        return $this->hasMany(CareRecordAddendum::class, 'care_record_id')->orderBy('created_at');
    }
}
