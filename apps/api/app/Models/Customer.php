<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;

class Customer extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, SoftDeletes;

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
        'home_branch_id',
        'preferred_professional_id',
        'alert',
    ];

    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    public const CLINICAL = ['medical_notes', 'blood_type'];

    public function scopeVisibleTo($query, User $user)
    {
        $query->where('customers.tenant_id', $user->tenant_id);
        $professionalId = \App\Support\Access::ownProfessionalId($user);

        if ($professionalId === null) {
            return $query;
        }

        if ($professionalId === 'none') {
            return $query->whereRaw('false');
        }

        return $query->where(function ($q) use ($professionalId) {
            $q->whereExists(fn ($s) => $s->selectRaw('1')->from('appointments as va')->whereColumn('va.customer_id', 'customers.id')->where('va.professional_id', $professionalId))
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('care_records as vr')->whereColumn('vr.customer_id', 'customers.id')->where('vr.professional_id', $professionalId));

            if (\App\Support\CustomerProfile::fieldsReady()) {
                $q->orWhere('customers.preferred_professional_id', $professionalId);
            }
        });
    }

    public static function clinicalAllowed(mixed $viewer = null): bool
    {
        $viewer ??= auth()->user();

        if (!$viewer instanceof User || !$viewer->tenant_id || $viewer->isTenantAdmin()) {
            return true;
        }

        return $viewer->allows('records.view') || $viewer->allows('records.edit') || $viewer->allows('records.all');
    }

    public function toArray()
    {
        $data = parent::toArray();

        return self::clinicalAllowed() ? $data : array_diff_key($data, array_flip(self::CLINICAL));
    }

    protected $casts = [
        'birth_date' => 'date',
        'last_activity_at' => 'datetime',
        'must_change_password' => 'boolean',
        'portal_enabled' => 'boolean',
        'password_changed_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    protected $appends = ['portal_access'];

    protected static function booted(): void
    {
        static::creating(function (Customer $customer) {
            if (empty($customer->code)) {
                $customer->code = static::nextCode($customer->tenant_id);
            }
        });
    }

    public static function nextCode(?string $tenantId): string
    {
        return \App\Support\Codes::next((string) $tenantId, 'customer');
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

    public function getRememberTokenName()
    {
        return '';
    }

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

    public function syncPortalPassword(bool $documentChanged = false): void
    {
        if (!$this->password_hash || ($documentChanged && $this->must_change_password)) {
            $this->resetPortalPassword();
        }
    }

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

    public function dependents()
    {
        return $this->hasMany(CustomerDependent::class, 'customer_id');
    }

    public function pipelineStage()
    {
        return $this->belongsTo(PipelineStage::class, 'pipeline_stage_id');
    }

    public function homeBranch()
    {
        return $this->belongsTo(Branch::class, 'home_branch_id');
    }

    public function preferredProfessional()
    {
        return $this->belongsTo(Professional::class, 'preferred_professional_id');
    }
}
