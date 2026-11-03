<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Support\TenantProvisioner;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RegisterTenantController extends Controller
{
    public function register(Request $request)
    {
        if (!PlatformSetting::value('allow_tenant_signup')) {
            return response()->json([
                'message' => 'El registro de empresas nuevas está cerrado por ahora. Escríbenos para crear tu cuenta.',
                'code'    => 'SIGNUP_CLOSED',
            ], 403);
        }

        $validated = $request->validate([
            'ruc'             => 'required|string|max:30',
            'company_name'    => 'required|string|max:200', 
            'commercial_name' => 'nullable|string|max:200',
            'phone'           => 'nullable|string|max:30',
            'address'         => 'nullable|string|max:255',
            'department'      => 'required|string|max:100',
            'district'        => 'nullable|string|max:100',
            'first_name'      => 'required|string|max:150',
            'last_name'       => 'required|string|max:150',
            'email'           => 'required|email|max:190',
            'business_type_id' => ['nullable', 'uuid', Rule::exists('business_types', 'id')->where('status', 'active')],
            'password'        => 'required|string|min:8',
            'plan_id'         => ['required', 'uuid', Rule::exists('saas_plans', 'id')->where('status', 'active')->where('is_public_catalog', true)],
        ]);

        $result = TenantProvisioner::provision([
            'name'             => $validated['company_name'],
            'legal_name'       => $validated['company_name'],
            'trade_name'       => $validated['commercial_name'] ?? $validated['company_name'],
            'tax_id'           => $validated['ruc'],
            'phone'            => $validated['phone'] ?? null,
            'address'          => $validated['address'] ?? null,
            'department'       => $validated['department'],
            'district'         => $validated['district'] ?? null,
            'business_type_id' => $validated['business_type_id'] ?? null,
            'plan_id'          => $validated['plan_id'],
            'start_trial'      => true,
            'first_name'       => $validated['first_name'],
            'last_name'        => $validated['last_name'],
            'email'            => $validated['email'],
            'password'         => $validated['password'],
        ]);

        $token = $result['user']->createToken('auth_token')->plainTextToken;
        $cardUrl = null;
        $subscription = \App\Support\Subscriptions::latest($result['tenant']->id);

        if ($subscription && \App\Support\PlanBilling::enabled() && \App\Support\Subscriptions::price($subscription->plan, $subscription->billing_cycle) > 0) {
            $subscription->update(['requires_card' => true]);

            try {
                $cardUrl = \App\Support\PlanBilling::cardUrl($subscription->fresh('plan'), $result['user']);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'message'      => 'Tenant registrado con éxito',
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $result['user'],
            'installed'    => $result['installed'],
            'card_url'     => $cardUrl,
        ], 201);
    }
}