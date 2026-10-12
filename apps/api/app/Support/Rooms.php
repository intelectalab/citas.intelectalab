<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Salas y equipos: cómo se muestra cada uno según la regla de la empresa para
 * cuando un equipo instalado en una sala queda fuera de uso.
 *
 *  - none:    no pasa nada.
 *  - observe: la sala sigue en uso, con una observación.
 *  - disable: la sala se muestra fuera de uso mientras el equipo no vuelva.
 *
 * La sala no se apaga en la base: su estado "efectivo" se calcula al listar,
 * así vuelve sola cuando el equipo vuelve o la empresa cambia la regla.
 */
class Rooms
{
    public const NONE = 'none';
    public const OBSERVE = 'observe';
    public const DISABLE = 'disable';

    // En una base sin resetear la columna aún no existe: todo lo demás funciona igual.
    public static function hasParent(): bool
    {
        static $has = null;

        return $has ??= Schema::hasColumn('rooms', 'parent_id');
    }

    public static function mode(?Tenant $tenant): string
    {
        $mode = $tenant ? (string) AppointmentFlow::rule($tenant, 'resources.equipment_down') : self::NONE;

        return in_array($mode, [self::OBSERVE, self::DISABLE], true) ? $mode : self::NONE;
    }

    /**
     * Salas y equipos de la empresa (o de una sede), listos para mostrarse.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function listing(Tenant $tenant, ?string $branchId = null): Collection
    {
        $columns = ['id', 'branch_id', 'name', 'description', 'kind', 'status', 'created_at'];

        $all = DB::table('rooms')
            ->where('tenant_id', $tenant->id)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('name')
            ->get(self::hasParent() ? [...$columns, 'parent_id'] : $columns);

        $mode = self::mode($tenant);
        $branchNames = DB::table('branches')->where('tenant_id', $tenant->id)->pluck('name', 'id');
        $roomNames = $all->pluck('name', 'id');
        // Equipos fuera de uso, agrupados por la sala donde están.
        $down = $all
            ->filter(fn (object $r) => $r->kind === 'equipment' && $r->status !== 'active' && !empty($r->parent_id ?? null))
            ->groupBy('parent_id');

        return $all->map(function (object $room) use ($branchNames, $roomNames, $down, $mode) {
            $downHere = $mode === self::NONE ? [] : ($down[$room->id] ?? collect())->pluck('name')->values()->all();
            $autoDown = $mode === self::DISABLE && $room->status === 'active' && $downHere !== [];

            return [
                'id'               => $room->id,
                'branch_id'        => $room->branch_id,
                'branch_name'      => $branchNames[$room->branch_id] ?? null,
                'name'             => $room->name,
                'description'      => $room->description,
                'kind'             => $room->kind,
                'status'           => $room->status,
                'effective_status' => $autoDown ? 'inactive' : $room->status,
                'auto_down'        => $autoDown,
                'down_equipment'   => $downHere,
                'parent_id'        => $room->parent_id ?? null,
                'parent_name'      => !empty($room->parent_id ?? null) ? ($roomNames[$room->parent_id] ?? null) : null,
                'created_at'       => $room->created_at,
            ];
        })->values();
    }

    /**
     * Un equipo instalado en una sala cambió de "en uso" a "fuera de uso" o al
     * revés. Si la empresa pidió enterarse (observe o disable), avisa a la
     * administración de lo que eso significa para la sala.
     */
    public static function equipmentChanged(Tenant $tenant, object $equipment, string $oldStatus, string $newStatus): void
    {
        try {
            $mode = self::mode($tenant);

            if ($mode === self::NONE || $oldStatus === $newStatus || $equipment->kind !== 'equipment' || empty($equipment->parent_id ?? null)) {
                return;
            }

            $room = DB::table('rooms')->where('id', $equipment->parent_id)->first();

            if (!$room) {
                return;
            }

            $branch = DB::table('branches')->where('id', $room->branch_id)->value('name');
            $wentDown = $newStatus !== 'active';
            // Otros equipos de la misma sala que siguen fuera de uso.
            $othersDown = DB::table('rooms')
                ->where('parent_id', $room->id)
                ->where('id', '!=', $equipment->id)
                ->where('kind', 'equipment')
                ->where('status', '!=', 'active')
                ->exists();

            if ($mode === self::DISABLE) {
                // La sala ya estaba apagada a mano, o ya estaba fuera de uso por otro equipo: no cambia nada.
                if ($room->status !== 'active' || $othersDown) {
                    return;
                }

                [$type, $title, $body] = $wentDown
                    ? ['room.down', "{$room->name} quedó fuera de uso", "Su equipo {$equipment->name} está fuera de uso. Volverá sola cuando el equipo vuelva."]
                    : ['room.up', "{$room->name} volvió a estar en uso", "Su equipo {$equipment->name} ya está en uso."];
            } else {
                [$type, $title, $body] = $wentDown
                    ? ['room.observed', 'Equipo fuera de uso en una sala', "{$equipment->name}, de {$room->name}, está fuera de uso. La sala sigue en uso."]
                    : ['room.up', 'Equipo de vuelta en uso', "{$equipment->name}, de {$room->name}, ya está en uso."];
            }

            foreach (Notifier::adminIds($tenant->id) as $userId) {
                Notifier::send($tenant->id, $userId, $type, $title, implode(' · ', array_filter([$body, $branch])), '/rooms');
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
