<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

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

    /**
     * Los clientes del portal no están en users: su cuenta es su ficha
     * (Customer). Se conserva para preguntar lo mismo a quien inició sesión.
     */
    public function isCustomer(): bool
    {
        return false;
    }
}