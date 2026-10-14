<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Status;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TenantSubscriptionController extends Controller
{
    private function subscription(string $tenantId): array
    {
        $tenant = Tenant::findOrFail($tenantId);
        $subscription = Subscription::with('plan')->where('tenant_id', $tenant->id)->latest('starts_at')->first();

        abort_unless($subscription, 422, 'La empresa no tiene una suscripción. Asígnale un plan primero.');

        return [$tenant, $subscription];
    }

    public function registerPayment(Request $request, string $tenant)
    {
        [$tenant, $subscription] = $this->subscription($tenant);

        $validated = $request->validate([
            'billing_cycle' => 'required|in:monthly,yearly',
            'amount'        => 'nullable|numeric|min:0',
            'paid_at'       => 'nullable|date|before_or_equal:now',
            'reference'     => 'nullable|string|max:255',
            'notes'         => 'nullable|string|max:255',
        ]);

        $plan = $subscription->plan;
        $cycle = $validated['billing_cycle'];
        $paidAt = isset($validated['paid_at']) ? Carbon::parse($validated['paid_at']) : now();
        $wasTrial = $subscription->status === 'trialing';

        $periodStart = !$wasTrial && $subscription->status === 'active' && $subscription->ends_at?->isFuture()
            ? $subscription->ends_at->copy()
            : $paidAt->copy();
        $periodEnd = $cycle === 'yearly' ? $periodStart->copy()->addYear() : $periodStart->copy()->addMonth();

        $paidStatusId = Status::whereHas('module', fn ($q) => $q->where('name', 'payments'))
            ->whereNull('tenant_id')
            ->where('slug', 'paid')
            ->value('id');

        abort_unless($paidStatusId, 500, 'Falta el estado "Pagado" del módulo de pagos.');

        $before = $subscription->only(['status', 'billing_cycle', 'ends_at']);

        $invoice = DB::transaction(function () use ($subscription, $tenant, $plan, $cycle, $validated, $paidAt, $periodStart, $periodEnd, $paidStatusId, $wasTrial) {
            $invoice = SubscriptionInvoice::create([
                'subscription_id'     => $subscription->id,
                'tenant_id'           => $tenant->id,
                'status_id'           => $paidStatusId,
                'amount'              => $validated['amount'] ?? ($cycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly),
                'currency'            => substr($plan->currency ?? 'PEN', 0, 3),
                'billing_reason'      => $wasTrial ? 'subscription_create' : 'manual',
                'invoice_date'        => $paidAt,
                'paid_at'             => $paidAt,
                'period_start'        => $periodStart,
                'period_end'          => $periodEnd,
                'external_invoice_id' => $validated['reference'] ?? null,
                'notes'               => $validated['notes'] ?? null,
            ]);

            $subscription->update([
                'status'        => 'active',
                'billing_cycle' => $cycle,
                'starts_at'     => $subscription->status === 'active' ? $subscription->starts_at : $periodStart,
                'ends_at'       => $periodEnd,
                'trial_ends_at' => null,
                'canceled_at'   => null,
            ]);

            return $invoice;
        });

        AuditEvent::record($request, 'subscription.payment_registered', $tenant, $before, [
            'invoice_id' => $invoice->id,
            'amount'     => $invoice->amount,
            'ends_at'    => $periodEnd,
        ]);

        return response()->json(app(TenantController::class)->detail($tenant));
    }

    public function extend(Request $request, string $tenant)
    {
        [$tenant, $subscription] = $this->subscription($tenant);

        $validated = $request->validate([
            'ends_at' => 'required|date|after:now',
        ]);

        $before = $subscription->only(['status', 'ends_at', 'trial_ends_at']);
        $endsAt = Carbon::parse($validated['ends_at'])->endOfDay();
        $isTrial = $subscription->status === 'trialing';

        $subscription->update([
            'status'        => $isTrial ? 'trialing' : 'active',
            'ends_at'       => $endsAt,
            'trial_ends_at' => $isTrial ? $endsAt : null,
            'canceled_at'   => null,
        ]);

        AuditEvent::record($request, 'subscription.extended', $tenant, $before, ['ends_at' => $endsAt]);

        return response()->json(app(TenantController::class)->detail($tenant));
    }

    public function cancel(Request $request, string $tenant)
    {
        [$tenant, $subscription] = $this->subscription($tenant);

        $before = $subscription->only(['status', 'ends_at']);
        $subscription->update(['status' => 'canceled', 'canceled_at' => now()]);

        AuditEvent::record($request, 'subscription.canceled', $tenant, $before, ['status' => 'canceled']);

        return response()->json(app(TenantController::class)->detail($tenant));
    }
}
