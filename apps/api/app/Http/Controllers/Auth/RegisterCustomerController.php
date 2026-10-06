<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RegisterCustomerController extends Controller
{
    /**
     * Registro de una cuenta de cliente con el código de empresa.
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
            'password'    => 'required|string|min:8',
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

        $alreadyRegistered = User::where('tenant_id', $tenant->id)->where('email', $validated['email'])->exists()
            || Customer::where('tenant_id', $tenant->id)->where('email', $validated['email'])->exists();

        if ($alreadyRegistered) {
            return response()->json([
                'message' => 'Ya tienes una cuenta registrada en esta empresa. Ingresa con tus credenciales.',
            ], 409);
        }

        return DB::transaction(function () use ($validated, $tenant) {
            $user = User::create([
                'id'            => (string) Str::uuid(),
                'tenant_id'     => $tenant->id,
                'first_name'    => $validated['first_name'],
                'last_name'     => $validated['last_name'],
                'email'         => $validated['email'],
                'password_hash' => Hash::make($validated['password']),
                'phone'         => $validated['phone'] ?? null,
                'is_owner'      => false,
                'account_type'  => User::TYPE_CUSTOMER,
                'status'        => 'active',
            ]);

            Customer::create([
                'tenant_id'  => $tenant->id,
                'user_id'    => $user->id,
                'first_name' => $validated['first_name'],
                'last_name'  => $validated['last_name'],
                'email'      => $validated['email'],
                'phone'      => $validated['phone'] ?? null,
            ]);

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message'      => 'Cuenta creada con éxito',
                'access_token' => $token,
                'token_type'   => 'Bearer',
                'user'         => $user,
            ], 201);
        });
    }
}
