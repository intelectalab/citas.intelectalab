<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Tenant extends Model
{
    use HasFactory;

    public const CODE_LENGTH = 8;

    public const DEFAULT_VOCABULARY = [
        'client'     => 'Cliente',
        'clients'    => 'Clientes',
        'pro'        => 'Profesional',
        'pros'       => 'Profesionales',
        'dependent'  => 'Dependiente',
        'dependents' => 'Dependientes',
    ];

    protected $keyType = 'string';
    public $incrementing = false;

    private ?array $resolvedModules = null;

    protected $fillable = [
        'id',
        'name',
        'slug',
        'code',
        'business_type_id',
        'timezone',
        'status',
        'suspension_reason',
        'modules_off',
        'business_profile',
        'business_profile_synced_at',
    ];

    protected $casts = [
        'modules_off' => 'array',
        'business_profile' => 'array',
        'business_profile_synced_at' => 'datetime',
    ];

    public const PROFILE_PARTS = ['vocabulary', 'modules_off', 'default_rules', 'dependents'];

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            if (empty($tenant->code)) {
                $tenant->code = static::generateCode();
            }

            if ($tenant->business_type_id && is_null($tenant->business_profile)) {
                $tenant->fillBusinessProfile();
            }
        });
    }

    public static function generateCode(): string
    {
        do {
            $code = str_pad((string) random_int(0, 10 ** static::CODE_LENGTH - 1), static::CODE_LENGTH, '0', STR_PAD_LEFT);
        } while (static::where('code', $code)->exists());

        return $code;
    }

    public static function profileFromType(?BusinessType $type): array
    {
        return [
            'vocabulary'    => $type?->vocabulary ?? [],
            'modules_off'   => array_values($type?->modules_off ?? []),
            'default_rules' => $type?->default_rules ?? [],
            'dependents'    => $type?->dependents ?? [],
        ];
    }

    public function businessProfile(string $part): array
    {
        if (is_array($this->business_profile)) {
            return $this->business_profile[$part] ?? [];
        }

        return static::profileFromType($this->businessType)[$part] ?? [];
    }

    public function fillBusinessProfile(?array $parts = null): static
    {
        $type = $this->business_type_id ? BusinessType::find($this->business_type_id) : null;
        $fresh = static::profileFromType($type);
        $profile = is_array($this->business_profile) ? $this->business_profile : [];

        foreach ($parts ?? static::PROFILE_PARTS as $part) {
            $profile[$part] = $fresh[$part];
        }

        $this->business_profile = $profile;
        $this->business_profile_synced_at = now();
        $this->resolvedModules = null;

        return $this;
    }

    public function outdatedProfileParts(?BusinessType $type = null): array
    {
        $current = static::profileFromType($type ?? $this->businessType);
        $normalize = function (array $value, string $part) {
            if ($part === 'modules_off') {
                sort($value);
                return array_values($value);
            }
            ksort($value);
            return $value;
        };

        return array_values(array_filter(
            static::PROFILE_PARTS,
            fn ($part) => $normalize($this->businessProfile($part), $part) != $normalize($current[$part], $part),
        ));
    }

    public function vocabulary(): array
    {
        return array_merge(
            static::DEFAULT_VOCABULARY,
            $this->businessProfile('vocabulary'),
            $this->settings?->vocabulary ?? [],
        );
    }

    public function modules(): array
    {
        if ($this->resolvedModules !== null) {
            return $this->resolvedModules;
        }

        $plan = $this->activeSubscription?->plan;

        if (!$plan) {
            return $this->resolvedModules = [];
        }

        $off = array_merge(
            $this->businessProfile('modules_off'),
            $this->modules_off ?? [],
            $this->settings?->modules_off ?? [],
        );

        $core = SaasModule::coreKeys();
        $optional = array_diff($plan->moduleKeys(), $off);

        return $this->resolvedModules = array_values(array_unique(array_merge($core, $optional)));
    }

    public function zone(): string
    {
        return $this->timezone ?: 'America/Lima';
    }

    public function hasModule(string $key): bool
    {
        return in_array($key, $this->modules(), true);
    }

    public function toggleableModules(): array
    {
        $plan = $this->activeSubscription?->plan;

        if (!$plan) {
            return [];
        }

        return array_values(array_diff(
            $plan->moduleKeys(),
            $this->businessProfile('modules_off'),
            $this->modules_off ?? [],
            SaasModule::coreKeys(),
        ));
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function usage(): array
    {
        $monthStart = now()->startOfMonth();

        return [
            'users'              => DB::table('users')->where('tenant_id', $this->id)->where('is_owner', false)->where('status', 'active')->count(),
            'branches'           => DB::table('branches')->where('tenant_id', $this->id)->count(),
            'professionals'      => DB::table('professionals')->where('tenant_id', $this->id)->count(),
            'appointments_month' => DB::table('appointments')->where('tenant_id', $this->id)->where('starts_at', '>=', $monthStart)
                ->when(Schema::hasColumn('appointments', 'status'), fn ($q) => $q->where('status', '!=', 'cancelled'))
                ->count(),
            'whatsapp_month'     => DB::table('customer_messages')->where('tenant_id', $this->id)->where('channel', 'whatsapp')->where('created_at', '>=', $monthStart)->count(),
        ];
    }

    public function rules(): array
    {
        return array_merge(
            $this->businessProfile('default_rules'),
            $this->settings?->rules ?? [],
        );
    }

    public function dependents(): array
    {
        return array_merge(
            ['enabled' => false, 'kind' => null, 'required' => false],
            $this->businessProfile('dependents'),
        );
    }

    public function installBusinessTemplate(): array
    {
        $installed = ['categories' => 0, 'services' => 0, 'record_templates' => 0];
        $template = $this->businessType?->template ?? [];

        if (empty($template)
            || DB::table('service_categories')->where('tenant_id', $this->id)->exists()
            || DB::table('services')->where('tenant_id', $this->id)->exists()) {
            return $installed;
        }

        $activeStatusId = Status::whereHas('module', fn ($q) => $q->where('name', 'services'))
            ->whereNull('tenant_id')
            ->where('is_default', true)
            ->value('id');

        $now = now();
        $recordServiceIds = [];
        $serviceIdsByCode = [];
        $recallServiceCodes = [];

        foreach ($template['categories'] ?? [] as $category) {
            $categoryId = (string) Str::uuid();

            DB::table('service_categories')->insert([
                'id'         => $categoryId,
                'tenant_id'  => $this->id,
                'name'       => $category['name'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $installed['categories']++;

            foreach ($category['services'] ?? [] as $service) {
                $serviceId = (string) Str::uuid();

                DB::table('services')->insert([
                    'id'                   => $serviceId,
                    'tenant_id'            => $this->id,
                    'category_id'          => $categoryId,
                    'name'                 => $service['name'],
                    'code'                 => $service['code'] ?? null,
                    'duration_minutes'     => $service['duration_minutes'],
                    'buffer_after_minutes' => $service['buffer_after_minutes'] ?? 0,
                    'price'                => $service['price'] ?? 0,
                    'requires_deposit'     => $service['requires_deposit'] ?? false,
                ] + (\Illuminate\Support\Facades\Schema::hasColumn('services', 'deposit_value') ? ['deposit_value' => (float) ($this->rules()['booking.deposit_percent'] ?? 30)] : []) + [
                    'online_booking'       => $service['online_booking'] ?? true,
                    'is_virtual'           => $service['is_virtual'] ?? false,
                    'kind'                 => $service['kind'] ?? Service::KIND_INDIVIDUAL,
                    'capacity'             => $service['capacity'] ?? 1,
                    'recall_label'         => $service['recall']['label'] ?? null,
                    'recall_days'          => $service['recall']['days'] ?? null,
                    'status_id'            => $activeStatusId,
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ]);
                $installed['services']++;

                if (!empty($service['code'])) {
                    $serviceIdsByCode[$service['code']] = $serviceId;
                }

                if (!empty($service['recall']['service_code'])) {
                    $recallServiceCodes[$serviceId] = $service['recall']['service_code'];
                }

                if (!empty($service['uses_record'])) {
                    $recordServiceIds[] = $serviceId;
                }
            }
        }

        foreach ($recallServiceCodes as $serviceId => $code) {
            if (isset($serviceIdsByCode[$code])) {
                DB::table('services')->where('id', $serviceId)->update(['recall_service_id' => $serviceIdsByCode[$code]]);
            }
        }

        $record = $template['record_template'] ?? null;

        if ($record) {
            $recordTemplateId = (string) Str::uuid();

            DB::table('record_templates')->insert([
                'id'              => $recordTemplateId,
                'tenant_id'       => $this->id,
                'name'            => $record['name'],
                'fields'          => json_encode($record['fields'] ?? [], JSON_UNESCAPED_UNICODE),
                'consent_text'    => $record['consent_text'] ?? null,
                'require_consent' => $record['require_consent'] ?? false,
                'is_default'      => true,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
            $installed['record_templates']++;

            foreach ($recordServiceIds as $serviceId) {
                DB::table('record_template_services')->insert([
                    'tenant_id'          => $this->id,
                    'record_template_id' => $recordTemplateId,
                    'service_id'         => $serviceId,
                ]);
            }
        }

        return $installed;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class);
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->whereIn('status', ['active', 'trialing'])
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->latest('starts_at');
    }

    public function company()
    {
        return $this->hasOne(Company::class, 'tenant_id');
    }

    public function businessType(): BelongsTo
    {
        return $this->belongsTo(BusinessType::class, 'business_type_id');
    }

    public function settings(): HasOne
    {
        return $this->hasOne(TenantSetting::class, 'tenant_id');
    }
}
