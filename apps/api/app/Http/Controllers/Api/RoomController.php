<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use App\Support\Rooms;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Support\Access;

class RoomController extends Controller
{
    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->tenant_id, 403);
        $user = $request->user();
        abort_unless($user->allows('rooms.create') || $user->allows('rooms.edit') || $user->allows('rooms.delete'), 403, 'Tu cuenta no tiene permiso para cambiar las salas y equipos.');
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
        ], [
            'name.required' => 'Escribe el nombre.',
            'name.unique'   => 'Esta sucursal ya tiene una sala o equipo con ese nombre.',
            'parent_id.exists' => 'Elige una sala de la misma sede.',
        ]);

        $kind = $validated['kind'] ?? 'room';

        return (Rooms::hasParent() ? ['parent_id' => $kind === 'equipment' ? ($validated['parent_id'] ?? null) : null] : []) + [
            'name'        => trim($validated['name']),
            'description' => $validated['description'] ?? null,
            'kind'        => $validated['kind'] ?? 'room',
            'status'      => $validated['status'] ?? 'active',
            'updated_at'  => now(),
        ];
    }

    private function present(string $id): object
    {
        return DB::table('rooms')->where('id', $id)->first(['id', 'branch_id', 'name', 'description', 'kind', 'status']);
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

        return response()->json($this->present($id), 201);
    }

    public function update(Request $request, string $room)
    {
        $this->assertCanManage($request);
        $room = $this->find($request, $room);

        DB::table('rooms')->where('id', $room->id)->update($this->validated($request, $room->branch_id, $room->id));

        $this->notifyIfEquipmentChanged($request, $room);

        return response()->json($this->present($room->id));
    }

    public function destroy(Request $request, string $room)
    {
        $this->assertCanManage($request);
        $room = $this->find($request, $room);

        if (DB::table('appointments')->where('room_id', $room->id)->exists()) {
            DB::table('rooms')->where('id', $room->id)->update(['status' => 'inactive', 'updated_at' => now()]);
            $this->notifyIfEquipmentChanged($request, $room);

            return response()->json(['message' => 'Tiene citas registradas: se desactivó en lugar de eliminarse.', 'deactivated' => true]);
        }

        DB::table('rooms')->where('id', $room->id)->delete();

        return response()->json(['message' => 'Eliminado correctamente', 'deactivated' => false]);
    }
}
