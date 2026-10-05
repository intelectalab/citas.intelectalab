<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StatusModule extends Model
{
    use HasFactory;

    protected $table = 'status_modules';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
        'description',
    ];

    public function statuses(): HasMany
    {
        return $this->hasMany(Status::class, 'module_id');
    }
}