<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Tenant;
use App\Support\Audit;
use App\Support\Currency;
use App\Support\Packages;
use App\Support\PaymentLinks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PortalShopController extends Controller
{
    private function tenant(Request $request): Tenant
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 403);
        abort_unless(Packages::onlineReady(), 503, Packages::NOT_READY);

        return $tenant;
    }

    private function plans(Tenant $tenant)
    {
        return DB::table('package_plans')
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->where('sell_online', true)
            ->where('booking_mode', 'free')
            ->where(fn ($q) => $q->whereNull('currency')->orWhere('currency', Currency::of($tenant)))
            ->orderBy('price')
            ->get();
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);
        $plans = $this->plans($tenant);
        $items = Packages::planItems($plans->pluck('id')->all());

        return response()->json([
            'packages' => $plans->map(fn ($plan) => collect(Packages::presentPlan($tenant, $plan, $items[$plan->id] ?? collect()))
                ->only(['id', 'name', 'description', 'price', 'currency', 'items', 'sessions', 'normal_total', 'savings', 'savings_percent', 'validity_days', 'terms_text'])
                ->all())->values(),
            'branches' => Branch::where('tenant_id', $tenant->id)->where('status', 'active')->orderBy('name')->get(['id', 'name'])->values(),
        ]);
    }

    public function buy(Request $request, string $plan)
    {
        $tenant = $this->tenant($request);
        $customer = $request->user();
        $validated = $request->validate(['branch_id' => 'required|uuid'], ['branch_id.required' => 'Elige la sede donde lo usarás.']);
        $row = $this->plans($tenant)->firstWhere('id', $plan);
        abort_unless($row, 404, 'Este paquete ya no se vende en línea.');
        $branch = Branch::where('tenant_id', $tenant->id)->where('status', 'active')->find($validated['branch_id']);

        if (!$branch) {
            throw ValidationException::withMessages(['branch_id' => 'Elige una de nuestras sedes.']);
        }

        PaymentLinks::expireDue($tenant->id);

        $open = DB::table('payment_links as l')
            ->join('sales as s', 's.id', '=', 'l.sale_id')
            ->join('sale_items as i', 'i.sale_id', '=', 's.id')
            ->where('l.tenant_id', $tenant->id)
            ->where('l.customer_id', $customer->id)
            ->where('l.status', 'pending')
            ->where('s.notes', Packages::ONLINE_NOTE)
            ->where('i.reference_id', $row->id)
            ->first(['l.*']);

        if ($open) {
            return response()->json(['token' => $open->token, 'reused' => true]);
        }

        $link = DB::transaction(function () use ($tenant, $customer, $row, $branch) {
            $sale = Packages::sellOnline($tenant, $customer, $row, $branch);

            return PaymentLinks::forSale($tenant, $sale, null);
        });

        Audit::log($request, 'package.online_started', [
            'tenant' => $tenant->id, 'entity_type' => 'package_plan', 'entity_id' => $row->id, 'entity_label' => $row->name, 'branch_id' => $branch->id,
            'after' => ['package' => $row->name, 'amount' => Currency::format((float) $link->amount, $link->currency), 'branch' => $branch->name],
        ]);

        return response()->json(['token' => $link->token, 'reused' => false], 201);
    }
}
