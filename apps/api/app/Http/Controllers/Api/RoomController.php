<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Salas y equipos de una sucursal (consultorios, cabinas, sillones, equipos).
 * Por ahora se registran y se listan; asignarlos a las citas vendrá después.
 */
class RoomController extends Controller
{
    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->tenant_id, 403);
        abort_unless($request->user()->isTenantAdmin(), 403, 'Solo el administrador de la empresa cambia las salas y equipos.');
    }

    private function find(Request $request, string $id): object
    {
        $room = DB::table('rooms')->where('tenant_id', $request->user()->tenant_id)->where('id', $id)->first();
        abort_unless($room, 404);

        return $room;
    }

    private function validated(Request $request, string $branchId, ?string $ignoreId = null): array
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:100', Rule::unique('rooms', 'name')->where('branch_id', $branchId)->ignore($ignoreId)],
            'description' => 'nullable|string|max:255',
            'kind'        => ['nullable', Rule::in(['room', 'equipment'])],
            'status'      => ['nullable', Rule::in(['active', 'inactive'])],
        ], [
            'name.required' => 'Escribe el nombre.',
            'name.unique'   => 'Esta sucursal ya tiene una sala o equipo con ese nombre.',
        ]);

        return [
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

    public function store(Request $request, string $branch)
    {
        $this->assertCanManage($request);
        $branch = Branch::where('tenant_id', $request->user()->tenant_id)->findOrFail($branch);

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

        return response()->json($this->present($room->id));
    }

    /**
     * Se elimina si ninguna cita la usó; si ya tiene historia, se desactiva.
     */
    public function destroy(Request $request, string $room)
    {
        $this->assertCanManage($request);
        $room = $this->find($request, $room);

        if (DB::table('appointments')->where('room_id', $room->id)->exists()) {
            DB::table('rooms')->where('id', $room->id)->update(['status' => 'inactive', 'updated_at' => now()]);

            return response()->json(['message' => 'Tiene citas registradas: se desactivó en lugar de eliminarse.', 'deactivated' => true]);
        }

        DB::table('rooms')->where('id', $room->id)->delete();

        return response()->json(['message' => 'Eliminado correctamente', 'deactivated' => false]);
    }
}
