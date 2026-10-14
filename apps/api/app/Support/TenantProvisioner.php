<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\BusinessType;
use App\Models\Company;
use App\Models\Role;
use App\Models\SaaSPlan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantContact;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Alta completa de una empresa: tenant con la copia de su rubro, catálogo
 * inicial, razón social, sede principal, cuenta del dueño con su rol y
 * suscripción. La usan el registro público y el alta desde la consola de la
 * plataforma, para que una empresa quede igual venga de donde venga.
 */
class TenantProvisioner
{
    /**
     * @param array{
     *   name: string, legal_name?: ?string, trade_name?: ?string, tax_id?: ?string, phone?: ?string,
     *   address?: ?string, department?: ?string, district?: ?string, business_type_id?: ?string,
     *   plan_id: string, start_trial?: bool, billing_cycle?: string, branch_name?: ?string,
     *   timezone?: ?string, status?: ?string, first_name: string, last_name: string, email: string, password: string
     * } $data
     * @return array{tenant: Tenant, user: User, installed: array}
     */
    public static function provision(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $tenantId = (string) Str::uuid();
            $timezone = $data['timezone'] ?? 'America/Lima';

            // El código de empresa de 8 dígitos y la copia del rubro los pone el modelo Tenant.
            $tenant = Tenant::create([
                'id'               => $tenantId,
                'name'             => $data['name'],
                'slug'             => Str::slug($data['name']) . '-' . Str::lower(Str::random(4)),
                'business_type_id' => $data['business_type_id'] ?? BusinessType::where('slug', 'generic')->value('id'),
                'timezone'         => $timezone,
                'status'           => $data['status'] ?? 'active',
            ]);

            TenantSetting::create(['tenant_id' => $tenantId]);

            // Catálogo inicial del rubro: categorías, servicios y plantilla de ficha.
            $installed = $tenant->installBusinessTemplate();

            $company = Company::create([
                'id'         => (string) Str::uuid(),
                'tenant_id'  => $tenantId,
                'legal_name' => $data['legal_name'] ?? $data['name'],
                'trade_name' => $data['trade_name'] ?? $data['name'],
                'tax_id'     => $data['tax_id'] ?? null,
                'phone'      => $data['phone'] ?? null,
                'address'    => $data['address'] ?? null,
                'department' => $data['department'] ?? 'Lima',
                'district'   => $data['district'] ?? null,
            ]);

            TenantContact::create([
                'id'         => (string) Str::uuid(),
                'tenant_id'  => $tenantId,
                'first_name' => $data['first_name'],
                'last_name'  => $data['last_name'],
                'email'      => $data['email'],
                'phone'      => $data['phone'] ?? null,
                'position'   => 'Dueño',
            ]);

            Branch::create([
                'id'         => (string) Str::uuid(),
                'tenant_id'  => $tenantId,
                'company_id' => $company->id,
                'name'       => $data['branch_name'] ?? 'Sede Principal',
                'timezone'   => $timezone,
                'status'     => 'active',
            ]);

            $userId = (string) Str::uuid();
            $user = User::create([
                'id'            => $userId,
                'tenant_id'     => $tenantId,
                'first_name'    => $data['first_name'],
                'last_name'     => $data['last_name'],
                'email'         => $data['email'],
                'password_hash' => Hash::make($data['password']),
                'is_owner'      => true,
                'status'        => 'active',
            ]);

            $ownerRole = Role::firstOrCreate(
                ['tenant_id' => $tenantId, 'slug' => 'owner'],
                [
                    'id'          => (string) Str::uuid(),
                    'name'        => Access::FACTORY['owner']['name'],
                    'description' => Access::FACTORY['owner']['description'],
                    'is_system'   => true,
                ]
            );

            // Los demás roles de fábrica (gerente de sede, recepción, caja y profesional) con sus permisos.
            Access::ensureRoles($tenant);

            DB::table('user_roles')->insert([
                'tenant_id' => $tenantId,
                'user_id'   => $userId,
                'role_id'   => $ownerRole->id,
            ]);

            $cycle = $data['billing_cycle'] ?? 'monthly';

            if ($data['start_trial'] ?? true) {
                // Los días de prueba los define el plan (Empresarial da 30).
                $trialDays = (int) (SaaSPlan::find($data['plan_id'])->trial_config['trial_days'] ?? 14);
                $endsAt = now()->addDays($trialDays);
                $subscription = ['status' => 'trialing', 'trial_ends_at' => $endsAt, 'ends_at' => $endsAt];
            } else {
                $subscription = ['status' => 'active', 'trial_ends_at' => null, 'ends_at' => $cycle === 'yearly' ? now()->addYear() : now()->addMonth()];
            }

            Subscription::create($subscription + [
                'id'            => (string) Str::uuid(),
                'tenant_id'     => $tenantId,
                'plan_id'       => $data['plan_id'],
                'billing_cycle' => $cycle,
                'starts_at'     => now(),
            ]);

            // Feriados nacionales de este año y del siguiente, para todas sus sedes.
            $year = (int) now($timezone)->format('Y');
            Holidays::loadNational($tenant, $year);
            Holidays::loadNational($tenant, $year + 1);

            return ['tenant' => $tenant, 'user' => $user, 'installed' => $installed];
        });
    }
}
