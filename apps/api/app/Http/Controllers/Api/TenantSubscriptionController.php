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

        $before = $subscription->only(['status', 'billing_cycle', 'ends_at']);

        $invoice = \App\Support\Subscriptions::recordPayment($subscription, [
            'billing_cycle' => $validated['billing_cycle'],
            'amount'        => $validated['amount'] ?? null,
            'paid_at'       => isset($validated['paid_at']) ? Carbon::parse($validated['paid_at']) : now(),
            'reason'        => $subscription->status === 'trialing' ? 'subscription_create' : 'manual',
            'reference'     => $validated['reference'] ?? null,
            'notes'         => $validated['notes'] ?? null,
        ]);
        $periodEnd = $invoice->period_end;

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
        \App\Support\PlanBilling::cancelCard($subscription);
        $subscription->update(['status' => 'canceled', 'canceled_at' => now()]);

        AuditEvent::record($request, 'subscription.canceled', $tenant, $before, ['status' => 'canceled']);

        return response()->json(app(TenantController::class)->detail($tenant));
    }
}
