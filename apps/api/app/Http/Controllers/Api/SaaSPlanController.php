<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SaaSPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SaaSPlanController extends Controller
{

    public function index(Request $request)
{
    $query = SaaSPlan::withCount(['subscriptions as active_subscriptions_count' => function ($q) {
        $q->whereIn('status', ['active', 'trialing']);
    }]);

    // 1. Búsqueda por texto
    if ($request->filled('search')) {
        $search = $request->input('search');
        $query->where(function($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('slug', 'like', "%{$search}%")
              ->orWhere('code_internal', 'like', "%{$search}%");
        });
    }

    // 2. Filtro por estado (Cambiado de 'filter_status' a 'status' para que coincida con el frontend)
    if ($request->filled('status')) {
        $query->where('status', $request->input('status'));
    }

    // 3. Filtro por periodo de prueba (has_trial)
    if ($request->filled('has_trial')) {
        $hasTrial = filter_var($request->input('has_trial'), FILTER_VALIDATE_BOOLEAN);
        $query->where('trial_config->has_trial', $hasTrial);
    }

    $plans = $query->orderBy('presentation_order', 'asc')->get();

    $stats = [
        'total_active_plans' => SaaSPlan::where('status', 'active')->count(),
        'total_tenants' => \App\Models\Tenant::where('status', 'active')->count(),
        'total_trials' => \DB::table('subscriptions')->where('status', 'trialing')->count(),
    ];

    return response()->json([
        'data' => $plans,
        'stats' => $stats
    ]);
}

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'slug' => 'nullable|string|max:150|unique:saas_plans,slug',
            'code_internal' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'price_monthly' => 'required|numeric|min:0',
            'price_yearly' => 'required|numeric|min:0',
            'currency' => 'required|string|max:10',
            'trial_config' => 'nullable|array',
            'subscription_config' => 'nullable|array',
            'modules' => 'nullable|array',
            'limits' => 'nullable|array',
            'consumption' => 'nullable|array',
            'support' => 'nullable|array',
            'features' => 'nullable|array',
            'status' => 'required|string|max:30',
            'presentation_order' => 'integer|min:0',
            'is_public_catalog' => 'boolean',
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $plan = SaaSPlan::create($validated);

        return response()->json([
            'message' => 'Plan SaaS creado correctamente',
            'data' => $plan
        ], 201);
    }

    public function show($id)
    {
        $plan = SaaSPlan::with('subscriptions')->findOrFail($id);

        return response()->json($plan);
    }

    public function update(Request $request, $id)
    {
        $plan = SaaSPlan::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'slug' => 'sometimes|required|string|max:150|unique:saas_plans,slug,' . $plan->id,
            'code_internal' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'price_monthly' => 'sometimes|required|numeric|min:0',
            'price_yearly' => 'sometimes|required|numeric|min:0',
            'currency' => 'sometimes|required|string|max:10',
            'trial_config' => 'nullable|array',
            'subscription_config' => 'nullable|array',
            'modules' => 'nullable|array',
            'limits' => 'nullable|array',
            'consumption' => 'nullable|array',
            'support' => 'nullable|array',
            'features' => 'nullable|array',
            'status' => 'sometimes|required|string|max:30',
            'presentation_order' => 'integer|min:0',
            'is_public_catalog' => 'boolean',
        ]);

        if (isset($validated['name']) && empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $plan->update($validated);

        return response()->json([
            'message' => 'Plan SaaS actualizado correctamente',
            'data' => $plan
        ]);
    }

    public function destroy($id)
    {
        $plan = SaaSPlan::findOrFail($id);
        
        if ($plan->subscriptions()->count() > 0) {
            return response()->json([
                'message' => 'No se puede eliminar el plan porque tiene clientes suscritos activos.'
            ], 422);
        }

        $plan->delete();

        return response()->json([
            'message' => 'Plan SaaS eliminado correctamente'
        ]);
    }
}