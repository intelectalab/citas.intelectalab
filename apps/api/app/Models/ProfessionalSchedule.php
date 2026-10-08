<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tramo del horario semanal de un profesional en una sede. Un día puede tener
 * varios tramos (mañana y tarde). weekday: 0 = domingo ... 6 = sábado.
 */
class ProfessionalSchedule extends Model
{
    use HasUuids;

    protected $table = 'professional_schedules';
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'professional_id',
        'branch_id',
        'weekday',
        'starts_local',
        'ends_local',
    ];

    protected $casts = [
        'weekday' => 'integer',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'professional_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
