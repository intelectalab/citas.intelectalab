<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RegisterCustomerController extends Controller
{
    /**
     * Una persona se registra como cliente con el código de empresa. Crea su
     * ficha, que es también su cuenta del portal: no hay fila en users.
     * El correo se valida solo dentro del tenant del código: que exista en
     * otra empresa no impide registrarse en esta.
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'tenant_code' => 'required|digits:' . Tenant::CODE_LENGTH,
            'first_name'  => 'required|string|max:100',
            'last_name'   => 'required|string|max:100',
            'email'       => 'required|email|max:190',
            'password'    => 'required|string|min:8|max:72',
            'phone'       => 'nullable|string|max:30',
        ]);

        $tenant = Tenant::with('settings')
            ->where('code', $validated['tenant_code'])
            ->where('status', 'active')
            ->first();

        if (!$tenant || $tenant->settings?->allow_customer_signup === false) {
            return response()->json([
                'message' => 'El código de empresa no es válido.',
                'errors'  => ['tenant_code' => ['El código de empresa no es válido.']],
            ], 422);
        }

        $email = trim($validated['email']);

        // Quien ya fue registrado por el personal tiene acceso con su correo y su
        // número de documento: no se crea una segunda ficha.
        $alreadyRegistered = Customer::where('tenant_id', $tenant->id)
            ->whereRaw('lower(email) = ?', [mb_strtolower($email)])
            ->exists();

        if ($alreadyRegistered) {
            return response()->json([
                'message' => 'Ya estás registrado en esta empresa. Ingresa con tu correo y tu contraseña; si es tu primer ingreso, tu contraseña es tu número de documento.',
            ], 409);
        }

        return DB::transaction(function () use ($validated, $tenant, $email) {
            $customer = Customer::create([
                'tenant_id'  => $tenant->id,
                'first_name' => $validated['first_name'],
                'last_name'  => $validated['last_name'],
                'email'      => $email,
                'phone'      => $validated['phone'] ?? null,
            ]);

            // Eligió su contraseña al registrarse: no tiene que cambiarla.
            $customer->forceFill([
                'password_hash'        => Hash::make($validated['password']),
                'must_change_password' => false,
                'password_changed_at'  => now(),
                'last_login_at'        => now(),
            ])->save();

            $token = $customer->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message'      => 'Cuenta creada con éxito',
                'access_token' => $token,
                'token_type'   => 'Bearer',
                'user'         => LoginController::sessionUser($customer),
            ], 201);
        });
    }
}
