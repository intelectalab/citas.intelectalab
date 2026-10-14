<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerDependent extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'customer_dependents';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'name',
        'kind',
        'species',
        'breed',
        'birth_date',
        'biological_sex',
        'notes',
        'status',
    ];

    protected $casts = [
        'birth_date' => 'date:Y-m-d',
    ];

    public static function rules(string $prefix = ''): array
    {
        return [
            $prefix . 'name'           => 'required|string|max:150',
            $prefix . 'species'        => 'nullable|string|max:80',
            $prefix . 'breed'          => 'nullable|string|max:80',
            $prefix . 'birth_date'     => 'nullable|date',
            $prefix . 'biological_sex' => 'nullable|string|in:M,F,O',
            $prefix . 'notes'          => 'nullable|string|max:2000',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
