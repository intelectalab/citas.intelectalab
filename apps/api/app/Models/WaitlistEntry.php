<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alguien que quiere una cita y no encontró horario: qué servicio, con quién,
 * entre qué fechas y a qué horas le sirve. Cuando se libera un horario, el
 * personal lo agenda desde aquí.
 */
class WaitlistEntry extends Model
{
    use HasUuids;

    public const WAITING = 'waiting';
    public const NOTIFIED = 'notified';
    public const BOOKED = 'booked';
    public const EXPIRED = 'expired';
    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::WAITING   => 'En espera',
        self::NOTIFIED  => 'Avisado',
        self::BOOKED    => 'Con cita',
        self::EXPIRED   => 'Venció',
        self::CANCELLED => 'Retirado',
    ];

    // Sigue esperando un horario.
    public const OPEN = [self::WAITING, self::NOTIFIED];
    public const CLOSED = [self::EXPIRED, self::CANCELLED];

    protected $table = 'waitlist_entries';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'customer_id',
        'customer_dependent_id',
        'service_id',
        'professional_id',
        'date_from',
        'date_to',
        'time_from',
        'time_to',
        'notes',
        'status',
        'priority',
        'notified_at',
        'expires_at',
        'appointment_id',
        'created_by',
    ];

    protected $casts = [
        'date_from'   => 'date',
        'date_to'     => 'date',
        'priority'    => 'integer',
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

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * Cita que se le consiguió.
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }
}
