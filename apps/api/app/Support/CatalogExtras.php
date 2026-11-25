<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CatalogExtras
{
    private static array $ready = [];

    public static function has(string $table, string $column): bool
    {
        return self::$ready["{$table}.{$column}"] ??= Schema::hasColumn($table, $column);
    }

    public static function serviceFields(): bool
    {
        return self::has('services', 'requires_record');
    }

    public static function categoryColor(): bool
    {
        return self::has('service_categories', 'color');
    }

    public static function roomFields(): bool
    {
        return self::has('rooms', 'maintenance_reason');
    }

    public static function codeTaken(string $tenantId, string $code, ?string $ignoreId = null): bool
    {
        $code = mb_strtolower(trim($code));

        if ($code === '') {
            return false;
        }

        return DB::table('services')
            ->where('tenant_id', $tenantId)
            ->whereRaw('lower(trim(code)) = ?', [$code])
            ->when($ignoreId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->exists();
    }

    public static function activeProfessionals(string $tenantId): Collection
    {
        $inactive = DB::table('statuses')->where('slug', 'inactive')->pluck('id')->all();

        return DB::table('professionals')
            ->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->whereNull('status_id')->orWhereNotIn('status_id', $inactive ?: ['00000000-0000-0000-0000-000000000000']))
            ->orderBy('display_name')
            ->get(['id', 'display_name', 'color_code']);
    }

    public static function roomUsageToday(Tenant $tenant, array $roomIds): array
    {
        if ($roomIds === [] || !Schema::hasColumn('appointments', 'room_id')) {
            return [];
        }

        $zone = $tenant->zone();
        $from = Carbon::now($zone)->startOfDay();
        $to = $from->copy()->addDay();

        return DB::table('appointments')
            ->where('tenant_id', $tenant->id)
            ->whereIn('room_id', $roomIds)
            ->whereNotIn('status', AppointmentFlow::RELEASED)
            ->where('starts_at', '<', $to->copy()->utc())
            ->where('ends_at', '>', $from->copy()->utc())
            ->get(['room_id', 'starts_at', 'ends_at'])
            ->groupBy('room_id')
            ->map(fn (Collection $rows) => (int) $rows->sum(function ($row) use ($from, $to) {
                $start = max(Carbon::parse($row->starts_at)->getTimestamp(), $from->getTimestamp());
                $end = min(Carbon::parse($row->ends_at)->getTimestamp(), $to->getTimestamp());

                return max(0, intdiv($end - $start, 60));
            }))
            ->all();
    }
}
