<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recall extends Model
{
    use HasUuids;

    public const PENDING = 'pending';
    public const NOTIFIED = 'notified';
    public const BOOKED = 'booked';
    public const DONE = 'done';
    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::PENDING   => 'Por agendar',
        self::NOTIFIED  => 'Avisado',
        self::BOOKED    => 'Agendado',
        self::DONE      => 'Realizado',
        self::CANCELLED => 'Descartado',
    ];

    public const OPEN = [self::PENDING, self::NOTIFIED];
    public const CLOSED = [self::DONE, self::CANCELLED];

    protected $table = 'recalls';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'customer_dependent_id',
        'care_record_id',
        'source_appointment_id',
        'service_id',
        'professional_id',
        'label',
        'due_date',
        'status',
        'notified_at',
        'appointment_id',
        'notes',
        'closed_reason',
        'created_by',
    ];

    protected $casts = [
        'due_date'    => 'date',
        'notified_at' => 'datetime',
    ];

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id')->withTrashed();
    }

    public function dependent(): BelongsTo
    {
        return $this->belongsTo(CustomerDependent::class, 'customer_dependent_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'professional_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    public function sourceAppointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'source_appointment_id');
    }
}
