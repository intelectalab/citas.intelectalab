<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Controller;
use App\Models\SaaSPlan;
use App\Models\Subscription;
use App\Support\Subscriptions;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function subscribe(Request $request)
    {
        $user = $request->user();

        abort_unless($user->isTenantAdmin(), 403, 'Solo el administrador de la empresa puede elegir el plan.');
        abort_unless($user->tenant_id, 422, 'El usuario no tiene una empresa asignada.');

        $validated = $request->validate([
            'plan_id'       => ['required', 'uuid', Rule::exists('saas_plans', 'id')->where('status', 'active')->where('is_public_catalog', true)],
            'billing_cycle' => 'required|in:monthly,yearly',
        ]);

        $plan = SaaSPlan::findOrFail($validated['plan_id']);
        $subscription = Subscriptions::latest($user->tenant_id) ?? Subscription::create([
            'id'            => (string) Str::uuid(),
            'tenant_id'     => $user->tenant_id,
            'plan_id'       => $plan->id,
            'status'        => 'canceled',
            'billing_cycle' => $validated['billing_cycle'],
            'starts_at'     => now(),
            'ends_at'       => now(),
            'canceled_at'   => now(),
        ])->load('plan');

        return response()->json(BillingController::changePlan($request, $subscription, $plan, $validated['billing_cycle']));
    }
}
