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

        if ($user instanceof \App\Models\Customer) {
            return $this->customer($user);
        }

        $user->load(['roles', 'tenant.businessType', 'tenant.settings']);

        $isSaasAdmin = $user->isSaasAdmin();
        $isSaasSupport = $user->isSaasSupport();

        $tokenName = (string) $user->currentAccessToken()?->name;
        $impersonator = str_starts_with($tokenName, 'impersonation:')
            ? \App\Models\User::find(substr($tokenName, strlen('impersonation:')))
            : null;
        $subscriptionFeatures = [];
        $planSlug = null;

        if ($user->tenant_id) {
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
            'account_type' => 'staff',
            'idle_minutes' => $tenant ? (int) \App\Support\AppointmentFlow::rule($tenant, 'session.idle_minutes') : \App\Support\AppointmentFlow::RULE_DEFAULTS['session.idle_minutes'],
            'roles' => $user->roles ? $user->roles->pluck('slug') : [],
            'permissions' => \App\Support\Access::of($user),
            'role_name' => $user->tenant_id ? $user->roles->first()?->name : null,
            'plan_slug' => $planSlug,
            'features' => $subscriptionFeatures,
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'code' => $tenant->code,
                'business_type' => $tenant->businessType?->slug,
                'business_type_name' => $tenant->businessType?->short_name,
                'color' => $tenant->settings?->brand_color ?? $tenant->businessType?->color,
                'vocabulary' => $tenant->vocabulary(),
                'modules' => $tenant->modules(),
                'rules' => (object) $tenant->rules(),
                'dependents' => $tenant->dependents(),
                'appointment_statuses' => \App\Support\AppointmentFlow::forTenant($tenant),
                'currency' => \App\Support\Currency::present(\App\Support\Currency::of($tenant)),
            ] : null,
            'currencies' => \App\Support\Currency::catalog(),
            'customer' => null,
        ]);
    }

    private function customer(\App\Models\Customer $customer)
    {
        $customer->load(['tenant.businessType', 'tenant.settings']);
        $tenant = $customer->tenant;

        return response()->json([
            'id' => $customer->id,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'is_owner' => false,
            'is_saas_admin' => false,
            'is_saas_support' => false,
            'is_tenant_admin' => false,
            'impersonator' => null,
            'account_type' => 'customer',
            'idle_minutes' => $tenant ? ((int) \App\Support\AppointmentFlow::rule($tenant, 'session.portal_idle_minutes') ?: null) : null,
            'must_change_password' => (bool) $customer->must_change_password,
            'roles' => [],
            'permissions' => [],
            'plan_slug' => null,
            'features' => [],
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'code' => null,
                'business_type' => $tenant->businessType?->slug,
                'business_type_name' => $tenant->businessType?->short_name,
                'color' => $tenant->settings?->brand_color ?? $tenant->businessType?->color,
                'vocabulary' => $tenant->vocabulary(),
                'modules' => $tenant->modules(),
                'rules' => (object) $tenant->rules(),
                'dependents' => $tenant->dependents(),
                'appointment_statuses' => \App\Support\AppointmentFlow::forTenant($tenant),
                'currency' => \App\Support\Currency::present(\App\Support\Currency::of($tenant)),
            ] : null,
            'currencies' => \App\Support\Currency::catalog(),
            'customer' => [
                'id' => $customer->id,
                'code' => $customer->code,
            ],
        ]);
    }
}
