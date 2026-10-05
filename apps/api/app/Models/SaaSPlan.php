<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaaSPlan extends Model
{
    use HasFactory;

    protected $table = 'saas_plans';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'slug',
        'code_internal',
        'description',
        'price_monthly',
        'price_yearly',
        'currency',
        'trial_config',
        'subscription_config',
        'modules',
        'limits',
        'consumption',
        'support',
        'features',
        'status',
        'presentation_order',
        'is_public_catalog',
    ];

    protected $casts = [
        'price_monthly' => 'float',
        'price_yearly' => 'float',
        'presentation_order' => 'integer',
        'is_public_catalog' => 'boolean',
        'trial_config' => 'array',
        'subscription_config' => 'array',
        'modules' => 'array',
        'limits' => 'array',
        'consumption' => 'array',
        'support' => 'array',
        'features' => 'array',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }
}