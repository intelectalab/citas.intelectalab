<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Status;

class StatusController extends Controller
{
    public function index(Request $request)
    {
        $currentUser = $request->user();

        if (!$currentUser || !$currentUser->tenant_id) {
            return response()->json([]);
        }

        $query = Status::with('module')
            ->where(function ($q) use ($currentUser) {
                $q->whereNull('tenant_id')
                  ->orWhere('tenant_id', $currentUser->tenant_id);
            });

        if ($request->filled('module')) {
            $moduleName = $request->input('module');
            $query->whereHas('module', function ($q) use ($moduleName) {
                $q->where('name', $moduleName);
            });
        }

        if ($request->filled('module_id')) {
            $query->where('module_id', $request->input('module_id'));
        }

        $statuses = $query->orderBy('sort_order', 'asc')->get();

        return response()->json($statuses);
    }

    public function store(Request $request)
    {
        $currentUser = $request->user();

        if (!$currentUser || !$currentUser->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'module_id' => 'required|exists:status_modules,id',
            'name' => 'required|string|max:100',
            'slug' => 'required|string|max:100',
            'color' => 'nullable|string|max:7',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ]);

        $status = Status::create([
            'tenant_id' => $currentUser->tenant_id,
            'module_id' => $validated['module_id'],
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'color' => $validated['color'] ?? '#022340',
            'is_default' => $validated['is_default'] ?? false,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return response()->json($status->load('module'), 201);
    }

    public function update(Request $request, $id)
    {
        $currentUser = $request->user();

        $status = Status::where('tenant_id', $currentUser->tenant_id)
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'slug' => 'sometimes|required|string|max:100',
            'color' => 'nullable|string|max:7',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ]);

        $status->update($validated);

        return response()->json($status->load('module'));
    }

    public function destroy(Request $request, $id)
    {
        $currentUser = $request->user();

        $status = Status::where('tenant_id', $currentUser->tenant_id)
            ->findOrFail($id);

        $status->delete();

        return response()->json(['message' => 'Status deleted successfully']);
    }
}