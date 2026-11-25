<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CompanyController extends Controller
{
    private function tenant(Request $request): Tenant
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 403, 'Esta cuenta no pertenece a una empresa.');

        return $tenant->loadMissing(['settings', 'activeSubscription.plan', 'businessType', 'company']);
    }

    public function show(Request $request)
    {
        return response()->json($this->payload($this->tenant($request), $request->user()->allows('company.edit')));
    }

    public function update(Request $request)
    {
        $tenant = $this->tenant($request);
        abort_unless($request->user()->allows('company.edit'), 403, 'Tu cuenta no tiene permiso para cambiar los datos de la empresa.');

        $validated = $request->validate([
            'name'       => 'required|string|max:150',
            'legal_name' => 'nullable|string|max:200',
            'tax_id'     => ['nullable', 'regex:/^(10|20)\d{9}$/'],
            'phone'      => 'nullable|string|max:30',
            'address'    => 'nullable|string|max:255',
            'district'   => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
        ], [
            'name.required' => 'Escribe el nombre de la empresa.',
            'tax_id.regex'  => 'El RUC tiene 11 dígitos y empieza con 10 (persona) o 20 (empresa).',
        ]);

        $fields = ['legal_name', 'tax_id', 'phone', 'address', 'district', 'department'];
        $before = ['name' => $tenant->name] + collect($fields)->mapWithKeys(fn ($key) => [$key => $tenant->company?->{$key}])->all();

        DB::transaction(function () use ($tenant, $validated) {
            $name = trim($validated['name']);
            $tenant->update(['name' => $name]);

            $company = $tenant->company ?? new Company(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id]);
            $company->fill([
                'trade_name' => $name,
                'legal_name' => $validated['legal_name'] ?? null,
                'tax_id'     => $validated['tax_id'] ?? null,
                'phone'      => $validated['phone'] ?? null,
                'address'    => $validated['address'] ?? null,
                'district'   => $validated['district'] ?? null,
                'department' => $validated['department'] ?? null,
            ])->save();
        });

        $fresh = $tenant->fresh(['settings', 'activeSubscription.plan', 'businessType', 'company']);
        $after = ['name' => $fresh->name] + collect($fields)->mapWithKeys(fn ($key) => [$key => $fresh->company?->{$key}])->all();
        $changed = array_keys(array_filter($after, fn ($value, $key) => ($before[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));

        if ($changed) {
            \App\Support\Audit::log($request, 'company.updated', ['tenant' => $tenant->id, 'entity_type' => 'tenant', 'entity_id' => $tenant->id, 'entity_label' => $fresh->name, 'before' => array_intersect_key($before, array_flip($changed)), 'after' => array_intersect_key($after, array_flip($changed))]);
        }

        return response()->json($this->payload($fresh, true));
    }

    public function catalogPreview(Request $request)
    {
        $tenant = $this->tenant($request);
        $type = \App\Models\BusinessType::where('status', 'active')->findOrFail($request->validate(['business_type_id' => 'required|uuid'])['business_type_id']);

        return response()->json(\App\Support\BusinessTemplates::catalogPreview($tenant, $type) + ['name' => $type->name]);
    }

    public function importCatalog(Request $request)
    {
        $tenant = $this->tenant($request);
        abort_unless($request->user()->allows('services.create'), 403, \App\Support\Access::denial('services.create', $tenant));
        $type = \App\Models\BusinessType::where('status', 'active')->findOrFail($request->validate(['business_type_id' => 'required|uuid'])['business_type_id']);

        $result = \App\Support\BusinessTemplates::importCatalog($tenant, $type);

        \App\Support\Audit::log($request, 'catalog.imported', ['tenant' => $tenant->id, 'entity_type' => 'business_type', 'entity_id' => $type->id, 'entity_label' => $type->name, 'after' => ['servicios' => $result['services'], 'categorias' => $result['categories'], 'ya_estaban' => $result['skipped']]]);

        $message = $result['services'] > 0
            ? "Listo: se agregaron {$result['services']} " . ($result['services'] === 1 ? 'servicio' : 'servicios') . " del rubro {$type->name}" . ($result['skipped'] ? " ({$result['skipped']} ya los tenías y no se repitieron)" : '') . '. Revisa precios y duraciones en Servicios.'
            : "No se agregó nada: ya tienes todos los servicios del rubro {$type->name}.";

        return response()->json($result + ['message' => $message]);
    }

    private function payload(Tenant $tenant, bool $canEdit): array
    {
        $company = $tenant->company;
        $subscription = $tenant->activeSubscription;
        $plan = $subscription?->plan;
        $limits = $tenant->planLimits();
        $usage = $tenant->usage();
        $timezone = $tenant->zone();
        $count = fn (string $table) => DB::table($table)->where('tenant_id', $tenant->id);

        return [
            'can_edit' => $canEdit,
            'tenant'   => [
                'name'          => $tenant->name,
                'code'          => $tenant->code,
                'timezone'      => $timezone,
                'business_type' => $tenant->businessType?->name,
                'member_since'  => $tenant->created_at?->copy()->setTimezone($timezone)->format('Y-m-d'),
                'contact_email' => DB::table('tenant_contacts')->where('tenant_id', $tenant->id)->orderBy('created_at')->value('email') ?? DB::table('users')->where('tenant_id', $tenant->id)->where('is_owner', true)->value('email'),
                'currency'      => \App\Support\Currency::ready() ? \App\Support\Currency::present(\App\Support\Currency::of($tenant)) : null,
            ],
            'company' => [
                'legal_name' => $company?->legal_name,
                'tax_id'     => $company?->tax_id,
                'phone'      => $company?->phone,
                'address'    => $company?->address,
                'district'   => $company?->district,
                'department' => $company?->department,
            ],
            'subscription' => $subscription ? [
                'plan'          => $plan?->name,
                'status'        => $subscription->status,
                'billing_cycle' => $subscription->billing_cycle,
                'trial_ends_at' => $subscription->status === 'trialing' ? $subscription->trial_ends_at?->copy()->setTimezone($timezone)->format('Y-m-d') : null,
                'ends_at'       => $subscription->ends_at?->copy()->setTimezone($timezone)->format('Y-m-d'),
            ] : null,
            'usage' => [
                ['key' => 'branches', 'used' => $count('branches')->where('status', 'active')->count(), 'limit' => $tenant->hasModule('multi_branch') ? ($limits['branches'] ?? null) : 1],
                ['key' => 'professionals', 'used' => $usage['professionals'], 'limit' => $limits['professionals'] ?? null],
                ['key' => 'users', 'used' => $usage['users'], 'limit' => $limits['users'] ?? null],
                ['key' => 'appointments_month', 'used' => $usage['appointments_month'], 'limit' => $limits['appointments_month'] ?? null],
                ['key' => 'whatsapp_month', 'used' => $usage['whatsapp_month'], 'limit' => \App\Support\MessageQuota::limit($tenant, 'whatsapp')],
                ...($tenant->hasModule('marketing') ? [['key' => 'campaign_emails_month', 'used' => $usage['campaign_emails_month'], 'limit' => \App\Support\MessageQuota::limit($tenant, 'campaign_email')]] : []),
            ],
            'totals' => [
                'customers' => $count('customers')->whereNull('deleted_at')->count(),
                'services'  => $count('services')->count(),
            ],
        ];
    }
}
