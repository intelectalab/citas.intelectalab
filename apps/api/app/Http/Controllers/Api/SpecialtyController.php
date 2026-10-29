<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Specialty;
use App\Support\Audit;
use Illuminate\Support\Str;

class SpecialtyController extends Controller
{
    public function index(Request $request)
    {
        $currentUser = $request->user();

        if (!$currentUser || !$currentUser->tenant_id) {
            return response()->json([]);
        }

        $query = Specialty::where('tenant_id', $currentUser->tenant_id);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        return response()->json($query->orderBy('name', 'asc')->get());
    }

    public function store(Request $request)
    {
        $currentUser = $request->user();

        if (!$currentUser || !$currentUser->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:255',
        ]);

        $specialty = Specialty::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $currentUser->tenant_id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json($specialty, 201);
    }

    public function show(Request $request, $id)
    {
        $currentUser = $request->user();

        $specialty = Specialty::where('tenant_id', $currentUser->tenant_id)
            ->findOrFail($id);

        return response()->json($specialty);
    }

    public function update(Request $request, $id)
    {
        $currentUser = $request->user();

        $specialty = Specialty::where('tenant_id', $currentUser->tenant_id)
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'description' => 'nullable|string|max:255',
        ]);

        $specialty->update($validated);

        return response()->json($specialty);
    }

    public function destroy(Request $request, $id)
    {
        $currentUser = $request->user();

        $specialty = Specialty::where('tenant_id', $currentUser->tenant_id)
            ->findOrFail($id);

        $specialty->delete();
        Audit::log($request, 'specialty.deleted', [
            'tenant'       => $currentUser->tenant_id,
            'entity_type'  => 'specialty',
            'entity_id'    => $specialty->id,
            'entity_label' => $specialty->name,
        ]);

        return response()->json(['message' => 'Specialty deleted successfully']);
    }
}