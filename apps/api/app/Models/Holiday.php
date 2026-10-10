<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Feriado de la empresa: un día del calendario, para todas sus sedes o para
 * una sola. Si la sede cierra, ese día no se ofrecen horarios ni se agenda.
 */
class Holiday extends Model
{
    use HasUuids;

    protected $table = 'holidays';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'date',
        'name',
        'closed',
        'created_by',
    ];

    protected $casts = [
        'date'   => 'date',
        'closed' => 'boolean',
    ];

    /**
     * La sede a la que aplica; sin sede, vale para todas.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
