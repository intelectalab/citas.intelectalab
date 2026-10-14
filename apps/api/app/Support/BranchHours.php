<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BranchHours
{
    public static function available(): bool
    {
        static $has = null;

        return $has ??= Schema::hasTable('branch_hours');
    }

    public static function of(string $branchId): array
    {
        if (!self::available()) {
            return [];
        }

        $hours = [];

        foreach (DB::table('branch_hours')->where('branch_id', $branchId)->orderBy('weekday')->orderBy('opens_local')->get() as $row) {
            $hours[(int) $row->weekday][] = ['opens' => substr($row->opens_local, 0, 5), 'closes' => substr($row->closes_local, 0, 5)];
        }

        return $hours;
    }

    public static function replace(string $tenantId, string $branchId, array $ranges): void
    {
        if (!self::available()) {
            return;
        }

        DB::transaction(function () use ($tenantId, $branchId, $ranges) {
            DB::table('branch_hours')->where('branch_id', $branchId)->delete();

            foreach ($ranges as $range) {
                DB::table('branch_hours')->insert([
                    'tenant_id'    => $tenantId,
                    'branch_id'    => $branchId,
                    'weekday'      => (int) $range['weekday'],
                    'opens_local'  => $range['opens'],
                    'closes_local' => $range['closes'],
                ]);
            }
        });
    }

    public static function covers(array $hours, int $weekday, string $starts, string $ends): bool
    {
        if ($hours === []) {
            return true;
        }

        foreach ($hours[$weekday] ?? [] as $range) {
            if ($range['opens'] <= $starts && $ends <= $range['closes']) {
                return true;
            }
        }

        return false;
    }

    public static function describe(array $hours, int $weekday): string
    {
        $ranges = $hours[$weekday] ?? [];

        return $ranges
            ? collect($ranges)->map(fn (array $r) => "{$r['opens']}–{$r['closes']}")->join(' y ')
            : 'cerrada ese día';
    }
}
