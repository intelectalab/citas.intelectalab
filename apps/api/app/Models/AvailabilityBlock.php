<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rango en el que no se atiende: vacaciones o ausencia de un profesional, o
 * cierre de una sede (sin profesional). Las fechas se guardan con zona horaria.
 */
class AvailabilityBlock extends Model
{
    use HasUuids;

    protected $table = 'availability_blocks';
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'professional_id',
        'starts_at',
        'ends_at',
        'reason',
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
