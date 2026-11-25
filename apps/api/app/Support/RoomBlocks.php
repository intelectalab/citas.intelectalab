<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RoomBlocks
{
    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('room_blocks');
    }

    public static function forget(): void
    {
        self::$ready = null;
    }

    public static function between(string $tenantId, array $roomIds, CarbonInterface $from, CarbonInterface $to): array
    {
        if (!self::ready() || $roomIds === []) {
            return [];
        }

        $children = DB::table('rooms')->where('tenant_id', $tenantId)->whereIn('parent_id', $roomIds)->pluck('parent_id', 'id')->all();

        return DB::table('room_blocks')
            ->where('tenant_id', $tenantId)
            ->whereIn('room_id', array_merge($roomIds, array_keys($children)))
            ->where('starts_at', '<', $to->toIso8601String())
            ->where('ends_at', '>', $from->toIso8601String())
            ->orderBy('starts_at')
            ->get(['id', 'room_id', 'starts_at', 'ends_at', 'reason'])
            ->groupBy(fn (object $block) => $children[$block->room_id] ?? $block->room_id)
            ->map(fn ($rows) => $rows->values()->all())
            ->all();
    }
}
