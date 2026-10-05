<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\SaaSPlan;
use Illuminate\Http\Request;


class AuthController extends Controller
{
    public function user(Request $request)
    {
        $user = $request->user();
        
        $user->load(['roles', 'tenant']);

        $isSaasAdmin = is_null($user->tenant_id);
        $subscriptionFeatures = [];
        $planSlug = null;

        if (!$isSaasAdmin && $user->tenant_id) {
            $subscription = \App\Models\Subscription::with('plan')
                ->where('tenant_id', $user->tenant_id)
                ->whereIn('status', ['active', 'trialing'])
                ->first();

            if ($subscription && $subscription->plan) {
                $planSlug = $subscription->plan->slug;
                $subscriptionFeatures = is_string($subscription->plan->features) 
                    ? json_decode($subscription->plan->features, true) 
                    : $subscription->plan->features;
            }
        }

        return response()->json([
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'is_owner' => $user->is_owner,
            'is_saas_admin' => $isSaasAdmin,
            'roles' => $user->roles ? $user->roles->pluck('slug') : [],
            'plan_slug' => $planSlug,
            'features' => $subscriptionFeatures,
        ]);
    }
}