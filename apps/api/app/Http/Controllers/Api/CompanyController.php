<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Datos generales de la propia empresa: nombre, razón social, RUC, contacto y
 * dirección fiscal. Los ve todo el personal; los cambia su administrador.
 * El rubro, el plan y el código los maneja la plataforma.
 */
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
        return response()->json($this->payload($this->tenant($request), $request->user()->isTenantAdmin()));
    }

    public function update(Request $request)
    {
        $tenant = $this->tenant($request);
        abort_unless($request->user()->isTenantAdmin(), 403, 'Solo el administrador de la empresa puede cambiar estos datos.');

        $validated = $request->validate([
            'name'       => 'required|string|max:150',
            'legal_name' => 'nullable|string|max:200',
            'tax_id'     => ['nullable', 'regex:/^\d{11}$/'],
            'phone'      => 'nullable|string|max:30',
            'address'    => 'nullable|string|max:255',
            'district'   => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
        ], [
            'name.required' => 'Escribe el nombre de la empresa.',
            'tax_id.regex'  => 'El RUC tiene 11 dígitos.',
        ]);

        DB::transaction(function () use ($tenant, $validated) {
            $name = trim($validated['name']);
            $tenant->update(['name' => $name]);

            // El nombre comercial es el nombre con que la empresa se ve en todo el sistema.
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

        return response()->json($this->payload($tenant->fresh(['settings', 'activeSubscription.plan', 'businessType', 'company']), true));
    }

    private function payload(Tenant $tenant, bool $canEdit): array
    {
        $company = $tenant->company;
        $subscription = $tenant->activeSubscription;
        $plan = $subscription?->plan;
        $limits = $plan?->limits ?? [];
        $usage = $tenant->usage();
        $timezone = $tenant->timezone ?: 'America/Lima';
        $count = fn (string $table) => DB::table($table)->where('tenant_id', $tenant->id);

        return [
            'can_edit' => $canEdit,
            'tenant'   => [
                'name'          => $tenant->name,
                'code'          => $tenant->code,
                'timezone'      => $timezone,
                'business_type' => $tenant->businessType?->name,
                'member_since'  => $tenant->created_at?->copy()->setTimezone($timezone)->format('Y-m-d'),
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
            // Uso frente a lo que permite el plan; sin tope cuando el plan no lo define.
            'usage' => [
                ['key' => 'branches', 'used' => $count('branches')->where('status', 'active')->count(), 'limit' => $tenant->hasModule('multi_branch') ? ($limits['branches'] ?? null) : 1],
                ['key' => 'professionals', 'used' => $usage['professionals'], 'limit' => $limits['professionals'] ?? null],
                ['key' => 'users', 'used' => $usage['users'], 'limit' => $limits['users'] ?? null],
                ['key' => 'appointments_month', 'used' => $usage['appointments_month'], 'limit' => $limits['appointments_month'] ?? null],
            ],
            'totals' => [
                'customers' => $count('customers')->whereNull('deleted_at')->count(),
                'services'  => $count('services')->count(),
            ],
        ];
    }
}
