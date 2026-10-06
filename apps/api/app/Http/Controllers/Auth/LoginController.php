<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    /**
     * Un mismo correo puede tener una cuenta en varios tenants, cada una con su
     * contraseña. Se prueba la contraseña contra todas las cuentas del correo:
     *  - ninguna coincide: credenciales incorrectas;
     *  - una coincide: se inicia sesión en ese tenant;
     *  - varias coinciden: se devuelven esas empresas para que el usuario elija
     *    y el cliente repite la llamada con `account_id`.
     * Solo se listan empresas donde la contraseña ya fue validada, así no se
     * revela a terceros en qué empresas está registrado un correo.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'      => ['required', 'email'],
            'password'   => ['required', 'string'],
            'account_id' => ['nullable', 'uuid'],
        ]);

        $matches = User::with('tenant.businessType')
            ->where('email', $credentials['email'])
            ->get()
            ->filter(fn (User $user) => Hash::check($credentials['password'], $user->password_hash))
            ->values();

        if (!empty($credentials['account_id'])) {
            $matches = $matches->where('id', $credentials['account_id'])->values();
        }

        if ($matches->isEmpty()) {
            return response()->json([
                'message' => 'Credenciales incorrectas'
            ], 401);
        }

        // Cuentas desactivadas y empresas suspendidas no entran. Se revisa después
        // de validar la contraseña, así el aviso solo lo ve el dueño de la cuenta.
        $allowed = $matches->filter(fn (User $user) => $user->status === 'active'
            && (is_null($user->tenant_id) || $user->tenant?->isActive()))->values();

        if ($allowed->isEmpty()) {
            $accountDisabled = $matches->contains(fn (User $user) => $user->status !== 'active');

            return response()->json([
                'message' => $accountDisabled
                    ? 'Tu cuenta está desactivada. Comunícate con el administrador de tu empresa.'
                    : 'El acceso de tu empresa está suspendido. Comunícate con soporte.',
                'code' => $accountDisabled ? 'ACCOUNT_DISABLED' : 'TENANT_SUSPENDED',
            ], 403);
        }

        $matches = $allowed;

        if ($matches->count() > 1) {
            return response()->json([
                'requires_tenant_selection' => true,
                'accounts' => $matches->map(fn (User $user) => [
                    'account_id'    => $user->id,
                    'account_type'  => $user->account_type,
                    'tenant_id'     => $user->tenant_id,
                    'tenant_name'   => $user->tenant?->name ?? 'Administración de la plataforma',
                    'business_type' => $user->tenant?->businessType?->short_name,
                    'color'         => $user->tenant?->businessType?->color,
                ]),
            ]);
        }

        $user = $matches->first();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user->withoutRelations(),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente']);
    }
}
