<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantContact extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'tenant_contacts';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'position',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}