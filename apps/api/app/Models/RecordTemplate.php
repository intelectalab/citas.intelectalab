<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecordTemplate extends Model
{
    use HasUuids;

    protected $table = 'record_templates';

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'fields',
        'consent_text',
        'require_consent',
        'require_signature',
        'is_default',
        'version',
        'status',
        'is_confidential',
    ];

    protected $casts = [
        'fields'            => 'array',
        'require_consent'   => 'boolean',
        'require_signature' => 'boolean',
        'is_default'        => 'boolean',
        'version'           => 'integer',
        'is_confidential'   => 'boolean',
    ];

    public static function confidentialReady(): bool
    {
        static $ready = null;

        return $ready ??= \Illuminate\Support\Facades\Schema::hasColumn('record_templates', 'is_confidential');
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'record_template_services', 'record_template_id', 'service_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(CareRecord::class, 'record_template_id');
    }
}
