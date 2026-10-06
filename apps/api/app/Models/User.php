<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const TYPE_STAFF = 'staff';
    public const TYPE_CUSTOMER = 'customer';

    protected $table = 'users';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'tenant_id',
        'first_name',
        'last_name',
        'email',
        'password_hash',
        'phone',
        'avatar_url',
        'is_owner',
        'account_type',
        'status',
        'email_verified_at',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_owner'          => 'boolean',
    ];

    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Ficha de cliente vinculada cuando la cuenta es del portal de cliente.
     */
    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class, 'user_id');
    }

    /**
     * Administrador de su empresa: el dueño o quien tenga el rol owner o admin.
     * Es el único que puede cambiar la configuración del tenant.
     */
    public function isTenantAdmin(): bool
    {
        if (!$this->tenant_id || $this->isCustomer()) {
            return false;
        }

        return $this->is_owner || $this->roles()->whereIn('slug', ['owner', 'admin'])->exists();
    }

    /**
     * Equipo de la plataforma: cuentas de personal sin empresa. Incluye al
     * administrador SaaS y al soporte SaaS.
     */
    public function isPlatformUser(): bool
    {
        return is_null($this->tenant_id) && !$this->isCustomer();
    }

    /**
     * Soporte SaaS: ve las empresas y entra a su panel para ayudarlas, pero no
     * cambia planes, rubros, catálogos ni cobros.
     */
    public function isSaasSupport(): bool
    {
        return $this->isPlatformUser() && $this->roles()->where('slug', 'saas_support')->exists();
    }

    public function isSaasAdmin(): bool
    {
        return $this->isPlatformUser() && !$this->isSaasSupport();
    }

    public function isCustomer(): bool
    {
        return $this->account_type === self::TYPE_CUSTOMER;
    }
}