<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ServiceCategory;
use Illuminate\Support\Str;

class ServiceCategoryController extends Controller
{
    public function index(Request $request)
    {
        $currentUser = $request->user();

        return response()->json(
            ServiceCategory::where('tenant_id', $currentUser->tenant_id)
                ->orderBy('name', 'asc')
                ->get()
        );
    }

    public function store(Request $request)
{
    try {
        $currentUser = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:120',
        ]);

        $exists = ServiceCategory::where('tenant_id', $currentUser->tenant_id)
            ->where('name', $request->name)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Ya existe una categoría con este nombre.'
            ], 422);
        }

        $category = ServiceCategory::create([
            'tenant_id' => $currentUser->tenant_id,
            'name' => $validated['name'],
        ]);

        return response()->json([
            'id' => $category->id,
            'name' => $category->name,
        ], 201);

    } catch (\Exception $e) {
        return response()->json([
            'error_message' => $e->getMessage(),
            'error_file'    => $e->getFile(),
            'error_line'    => $e->getLine(),
            'trace'         => $e->getTraceAsString()
        ], 500);
    }
}
}