<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerStages
{
    public const COLORS = ['#64748B', '#0EA5E9', '#6366F1', '#10B981', '#A855F7', '#F59E0B', '#EF4444', '#3F768C', '#EC4899', '#84CC16'];

    public const MAX_COLUMNS = 12;

    public const DEFAULTS = [
        ['key' => 'lead', 'name' => 'Nuevo contacto', 'description' => 'Registrado, aún sin cita', 'color' => '#64748B'],
        ['key' => 'contacted', 'name' => 'Contactado', 'description' => 'Se le escribió o llamó', 'color' => '#0EA5E9'],
        ['key' => 'booked', 'name' => 'Primera cita agendada', 'description' => 'Ya reservó, aún no viene', 'color' => '#6366F1'],
        ['key' => 'active', 'name' => 'Activo', 'description' => 'Ya fue atendido y vuelve', 'color' => '#10B981'],
        ['key' => 'loyal', 'name' => 'Fidelizado', 'description' => 'Viene seguido o gasta más', 'color' => '#A855F7'],
        ['key' => 'recover', 'name' => 'Por recuperar', 'description' => 'Hace tiempo que no viene y no tiene cita', 'color' => '#F59E0B'],
        ['key' => 'lost', 'name' => 'Perdido', 'description' => 'No desea volver', 'color' => '#EF4444'],
    ];

    public const SOURCES = ['board', 'form', 'detail', 'column_deleted', 'auto'];

    public const MANUAL_SOURCES = ['board', 'form', 'detail'];

    public const AUTO = 'auto';

    public const AUTOMATIC = ['lead', 'booked', 'active', 'loyal', 'recover'];

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasColumn('pipelines', 'tenant_id')
            && Schema::hasColumn('pipeline_stages', 'system_key')
            && Schema::hasTable('customer_stage_changes');
    }

    public static function automatic(): bool
    {
        static $ready = null;

        return $ready ??= self::ready() && Schema::hasColumn('customers', 'stage_manual_at');
    }

    public static function boardId(Tenant $tenant): string
    {
        $id = DB::table('pipelines')->where('tenant_id', $tenant->id)->value('id');

        if ($id) {
            return $id;
        }

        return DB::transaction(function () use ($tenant) {
            $id = (string) Str::uuid();
            $created = DB::table('pipelines')->insertOrIgnore(['id' => $id, 'tenant_id' => $tenant->id, 'name' => 'Clientes', 'created_at' => now(), 'updated_at' => now()]);

            if (!$created) {
                return DB::table('pipelines')->where('tenant_id', $tenant->id)->value('id');
            }

            DB::table('pipeline_stages')->insert(array_map(fn (array $stage, int $index) => [
                'id'          => (string) Str::uuid(),
                'pipeline_id' => $id,
                'name'        => $stage['name'],
                'description' => $stage['description'],
                'color'       => $stage['color'],
                'system_key'  => $stage['key'],
                'probability' => 0,
                'stage_order' => $index + 1,
                'created_at'  => now(),
                'updated_at'  => now(),
            ], self::DEFAULTS, array_keys(self::DEFAULTS)));

            return $id;
        });
    }

    public static function stages(Tenant $tenant): array
    {
        $boardId = self::boardId($tenant);

        $counts = DB::table('customers')
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->whereNotNull('pipeline_stage_id')
            ->groupBy('pipeline_stage_id')
            ->selectRaw('pipeline_stage_id, count(*) AS total')
            ->pluck('total', 'pipeline_stage_id');

        $stages = DB::table('pipeline_stages')
            ->where('pipeline_id', $boardId)
            ->orderBy('stage_order')
            ->get(['id', 'name', 'description', 'color', 'system_key'])
            ->map(fn ($stage) => [
                'id'          => $stage->id,
                'name'        => $stage->name,
                'description' => $stage->description,
                'color'       => $stage->color,
                'system_key'  => $stage->system_key,
                'customers'   => (int) ($counts[$stage->id] ?? 0),
            ])
            ->all();

        $default = self::defaultId($stages);
        $known = array_column($stages, 'id');
        $loose = DB::table('customers')
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('pipeline_stage_id')->orWhereNotIn('pipeline_stage_id', $known ?: ['00000000-0000-0000-0000-000000000000']))
            ->count();

        foreach ($stages as &$stage) {
            if ($stage['id'] === $default) {
                $stage['customers'] += $loose;
            }
        }

        return $stages;
    }

    public static function defaultId(array $stages): ?string
    {
        foreach ($stages as $stage) {
            if ($stage['system_key'] === 'lead') {
                return $stage['id'];
            }
        }

        return $stages[0]['id'] ?? null;
    }

    public static function move(Tenant $tenant, string $customerId, ?string $stageId, ?User $by, string $source): bool
    {
        $stages = collect(self::stages($tenant))->keyBy('id');

        if ($stageId !== null && !$stages->has($stageId)) {
            throw ValidationException::withMessages(['stage_id' => 'Esa columna ya no existe en tu tablero. Vuelve a cargar la página.']);
        }

        $current = DB::table('customers')->where('tenant_id', $tenant->id)->where('id', $customerId)->whereNull('deleted_at')->value('pipeline_stage_id');
        $current = $current && $stages->has($current) ? $current : null;

        if ($current === $stageId) {
            return false;
        }

        $default = self::defaultId($stages->values()->all());
        $from = $current ?? $default;
        $to = $stageId ?? $default;

        DB::transaction(function () use ($tenant, $customerId, $stageId, $by, $source, $stages, $from, $to) {
            DB::table('customers')->where('id', $customerId)->update(['pipeline_stage_id' => $stageId, 'updated_at' => now()]
                + (self::automatic() && in_array($source, self::MANUAL_SOURCES, true) ? ['stage_manual_at' => now()] : []));

            if ($from !== $to) {
                self::record($tenant->id, $customerId, $from, $stages[$from]['name'] ?? null, $to, $stages[$to]['name'] ?? null, $source, $by?->id);
            }
        });

        if ($from !== $to && $by) {
            $person = DB::table('customers')->where('id', $customerId)->first(['first_name', 'last_name']);

            Audit::log(request(), 'customer.stage_changed', [
                'tenant'       => $tenant->id,
                'entity_type'  => 'customer',
                'entity_id'    => $customerId,
                'entity_label' => Person::name($person),
                'before'       => ['stage' => $stages[$from]['name'] ?? null],
                'after'        => ['stage' => $stages[$to]['name'] ?? null],
            ]);
        }

        return true;
    }

    public static function release(Tenant $tenant, string $customerId): void
    {
        if (!self::automatic()) {
            return;
        }

        DB::table('customers')->where('tenant_id', $tenant->id)->where('id', $customerId)->update(['stage_manual_at' => null]);
        self::refresh($tenant, [$customerId], true);
    }

    public static function refreshStale(Tenant $tenant): void
    {
        if (self::automatic() && Cache::add('customer-stages:' . $tenant->id . ':' . now($tenant->zone())->toDateString(), true, now()->addDay())) {
            self::refresh($tenant);
        }
    }

    public static function refreshFor(string $tenantId, ?string $customerId): void
    {
        if (!$customerId || !self::automatic()) {
            return;
        }

        try {
            if ($tenant = Tenant::find($tenantId)) {
                self::refresh($tenant, [$customerId]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function refresh(Tenant $tenant, ?array $customerIds = null, bool $force = false): int
    {
        if (!self::automatic()) {
            return 0;
        }

        $stages = collect(self::stages($tenant));
        $byKey = $stages->whereNotNull('system_key')->keyBy('system_key');
        $byId = $stages->keyBy('id');
        $default = self::defaultId($stages->all());
        $rules = Campaigns::rules($tenant);
        $now = now();

        $visits = DB::table('appointments')
            ->where('tenant_id', $tenant->id)
            ->where('status', AppointmentFlow::COMPLETED)
            ->groupBy('customer_id')
            ->selectRaw('customer_id, count(*) AS visits, max(starts_at) AS last_visit, count(*) FILTER (WHERE starts_at >= ?) AS recent', [$now->copy()->subDays(90)->toIso8601String()]);

        $spend = DB::table('sales')
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['paid', 'partial', 'partially_refunded'])
            ->whereRaw('coalesce(paid_at, created_at) >= ?', [$now->copy()->subMonths(12)->toIso8601String()])
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->selectRaw('customer_id, sum(paid) AS spent');

        $rows = DB::table('customers as c')
            ->where('c.tenant_id', $tenant->id)
            ->whereNull('c.deleted_at')
            ->when($customerIds !== null, fn ($q) => $q->whereIn('c.id', $customerIds))
            ->leftJoinSub($visits, 'v', 'v.customer_id', '=', 'c.id')
            ->leftJoinSub($spend, 's', 's.customer_id', '=', 'c.id')
            ->select('c.id', 'c.pipeline_stage_id', 'c.stage_manual_at', 'v.visits', 'v.last_visit', 'v.recent', 's.spent')
            ->selectRaw("EXISTS (SELECT 1 FROM appointments a WHERE a.customer_id = c.id AND a.status IN ('pending', 'confirmed') AND a.starts_at > ?) AS upcoming", [$now->toIso8601String()])
            ->selectRaw('(c.stage_manual_at IS NOT NULL AND EXISTS (SELECT 1 FROM appointments a WHERE a.customer_id = c.id AND a.created_at > c.stage_manual_at)) AS booked_after')
            ->selectRaw("(c.stage_manual_at IS NOT NULL AND EXISTS (SELECT 1 FROM appointments a WHERE a.customer_id = c.id AND a.status = 'completed' AND a.completed_at > c.stage_manual_at)) AS attended_after")
            ->get();

        $moved = 0;

        foreach ($rows as $row) {
            $current = $row->pipeline_stage_id && $byId->has($row->pipeline_stage_id) ? $byId[$row->pipeline_stage_id] : null;

            if ($row->stage_manual_at) {
                $released = ($current['system_key'] ?? null) === 'lost' ? $row->booked_after : ($row->booked_after || $row->attended_after);

                if (!$released) {
                    continue;
                }

                DB::table('customers')->where('id', $row->id)->update(['stage_manual_at' => null]);
            } elseif (!$force && $current && !in_array($current['system_key'], self::AUTOMATIC, true)) {
                continue;
            }

            $target = $byKey->get(self::targetKey($row, $rules, $now));

            if (!$target || $target['id'] === ($current['id'] ?? null)) {
                continue;
            }

            $from = $current['id'] ?? $default;
            DB::table('customers')->where('id', $row->id)->update(['pipeline_stage_id' => $target['id']]);

            if ($target['id'] !== $from) {
                self::record($tenant->id, $row->id, $from, $byId[$from]['name'] ?? null, $target['id'], $target['name'], 'auto', null);
                $moved++;
            }
        }

        return $moved;
    }

    private static function targetKey(object $row, array $rules, Carbon $now): string
    {
        if (!(int) $row->visits) {
            return $row->upcoming ? 'booked' : 'lead';
        }

        if (!$row->upcoming && $row->last_visit && Carbon::parse($row->last_visit)->lte($now->copy()->subDays($rules['inactive_days']))) {
            return 'recover';
        }

        if ((int) $row->recent >= max(1, $rules['frequent_visits']) || (float) $row->spent >= max(0.01, $rules['vip_spend'])) {
            return 'loyal';
        }

        return 'active';
    }

    public static function save(Tenant $tenant, array $columns, array $moves, User $by): array
    {
        $boardId = self::boardId($tenant);
        $existing = collect(self::stages($tenant))->keyBy('id');

        if (count($columns) === 0) {
            throw ValidationException::withMessages(['columns' => 'El tablero necesita al menos una columna.']);
        }

        if (count($columns) > self::MAX_COLUMNS) {
            throw ValidationException::withMessages(['columns' => 'El tablero admite hasta ' . self::MAX_COLUMNS . ' columnas.']);
        }

        $names = [];

        foreach ($columns as $index => $column) {
            $name = trim((string) ($column['name'] ?? ''));
            $key = Str::lower(Str::ascii($name));

            if ($name === '') {
                throw ValidationException::withMessages(["columns.$index.name" => 'Cada columna necesita un nombre.']);
            }

            if (isset($names[$key])) {
                throw ValidationException::withMessages(["columns.$index.name" => "Hay dos columnas llamadas \"$name\". Ponle otro nombre a una de ellas."]);
            }

            if (!empty($column['id']) && !$existing->has($column['id'])) {
                throw ValidationException::withMessages(["columns.$index.id" => 'Una de las columnas ya no existe. Vuelve a cargar la página.']);
            }

            $names[$key] = true;
        }

        $kept = collect($columns)->pluck('id')->filter()->all();
        $removed = $existing->keys()->diff($kept)->values();

        foreach ($removed as $removedId) {
            $target = $moves[$removedId] ?? null;

            if ($existing[$removedId]['customers'] > 0 && (!$target || !in_array($target, $kept, true))) {
                throw ValidationException::withMessages(['moves' => 'Elige a qué columna pasan las tarjetas de "' . $existing[$removedId]['name'] . '".']);
            }
        }

        $default = self::defaultId($existing->values()->all());

        DB::transaction(function () use ($tenant, $boardId, $columns, $moves, $removed, $existing, $by, $default) {
            foreach ($columns as $index => $column) {
                $data = [
                    'name'        => trim($column['name']),
                    'description' => trim((string) ($column['description'] ?? '')) ?: null,
                    'color'       => in_array($column['color'] ?? null, self::COLORS, true) ? $column['color'] : self::COLORS[0],
                    'stage_order' => $index + 1,
                    'updated_at'  => now(),
                ];

                if (!empty($column['id'])) {
                    DB::table('pipeline_stages')->where('id', $column['id'])->update($data);
                } else {
                    DB::table('pipeline_stages')->insert($data + ['id' => (string) Str::uuid(), 'pipeline_id' => $boardId, 'probability' => 0, 'created_at' => now()]);
                }
            }

            $names = collect($columns)->filter(fn ($column) => !empty($column['id']))->mapWithKeys(fn ($column) => [$column['id'] => trim($column['name'])]);

            foreach ($removed as $removedId) {
                $target = $moves[$removedId] ?? null;

                if ($target && $existing[$removedId]['customers'] > 0) {
                    $inColumn = fn ($q) => $q->where('pipeline_stage_id', $removedId)
                        ->when($removedId === $default, fn ($q) => $q->orWhereNull('pipeline_stage_id')->orWhereNotIn('pipeline_stage_id', $existing->keys()->all()));

                    $customers = DB::table('customers')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->where($inColumn)->pluck('id');

                    foreach ($customers as $customerId) {
                        self::record($tenant->id, $customerId, null, $existing[$removedId]['name'], $target, $names[$target] ?? null, 'column_deleted', $by->id);
                    }

                    $manual = self::automatic() && !in_array($existing[$target]['system_key'] ?? null, self::AUTOMATIC, true) ? ['stage_manual_at' => now()] : [];
                    DB::table('customers')->whereIn('id', $customers)->update(['pipeline_stage_id' => $target, 'updated_at' => now()] + $manual);
                }

                DB::table('customers')->where('tenant_id', $tenant->id)->where('pipeline_stage_id', $removedId)->update(['pipeline_stage_id' => null]);
                DB::table('pipeline_stages')->where('id', $removedId)->delete();
            }
        });

        return self::stages($tenant);
    }

    public static function changes(Tenant $tenant, string $customerId): array
    {
        return DB::table('customer_stage_changes as h')
            ->leftJoin('users as u', 'u.id', '=', 'h.changed_by')
            ->where('h.tenant_id', $tenant->id)
            ->where('h.customer_id', $customerId)
            ->orderByDesc('h.created_at')
            ->limit(100)
            ->get(['h.id', 'h.from_name', 'h.to_name', 'h.to_stage_id', 'h.source', 'h.created_at', 'h.changed_by', 'u.first_name', 'u.last_name'])
            ->map(fn ($row) => [
                'id'         => $row->id,
                'from'       => $row->from_name,
                'to'         => $row->to_name,
                'source'     => $row->source,
                'created_at' => \Illuminate\Support\Carbon::parse($row->created_at)->setTimezone($tenant->zone())->format('Y-m-d\TH:i:s'),
                'by'         => $row->changed_by ? ['id' => $row->changed_by, 'name' => Person::name($row)] : null,
            ])
            ->all();
    }

    private static function record(string $tenantId, string $customerId, ?string $fromId, ?string $fromName, ?string $toId, ?string $toName, string $source, ?string $userId): void
    {
        DB::table('customer_stage_changes')->insert([
            'tenant_id'     => $tenantId,
            'customer_id'   => $customerId,
            'from_stage_id' => $fromId,
            'from_name'     => $fromName,
            'to_stage_id'   => $toId,
            'to_name'       => $toName,
            'source'        => in_array($source, self::SOURCES, true) ? $source : 'board',
            'changed_by'    => $userId,
            'created_at'    => DB::raw('clock_timestamp()'),
        ]);
    }
}
