<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalkinEntry extends Model
{
    use HasUuids;

    public const WAITING = 'waiting';
    public const SERVING = 'serving';
    public const DONE = 'done';
    public const LEFT = 'left';

    public const STATUSES = [
        self::WAITING => 'En espera',
        self::SERVING => 'En atención',
        self::DONE    => 'Atendido',
        self::LEFT    => 'Se retiró',
    ];

    public const ACTIVE = [self::WAITING, self::SERVING];

    protected $table = 'walkin_entries';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'customer_id',
        'customer_dependent_id',
        'service_id',
        'professional_id',
        'appointment_id',
        'ticket',
        'status',
        'source',
        'notes',
        'waited_minutes',
        'arrived_at',
        'served_at',
        'done_at',
        'left_at',
        'created_by',
    ];

    protected $casts = [
        'waited_minutes' => 'integer',
        'arrived_at'     => 'datetime',
        'served_at'      => 'datetime',
        'done_at'        => 'datetime',
        'left_at'        => 'datetime',
    ];

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
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

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
