<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use HasFactory;

    public const KIND_INDIVIDUAL = 'individual';
    public const KIND_GROUP = 'group';

    protected $table = 'services';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tenant_id',
        'category_id',
        'name',
        'code',
        'duration_minutes',
        'buffer_before_minutes',
        'buffer_after_minutes',
        'price',
        'requires_deposit',
        'deposit_type',
        'deposit_value',
        'online_booking',
        'is_virtual',
        'kind',
        'capacity',
        'recall_label',
        'recall_days',
        'recall_service_id',
        'status_id',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'duration_minutes' => 'integer',
        'buffer_before_minutes' => 'integer',
        'buffer_after_minutes' => 'integer',
        'requires_deposit' => 'boolean',
        'deposit_value' => 'decimal:2',
        'online_booking' => 'boolean',
        'is_virtual' => 'boolean',
        'capacity' => 'integer',
        'recall_days' => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function status()
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    public function recallService()
    {
        return $this->belongsTo(Service::class, 'recall_service_id');
    }

    protected $appends = ['branch_ids'];

    private static array $branchMaps = [];

    public static function branchMap(string $tenantId): array
    {
        if (!array_key_exists($tenantId, self::$branchMaps)) {
            self::$branchMaps[$tenantId] = \Illuminate\Support\Facades\Schema::hasTable('service_branches')
                ? \Illuminate\Support\Facades\DB::table('service_branches')->where('tenant_id', $tenantId)->get(['service_id', 'branch_id'])
                    ->groupBy('service_id')->map(fn ($rows) => $rows->pluck('branch_id')->all())->all()
                : [];
        }

        return self::$branchMaps[$tenantId];
    }

    public static function forgetBranchMap(string $tenantId): void
    {
        unset(self::$branchMaps[$tenantId]);
    }

    public function getBranchIdsAttribute(): array
    {
        return $this->tenant_id ? (self::branchMap($this->tenant_id)[$this->id] ?? []) : [];
    }

    public function offeredAt(?string $branchId): bool
    {
        $branches = $this->branch_ids;

        return !$branchId || $branches === [] || in_array($branchId, $branches, true);
    }
}
