<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Subscription;

class SubscriptionController extends Controller
{
    public function subscribe(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|uuid|exists:saas_plans,id',
            'billing_cycle' => 'required|in:monthly,yearly',
        ]);

        $user = $request->user();

        if (!$user->tenant_id && !$user->tenant) {
            return response()->json([
                'message' => 'El usuario no tiene una empresa (tenant) asignada.'
            ], 422);
        }

        $tenantId = $user->tenant_id ?? $user->tenant->id;

        $endsAt = $request->billing_cycle === 'yearly' ? now()->addYear() : now()->addMonth();

        $subscription = Subscription::updateOrCreate(
            ['tenant_id' => $tenantId],
            [
                'plan_id' => $request->plan_id,
                'status' => 'active',
                'billing_cycle' => $request->billing_cycle,
                'starts_at' => now(),
                'ends_at' => $endsAt,
                'trial_ends_at' => null, 
            ]
        );

        return response()->json([
            'message' => 'Suscripción actualizada exitosamente',
            'subscription' => $subscription
        ], 200);
    }
}