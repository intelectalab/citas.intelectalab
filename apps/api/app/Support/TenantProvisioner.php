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

class TenantProvisioner
{
    public static function provision(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $tenantId = (string) Str::uuid();
            $timezone = $data['timezone'] ?? 'America/Lima';

            $tenant = Tenant::create([
                'id'               => $tenantId,
                'name'             => $data['name'],
                'slug'             => Str::slug($data['name']) . '-' . Str::lower(Str::random(4)),
                'business_type_id' => $data['business_type_id'] ?? BusinessType::where('slug', 'generic')->value('id'),
                'timezone'         => $timezone,
                'status'           => $data['status'] ?? 'active',
            ]);

            TenantSetting::create(['tenant_id' => $tenantId, 'modules_off' => Tenant::startingModulesOff($tenant->businessType)]);

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

            Access::ensureRoles($tenant);

            DB::table('user_roles')->insert([
                'tenant_id' => $tenantId,
                'user_id'   => $userId,
                'role_id'   => $ownerRole->id,
            ]);

            $cycle = $data['billing_cycle'] ?? 'monthly';

            if ($data['start_trial'] ?? true) {
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

            $year = (int) now($timezone)->format('Y');
            Holidays::loadNational($tenant, $year);
            Holidays::loadNational($tenant, $year + 1);

            return ['tenant' => $tenant, 'user' => $user, 'installed' => $installed];
        });
    }
}
