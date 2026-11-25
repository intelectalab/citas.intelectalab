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
        'alert',
        'status',
        'weight_kg',
        'color',
        'sterilized',
        'microchip',
        'relationship',
        'document_number',
    ];

    protected $casts = [
        'birth_date' => 'date:Y-m-d',
        'weight_kg'  => 'float',
        'sterilized' => 'boolean',
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

    public static function alertReady(): bool
    {
        static $ready = null;

        return $ready ??= \Illuminate\Support\Facades\Schema::hasColumn('customer_dependents', 'alert');
    }

    public static function staffRules(string $prefix = ''): array
    {
        return self::rules($prefix)
            + (self::alertReady() ? [$prefix . 'alert' => 'nullable|string|max:160'] : [])
            + (self::detailsReady() ? [
                $prefix . 'weight_kg'       => 'nullable|numeric|min:0|max:9999',
                $prefix . 'color'           => 'nullable|string|max:60',
                $prefix . 'sterilized'      => 'nullable|boolean',
                $prefix . 'microchip'       => 'nullable|string|max:40',
                $prefix . 'relationship'    => 'nullable|string|max:60',
                $prefix . 'document_number' => 'nullable|string|max:30',
            ] : []);
    }

    public static function detailsReady(): bool
    {
        static $ready = null;

        return $ready ??= \Illuminate\Support\Facades\Schema::hasColumn('customer_dependents', 'microchip');
    }

    public static function cleanAlert(array $data): array
    {
        if (array_key_exists('alert', $data)) {
            $data['alert'] = trim((string) $data['alert']) ?: null;
        }

        return $data;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
