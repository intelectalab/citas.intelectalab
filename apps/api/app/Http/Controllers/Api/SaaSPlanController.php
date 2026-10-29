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

    if ($request->filled('search')) {
        $search = $request->input('search');
        $query->where(function($q) use ($search) {
            \App\Support\Search::contains($q, 'name', (string) $search);
            \App\Support\Search::orContains($q, 'slug', (string) $search);
            \App\Support\Search::orContains($q, "coalesce(code_internal, '')", (string) $search);
        });
    }

    if ($request->filled('status')) {
        $query->where('status', $request->input('status'));
    }

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
            'currency' => ['required', 'string', 'max:10', \App\Support\Currency::ready() ? \Illuminate\Validation\Rule::in(\App\Support\Currency::all()->where('is_active', true)->pluck('code')->all()) : 'string'],
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
        ], ['currency.in' => 'Esa moneda no está disponible.']);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $plan = SaaSPlan::create($validated);
        \App\Support\Audit::log($request, 'plan.created', ['entity_type' => 'plan', 'entity_id' => $plan->id, 'entity_label' => $plan->name]);

        return response()->json([
            'message' => 'Plan SaaS creado correctamente',
            'data' => $plan
        ], 201);
    }

    public function show($id)
    {
        $plan = SaaSPlan::findOrFail($id);

        $tenants = \Illuminate\Support\Facades\DB::table('subscriptions as s')
            ->join('tenants as t', 't.id', '=', 's.tenant_id')
            ->where('s.plan_id', $plan->id)
            ->whereIn('s.status', ['active', 'trialing'])
            ->whereNull('t.deleted_at')
            ->orderBy('t.name')
            ->get(['t.id', 't.name', 't.status', 's.status as subscription_status', 's.billing_cycle'])
            ->map(fn ($row) => [
                'id'                  => $row->id,
                'name'                => $row->name,
                'status'              => $row->status,
                'subscription_status' => $row->subscription_status,
                'billing_cycle'       => $row->billing_cycle,
            ])
            ->values();

        return response()->json($plan->toArray() + ['tenants' => $tenants]);
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
            'currency' => ['sometimes', 'required', 'string', 'max:10', \App\Support\Currency::ready() ? \Illuminate\Validation\Rule::in(\App\Support\Currency::all()->pluck('code')->all()) : 'string'],
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
        ], ['currency.in' => 'Esa moneda no está disponible.']);

        if (isset($validated['name']) && empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        [$was, $now] = \App\Support\Audit::changed($plan->only(array_keys($validated)), $validated);
        $plan->update($validated);

        if ($now) {
            \App\Support\Audit::log($request, 'plan.updated', ['entity_type' => 'plan', 'entity_id' => $plan->id, 'entity_label' => $plan->name, 'before' => $was, 'after' => $now]);
        }

        return response()->json([
            'message' => 'Plan SaaS actualizado correctamente',
            'data' => $plan
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $plan = SaaSPlan::findOrFail($id);
        
        if ($plan->subscriptions()->count() > 0) {
            return response()->json([
                'message' => 'No se puede eliminar el plan porque tiene clientes suscritos activos.'
            ], 422);
        }

        $plan->delete();
        \App\Support\Audit::log($request, 'plan.deleted', ['entity_type' => 'plan', 'entity_id' => $plan->id, 'entity_label' => $plan->name]);

        return response()->json([
            'message' => 'Plan SaaS eliminado correctamente'
        ]);
    }
}