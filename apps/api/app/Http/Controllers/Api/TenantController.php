<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TenantController extends Controller
{

    public function index(Request $request)
{
    $query = Tenant::with(['subscription.plan', 'users']);

    // Filtro por término de búsqueda (nombre o slug)
    if ($request->has('search') && !empty($request->input('search'))) {
        $search = $request->input('search');
        $query->where(function($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('slug', 'like', "%{$search}%");
        });
    }

    // NUEVO: Filtro por estado (active / inactive)
    if ($request->has('status') && !empty($request->input('status'))) {
        $query->where('status', $request->input('status'));
    }

    $tenants = $query->latest()->get();

    return response()->json($tenants);
}

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'slug' => 'nullable|string|max:150|unique:tenants,slug',
            'timezone' => 'required|string|max:100',
            'status' => 'required|string|max:30',
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $tenant = Tenant::create($validated);

        return response()->json([
            'message' => 'Tenant creado correctamente',
            'data' => $tenant
        ], 201);
    }

    public function show($id)
    {
        $tenant = Tenant::with(['subscription.plan', 'users'])->findOrFail($id);

        return response()->json($tenant);
    }

    public function update(Request $request, $id)
    {
        $tenant = Tenant::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'slug' => 'sometimes|required|string|max:150|unique:tenants,slug,' . $tenant->id,
            'timezone' => 'sometimes|required|string|max:100',
            'status' => 'sometimes|required|string|max:30',
        ]);

        $tenant->update($validated);

        return response()->json([
            'message' => 'Tenant actualizado correctamente',
            'data' => $tenant
        ]);
    }

    public function destroy($id)
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->delete();

        return response()->json([
            'message' => 'Tenant eliminado correctamente'
        ]);
    }
}