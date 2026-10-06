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

    // Configuración para UUIDs
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

    /**
     * Servicio con el que se agenda el control de seguimiento (si no es el mismo).
     */
    public function recallService()
    {
        return $this->belongsTo(Service::class, 'recall_service_id');
    }
}
