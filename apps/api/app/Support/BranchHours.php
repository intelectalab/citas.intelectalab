<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Horario de atención de una sede: por cada día de la semana (0 = domingo),
 * los tramos en que abre. Una sede sin ningún tramo no limita la agenda: vale
 * solo el horario de cada profesional.
 */
class BranchHours
{
    /**
     * La base puede ir un esquema por detrás: sin la tabla, ninguna sede tiene horario.
     */
    public static function available(): bool
    {
        static $has = null;

        return $has ??= Schema::hasTable('branch_hours');
    }

    /**
     * @return array<int, array<int, array{opens: string, closes: string}>> Solo los días en que abre.
     */
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

    /**
     * @param array<int, array{weekday: int, opens: string, closes: string}> $ranges
     */
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

    /**
     * ¿Ese tramo de un día cae completo dentro de lo que abre la sede? Una
     * sede sin horario definido lo cubre todo.
     *
     * @param string $ends "24:00" para la medianoche.
     */
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

    /**
     * "08:00–13:00 y 15:00–20:00", o "cerrada ese día".
     */
    public static function describe(array $hours, int $weekday): string
    {
        $ranges = $hours[$weekday] ?? [];

        return $ranges
            ? collect($ranges)->map(fn (array $r) => "{$r['opens']}–{$r['closes']}")->join(' y ')
            : 'cerrada ese día';
    }
}
