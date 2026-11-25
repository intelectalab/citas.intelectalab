<?php

namespace App\Support;

use App\Models\BusinessType;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BusinessTemplates
{
    public const FACTORY_KEYS = ['template', 'default_rules', 'modules_off', 'vocabulary', 'dependents'];

    public const ROOM_KINDS = ['room', 'equipment'];

    public static function factoryReady(): bool
    {
        return Schema::hasColumn('business_types', 'factory');
    }

    public static function extras(): array
    {
        $path = database_path('data/plantillas-salas-y-paquetes.json');

        return is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
    }

    public static function merge(array $template, array $extra): array
    {
        if (!array_key_exists('rooms', $template) && !empty($extra['rooms'])) {
            $template['rooms'] = array_values($extra['rooms']);
        }

        if (!array_key_exists('packages', $template) && !empty($extra['packages'])) {
            $template['packages'] = array_values($extra['packages']);
        }

        if (!array_key_exists('memberships', $template) && !empty($extra['memberships'])) {
            $template['memberships'] = array_values($extra['memberships']);
        }

        foreach ($template['categories'] ?? [] as $c => $category) {
            foreach ($category['services'] ?? [] as $s => $service) {
                $code = $service['code'] ?? null;

                if ($code && !array_key_exists('rooms', $service) && !empty($extra['service_rooms'][$code])) {
                    $template['categories'][$c]['services'][$s]['rooms'] = array_values($extra['service_rooms'][$code]);
                }
            }
        }

        return $template;
    }

    private static function decode($value): array
    {
        $value = is_string($value) ? json_decode($value, true) : $value;

        return is_array($value) ? $value : [];
    }

    public static function snapshot(object $row): array
    {
        $out = [];

        foreach (self::FACTORY_KEYS as $key) {
            $out[$key] = self::decode($row->{$key} ?? null);
        }

        return $out;
    }

    public static function applyExtras(): int
    {
        if (!Schema::hasTable('business_types')) {
            return 0;
        }

        $extras = self::extras();
        $changed = 0;
        $factory = self::factoryReady();

        foreach (DB::table('business_types')->get() as $row) {
            $template = self::decode($row->template);
            $update = [];

            if (isset($extras[$row->slug]) && $template) {
                $merged = self::merge($template, $extras[$row->slug]);

                if ($merged !== $template) {
                    $update['template'] = json_encode($merged, JSON_UNESCAPED_UNICODE);
                    $row->template = $update['template'];
                }
            }

            if ($factory && empty($row->factory)) {
                $update['factory'] = json_encode(self::snapshot($row), JSON_UNESCAPED_UNICODE);
            }

            if ($update) {
                DB::table('business_types')->where('id', $row->id)->update($update);
                $changed++;
            }
        }

        return $changed;
    }

    public static function factoryOf(BusinessType $type): ?array
    {
        if (!self::factoryReady()) {
            return null;
        }

        $raw = DB::table('business_types')->where('id', $type->id)->value('factory');
        $factory = self::decode($raw);

        return $factory ?: null;
    }

    public static function reset(BusinessType $type): BusinessType
    {
        $factory = self::factoryOf($type);
        abort_if($factory === null, 422, 'Este rubro no tiene una plantilla de fábrica guardada: se creó desde la consola.');

        $type->fill([
            'template'      => $factory['template'] ?? [],
            'default_rules' => $factory['default_rules'] ?? [],
            'modules_off'   => $factory['modules_off'] ?? [],
            'vocabulary'    => $factory['vocabulary'] ?? [],
            'dependents'    => $factory['dependents'] ?? [],
        ])->save();

        return $type->fresh();
    }

    public static function catalogPreview(Tenant $tenant, BusinessType $type): array
    {
        $existing = DB::table('services')->where('tenant_id', $tenant->id)->get(['code', 'name']);
        $codes = $existing->pluck('code')->filter()->map(fn ($c) => mb_strtolower($c))->all();
        $names = $existing->pluck('name')->map(fn ($n) => mb_strtolower(trim($n)))->all();
        $new = 0;
        $skipped = 0;

        foreach ($type->template['categories'] ?? [] as $category) {
            foreach ($category['services'] ?? [] as $service) {
                $taken = (!empty($service['code']) && in_array(mb_strtolower($service['code']), $codes, true)) || in_array(mb_strtolower(trim($service['name'] ?? '')), $names, true);
                $taken ? $skipped++ : $new++;
            }
        }

        return ['new' => $new, 'skipped' => $skipped, 'categories' => count($type->template['categories'] ?? [])];
    }

    public static function importCatalog(Tenant $tenant, BusinessType $type): array
    {
        $result = ['categories' => 0, 'services' => 0, 'skipped' => 0];
        $statuses = \App\Models\Status::whereHas('module', fn ($q) => $q->where('name', 'services'))->whereNull('tenant_id')->get(['id', 'slug', 'is_default']);
        $active = $statuses->firstWhere('is_default', true)?->id;
        $inactive = $statuses->firstWhere('slug', 'inactive')?->id;
        $deposit = Schema::hasColumn('services', 'deposit_value') ? ['deposit_value' => (float) ($tenant->rules()['booking.deposit_percent'] ?? 30)] : [];
        $now = now();

        return DB::transaction(function () use ($tenant, $type, $result, $active, $inactive, $deposit, $now) {
            $existing = DB::table('services')->where('tenant_id', $tenant->id)->get(['code', 'name']);
            $codes = $existing->pluck('code')->filter()->map(fn ($c) => mb_strtolower($c))->all();
            $names = $existing->pluck('name')->map(fn ($n) => mb_strtolower(trim($n)))->all();
            $categories = DB::table('service_categories')->where('tenant_id', $tenant->id)->get(['id', 'name'])->mapWithKeys(fn ($c) => [mb_strtolower(trim($c->name)) => $c->id])->all();

            foreach ($type->template['categories'] ?? [] as $category) {
                $categoryId = null;

                foreach ($category['services'] ?? [] as $service) {
                    $name = trim((string) ($service['name'] ?? ''));
                    $code = trim((string) ($service['code'] ?? ''));

                    if ($name === '' || ($code !== '' && in_array(mb_strtolower($code), $codes, true)) || in_array(mb_strtolower($name), $names, true)) {
                        $result['skipped']++;
                        continue;
                    }

                    if (!$categoryId) {
                        $key = mb_strtolower(trim((string) $category['name']));
                        $categoryId = $categories[$key] ?? null;

                        if (!$categoryId) {
                            $categoryId = (string) Str::uuid();
                            DB::table('service_categories')->insert(['id' => $categoryId, 'tenant_id' => $tenant->id, 'name' => trim((string) $category['name']), 'created_at' => $now, 'updated_at' => $now]);
                            $categories[$key] = $categoryId;
                            $result['categories']++;
                        }
                    }

                    DB::table('services')->insert([
                        'id'                   => (string) Str::uuid(),
                        'tenant_id'            => $tenant->id,
                        'category_id'          => $categoryId,
                        'name'                 => $name,
                        'code'                 => $code !== '' ? $code : null,
                        'duration_minutes'     => max(1, (int) ($service['duration_minutes'] ?? 30)),
                        'buffer_after_minutes' => (int) ($service['buffer_after_minutes'] ?? 0),
                        'price'                => (float) ($service['price'] ?? 0),
                        'requires_deposit'     => (bool) ($service['requires_deposit'] ?? false),
                        'online_booking'       => (bool) ($service['online_booking'] ?? true),
                        'is_virtual'           => (bool) ($service['is_virtual'] ?? false),
                        'kind'                 => $service['kind'] ?? 'individual',
                        'capacity'             => (int) ($service['capacity'] ?? 1),
                        'recall_label'         => $service['recall']['label'] ?? null,
                        'recall_days'          => $service['recall']['days'] ?? null,
                        'status_id'            => ($service['active'] ?? true) === false && $inactive ? $inactive : $active,
                        'created_at'           => $now,
                        'updated_at'           => $now,
                    ] + $deposit);

                    $codes[] = mb_strtolower($code);
                    $names[] = mb_strtolower($name);
                    $result['services']++;
                }
            }

            return $result;
        });
    }

    public static function installExtras(Tenant $tenant, ?string $branchId = null, ?array $template = null): array
    {
        $installed = ['rooms' => 0, 'packages' => 0, 'memberships' => 0];
        $template ??= $tenant->businessType?->template ?? [];
        $branchId ??= DB::table('branches')->where('tenant_id', $tenant->id)->orderBy('created_at')->value('id');
        $services = DB::table('services')->where('tenant_id', $tenant->id)->whereNotNull('code')->pluck('id', 'code');
        $currency = Currency::ready() ? Currency::of($tenant) : 'PEN';
        $now = now();

        if ($branchId && !empty($template['rooms']) && Schema::hasTable('rooms') && !DB::table('rooms')->where('tenant_id', $tenant->id)->exists()) {
            $byType = [];

            foreach ($template['rooms'] as $room) {
                $name = trim((string) ($room['name'] ?? ''));

                if ($name === '') {
                    continue;
                }

                $id = (string) Str::uuid();
                DB::table('rooms')->insert([
                    'id'         => $id,
                    'tenant_id'  => $tenant->id,
                    'branch_id'  => $branchId,
                    'name'       => mb_substr($name, 0, 100),
                    'kind'       => in_array($room['kind'] ?? 'room', self::ROOM_KINDS, true) ? $room['kind'] : 'room',
                    'status'     => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $byType[$room['type'] ?? $name][] = $id;
                $installed['rooms']++;
            }

            if (Schema::hasTable('service_rooms')) {
                foreach ($template['categories'] ?? [] as $category) {
                    foreach ($category['services'] ?? [] as $service) {
                        $serviceId = $services[$service['code'] ?? ''] ?? null;

                        foreach ($serviceId ? ($service['rooms'] ?? []) : [] as $type) {
                            foreach ($byType[$type] ?? [] as $roomId) {
                                DB::table('service_rooms')->insertOrIgnore(['tenant_id' => $tenant->id, 'service_id' => $serviceId, 'room_id' => $roomId, 'created_at' => $now]);
                            }
                        }
                    }
                }
            }
        }

        $items = fn (array $list) => collect($list)->map(fn ($i) => ['service_id' => $services[$i['code'] ?? ''] ?? null, 'sessions' => max(1, (int) ($i['sessions'] ?? 1))])->filter(fn ($i) => $i['service_id'])->values();

        if (!empty($template['packages']) && Schema::hasTable('package_plans') && !DB::table('package_plans')->where('tenant_id', $tenant->id)->exists()) {
            foreach ($template['packages'] as $package) {
                $rows = $items($package['items'] ?? []);

                if ($rows->isEmpty() || empty($package['name'])) {
                    continue;
                }

                $id = (string) Str::uuid();
                DB::table('package_plans')->insert([
                    'id'            => $id,
                    'tenant_id'     => $tenant->id,
                    'name'          => mb_substr($package['name'], 0, 150),
                    'price'         => max(0, (float) ($package['price'] ?? 0)),
                    'currency'      => $currency,
                    'validity_days' => min(3650, max(1, (int) ($package['validity_days'] ?? 90))),
                    'status'        => 'active',
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
                DB::table('package_plan_items')->insert($rows->map(fn ($i, $position) => $i + ['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'package_plan_id' => $id, 'sessions' => min(200, $i['sessions']), 'position' => $position])->all());
                $installed['packages']++;
            }
        }

        if (!empty($template['memberships']) && Schema::hasTable('membership_plan_items') && !DB::table('membership_plans')->where('tenant_id', $tenant->id)->exists()) {
            foreach ($template['memberships'] as $membership) {
                $rows = $items($membership['items'] ?? []);

                if (empty($membership['name'])) {
                    continue;
                }

                $id = (string) Str::uuid();
                DB::table('membership_plans')->insert([
                    'id'               => $id,
                    'tenant_id'        => $tenant->id,
                    'name'             => mb_substr($membership['name'], 0, 150),
                    'price'            => max(0, (float) ($membership['price'] ?? 0)),
                    'billing_period'   => 'monthly',
                    'discount_percent' => min(100, max(0, (float) ($membership['discount_percent'] ?? 0))),
                    'currency'         => $currency,
                    'status'           => 'active',
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]);

                foreach ($rows as $position => $item) {
                    DB::table('membership_plan_items')->insert($item + ['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'membership_plan_id' => $id, 'sessions' => min(99, $item['sessions']), 'position' => $position]);
                }

                $installed['memberships']++;
            }
        }

        return $installed;
    }
}
