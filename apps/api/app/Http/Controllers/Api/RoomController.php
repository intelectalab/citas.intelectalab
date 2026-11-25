<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use App\Support\Rooms;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Support\Access;
use App\Support\Audit;

class RoomController extends Controller
{
    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->tenant_id, 403);
        $user = $request->user();
        abort_unless($user->allows('rooms.create') || $user->allows('rooms.edit') || $user->allows('rooms.delete'), 403, 'Tu cuenta no tiene permiso para cambiar las salas y equipos.');
    }

    private function auditRoom(Request $request, string $action, object $room): void
    {
        Audit::log($request, $action, [
            'tenant'       => $request->user()->tenant_id,
            'entity_type'  => 'room',
            'entity_id'    => $room->id,
            'entity_label' => $room->name,
            'branch_id'    => $room->branch_id,
            'before'       => array_filter([
                'room_kind' => ($room->kind ?? null) === 'equipment' ? 'Equipo' : 'Sala',
                'branch'    => $room->branch_id ? DB::table('branches')->where('id', $room->branch_id)->value('name') : null,
            ]),
        ]);
    }

    private function auditable(object $room): array
    {
        return [
            'name'        => $room->name,
            'description' => $room->description ?? null,
            'room_kind'   => ($room->kind ?? null) === 'equipment' ? 'Equipo' : 'Sala',
            'branch'      => $room->branch_id ? DB::table('branches')->where('id', $room->branch_id)->value('name') : null,
            'parent_room' => !empty($room->parent_id) ? DB::table('rooms')->where('id', $room->parent_id)->value('name') : null,
            'in_use'      => ($room->status ?? 'active') === 'active',
            'room_capacity' => isset($room->capacity) ? (int) $room->capacity : null,
            'maintenance_reason' => $room->maintenance_reason ?? null,
        ];
    }

    private function auditChange(Request $request, string $action, object $room, array $before, array $after): void
    {
        Audit::log($request, $action, [
            'tenant'       => $request->user()->tenant_id,
            'entity_type'  => 'room',
            'entity_id'    => $room->id,
            'entity_label' => $room->name,
            'branch_id'    => $room->branch_id,
            'before'       => $before ?: null,
            'after'        => $after ?: null,
        ]);
    }

    private function find(Request $request, string $id): object
    {
        $room = DB::table('rooms')->where('tenant_id', $request->user()->tenant_id)->where('id', $id)->first();
        abort_unless($room, 404);
        Access::assertBranch($request->user(), $room->branch_id, true);

        return $room;
    }

    private function validated(Request $request, string $branchId, ?string $ignoreId = null): array
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:100', Rule::unique('rooms', 'name')->where('branch_id', $branchId)->ignore($ignoreId)],
            'description' => 'nullable|string|max:255',
            'kind'        => ['nullable', Rule::in(['room', 'equipment'])],
            'status'      => ['nullable', Rule::in(['active', 'inactive'])],
            'parent_id'   => ['nullable', 'uuid', Rule::exists('rooms', 'id')->where('branch_id', $branchId)->where('kind', 'room')],
            'capacity'    => 'sometimes|nullable|integer|min:1|max:500',
        ], [
            'capacity.min'  => 'La capacidad es de al menos 1.',
            'capacity.max'  => 'La capacidad va hasta 500.',
            'name.required' => 'Escribe el nombre.',
            'name.unique'   => 'Esta sucursal ya tiene una sala o equipo con ese nombre.',
            'parent_id.exists' => 'Elige una sala de la misma sede.',
        ]);

        $kind = $validated['kind'] ?? 'room';

        $extras = \App\Support\CatalogExtras::roomFields();
        $capacity = $extras && array_key_exists('capacity', $validated) ? ['capacity' => (int) ($validated['capacity'] ?? 1)] : [];
        $back = $extras && ($validated['status'] ?? 'active') === 'active' ? ['maintenance_reason' => null, 'maintenance_since' => null] : [];

        return $capacity + $back + (Rooms::hasParent() ? ['parent_id' => $kind === 'equipment' ? ($validated['parent_id'] ?? null) : null] : []) + [
            'name'        => trim($validated['name']),
            'description' => $validated['description'] ?? null,
            'kind'        => $validated['kind'] ?? 'room',
            'status'      => $validated['status'] ?? 'active',
            'updated_at'  => now(),
        ];
    }

    private function present(string $id): object
    {
        return DB::table('rooms')->where('id', $id)->first(['id', 'branch_id', 'name', 'description', 'kind', 'status', ...(\App\Support\CatalogExtras::roomFields() ? ['capacity', 'maintenance_reason', 'maintenance_since'] : [])]);
    }

    public function maintenance(Request $request, string $room)
    {
        $this->assertCanManage($request);
        abort_unless($request->user()->allows('rooms.edit'), 403, 'Tu cuenta no tiene permiso para cambiar las salas y equipos.');
        abort_unless(\App\Support\CatalogExtras::roomFields(), 409, 'Falta actualizar la base de datos para registrar mantenimientos.');
        $room = $this->find($request, $room);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['start', 'end'])],
            'reason' => 'nullable|string|max:255|required_if:action,start',
        ], ['reason.required_if' => 'Escribe qué mantenimiento se hace (por ejemplo, «cambio de camilla»).']);

        $starting = $validated['action'] === 'start';
        $before = $this->auditable($room);
        DB::table('rooms')->where('id', $room->id)->update([
            'status'             => $starting ? 'inactive' : 'active',
            'maintenance_reason' => $starting ? trim($validated['reason']) : null,
            'maintenance_since'  => $starting ? now() : null,
            'updated_at'         => now(),
        ]);

        $this->notifyIfEquipmentChanged($request, $room);
        $fresh = DB::table('rooms')->where('id', $room->id)->first();
        [$from, $to] = Audit::changed($before, $this->auditable($fresh));
        $this->auditChange($request, $starting ? 'room.maintenance_started' : 'room.maintenance_ended', $fresh, $from, $to);

        return response()->json($this->present($room->id));
    }

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->tenant_id, 403);

        $limit = Access::branchLimit($user);
        $branches = Branch::where('tenant_id', $user->tenant_id)->when($limit, fn ($q, $id) => $q->where('id', $id))->orderBy('name')->get(['id', 'name', 'status']);
        $names = $branches->pluck('name', 'id');

        $rooms = $user->tenant ? Rooms::listing($user->tenant, $limit) : collect();
        $mode = Rooms::mode($user->tenant);

        if ($user->tenant && $rooms->isNotEmpty()) {
            $usage = \App\Support\CatalogExtras::roomUsageToday($user->tenant, $rooms->pluck('id')->all());
            $services = \App\Support\RoomBooking::ready()
                ? DB::table('service_rooms')->join('services', 'services.id', '=', 'service_rooms.service_id')
                    ->where('service_rooms.tenant_id', $user->tenant_id)
                    ->orderBy('services.name')
                    ->get(['service_rooms.room_id', 'services.name'])
                    ->groupBy('room_id')
                : collect();
            $rooms = $rooms->map(fn (array $room) => $room + [
                'services'      => ($services[$room['id']] ?? collect())->pluck('name')->values()->all(),
                'minutes_today' => (int) ($usage[$room['id']] ?? 0),
            ]);
        }

        return response()->json([
            'can_manage' => $user->allows('rooms.edit'),
            'equipment_down' => $mode,
            'links_equipment' => Rooms::hasParent(),
            'branches'   => $branches->where('status', 'active')->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name])->values(),
            'rooms'      => $rooms,
        ]);
    }

    private function notifyIfEquipmentChanged(Request $request, object $before): void
    {
        $after = DB::table('rooms')->where('id', $before->id)->first();

        if ($after && $request->user()->tenant) {
            Rooms::equipmentChanged($request->user()->tenant, $after, $before->status, $after->status);
        }
    }

    public function store(Request $request, string $branch)
    {
        $this->assertCanManage($request);
        $branch = Branch::where('tenant_id', $request->user()->tenant_id)->findOrFail($branch);
        Access::assertBranch($request->user(), $branch->id, true);

        $id = (string) \Illuminate\Support\Str::uuid();
        DB::table('rooms')->insert($this->validated($request, $branch->id) + [
            'id'         => $id,
            'tenant_id'  => $branch->tenant_id,
            'branch_id'  => $branch->id,
            'created_at' => now(),
        ]);

        $created = DB::table('rooms')->where('id', $id)->first();
        $this->auditChange($request, 'room.created', $created, [], array_filter($this->auditable($created), fn ($value) => $value !== null));

        return response()->json($this->present($id), 201);
    }

    public function update(Request $request, string $room)
    {
        $this->assertCanManage($request);
        $room = $this->find($request, $room);

        DB::table('rooms')->where('id', $room->id)->update($this->validated($request, $room->branch_id, $room->id));

        $this->notifyIfEquipmentChanged($request, $room);

        $fresh = DB::table('rooms')->where('id', $room->id)->first();
        [$before, $after] = Audit::changed($this->auditable($room), $this->auditable($fresh));

        if ($after) {
            $this->auditChange($request, 'room.updated', $fresh, $before, $after);
        }

        return response()->json($this->present($room->id));
    }

    public function destroy(Request $request, string $room)
    {
        $this->assertCanManage($request);
        $room = $this->find($request, $room);

        if (DB::table('appointments')->where('room_id', $room->id)->exists() || (\App\Support\ClassSessions::ready() && DB::table('class_sessions')->where('room_id', $room->id)->exists())) {
            DB::table('rooms')->where('id', $room->id)->update(['status' => 'inactive', 'updated_at' => now()]);
            $this->notifyIfEquipmentChanged($request, $room);
            $this->auditRoom($request, 'room.deactivated', $room);

            return response()->json(['message' => 'Tiene citas registradas: se desactivó en lugar de eliminarse.', 'deactivated' => true]);
        }

        DB::table('rooms')->where('id', $room->id)->delete();
        $this->auditRoom($request, 'room.deleted', $room);

        return response()->json(['message' => 'Eliminado correctamente', 'deactivated' => false]);
    }
}
