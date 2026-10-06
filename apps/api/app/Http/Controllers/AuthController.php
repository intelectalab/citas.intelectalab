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

        $user->load(['roles', 'tenant.businessType', 'tenant.settings']);

        // is_saas_admin se mantiene para todo el equipo de plataforma con permisos
        // completos; el soporte SaaS va aparte porque solo ve empresas y entra a su panel.
        $isSaasAdmin = $user->isSaasAdmin();
        $isSaasSupport = $user->isSaasSupport();

        // Sesión abierta por la plataforma para dar soporte: el token lo indica.
        $tokenName = (string) $user->currentAccessToken()?->name;
        $impersonator = str_starts_with($tokenName, 'impersonation:')
            ? \App\Models\User::find(substr($tokenName, strlen('impersonation:')))
            : null;
        $isCustomer = $user->isCustomer();
        $subscriptionFeatures = [];
        $planSlug = null;

        // El plan y sus módulos solo interesan al personal del tenant.
        if (!$isCustomer && $user->tenant_id) {
            $subscription = \App\Models\Subscription::with('plan')
                ->where('tenant_id', $user->tenant_id)
                ->whereIn('status', ['active', 'trialing'])
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
                ->first();

            if ($subscription && $subscription->plan) {
                $planSlug = $subscription->plan->slug;
                $subscriptionFeatures = is_string($subscription->plan->features)
                    ? json_decode($subscription->plan->features, true)
                    : $subscription->plan->features;
            }
        }

        $tenant = $user->tenant;
        $customer = $isCustomer ? $user->customer : null;

        return response()->json([
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'is_owner' => $user->is_owner,
            'is_saas_admin' => $isSaasAdmin,
            'is_saas_support' => $isSaasSupport,
            'is_tenant_admin' => $user->isTenantAdmin(),
            'impersonator' => $impersonator ? [
                'name' => trim($impersonator->first_name . ' ' . $impersonator->last_name),
            ] : null,
            'account_type' => $user->account_type,
            'roles' => $user->roles ? $user->roles->pluck('slug') : [],
            'plan_slug' => $planSlug,
            'features' => $subscriptionFeatures,
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                // El código de empresa lo reparte el personal; el cliente no lo necesita.
                'code' => $isCustomer ? null : $tenant->code,
                'business_type' => $tenant->businessType?->slug,
                'business_type_name' => $tenant->businessType?->short_name,
                'color' => $tenant->settings?->brand_color ?? $tenant->businessType?->color,
                'vocabulary' => $tenant->vocabulary(),
                'modules' => $tenant->modules(),
                'rules' => (object) $tenant->rules(),
                'dependents' => $tenant->dependents(),
            ] : null,
            'customer' => $customer ? [
                'id' => $customer->id,
                'code' => $customer->code,
            ] : null,
        ]);
    }
}
