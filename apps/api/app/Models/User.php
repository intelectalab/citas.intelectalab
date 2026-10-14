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
        'branch_id',
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

    public ?array $accessCache = null;
    public ?string $scopeCache = null;

    public function allows(string $permission): bool
    {
        return \App\Support\Access::allows($this, $permission);
    }

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

    public function isTenantAdmin(): bool
    {
        if (!$this->tenant_id || $this->isCustomer()) {
            return false;
        }

        return $this->is_owner || $this->roles()->whereIn('slug', ['owner', 'admin'])->exists();
    }

    public function isPlatformUser(): bool
    {
        return is_null($this->tenant_id) && !$this->isCustomer();
    }

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
        return false;
    }
}