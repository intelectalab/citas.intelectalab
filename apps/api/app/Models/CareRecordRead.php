<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareRecordRead extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const ACTIONS = ['view', 'appointment', 'previous', 'pdf', 'emergency'];

    protected $table = 'care_record_reads';

    protected $fillable = ['tenant_id', 'care_record_id', 'user_id', 'action', 'by_emergency', 'ip_address'];

    protected $casts = ['by_emergency' => 'boolean', 'created_at' => 'datetime'];

    protected $dateFormat = 'Y-m-d H:i:sP';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
