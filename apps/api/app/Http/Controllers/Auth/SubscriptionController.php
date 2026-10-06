<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Subscription;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function subscribe(Request $request)
    {
        $user = $request->user();

        // Solo el administrador de la empresa elige plan, y solo entre los del
        // catálogo público. Los planes privados los asigna la plataforma.
        abort_unless($user->isTenantAdmin(), 403, 'Solo el administrador de la empresa puede elegir el plan.');

        $request->validate([
            'plan_id' => ['required', 'uuid', Rule::exists('saas_plans', 'id')->where('status', 'active')->where('is_public_catalog', true)],
            'billing_cycle' => 'required|in:monthly,yearly',
        ]);

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