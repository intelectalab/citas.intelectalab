<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    /**
     * Un solo ingreso para todos: el personal está en users y los clientes en
     * customers (su ficha es su cuenta). Un mismo correo puede tener cuenta en
     * varias empresas, cada una con su contraseña. Se prueba la contraseña
     * contra todas las cuentas del correo:
     *  - ninguna coincide: credenciales incorrectas;
     *  - una coincide: se inicia sesión en esa cuenta;
     *  - varias coinciden: se devuelven esas empresas para que la persona elija
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

        $email = trim($credentials['email']);

        $staff = User::with('tenant.businessType')->where('email', $email)->get();

        // Clientes con acceso al portal. El personal pudo escribir el correo con mayúsculas.
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

        // Cuentas desactivadas y empresas suspendidas no entran. Se revisa después
        // de validar la contraseña, así el aviso solo lo ve el dueño de la cuenta.
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

        if ($account instanceof Customer) {
            $account->forceFill(['last_login_at' => now()])->saveQuietly();
        }

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => self::sessionUser($account),
        ]);
    }

    /**
     * Lo mínimo que la pantalla de ingreso necesita para saber a dónde llevar
     * a la persona. El detalle lo entrega /api/user.
     */
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
