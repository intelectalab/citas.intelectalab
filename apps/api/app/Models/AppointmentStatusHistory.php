<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un paso en la vida de la cita: un cambio de fase o una reprogramación
 * (en ese caso la fase de origen y la de destino son la misma).
 */
class AppointmentStatusHistory extends Model
{
    use HasUuids;

    protected $table = 'appointment_status_history';
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'appointment_id',
        'from_status',
        'to_status',
        'reason',
        'actor_user_id',
        'actor_customer_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * El cliente, cuando el paso lo dio él mismo desde el portal.
     */
    public function customerActor(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'actor_customer_id')->withTrashed();
    }
}
