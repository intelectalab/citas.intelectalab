<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
