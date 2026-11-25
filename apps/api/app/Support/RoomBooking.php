<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RoomBooking
{
    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('service_rooms');
    }

    public static function enabled(?Tenant $tenant): bool
    {
        return $tenant !== null && self::ready() && $tenant->hasModule('resources');
    }

    public static function roomIdsOf(string $serviceId): array
    {
        if (!self::ready()) {
            return [];
        }

        return DB::table('service_rooms')->where('service_id', $serviceId)->pluck('room_id')->all();
    }

    public static function save(Service $service, ?array $roomIds): void
    {
        if ($roomIds === null || !self::ready()) {
            return;
        }

        $valid = DB::table('rooms')
            ->where('tenant_id', $service->tenant_id)
            ->where('kind', 'room')
            ->whereIn('id', array_values(array_unique($roomIds)))
            ->pluck('id')
            ->all();

        DB::table('service_rooms')->where('service_id', $service->id)->delete();
        DB::table('service_rooms')->insert(array_map(fn (string $id) => [
            'tenant_id'  => $service->tenant_id,
            'service_id' => $service->id,
            'room_id'    => $id,
            'created_at' => now(),
        ], $valid));
    }

    public static function marked(Tenant $tenant, Service $service, string $branchId): Collection
    {
        if (!self::enabled($tenant) || $service->kind === Service::KIND_GROUP || $service->is_virtual) {
            return collect();
        }

        return DB::table('rooms')
            ->join('service_rooms', 'service_rooms.room_id', '=', 'rooms.id')
            ->where('service_rooms.service_id', $service->id)
            ->where('rooms.tenant_id', $tenant->id)
            ->where('rooms.branch_id', $branchId)
            ->where('rooms.kind', 'room')
            ->orderBy('rooms.name')
            ->get(['rooms.id', 'rooms.name', 'rooms.status']);
    }

    public static function usable(Tenant $tenant, Collection $marked): array
    {
        $down = Rooms::mode($tenant) === Rooms::DISABLE && $marked->isNotEmpty()
            ? DB::table('rooms')
                ->whereIn('parent_id', $marked->pluck('id'))
                ->where('kind', 'equipment')
                ->where('status', '!=', 'active')
                ->pluck('parent_id')
                ->unique()
                ->all()
            : [];

        return $marked
            ->filter(fn (object $room) => $room->status === 'active' && !in_array($room->id, $down, true))
            ->pluck('name', 'id')
            ->all();
    }

    public static function context(Tenant $tenant, Service $service, string $branchId, CarbonInterface $from, CarbonInterface $to, ?string $ignoreId = null): ?array
    {
        $marked = self::marked($tenant, $service, $branchId);

        if ($marked->isEmpty()) {
            return null;
        }

        $usable = self::usable($tenant, $marked);

        return [
            'marked'  => $marked->pluck('name', 'id')->all(),
            'usable'  => $usable,
            'busy'    => self::occupancy($tenant->id, array_keys($usable), $from->copy()->subHours(12), $to->copy()->addHours(12), $ignoreId),
            'buffers' => (bool) AppointmentFlow::rule($tenant, 'agenda.respect_buffers'),
        ];
    }

    public static function occupancy(string $tenantId, array $roomIds, CarbonInterface $from, CarbonInterface $to, ?string $ignoreId = null): array
    {
        $busy = array_fill_keys($roomIds, []);

        if ($roomIds === []) {
            return $busy;
        }

        $appointments = Appointment::where('tenant_id', $tenantId)
            ->whereIn('room_id', $roomIds)
            ->whereNotIn('status', AppointmentFlow::RELEASED)
            ->when($ignoreId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->where('starts_at', '<', $to->toIso8601String())
            ->where('ends_at', '>', $from->toIso8601String())
            ->with('service:id,buffer_before_minutes,buffer_after_minutes')
            ->get();

        foreach ($appointments as $appointment) {
            $other = $appointment->service;
            $busy[$appointment->room_id][] = [
                'starts' => $appointment->starts_at->copy(),
                'ends'   => AppointmentTiming::occupiedUntil($appointment)->copy(),
                'before' => $other instanceof Service ? (int) $other->buffer_before_minutes : 0,
                'after'  => $other instanceof Service ? (int) $other->buffer_after_minutes : 0,
            ];
        }

        foreach (RoomBlocks::between($tenantId, $roomIds, $from, $to) as $roomId => $blocks) {
            foreach ($blocks as $block) {
                $busy[$roomId][] = [
                    'starts' => Carbon::parse($block->starts_at),
                    'ends'   => Carbon::parse($block->ends_at),
                    'before' => 0,
                    'after'  => 0,
                ];
            }
        }

        if (ClassSessions::ready()) {
            $classes = DB::table('class_sessions')
                ->where('tenant_id', $tenantId)
                ->whereIn('room_id', $roomIds)
                ->where('status', 'scheduled')
                ->where('starts_at', '<', $to->toIso8601String())
                ->where('ends_at', '>', $from->toIso8601String())
                ->get(['room_id', 'starts_at', 'ends_at']);

            foreach ($classes as $class) {
                $busy[$class->room_id][] = [
                    'starts' => Carbon::parse($class->starts_at),
                    'ends'   => Carbon::parse($class->ends_at),
                    'before' => 0,
                    'after'  => 0,
                ];
            }
        }

        return $busy;
    }

    public static function freeIn(array $context, Service $service, CarbonInterface $start, CarbonInterface $end): array
    {
        $buffers = $context['buffers'];
        $from = $start->copy()->subMinutes($buffers ? (int) $service->buffer_before_minutes : 0);
        $to = $end->copy()->addMinutes($buffers ? (int) $service->buffer_after_minutes : 0);

        return array_values(array_filter(array_keys($context['usable']), function (string $roomId) use ($context, $from, $to, $buffers) {
            foreach ($context['busy'][$roomId] ?? [] as $other) {
                $otherFrom = $other['starts']->copy()->subMinutes($buffers ? $other['before'] : 0);
                $otherTo = $other['ends']->copy()->addMinutes($buffers ? $other['after'] : 0);

                if ($otherFrom->lt($to) && $from->lt($otherTo)) {
                    return false;
                }
            }

            return true;
        }));
    }

    public static function reason(array $context): array
    {
        if ($context['usable'] === []) {
            return ['code' => 'NO_ROOM', 'message' => 'No hay una sala en uso para este servicio en esta sede', 'detail' => implode(', ', $context['marked'])];
        }

        if (count($context['usable']) === 1) {
            return ['code' => 'ROOM_BUSY', 'message' => 'La sala ' . reset($context['usable']) . ' ya está ocupada a esa hora', 'detail' => ''];
        }

        return ['code' => 'ROOM_BUSY', 'message' => 'Todas las salas para este servicio están ocupadas a esa hora', 'detail' => implode(', ', $context['usable'])];
    }

    public static function pick(Appointment $appointment): ?string
    {
        $current = $appointment->room_id;

        try {
            if (!self::ready() || !$appointment->service_id || !$appointment->branch_id
                || in_array($appointment->status, AppointmentFlow::RELEASED, true)
                || !DB::table('service_rooms')->where('service_id', $appointment->service_id)->exists()) {
                return $current;
            }

            $tenant = Tenant::find($appointment->tenant_id);
            $service = Service::find($appointment->service_id);

            if (!$tenant || !$service) {
                return $current;
            }

            $context = self::context($tenant, $service, $appointment->branch_id, $appointment->starts_at, $appointment->ends_at, $appointment->exists ? $appointment->id : null);

            if ($context === null) {
                return $current;
            }

            $free = self::freeIn($context, $service, $appointment->starts_at, $appointment->ends_at);

            if ($current && in_array($current, $free, true)) {
                return $current;
            }

            return $free[0] ?? $current;
        } catch (\Throwable $e) {
            report($e);

            return $current;
        }
    }

    public static function options(Tenant $tenant, Appointment $appointment): ?array
    {
        $service = $appointment->service;

        if (!$service instanceof Service || !$appointment->branch_id) {
            return null;
        }

        $context = self::context($tenant, $service, $appointment->branch_id, $appointment->starts_at, $appointment->ends_at, $appointment->id);

        if ($context === null) {
            return null;
        }

        $free = self::freeIn($context, $service, $appointment->starts_at, $appointment->ends_at);

        return collect($context['usable'])
            ->map(fn (string $name, string $id) => ['id' => $id, 'name' => $name, 'free' => in_array($id, $free, true) || $id === $appointment->room_id])
            ->values()
            ->all();
    }

    public static function assertCanUse(Tenant $tenant, Appointment $appointment, string $roomId): void
    {
        $service = $appointment->service;
        $context = $service instanceof Service ? self::context($tenant, $service, $appointment->branch_id, $appointment->starts_at, $appointment->ends_at, $appointment->id) : null;

        if ($context === null || !array_key_exists($roomId, $context['marked'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['room_id' => 'Esa sala no está marcada para este servicio en esta sede.']);
        }

        $name = $context['marked'][$roomId];

        if (!array_key_exists($roomId, $context['usable'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['room_id' => "La sala {$name} está fuera de uso."]);
        }

        if (!in_array($roomId, self::freeIn($context, $service, $appointment->starts_at, $appointment->ends_at), true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['room_id' => "La sala {$name} ya está ocupada a esa hora."]);
        }
    }

    public static function name(?string $roomId): ?string
    {
        if (!$roomId) {
            return null;
        }

        $attributes = request()->attributes;
        $names = $attributes->get('room_names', []);

        if (!array_key_exists($roomId, $names)) {
            $names[$roomId] = DB::table('rooms')->where('id', $roomId)->value('name');
            $attributes->set('room_names', $names);
        }

        return $names[$roomId];
    }
}
