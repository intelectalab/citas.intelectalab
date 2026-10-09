<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;

/**
 * Cliente del tenant (antes "Patient"). El término visible puede variar
 * por rubro (paciente, alumno, tutor), pero la entidad es una sola.
 *
 * También es su cuenta del portal: ingresa con su correo y su contraseña, que
 * viven aquí. Los clientes no tienen fila en users, así sus datos están una
 * sola vez. La contraseña inicial es su número de documento.
 */
class Customer extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, SoftDeletes;

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
        'pipeline_stage_id',
        'address',
        'district',
        'department',
        'postal_code',
        'medical_notes',
        'last_activity_at',
    ];

    // La contraseña nunca viaja en una respuesta, ni la del personal que lista clientes.
    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'last_activity_at' => 'datetime',
        'must_change_password' => 'boolean',
        'portal_enabled' => 'boolean',
        'password_changed_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    // Estado del acceso al portal, para mostrarlo al personal.
    protected $appends = ['portal_access'];

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

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    // Sin "recordarme": la sesión del portal va por token.
    public function getRememberTokenName()
    {
        return '';
    }

    /**
     * Estado del acceso al portal:
     *  - no_email / no_document: falta el dato para darle acceso;
     *  - disabled: el personal se lo quitó;
     *  - pending: puede entrar con su documento y aún no creó su contraseña;
     *  - active: ya entra con su propia contraseña.
     */
    public function getPortalAccessAttribute(): string
    {
        if (!$this->email) {
            return 'no_email';
        }
        if (!$this->password_hash) {
            return $this->document_number ? 'pending' : 'no_document';
        }
        if ($this->portal_enabled === false) {
            return 'disabled';
        }

        return $this->must_change_password ? 'pending' : 'active';
    }

    public function canLogin(): bool
    {
        return (bool) $this->email && (bool) $this->password_hash && $this->portal_enabled !== false;
    }

    /**
     * Deja la contraseña igual al número de documento y obliga a cambiarla al
     * ingresar. Se usa al registrar al cliente y cuando el personal restablece
     * su acceso. Sin correo o sin documento no hay acceso que dar.
     */
    public function resetPortalPassword(): bool
    {
        $document = trim((string) $this->document_number);

        if (!$this->email || $document === '') {
            return false;
        }

        $this->forceFill([
            'password_hash'        => Hash::make($document),
            'must_change_password' => true,
            'password_changed_at'  => null,
        ])->save();

        return true;
    }

    /**
     * Tras crear o editar la ficha: da el acceso inicial si ya tiene correo y
     * documento, y sigue al documento mientras el cliente no haya creado su
     * propia contraseña. Una contraseña que el cliente ya eligió no se toca.
     */
    public function syncPortalPassword(bool $documentChanged = false): void
    {
        if (!$this->password_hash || ($documentChanged && $this->must_change_password)) {
            $this->resetPortalPassword();
        }
    }

    // Las rutas y controladores preguntan esto a quien inició sesión, sea
    // personal (User) o cliente. Un cliente nunca es personal ni plataforma.
    public function isCustomer(): bool
    {
        return true;
    }

    public function isTenantAdmin(): bool
    {
        return false;
    }

    public function isPlatformUser(): bool
    {
        return false;
    }

    public function isSaasAdmin(): bool
    {
        return false;
    }

    public function isSaasSupport(): bool
    {
        return false;
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
