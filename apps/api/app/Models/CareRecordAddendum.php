<?php

namespace App\Models;

use App\Casts\Sealed;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareRecordAddendum extends Model
{
    use HasUuids;

    protected $table = 'care_record_addenda';
    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'care_record_id', 'author_user_id', 'note'];

    protected $casts = ['created_at' => 'datetime', 'note' => Sealed::class];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
