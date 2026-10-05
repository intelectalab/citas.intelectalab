<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Patient extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'patients';

    protected $fillable = [
        'tenant_id',
        'code',
        'first_name',
        'last_name',
        'birth_date',
        'biological_sex',
        'blood_type',
        'type_document_id',
        'document_number',
        'email',
        'phone',
        'emergency_contact_name',
        'emergency_contact_phone',
        'status_id',
        'manager_user_id',
        'pipeline_stage_id',
        'address',
        'district',
        'department',
        'postal_code',
        'medical_notes',
        'last_activity_at',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'last_activity_at' => 'datetime',
    ];

    public function typeDocument()
    {
        return $this->belongsTo(TypeDocument::class, 'type_document_id');
    }

    public function status()
    {
        return $this->belongsTo(Status::class, 'status_id');
    }
}