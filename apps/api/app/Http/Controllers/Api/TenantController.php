<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\SaasModule;
use App\Models\SaaSPlan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\TenantProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    public const STATUSES = ['active', 'suspended', 'inactive'];

    public function index(Request $request)
    {
        $query = Tenant::with(['subscription.plan', 'businessType', 'users']);

        if ($request->has('search') && !empty($request->input('search'))) {
            $search = $request->input('search');
            $query->where(function($q) use ($search) {
                \App\Support\Search::contains($q, 'name', (string) $search);
                \App\Support\Search::orContains($q, 'slug', (string) $search);
            });
        }

        if ($request->has('status') && !empty($request->input('status'))) {
            $request->input('status') === 'not_active'
                ? $query->where('status', '<>', 'active')
                : $query->where('status', $request->input('status'));
        }

        $tenants = $query->latest()->get();
        $tenants->each(fn ($tenant) => $tenant->setAttribute('billing_state', \App\Support\Subscriptions::state($tenant)['state']));

        return response()->json($tenants);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'             => 'required|string|max:150',
            'timezone'         => 'required|string|max:100',
            'status'           => ['required', Rule::in(self::STATUSES)],
            'business_type_id' => ['required', 'uuid', Rule::exists('business_types', 'id')],
            'plan_id'          => ['required', 'uuid', Rule::exists('saas_plans', 'id')->where('status', 'active')],
            'start_trial'      => 'sometimes|boolean',
            'billing_cycle'    => 'sometimes|in:monthly,yearly',
            'legal_name'       => 'required|string|max:200',
            'tax_id'           => 'required|string|max:30',
            'phone'            => 'nullable|string|max:30',
            'address'          => 'nullable|string|max:255',
            'department'       => 'nullable|string|max:100',
            'district'         => 'nullable|string|max:100',
            'branch_name'      => 'required|string|max:150',
            'admin_first_name' => 'required|string|max:100',
            'admin_last_name'  => 'required|string|max:100',
            'admin_email'      => 'required|email|max:190',
        ]);

        $password = Str::password(12, symbols: false);

        $result = TenantProvisioner::provision([
            'name'             => $validated['name'],
            'legal_name'       => $validated['legal_name'],
            'trade_name'       => $validated['name'],
            'tax_id'           => $validated['tax_id'],
            'phone'            => $validated['phone'] ?? null,
            'address'          => $validated['address'] ?? null,
            'department'       => $validated['department'] ?? null,
            'district'         => $validated['district'] ?? null,
            'business_type_id' => $validated['business_type_id'],
            'plan_id'          => $validated['plan_id'],
            'start_trial'      => $validated['start_trial'] ?? true,
            'billing_cycle'    => $validated['billing_cycle'] ?? 'monthly',
            'branch_name'      => $validated['branch_name'],
            'timezone'         => $validated['timezone'],
            'status'           => $validated['status'],
            'first_name'       => $validated['admin_first_name'],
            'last_name'        => $validated['admin_last_name'],
            'email'            => $validated['admin_email'],
            'password'         => $password,
        ]);

        AuditEvent::record($request, 'tenant.created', $result['tenant'], null, [
            'plan_id'          => $validated['plan_id'],
            'business_type_id' => $validated['business_type_id'],
            'owner_email'      => $validated['admin_email'],
        ]);

        return response()->json([
            'message' => 'Tenant creado correctamente',
            'data' => $result['tenant'],
            'installed' => $result['installed'],
            'owner' => ['email' => $validated['admin_email'], 'temporary_password' => $password],
        ], 201);
    }

    public function show($id)
    {
        return response()->json($this->detail(Tenant::findOrFail($id)));
    }

    public function update(Request $request, $id)
    {
        $tenant = Tenant::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'slug' => 'sometimes|required|string|max:150|unique:tenants,slug,' . $tenant->id,
            'timezone' => 'sometimes|required|string|max:100',
            'status' => ['sometimes', 'required', Rule::in(self::STATUSES)],
        ]);

        $previousStatus = $tenant->status;
        [$was, $now] = \App\Support\Audit::changed($tenant->only(array_keys($validated)), $validated);
        $tenant->update($validated);
        unset($was['status'], $now['status']);

        if ($now) {
            AuditEvent::record($request, 'tenant.updated', $tenant, $was, $now);
        }

        if ($tenant->status !== $previousStatus) {
            $this->afterStatusChange($request, $tenant, $previousStatus);
        }

        return response()->json([
            'message' => 'Tenant actualizado correctamente',
            'data' => $tenant
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->delete();
        \App\Support\Audit::log($request, 'tenant.deleted', ['entity_type' => 'tenant', 'entity_id' => $tenant->id, 'entity_label' => $tenant->name]);

        return response()->json([
            'message' => 'Tenant eliminado correctamente'
        ]);
    }

    public function suspend(Request $request, $id)
    {
        $tenant = Tenant::findOrFail($id);
        $validated = $request->validate(['reason' => 'nullable|string|max:255']);

        $previousStatus = $tenant->status;
        $tenant->update(['status' => 'suspended', 'suspension_reason' => $validated['reason'] ?? null]);
        $this->afterStatusChange($request, $tenant, $previousStatus);

        return response()->json($this->detail($tenant));
    }

    public function reactivate(Request $request, $id)
    {
        $tenant = Tenant::findOrFail($id);

        $previousStatus = $tenant->status;
        $tenant->update(['status' => 'active', 'suspension_reason' => null]);
        $this->afterStatusChange($request, $tenant, $previousStatus);

        return response()->json($this->detail($tenant));
    }

    public function changePlan(Request $request, $id)
    {
        $tenant = Tenant::findOrFail($id);

        $validated = $request->validate([
            'plan_id' => ['required', 'uuid', Rule::exists('saas_plans', 'id')->where('status', 'active')],
            'billing_cycle' => 'nullable|in:monthly,yearly',
        ]);

        $subscription = Subscription::where('tenant_id', $tenant->id)->latest('starts_at')->first();
        $before = ['plan_id' => $subscription?->plan_id, 'status' => $subscription?->status];

        if ($subscription?->isCurrent()) {
            $subscription->update(array_filter([
                'plan_id' => $validated['plan_id'],
                'billing_cycle' => $validated['billing_cycle'] ?? null,
            ]));
        } else {
            $cycle = $validated['billing_cycle'] ?? $subscription?->billing_cycle ?? 'monthly';
            $data = [
                'plan_id' => $validated['plan_id'],
                'status' => 'active',
                'billing_cycle' => $cycle,
                'starts_at' => now(),
                'ends_at' => $cycle === 'yearly' ? now()->addYear() : now()->addMonth(),
                'trial_ends_at' => null,
                'canceled_at' => null,
            ];

            if ($subscription) {
                $subscription->update($data);
            } else {
                $subscription = new Subscription($data + ['tenant_id' => $tenant->id]);
                $subscription->id = (string) Str::uuid();
                $subscription->save();
            }
        }

        AuditEvent::record($request, 'tenant.plan_changed', $tenant, $before, ['plan_id' => $validated['plan_id'], 'status' => $subscription->status]);

        return response()->json($this->detail($tenant));
    }

    public function changeBusinessType(Request $request, $id)
    {
        $tenant = Tenant::findOrFail($id);

        $validated = $request->validate([
            'business_type_id' => ['required', 'uuid', Rule::exists('business_types', 'id')],
            'install_template' => 'sometimes|boolean',
        ]);

        $before = ['business_type_id' => $tenant->business_type_id];
        $tenant->business_type_id = $validated['business_type_id'];
        $tenant->unsetRelation('businessType');
        $tenant->fillBusinessProfile()->save();
        $tenant->settings()->updateOrCreate([], ['modules_off' => Tenant::startingModulesOff($tenant->businessType)]);
        $tenant->unsetRelation('settings');

        $installed = !empty($validated['install_template'])
            ? DB::transaction(fn () => $tenant->installBusinessTemplate())
            : null;

        AuditEvent::record($request, 'tenant.business_type_changed', $tenant, $before, ['business_type_id' => $tenant->business_type_id, 'installed' => $installed]);

        return response()->json($this->detail($tenant) + ['installed' => $installed]);
    }

    public function updateModules(Request $request, $id)
    {
        $tenant = Tenant::findOrFail($id);
        $optional = SaasModule::where('is_core', false)->pluck('key')->all();

        $validated = $request->validate([
            'modules_off'   => 'present|array',
            'modules_off.*' => ['string', 'distinct', Rule::in($optional)],
        ], [
            'modules_off.*.in' => 'Ese módulo no existe o es un módulo base, que no se puede apagar.',
        ]);

        $before = ['modules_off' => $tenant->modules_off ?? []];
        $tenant->update(['modules_off' => array_values($validated['modules_off'])]);

        AuditEvent::record($request, 'tenant.modules_changed', $tenant, $before, ['modules_off' => $tenant->modules_off]);

        return response()->json($this->detail($tenant));
    }

    private function afterStatusChange(Request $request, Tenant $tenant, string $previousStatus): void
    {
        if (!$tenant->isActive()) {
            DB::table('personal_access_tokens')
                ->where(fn ($q) => $q
                    ->whereIn('tokenable_id', DB::table('users')->where('tenant_id', $tenant->id)->select(DB::raw('id::text')))
                    ->orWhereIn('tokenable_id', DB::table('customers')->where('tenant_id', $tenant->id)->select(DB::raw('id::text'))))
                ->delete();
        }

        AuditEvent::record(
            $request,
            $tenant->isActive() ? 'tenant.reactivated' : 'tenant.suspended',
            $tenant,
            ['status' => $previousStatus],
            ['status' => $tenant->status, 'reason' => $tenant->suspension_reason],
        );
    }

    public function detail(Tenant $tenant): array
    {
        $tenant = Tenant::with(['businessType', 'settings', 'company', 'users.roles'])->findOrFail($tenant->id);

        $subscription = Subscription::with('plan')->where('tenant_id', $tenant->id)->latest('starts_at')->first();
        $plan = $subscription?->plan;
        $isCurrent = (bool) $subscription?->isCurrent();

        $planKeys = $plan?->moduleKeys() ?? [];
        $platformOff = $tenant->modules_off ?? [];
        $tenantOff = $tenant->settings?->modules_off ?? [];
        $active = $tenant->modules();

        $modules = SaasModule::orderBy('sort_order')->get()->map(function (SaasModule $module) use ($isCurrent, $planKeys, $platformOff, $tenantOff, $active) {
            $inPlan = $module->is_core || in_array($module->key, $planKeys, true);

            $reason = match (true) {
                !$isCurrent                                  => 'no_subscription',
                !$inPlan                                     => 'plan',
                in_array($module->key, $platformOff, true)   => 'platform',
                in_array($module->key, $tenantOff, true)     => 'tenant',
                default                                      => null,
            };

            return [
                'key'          => $module->key,
                'name'         => $module->name,
                'is_core'      => $module->is_core,
                'in_plan'      => $inPlan,
                'enabled'      => in_array($module->key, $active, true),
                'off_reason'   => $reason,
                'platform_off' => in_array($module->key, $platformOff, true),
            ];
        });

        $limits = $plan?->limits ?? [];
        $usage = collect($tenant->usage())->map(fn ($used, $key) => [
            'key'   => $key,
            'used'  => $used,
            'limit' => isset($limits[$key]) ? (int) $limits[$key] : null,
        ])->values();

        $invoices = $subscription
            ? $subscription->invoices()->with('status')->latest('invoice_date')->limit(24)->get()
            : collect();

        $owner = $tenant->users->firstWhere('is_owner', true);

        return [
            'id'                => $tenant->id,
            'name'              => $tenant->name,
            'slug'              => $tenant->slug,
            'code'              => $tenant->code,
            'timezone'          => $tenant->timezone,
            'status'            => $tenant->status,
            'suspension_reason' => $tenant->suspension_reason,
            'created_at'        => $tenant->created_at,
            'business_type'     => $tenant->businessType?->only(['id', 'slug', 'name', 'short_name', 'color']),
            'business_profile_outdated' => $tenant->businessType ? $tenant->outdatedProfileParts() : [],
            'business_profile_synced_at' => $tenant->business_profile_synced_at,
            'company'           => $tenant->company?->only(['legal_name', 'trade_name', 'tax_id', 'phone', 'address', 'district', 'department']),
            'subscription'      => $subscription ? [
                'id'            => $subscription->id,
                'status'        => $subscription->status,
                'is_current'    => $isCurrent,
                'billing_cycle' => $subscription->billing_cycle,
                'starts_at'     => $subscription->starts_at,
                'ends_at'       => $subscription->ends_at,
                'trial_ends_at' => $subscription->trial_ends_at,
                'canceled_at'   => $subscription->canceled_at,
                'plan'          => $plan?->only(['id', 'name', 'slug', 'price_monthly', 'price_yearly', 'currency', 'is_public_catalog']),
            ] : null,
            'invoices' => $invoices->map(fn ($invoice) => [
                'id'           => $invoice->id,
                'amount'       => $invoice->amount,
                'currency'     => $invoice->currency,
                'status'       => $invoice->status?->name,
                'status_slug'  => $invoice->status?->slug,
                'reason'       => $invoice->billing_reason,
                'invoice_date' => $invoice->invoice_date,
                'paid_at'      => $invoice->paid_at,
                'period_start' => $invoice->period_start,
                'period_end'   => $invoice->period_end,
                'reference'    => $invoice->external_invoice_id,
                'notes'        => $invoice->notes,
            ]),
            'billing' => \App\Support\Subscriptions::present($tenant),
            'plan_payments' => $subscription ? \App\Support\PlanBilling::history($subscription->id) : [],
            'modules' => $modules,
            'usage'   => $usage,
            'users'   => $tenant->users->values()->map(fn ($user) => [
                'id'         => $user->id,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'email'      => $user->email,
                'status'     => $user->status,
                'is_owner'   => $user->is_owner,
                'role'       => $user->is_owner ? 'Dueño' : $user->roles->first()?->name,
            ]),
            'settings' => [
                'vocabulary'            => $tenant->vocabulary(),
                'vocabulary_overrides'  => (object) ($tenant->settings?->vocabulary ?? []),
                'modules_off'           => $tenant->settings?->modules_off ?? [],
                'rules'                 => (object) $tenant->rules(),
                'rules_overrides'       => (object) ($tenant->settings?->rules ?? []),
                'dependents'            => $tenant->dependents(),
                'allow_customer_signup' => $tenant->settings?->allow_customer_signup ?? true,
                'brand_color'           => $tenant->settings?->brand_color,
            ],
            'customer_accounts' => DB::table('customers')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->whereNotNull('password_hash')->count(),
            'can_impersonate'   => (bool) $owner,
        ];
    }
}
