<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\SaaSPlan;
use App\Models\Subscription;
use App\Support\PlanBilling;
use App\Support\Subscriptions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BillingController extends Controller
{
    private function subscription(Request $request, bool $admin = true): Subscription
    {
        $user = $request->user();
        abort_unless($user->tenant_id, 403);
        abort_unless(Subscriptions::ready(), 503, 'Falta actualizar la base de datos para el cobro del plan: ejecuta php artisan migrate.');

        if ($admin) {
            abort_unless($user->isTenantAdmin(), 403, 'Solo el administrador de la empresa puede pagar o cambiar el plan.');
        }

        $subscription = Subscriptions::latest($user->tenant_id);
        abort_unless($subscription, 422, 'Tu empresa no tiene un plan. Elige uno para empezar.');

        return $subscription;
    }

    private function choice(Request $request, Subscription $subscription): array
    {
        $validated = $request->validate([
            'plan_id'       => ['nullable', 'uuid', Rule::exists('saas_plans', 'id')->where('status', 'active')],
            'billing_cycle' => 'nullable|in:monthly,yearly',
        ]);

        $plan = isset($validated['plan_id']) ? SaaSPlan::findOrFail($validated['plan_id']) : ($subscription->pending_plan_id ? SaaSPlan::find($subscription->pending_plan_id) : $subscription->plan);
        $cycle = $validated['billing_cycle'] ?? ($subscription->pending_plan_id ? $subscription->pending_billing_cycle : $subscription->billing_cycle) ?? 'monthly';

        if ($plan->id !== $subscription->plan_id && $plan->id !== $subscription->pending_plan_id && !$plan->is_public_catalog) {
            throw ValidationException::withMessages(['plan_id' => 'Ese plan no está disponible.']);
        }

        return [$plan, $cycle];
    }

    public function show(Request $request)
    {
        $subscription = $this->subscription($request, false);
        $tenant = $request->user()->tenant;

        if ($request->boolean('sync')) {
            try {
                PlanBilling::sync($subscription);
            } catch (\Throwable $e) {
                report($e);
            }

            Subscriptions::forget($tenant->id);
            $subscription = $subscription->fresh('plan');
        }

        $zone = $tenant->zone();

        return response()->json([
            'billing'  => Subscriptions::present($tenant, $request->user()),
            'history'  => PlanBilling::history($subscription->id),
            'invoices' => $subscription->invoices()->latest('invoice_date')->limit(24)->get()->map(fn ($invoice) => [
                'id'           => $invoice->id,
                'amount'       => round((float) $invoice->amount, 2),
                'currency'     => $invoice->currency,
                'reason'       => $invoice->billing_reason,
                'paid_at'      => $invoice->paid_at ? Carbon::parse($invoice->paid_at)->setTimezone($zone)->format('Y-m-d') : null,
                'period_start' => $invoice->period_start ? Carbon::parse($invoice->period_start)->setTimezone($zone)->format('Y-m-d') : null,
                'period_end'   => $invoice->period_end ? Carbon::parse($invoice->period_end)->setTimezone($zone)->format('Y-m-d') : null,
                'notes'        => $invoice->notes,
            ])->values(),
            'plans'    => SaaSPlan::where('status', 'active')->where('is_public_catalog', true)->orderBy('presentation_order')
                ->get(['id', 'name', 'price_monthly', 'price_yearly', 'currency', 'description'])
                ->map(fn ($plan) => [
                    'id'            => $plan->id,
                    'name'          => $plan->name,
                    'description'   => $plan->description,
                    'currency'      => $plan->currency ?? 'PEN',
                    'price_monthly' => round((float) $plan->price_monthly, 2),
                    'price_yearly'  => round((float) $plan->price_yearly, 2),
                ])->values(),
        ]);
    }

    public function card(Request $request)
    {
        $subscription = $this->subscription($request);
        [$plan, $cycle] = $this->choice($request, $subscription);

        return response()->json(['checkout_url' => PlanBilling::cardUrl($subscription, $request->user(), $plan, $cycle)]);
    }

    public function pay(Request $request)
    {
        $subscription = $this->subscription($request);
        [$plan, $cycle] = $this->choice($request, $subscription);

        return response()->json(['checkout_url' => PlanBilling::payUrl($subscription, $request->user(), $plan, $cycle)]);
    }

    public function cancelCard(Request $request)
    {
        $subscription = $this->subscription($request);
        PlanBilling::cancelCard($subscription);
        AuditEvent::record($request, 'subscription.card_cancelled', $request->user()->tenant, [], ['card_status' => 'cancelled']);

        return $this->show($request);
    }

    public function change(Request $request)
    {
        $subscription = $this->subscription($request);
        [$plan, $cycle] = $this->choice($request, $subscription);

        return response()->json(self::changePlan($request, $subscription, $plan, $cycle));
    }

    public static function changePlan(Request $request, Subscription $subscription, SaaSPlan $plan, string $cycle): array
    {
        $user = $request->user();
        $state = Subscriptions::state($subscription->tenant_id)['state'];
        $before = $subscription->only(['plan_id', 'billing_cycle', 'status']);
        $same = $plan->id === $subscription->plan_id && $cycle === $subscription->billing_cycle;
        $price = Subscriptions::price($plan, $cycle);

        if ($price <= 0) {
            PlanBilling::cancelCard($subscription);
            $subscription->update(['plan_id' => $plan->id, 'billing_cycle' => $cycle, 'status' => 'active', 'ends_at' => null, 'trial_ends_at' => null, 'canceled_at' => null, 'pending_plan_id' => null, 'pending_billing_cycle' => null]);
            Subscriptions::forget($subscription->tenant_id);
            AuditEvent::record($request, 'subscription.plan_changed', $user->tenant, $before, $subscription->only(['plan_id', 'billing_cycle', 'status']));

            return ['message' => "Listo: tu empresa quedó en el plan {$plan->name}."];
        }

        abort_unless(PlanBilling::enabled(), 422, PlanBilling::NOT_CONNECTED);

        if (in_array($state, ['grace', 'locked', 'none'], true) || $subscription->status === 'canceled') {
            return ['checkout_url' => PlanBilling::payUrl($subscription, $user, $plan, $cycle)];
        }

        if ($state === 'trial' || $state === 'card_needed') {
            if (!$same) {
                $subscription->update(['plan_id' => $plan->id, 'billing_cycle' => $cycle]);
                AuditEvent::record($request, 'subscription.plan_changed', $user->tenant, $before, $subscription->only(['plan_id', 'billing_cycle', 'status']));
            }

            if ($subscription->card_status === 'authorized' && $same) {
                return ['message' => "Ya estás en el plan {$plan->name}."];
            }

            return ['checkout_url' => PlanBilling::cardUrl($subscription->fresh('plan'), $user, $plan, $cycle)];
        }

        if ($same) {
            return ['message' => "Ya estás en el plan {$plan->name}."];
        }

        if ($subscription->card_status === 'authorized') {
            return ['checkout_url' => PlanBilling::cardUrl($subscription, $user, $plan, $cycle)];
        }

        $subscription->update(['pending_plan_id' => $plan->id, 'pending_billing_cycle' => $cycle]);
        AuditEvent::record($request, 'subscription.plan_changed', $user->tenant, $before, ['pending_plan_id' => $plan->id, 'pending_billing_cycle' => $cycle]);
        Subscriptions::forget($subscription->tenant_id);

        return ['message' => "El plan {$plan->name} empieza cuando pagues el próximo periodo."];
    }

    public function simulatedView(string $id)
    {
        $view = PlanBilling::simulatedView($id);
        abort_unless($view, 404, 'Este pago no existe.');

        return response()->json($view);
    }

    public function simulate(Request $request, string $id)
    {
        $validated = $request->validate(['result' => ['required', Rule::in(['approved', 'rejected'])]]);

        return response()->json(PlanBilling::simulate($id, $validated['result'] === 'approved') + ['view' => PlanBilling::simulatedView($id)]);
    }
}
