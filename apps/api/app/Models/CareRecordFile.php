<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareRecordFile extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const MAX_KB = 10240;

    public const MAX_PER_RECORD = 20;

    public const MIMES = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'doc', 'docx'];

    public const LINK_MINUTES = 10;

    protected $table = 'care_record_files';

    protected $fillable = ['tenant_id', 'care_record_id', 'file_name', 'mime', 'size', 'path', 'uploaded_by', 'deleted_at'];

    protected $casts = ['created_at' => 'datetime', 'deleted_at' => 'datetime', 'size' => 'integer'];

    protected $dateFormat = 'Y-m-d H:i:sP';

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
