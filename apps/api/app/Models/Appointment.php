<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends Model
{
    use HasUuids;

    public const CODE_PREFIX = 'C-';

    protected $table = 'appointments';

    protected $fillable = [
        'currency',
        'tenant_id',
        'code',
        'branch_id',
        'room_id',
        'customer_id',
        'customer_dependent_id',
        'service_id',
        'professional_id',
        'starts_at',
        'ends_at',
        'status',
        'source',
        'price',
        'notes',
        'reschedule_count',
        'override_reason',
        'cancel_reason',
        'cancelled_by',
        'confirmed_at',
        'checked_in_at',
        'started_at',
        'completed_at',
        'cancelled_at',
        'created_by',
        'customer_package_id',
    ];

    protected $casts = [
        'starts_at'        => 'datetime',
        'ends_at'          => 'datetime',
        'confirmed_at'     => 'datetime',
        'checked_in_at'    => 'datetime',
        'started_at'       => 'datetime',
        'completed_at'     => 'datetime',
        'cancelled_at'     => 'datetime',
        'price'            => 'decimal:2',
        'reschedule_count' => 'integer',
    ];

    protected $dateFormat = 'Y-m-d H:i:sP';

    public static function nextCode(string $tenantId): string
    {
        $max = static::where('tenant_id', $tenantId)
            ->where('code', 'like', static::CODE_PREFIX . '%')
            ->pluck('code')
            ->map(fn ($code) => (int) substr($code, strlen(static::CODE_PREFIX)))
            ->max() ?? 0;

        return static::CODE_PREFIX . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id')->withTrashed();
    }

    public function dependent(): BelongsTo
    {
        return $this->belongsTo(CustomerDependent::class, 'customer_dependent_id')->withTrashed();
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'professional_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function record(): HasOne
    {
        return $this->hasOne(CareRecord::class, 'appointment_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(AppointmentStatusHistory::class, 'appointment_id')->orderBy('created_at')->orderBy('id');
    }
}
