<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantContact;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RegisterTenantController extends Controller
{
    public function register(Request $request)
    {
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
            'email'           => 'required|email|unique:users,email',
            'password'        => 'required|string|min:8',
            'plan_id'         => 'required|uuid|exists:saas_plans,id',
        ]);

        return DB::transaction(function () use ($validated) {
            $tenantId = (string) Str::uuid();
            $slug = Str::slug($validated['company_name']) . '-' . Str::random(4);
            
            $tenant = Tenant::create([
                'id'       => $tenantId,
                'name'     => $validated['company_name'],
                'slug'     => $slug,
                'timezone' => 'America/Lima',
                'status'   => 'active',
            ]);

            $company = Company::create([
                'id'          => (string) Str::uuid(),
                'tenant_id'   => $tenantId,
                'legal_name'  => $validated['company_name'],
                'trade_name'  => $validated['commercial_name'] ?? $validated['company_name'],
                'tax_id'      => $validated['ruc'],
                'phone'       => $validated['phone'],
                'address'     => $validated['address'],
                'department'  => $validated['department'],
                'district'    => $validated['district'],
            ]);

            TenantContact::create([
                'id'         => (string) Str::uuid(),
                'tenant_id'  => $tenantId,
                'first_name' => $validated['first_name'],
                'last_name'  => $validated['last_name'],
                'email'      => $validated['email'],
                'phone'      => $validated['phone'],
                'position'   => 'Dueño',
            ]);

            Branch::create([
                'id'         => (string) Str::uuid(),
                'tenant_id'  => $tenantId,
                'company_id' => $company->id,
                'name'       => 'Sede Principal',
                'timezone'   => 'America/Lima',
                'status'     => 'active',
            ]);

            $userId = (string) Str::uuid();
            $user = User::create([
                'id'            => $userId,
                'tenant_id'     => $tenantId,
                'first_name'    => $validated['first_name'],
                'last_name'     => $validated['last_name'],
                'email'         => $validated['email'],
                'password_hash' => Hash::make($validated['password']),
                'is_owner'      => true,
                'status'        => 'active',
            ]);

            $ownerRole = Role::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'slug'      => 'owner',
                ],
                [
                    'id'          => (string) Str::uuid(),
                    'name'        => 'Dueño',
                    'description' => 'Administrador principal del negocio',
                    'is_system'   => true,
                ]
            );

            DB::table('user_roles')->insert([
                'tenant_id' => $tenantId,
                'user_id'   => $userId,
                'role_id'   => $ownerRole->id,
            ]);

            Subscription::create([
                'id'            => (string) Str::uuid(),
                'tenant_id'     => $tenantId,
                'plan_id'       => $validated['plan_id'],
                'status'        => 'trialing',
                'billing_cycle' => 'monthly',
                'starts_at'     => now(),
                'trial_ends_at' => now()->addDays(14),
                'ends_at'       => now()->addDays(14),
            ]);

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message'      => 'Tenant registrado con éxito',
                'access_token' => $token,
                'token_type'   => 'Bearer',
                'user'         => $user,
            ], 201);
        });
    }
}