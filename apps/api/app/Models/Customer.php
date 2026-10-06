<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cliente del tenant (antes "Patient"). El término visible puede variar
 * por rubro (paciente, alumno, tutor), pero la entidad es una sola.
 */
class Customer extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    public const CODE_PREFIX = 'CLI-';

    protected $table = 'customers';

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
        'user_id',
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

    protected static function booted(): void
    {
        // Garantiza el código correlativo aunque quien crea el registro no lo envíe.
        static::creating(function (Customer $customer) {
            if (empty($customer->code)) {
                $customer->code = static::nextCode($customer->tenant_id);
            }
        });
    }

    /**
     * Siguiente código correlativo del tenant (CLI-0001, CLI-0002, ...).
     * Considera registros eliminados para no reutilizar códigos.
     */
    public static function nextCode(?string $tenantId): string
    {
        $max = static::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('code', 'like', static::CODE_PREFIX . '%')
            ->pluck('code')
            ->map(fn ($code) => (int) substr($code, strlen(static::CODE_PREFIX)))
            ->max() ?? 0;

        return static::CODE_PREFIX . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    public function typeDocument()
    {
        return $this->belongsTo(TypeDocument::class, 'type_document_id');
    }

    public function status()
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    public function managerUser()
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    /**
     * Cuenta del portal de cliente, si la persona se registró.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Dependientes del cliente (mascotas, hijos) en los rubros que los usan.
     */
    public function dependents()
    {
        return $this->hasMany(CustomerDependent::class, 'customer_id');
    }

    public function pipelineStage()
    {
        return $this->belongsTo(PipelineStage::class, 'pipeline_stage_id');
    }
}
