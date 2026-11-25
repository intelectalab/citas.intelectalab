<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ServiceCategory;
use App\Support\Audit;
use App\Support\CatalogExtras;
use Illuminate\Support\Facades\DB;

class ServiceCategoryController extends Controller
{
    private const COLOR = 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/';

    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $counts = DB::table('services')->where('tenant_id', $tenantId)->whereNotNull('category_id')->selectRaw('category_id, count(*) as total')
            ->groupBy('category_id')->pluck('total', 'category_id');

        return response()->json(
            ServiceCategory::where('tenant_id', $tenantId)
                ->orderBy('name', 'asc')
                ->get()
                ->map(fn (ServiceCategory $category) => $category->toArray() + [
                    'color'          => CatalogExtras::categoryColor() ? $category->color : null,
                    'services_count' => (int) ($counts[$category->id] ?? 0),
                ])
        );
    }

    private function nameTaken(string $tenantId, string $name, ?string $ignoreId = null): bool
    {
        return ServiceCategory::where('tenant_id', $tenantId)
            ->whereRaw('lower(trim(name)) = ?', [mb_strtolower(trim($name))])
            ->when($ignoreId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->exists();
    }

    public function store(Request $request)
    {
        $currentUser = $request->user();

        $validated = $request->validate([
            'name'  => 'required|string|max:120',
            'color' => self::COLOR,
        ], ['color.regex' => 'Elige un color de la lista.']);

        if ($this->nameTaken($currentUser->tenant_id, $validated['name'])) {
            return response()->json(['message' => 'Ya existe una categoría con este nombre.'], 422);
        }

        $category = ServiceCategory::create([
            'tenant_id' => $currentUser->tenant_id,
            'name'      => trim($validated['name']),
        ] + (CatalogExtras::categoryColor() ? ['color' => $validated['color'] ?? null] : []));

        Audit::log($request, 'service_category.created', [
            'tenant'       => $currentUser->tenant_id,
            'entity_type'  => 'service_category',
            'entity_id'    => $category->id,
            'entity_label' => $category->name,
            'after'        => array_filter(['name' => $category->name, 'color' => $category->color ?? null]),
        ]);

        return response()->json([
            'id'    => $category->id,
            'name'  => $category->name,
            'color' => $category->color ?? null,
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        $currentUser = $request->user();
        $category = ServiceCategory::where('tenant_id', $currentUser->tenant_id)->findOrFail($id);

        $validated = $request->validate([
            'name'  => 'required|string|max:120',
            'color' => self::COLOR,
        ], ['color.regex' => 'Elige un color de la lista.']);

        if ($this->nameTaken($currentUser->tenant_id, $validated['name'], $category->id)) {
            return response()->json(['message' => 'Ya existe una categoría con este nombre.'], 422);
        }

        $before = ['name' => $category->name, 'color' => $category->color ?? null];
        $category->update(['name' => trim($validated['name'])] + (CatalogExtras::categoryColor() ? ['color' => $validated['color'] ?? null] : []));
        [$from, $to] = Audit::changed($before, ['name' => $category->name, 'color' => $category->color ?? null]);

        if ($to) {
            Audit::log($request, 'service_category.updated', [
                'tenant'       => $currentUser->tenant_id,
                'entity_type'  => 'service_category',
                'entity_id'    => $category->id,
                'entity_label' => $category->name,
                'before'       => $from ?: null,
                'after'        => $to,
            ]);
        }

        return response()->json(['id' => $category->id, 'name' => $category->name, 'color' => $category->color ?? null]);
    }
}
