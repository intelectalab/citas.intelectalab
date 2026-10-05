<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Service;
use Illuminate\Support\Str;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        $currentUser = $request->user();

        $query = Service::with(['category', 'status'])
        ->where('tenant_id', $currentUser->tenant_id);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('status_id')) {
            $query->where('status_id', $request->status_id);
        }

        return response()->json($query->orderBy('created_at', 'desc')->get());
    }

    public function store(Request $request)
    {
        $currentUser = $request->user();

        $validated = $request->validate([
            'category_id' => 'nullable|exists:service_categories,id',
            'name' => 'required|string|max:150',
            'duration_minutes' => 'required|integer|min:1',
            'buffer_before_minutes' => 'nullable|integer|min:0',
            'buffer_after_minutes' => 'nullable|integer|min:0',
            'price' => 'required|numeric|min:0',
            'status_id' => 'nullable|exists:statuses,id',
        ]);

        if (empty($validated['status_id'])) {
            $defaultStatus = Status::whereHas('module', function($q) {
                $q->where('name', 'services');
            })->where('is_default', true)->first();

            $validated['status_id'] = $defaultStatus ? $defaultStatus->id : null;
        }

        $validated['id'] = (string) Str::uuid();
        $validated['tenant_id'] = $currentUser->tenant_id;
        $validated['buffer_before_minutes'] = $validated['buffer_before_minutes'] ?? 0;
        $validated['buffer_after_minutes'] = $validated['buffer_after_minutes'] ?? 0;

        $service = Service::create($validated);

        return response()->json($service->load('category'), 201);
    }

    public function update(Request $request, $id)
    {
        $currentUser = $request->user();
        $service = Service::where('tenant_id', $currentUser->tenant_id)->findOrFail($id);

        $validated = $request->validate([
            'category_id' => 'nullable|exists:service_categories,id',
            'name' => 'required|string|max:150',
            'duration_minutes' => 'required|integer|min:1',
            'buffer_before_minutes' => 'nullable|integer|min:0',
            'buffer_after_minutes' => 'nullable|integer|min:0',
            'price' => 'required|numeric|min:0',
            'status_id' => 'nullable|exists:statuses,id',
        ]);

        $service->update($validated);

        return response()->json($service->load(['category', 'status']));
    }

    public function destroy(Request $request, $id)
    {
        $currentUser = $request->user();
        
        $service = Service::where('tenant_id', $currentUser->tenant_id)->findOrFail($id);
        $service->delete();

        return response()->json(['message' => 'Servicio eliminado correctamente']);
    }
}