<?php

namespace App\Support;

use App\Models\Holiday;
use App\Models\Tenant;
use Illuminate\Support\Facades\Schema;

class Holidays
{
    public const NATIONAL = [
        '01-01' => 'Año Nuevo',
        '05-01' => 'Día del Trabajo',
        '06-07' => 'Batalla de Arica y Día de la Bandera',
        '06-29' => 'San Pedro y San Pablo',
        '07-23' => 'Día de la Fuerza Aérea',
        '07-28' => 'Fiestas Patrias',
        '07-29' => 'Fiestas Patrias',
        '08-06' => 'Batalla de Junín',
        '08-30' => 'Santa Rosa de Lima',
        '10-08' => 'Combate de Angamos',
        '11-01' => 'Todos los Santos',
        '12-08' => 'Inmaculada Concepción',
        '12-09' => 'Batalla de Ayacucho',
        '12-25' => 'Navidad',
    ];

    public static function available(): bool
    {
        static $has = null;

        return $has ??= Schema::hasTable('holidays');
    }

    public static function loadNational(Tenant $tenant, int $year, ?string $userId = null): int
    {
        if (!self::available()) {
            return 0;
        }

        $existing = Holiday::where('tenant_id', $tenant->id)
            ->whereNull('branch_id')
            ->whereYear('date', $year)
            ->get()
            ->map(fn (Holiday $holiday) => $holiday->date->format('m-d'))
            ->all();

        $added = 0;

        foreach (self::NATIONAL as $day => $name) {
            if (in_array($day, $existing, true)) {
                continue;
            }

            Holiday::create([
                'tenant_id'  => $tenant->id,
                'branch_id'  => null,
                'date'       => "{$year}-{$day}",
                'name'       => $name,
                'closed'     => true,
                'created_by' => $userId,
            ]);
            $added++;
        }

        return $added;
    }

    public static function on(Tenant $tenant, string $branchId, string $date, bool $closedOnly = false)
    {
        if (!self::available()) {
            return collect();
        }

        return Holiday::where('tenant_id', $tenant->id)
            ->where('date', $date)
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->when($closedOnly, fn ($q) => $q->where('closed', true))
            ->orderBy('name')
            ->get();
    }
}
