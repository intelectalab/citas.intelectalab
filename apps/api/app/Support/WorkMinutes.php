<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WorkMinutes
{
    public static function compute(Tenant $tenant, Carbon $from, Carbon $to, ?string $branchId = null, ?string $professionalId = null): array
    {
        $zone = $tenant->zone();
        $from = $from->copy()->setTimezone($zone)->startOfDay();
        $to = $to->copy()->setTimezone($zone);

        $schedules = DB::table('professional_schedules as ps')
            ->join('branches as b', 'b.id', '=', 'ps.branch_id')
            ->where('ps.tenant_id', $tenant->id)
            ->where('b.status', 'active')
            ->when($branchId, fn ($q, $id) => $q->where('ps.branch_id', $id))
            ->when($professionalId, fn ($q, $id) => $q->where('ps.professional_id', $id))
            ->get(['ps.professional_id', 'ps.branch_id', 'ps.weekday', 'ps.starts_local', 'ps.ends_local'])
            ->groupBy('weekday');

        $holidays = Schema::hasTable('holidays')
            ? DB::table('holidays')->where('tenant_id', $tenant->id)->where('closed', true)
                ->where('date', '>=', $from->toDateString())->where('date', '<', $to->copy()->addDay()->toDateString())
                ->get(['date', 'branch_id'])
                ->groupBy(fn ($h) => Carbon::parse($h->date)->toDateString())
            : collect();

        $blocks = DB::table('availability_blocks')->where('tenant_id', $tenant->id)
            ->where('starts_at', '<', $to->copy()->utc()->toIso8601String())
            ->where('ends_at', '>', $from->copy()->utc()->toIso8601String())
            ->get(['professional_id', 'branch_id', 'starts_at', 'ends_at'])
            ->map(fn ($b) => (object) [
                'professional_id' => $b->professional_id,
                'branch_id'       => $b->branch_id,
                'starts'          => Carbon::parse($b->starts_at)->getTimestamp(),
                'ends'            => Carbon::parse($b->ends_at)->getTimestamp(),
            ]);

        $byDay = [];
        $byPro = [];

        for ($day = $from->copy(); $day->lt($to); $day->addDay()) {
            $date = $day->toDateString();
            $closed = $holidays->get($date, collect());

            foreach ($schedules->get($day->dayOfWeek, collect()) as $row) {
                if ($closed->contains(fn ($h) => $h->branch_id === null || $h->branch_id === $row->branch_id)) {
                    continue;
                }

                $start = Carbon::parse("{$date} {$row->starts_local}", $zone)->getTimestamp();
                $end = Carbon::parse("{$date} {$row->ends_local}", $zone)->getTimestamp();

                if ($end <= $start) {
                    continue;
                }

                $cuts = $blocks
                    ->filter(fn ($b) => ($b->professional_id === $row->professional_id || ($b->professional_id === null && $b->branch_id === $row->branch_id))
                        && $b->starts < $end && $b->ends > $start)
                    ->map(fn ($b) => [max($b->starts, $start), min($b->ends, $end)])
                    ->sortBy(0)->values()->all();

                $off = 0;
                $cursor = $start;

                foreach ($cuts as [$a, $b]) {
                    $a = max($a, $cursor);

                    if ($b > $a) {
                        $off += $b - $a;
                        $cursor = $b;
                    }
                }

                $minutes = intdiv(max(0, $end - $start - $off), 60);
                $byDay[$date] = ($byDay[$date] ?? 0) + $minutes;
                $byPro[$row->professional_id] = ($byPro[$row->professional_id] ?? 0) + $minutes;
            }
        }

        return ['by_day' => $byDay, 'by_pro' => $byPro, 'total' => array_sum($byDay)];
    }
}
