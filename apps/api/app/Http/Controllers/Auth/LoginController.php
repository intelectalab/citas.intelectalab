<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'      => ['required', 'email'],
            'password'   => ['required', 'string'],
            'account_id' => ['nullable', 'uuid'],
        ]);

        $email = trim($credentials['email']);

        $staff = User::with('tenant.businessType')->where('email', $email)->get();

        $customers = Customer::with('tenant.businessType')
            ->whereRaw('lower(email) = ?', [mb_strtolower($email)])
            ->whereNotNull('password_hash')
            ->get();

        $matches = $staff->concat($customers)
            ->filter(fn (User|Customer $account) => Hash::check($credentials['password'], $account->getAuthPassword()))
            ->values();

        if (!empty($credentials['account_id'])) {
            $matches = $matches->where('id', $credentials['account_id'])->values();
        }

        if ($matches->isEmpty()) {
            return response()->json([
                'message' => 'Credenciales incorrectas'
            ], 401);
        }

        $enabled = fn (User|Customer $account) => $account instanceof Customer
            ? $account->portal_enabled !== false
            : $account->status === 'active';

        $allowed = $matches->filter(fn (User|Customer $account) => $enabled($account)
            && (is_null($account->tenant_id) || $account->tenant?->isActive()))->values();

        if ($allowed->isEmpty()) {
            $accountDisabled = $matches->contains(fn (User|Customer $account) => !$enabled($account));
            $isCustomer = $matches->every(fn (User|Customer $account) => $account instanceof Customer);

            return response()->json([
                'message' => match (true) {
                    $accountDisabled && $isCustomer => 'Tu acceso al portal está desactivado. Comunícate con la empresa.',
                    $accountDisabled                => 'Tu cuenta está desactivada. Comunícate con el administrador de tu empresa.',
                    $isCustomer                     => 'El portal de esta empresa no está disponible por ahora.',
                    default                         => 'El acceso de tu empresa está suspendido. Comunícate con soporte.',
                },
                'code' => $accountDisabled ? 'ACCOUNT_DISABLED' : 'TENANT_SUSPENDED',
            ], 403);
        }

        $matches = $allowed;

        if ($matches->count() > 1) {
            return response()->json([
                'requires_tenant_selection' => true,
                'accounts' => $matches->map(fn (User|Customer $account) => [
                    'account_id'    => $account->id,
                    'account_type'  => $account instanceof Customer ? 'customer' : 'staff',
                    'tenant_id'     => $account->tenant_id,
                    'tenant_name'   => $account->tenant?->name ?? 'Administración de la plataforma',
                    'business_type' => $account->tenant?->businessType?->short_name,
                    'color'         => $account->tenant?->businessType?->color,
                ]),
            ]);
        }

        $account = $matches->first();
        $token = $account->createToken('auth_token')->plainTextToken;

        if ($account instanceof Customer || \Illuminate\Support\Facades\Schema::hasColumn('users', 'last_login_at')) {
            $account->forceFill(['last_login_at' => now()])->saveQuietly();
        }

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => self::sessionUser($account),
        ]);
    }

    public static function sessionUser(User|Customer $account): array
    {
        $isCustomer = $account instanceof Customer;

        return [
            'id'           => $account->id,
            'tenant_id'    => $account->tenant_id,
            'first_name'   => $account->first_name,
            'last_name'    => $account->last_name,
            'email'        => $account->email,
            'phone'        => $account->phone,
            'is_owner'     => $isCustomer ? false : (bool) $account->is_owner,
            'account_type' => $isCustomer ? 'customer' : 'staff',
            'status'       => $isCustomer ? 'active' : $account->status,
            'must_change_password' => $isCustomer && $account->must_change_password,
        ];
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente']);
    }
}
