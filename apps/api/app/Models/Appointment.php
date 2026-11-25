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
        'channel',
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
        'video_url',
        'series_id',
        'series_number',
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

    protected static function booted(): void
    {
        static::saving(function (Appointment $appointment) {
            if (!$appointment->exists || $appointment->isDirty(['starts_at', 'ends_at', 'branch_id', 'service_id'])) {
                $appointment->room_id = \App\Support\RoomBooking::pick($appointment);
            }
        });
    }

    public static function nextCode(string $tenantId): string
    {
        return \App\Support\Codes::next($tenantId, 'appointment');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
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
