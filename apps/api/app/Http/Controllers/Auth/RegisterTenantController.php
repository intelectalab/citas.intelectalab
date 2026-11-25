<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Support\SignupGuard;
use App\Support\TenantProvisioner;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RegisterTenantController extends Controller
{
    public function start()
    {
        return response()->json([
            'form_token'         => SignupGuard::token(),
            'turnstile_site_key' => SignupGuard::siteKey(),
        ]);
    }

    public function register(Request $request)
    {
        if (!PlatformSetting::value('allow_tenant_signup')) {
            return response()->json([
                'message' => 'El registro de empresas nuevas está cerrado por ahora. Escríbenos para crear tu cuenta.',
                'code'    => 'SIGNUP_CLOSED',
            ], 403);
        }

        SignupGuard::check($request);

        $validated = $request->validate([
            'ruc'             => ['required', 'string', 'regex:/^(10|15|17|20)\d{9}$/', function ($attribute, $value, $fail) {
                if (is_string($value) && preg_match('/^(10|15|17|20)\d{9}$/', $value) && !\App\Http\Controllers\Api\InventoryController::validRuc($value)) {
                    $fail('Revisa el RUC: el último número no coincide con los demás. Puede que se haya escrito mal un número.');
                }
            }],
            'company_name'    => 'required|string|max:200', 
            'commercial_name' => 'nullable|string|max:200',
            'phone'           => ['nullable', 'string', 'max:30', function ($attribute, $value, $fail) {
                if (is_string($value) && trim($value) !== '' && !preg_match('/^9\d{8}$/', preg_replace('/^(\+?51)/', '', preg_replace('/[\s-]/', '', $value)))) {
                    $fail('Escribe un celular de 9 dígitos que empiece con 9.');
                }
            }],
            'address'         => 'nullable|string|max:255',
            'department'      => 'required|string|max:100',
            'district'        => 'nullable|string|max:100',
            'first_name'      => 'required|string|max:150',
            'last_name'       => 'required|string|max:150',
            'email'           => ['required', 'email', 'max:190', function ($attribute, $value, $fail) {
                if (is_string($value) && SignupGuard::ownerEmailTaken($value)) {
                    $fail(SignupGuard::takenMessage());
                }
            }],
            'business_type_id' => ['nullable', 'uuid', Rule::exists('business_types', 'id')->where('status', 'active')],
            'password'        => 'required|string|min:8',
            'ref'             => 'nullable|string|max:20',
            'plan_id'         => ['required', 'uuid', Rule::exists('saas_plans', 'id')->where('status', 'active')->where('is_public_catalog', true)],
            'coupon'          => 'nullable|string|max:30',
            'utm'             => 'nullable|array',
            'heard_from'      => ['nullable', Rule::in(array_keys(\App\Support\WebLeads::HEARD_FROM))],
            'sample_data'     => 'nullable|boolean',
            'accept_terms'    => 'sometimes|accepted',
        ], [
            'ruc.regex'             => 'El RUC tiene 11 números y empieza con 10, 15, 17 o 20.',
            'accept_terms.accepted' => 'Para crear tu cuenta acepta los términos y la política de privacidad.',
        ]);

        $coupon = trim((string) ($validated['coupon'] ?? ''));

        if ($coupon !== '') {
            \App\Support\PlanCoupons::check($coupon, $validated['plan_id']);
        }

        $result = \Illuminate\Support\Facades\DB::transaction(function () use ($validated, $coupon) {
            SignupGuard::lockEmail($validated['email']);

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

            if ($coupon !== '' && ($subscription = \App\Support\Subscriptions::latest($result['tenant']->id))) {
                \App\Support\PlanCoupons::redeem($subscription, $coupon, 'signup');
            }

            return $result;
        });

        \App\Support\WebLeads::fromSignup($result['tenant'], [
            'name'   => trim($validated['first_name'] . ' ' . $validated['last_name']),
            'email'  => $validated['email'],
            'phone'  => $validated['phone'] ?? null,
            'utm'    => $validated['utm'] ?? [],
            'coupon' => $coupon !== '' ? \App\Support\PlanCoupons::normalize($coupon) : null,
            'heard_from' => $validated['heard_from'] ?? null,
        ]);

        if (!empty($validated['sample_data']) && \App\Support\SampleData::ready()) {
            try {
                \App\Support\Rls::asTenant($result['tenant']->id, fn () => \App\Support\SampleData::create($result['tenant']->fresh(), $result['user']));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        \App\Support\Referrals::attach($result['tenant'], $validated['ref'] ?? null, $validated['email']);

        $token = $result['user']->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message'      => 'Tenant registrado con éxito',
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $result['user'],
            'installed'    => $result['installed'],
        ], 201);
    }
}