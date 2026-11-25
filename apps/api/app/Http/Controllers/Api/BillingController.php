<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\SaaSPlan;
use App\Models\Subscription;
use App\Support\PlanBilling;
use App\Support\PlanTransfers;
use App\Support\Subscriptions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
            abort_unless(Subscriptions::manages($user), 403, Subscriptions::NO_PERMISSION);
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

        $billing = Subscriptions::present($tenant, $request->user());

        if (!Subscriptions::manages($request->user())) {
            return response()->json(['billing' => $billing, 'history' => [], 'invoices' => [], 'billing_details' => null, 'exports' => [], 'exports_ready' => false, 'plans' => []]);
        }

        $statuses = DB::table('statuses')->whereIn('id', $subscription->invoices()->pluck('status_id')->filter()->unique()->all())->get(['id', 'slug', 'name'])->keyBy('id');
        $limitLabels = ['users' => ['usuario', 'usuarios'], 'branches' => ['sede', 'sedes'], 'professionals' => ['persona que atiende', 'personas que atienden'], 'appointments_month' => ['cita al mes', 'citas al mes'], 'whatsapp_month' => ['mensaje de WhatsApp al mes', 'mensajes de WhatsApp al mes'], 'campaign_emails_month' => ['correo de campañas al mes', 'correos de campañas al mes']];

        return response()->json([
            'billing'  => $billing,
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
                'list_amount'  => isset($invoice->list_amount) ? round((float) $invoice->list_amount, 2) : null,
                'discount'     => isset($invoice->list_amount) ? max(0, round((float) $invoice->list_amount - (float) $invoice->amount, 2)) : 0,
                'status'       => $statuses[$invoice->status_id]->slug ?? 'paid',
                'status_label' => $statuses[$invoice->status_id]->name ?? 'Pagado',
                'document'     => \App\Support\PlanInvoices::present($invoice),
            ])->values(),
            'billing_details' => \App\Support\PlanInvoices::ready() ? \App\Support\PlanInvoices::billingOf($tenant) : null,
            'exports'  => \App\Support\DataExports::listFor($tenant->id),
            'exports_ready' => \App\Support\DataExports::ready(),
            'plans'    => SaaSPlan::where('status', 'active')->where('is_public_catalog', true)->orderBy('presentation_order')
                ->get(['id', 'name', 'price_monthly', 'price_yearly', 'currency', 'description', 'limits', 'modules'])
                ->map(fn ($plan) => [
                    'id'            => $plan->id,
                    'name'          => $plan->name,
                    'description'   => $plan->description,
                    'currency'      => $plan->currency ?? 'PEN',
                    'price_monthly' => round((float) $plan->price_monthly, 2),
                    'price_yearly'  => round((float) $plan->price_yearly, 2),
                    'free_months'   => (float) $plan->price_monthly > 0 && (float) $plan->price_yearly > 0 ? max(0, (int) round((12 * (float) $plan->price_monthly - (float) $plan->price_yearly) / (float) $plan->price_monthly)) : 0,
                    'limits'        => collect($limitLabels)->map(fn ($label, $key) => ['key' => $key, 'label' => is_numeric($plan->limits[$key] ?? null) && (int) $plan->limits[$key] === 1 ? $label[0] : $label[1], 'value' => is_numeric($plan->limits[$key] ?? null) ? (int) $plan->limits[$key] : null])->filter(fn ($row) => array_key_exists($row['key'], $plan->limits ?? []))->values(),
                    'modules'       => $plan->moduleKeys(),
                ])->values(),
        ]);
    }

    public function card(Request $request)
    {
        $subscription = $this->subscription($request);
        [$plan, $cycle] = $this->choice($request, $subscription);

        return response()->json(PlanBilling::card($subscription, $request->user(), $plan, $cycle));
    }

    public function pay(Request $request)
    {
        $subscription = $this->subscription($request);
        [$plan, $cycle] = $this->choice($request, $subscription);

        return response()->json(PlanBilling::pay($subscription, $request->user(), $plan, $cycle));
    }

    public function activate(Request $request)
    {
        $subscription = $this->subscription($request);
        [$plan, $cycle] = $this->choice($request, $subscription);
        abort_unless(PlanBilling::cardEnabled(), 422, PlanBilling::NOT_CONNECTED);

        if (($check = \App\Support\PlanChanges::preview($subscription, $plan, $cycle))['blocked']) {
            return \App\Support\PlanChanges::limitResponse($check);
        }

        if (Subscriptions::price($plan, $cycle) <= 0) {
            return response()->json(self::changePlan($request, $subscription, $plan, $cycle));
        }

        if ($plan->id !== $subscription->plan_id || $cycle !== $subscription->billing_cycle) {
            $before = $subscription->only(['plan_id', 'billing_cycle']);
            $subscription->update(['plan_id' => $plan->id, 'billing_cycle' => $cycle, 'pending_plan_id' => null, 'pending_billing_cycle' => null]);
            AuditEvent::record($request, 'subscription.plan_changed', $request->user()->tenant, $before, $subscription->only(['plan_id', 'billing_cycle']));
            Subscriptions::forget($subscription->tenant_id);
            $subscription = $subscription->fresh('plan');
        }

        if (PlanBilling::savedCard($subscription)) {
            $result = PlanBilling::chargeSaved($subscription, 'activate');

            if (!$result['ok']) {
                throw ValidationException::withMessages(['plan' => 'El cobro no pasó: ' . rtrim($result['message'], '.') . '. Cambia la tarjeta o paga con otro medio.']);
            }

            return response()->json(['message' => 'Listo: cobramos el plan a tu tarjeta y tu plan quedó activo.'] + json_decode($this->show($request)->getContent(), true));
        }

        return response()->json(PlanBilling::card($subscription, $request->user(), $plan, $cycle, 'activate'));
    }

    public function confirm(Request $request)
    {
        $subscription = $this->subscription($request);
        $validated = $request->validate([
            'payment_id' => 'required|uuid',
            'token'      => ['required', 'string', 'max:120', 'regex:/^(tkn|ype)_[A-Za-z0-9_]+$/'],
        ], ['token.regex' => 'Culqi no devolvió una tarjeta válida. Inténtalo de nuevo.']);

        $kind = DB::table('plan_payments')->where('id', $validated['payment_id'])->where('subscription_id', $subscription->id)->value('kind');
        abort_unless(in_array($kind, ['card', 'link'], true), 404, 'Este pago no existe.');

        $result = $kind === 'card'
            ? PlanBilling::confirmCard($subscription, $validated['payment_id'], $validated['token'], $request->user())
            : PlanBilling::confirmPay($subscription, $validated['payment_id'], $validated['token'], $request->user());

        if (!empty($result['checkout_url'])) {
            return response()->json($result);
        }

        AuditEvent::record($request, $kind === 'card' ? 'subscription.card_saved' : 'subscription.paid_online', $request->user()->tenant, [], ['provider' => 'Culqi']);

        return response()->json(['message' => $result['message'], 'charge_failed' => ($result['status'] ?? null) === 'failed'] + json_decode($this->show($request)->getContent(), true));
    }

    public function receipt(Request $request, string $invoice)
    {
        $user = $request->user();
        abort_unless(Subscriptions::manages($user), 403, Subscriptions::NO_PERMISSION);
        $row = \App\Models\SubscriptionInvoice::where('tenant_id', $user->tenant_id)->findOrFail($invoice);

        return response(\App\Support\PlanReceipts::pdf($row), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . \App\Support\PlanReceipts::fileName($row) . '"',
            'Cache-Control'       => 'private, no-store',
        ]);
    }

    public function requestCancel(Request $request)
    {
        $subscription = $this->subscription($request);
        $validated = $request->validate([
            'reason'       => ['required', Rule::in(array_keys(\App\Support\Cancellations::REASONS))],
            'details'      => 'nullable|string|max:500',
            'accept_offer' => 'boolean',
            'confirm'      => 'boolean',
        ], [
            'reason.required' => 'Elige por qué te vas.',
        ]);

        if (!($validated['accept_offer'] ?? false) && !($validated['confirm'] ?? false)) {
            throw ValidationException::withMessages(['confirm' => 'Confirma que entiendes qué pasa al dar de baja.']);
        }

        $result = \App\Support\Cancellations::request($subscription, $request->user(), $validated['reason'], $validated['details'] ?? null, (bool) ($validated['accept_offer'] ?? false));
        AuditEvent::record($request, $result['kept'] ? 'subscription.offer_accepted' : 'subscription.cancel_requested', $request->user()->tenant, [], array_filter([
            'reason' => \App\Support\Cancellations::REASONS[$validated['reason']],
            'offer'  => $result['offer']['text'] ?? null,
        ]));

        return $this->show($request);
    }

    public function resumeCancel(Request $request)
    {
        $subscription = $this->subscription($request);
        \App\Support\Cancellations::resume($subscription);
        AuditEvent::record($request, 'subscription.cancel_resumed', $request->user()->tenant, [], []);

        return $this->show($request);
    }

    public function exportAll(Request $request)
    {
        $user = $request->user();
        abort_unless(Subscriptions::manages($user), 403, Subscriptions::NO_PERMISSION);
        $tenant = $user->tenant;

        AuditEvent::create([
            'tenant_id'     => $tenant->id,
            'actor_user_id' => $user->id,
            'action'        => \App\Http\Controllers\Api\DataExportController::ACTION,
            'entity_type'   => 'Todos los datos',
            'ip_address'    => $request->ip(),
            'user_agent'    => mb_substr((string) $request->userAgent(), 0, 500),
            'after_data'    => ['title' => 'Todos los datos de la empresa', 'format' => 'xlsx', 'scope' => 'all', 'rows' => 0],
        ]);

        return response()->json(['company' => $tenant->name, 'sheets' => \App\Support\DataBackup::sheets($tenant)]);
    }

    public function cancelCard(Request $request)
    {
        $subscription = $this->subscription($request);
        PlanBilling::cancelCard($subscription);
        AuditEvent::record($request, 'subscription.card_cancelled', $request->user()->tenant, [], ['card_status' => 'cancelled']);

        return $this->show($request);
    }

    public function coupon(Request $request)
    {
        $subscription = $this->subscription($request);
        $code = $request->validate(['code' => 'required|string|max:30'], ['code.required' => 'Escribe el cupón.'])['code'];
        $redemption = \App\Support\PlanCoupons::redeem($subscription, $code, 'billing');
        \App\Support\Audit::log($request, 'plan_coupon.redeemed', ['tenant' => $subscription->tenant_id, 'entity_type' => 'plan_coupon', 'entity_id' => $redemption->coupon_id, 'entity_label' => $redemption->code]);
        Subscriptions::forget($subscription->tenant_id);

        return response()->json(['message' => "Listo: el cupón {$redemption->code} quedó aplicado. Lo verás en tu próximo cobro."] + json_decode($this->show($request)->getContent(), true));
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
        $preview = \App\Support\PlanChanges::preview($subscription, $plan, $cycle);

        if ($preview['blocked']) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(\App\Support\PlanChanges::limitResponse($preview));
        }

        if ($price <= 0) {
            PlanBilling::cancelCard($subscription);
            $subscription->update(['plan_id' => $plan->id, 'billing_cycle' => $cycle, 'status' => 'active', 'ends_at' => null, 'trial_ends_at' => null, 'canceled_at' => null, 'pending_plan_id' => null, 'pending_billing_cycle' => null]);
            Subscriptions::forget($subscription->tenant_id);
            AuditEvent::record($request, 'subscription.plan_changed', $user->tenant, $before, $subscription->only(['plan_id', 'billing_cycle', 'status']));
            \App\Support\SaasEvents::record($subscription->tenant_id);

            return ['message' => "Listo: tu empresa quedó en el plan {$plan->name}."];
        }

        abort_unless(PlanBilling::enabled(), 422, PlanBilling::NOT_CONNECTED);

        if (in_array($state, ['grace', 'locked', 'none'], true) || $subscription->status === 'canceled') {
            if (!PlanBilling::cardEnabled() || PlanBilling::savedCard($subscription)) {
                $subscription->update(['pending_plan_id' => $plan->id, 'pending_billing_cycle' => $cycle]);
                Subscriptions::forget($subscription->tenant_id);

                return ['message' => "Listo: elegiste el plan {$plan->name}. Ahora págalo con \"Pagar ahora\" para activarlo."];
            }

            return PlanBilling::pay($subscription, $user, $plan, $cycle);
        }

        if ($state === 'trial') {
            if ($same) {
                return ['message' => "Ya estás en el plan {$plan->name}."];
            }

            $subscription->update(['plan_id' => $plan->id, 'billing_cycle' => $cycle]);
            AuditEvent::record($request, 'subscription.plan_changed', $user->tenant, $before, $subscription->only(['plan_id', 'billing_cycle', 'status']));
            Subscriptions::forget($subscription->tenant_id);

            return ['message' => "Listo: tu prueba sigue con el plan {$plan->name}. Cuando quieras, actívalo con \"Activar mi plan\"."];
        }

        if ($same) {
            return ['message' => "Ya estás en el plan {$plan->name}."];
        }

        if ($preview['kind'] === 'upgrade' && $preview['immediate'] && $preview['today'] > 0) {
            $result = PlanBilling::chargeCard($subscription, $plan, $cycle, (float) $preview['today'], 'upgrade');

            if (!$result['ok']) {
                throw ValidationException::withMessages(['plan' => 'El cobro de la diferencia no pasó: ' . rtrim($result['message'], '.') . '. Cambia la tarjeta o elige el cambio para el próximo periodo.']);
            }

            AuditEvent::record($request, 'subscription.plan_changed', $user->tenant, $before, ['plan_id' => $plan->id, 'billing_cycle' => $cycle, 'prorated' => $preview['today']]);

            return ['message' => "Listo: ya estás en el plan {$plan->name}. Cobramos " . \App\Support\Currency::format((float) $preview['today'], $plan->currency ?? 'PEN') . ' por los días que faltan.'];
        }

        if ($preview['kind'] === 'yearly_now' && $preview['immediate']) {
            $result = $preview['today'] > 0
                ? PlanBilling::chargeCard($subscription, $plan, $cycle, (float) $preview['today'], 'yearly_now')
                : ['ok' => true, 'free' => Subscriptions::recordYearlyNow($subscription->fresh('plan'), $plan, 0.0, ['reference' => 'credit:' . $subscription->id])];

            if (!$result['ok']) {
                throw ValidationException::withMessages(['plan' => 'El cobro del pago anual no pasó: ' . rtrim($result['message'], '.') . '. Cambia la tarjeta o elige el cambio para el próximo periodo.']);
            }

            AuditEvent::record($request, 'subscription.plan_changed', $user->tenant, $before, ['plan_id' => $plan->id, 'billing_cycle' => 'yearly', 'yearly_now' => $preview['today'], 'credit' => $preview['credit']['amount'] ?? 0]);

            return ['message' => "Listo: pasaste al pago anual del plan {$plan->name}. Cobramos " . \App\Support\Currency::format((float) $preview['today'], $plan->currency ?? 'PEN') . ' con el descuento de los días que te quedaban.'];
        }

        if ($subscription->card_status === 'authorized' && $subscription->external_provider === 'mercadopago' && PlanBilling::cardEnabled()) {
            return PlanBilling::card($subscription, $user, $plan, $cycle);
        }

        $subscription->update(['pending_plan_id' => $plan->id, 'pending_billing_cycle' => $cycle]);
        AuditEvent::record($request, 'subscription.plan_changed', $user->tenant, $before, ['pending_plan_id' => $plan->id, 'pending_billing_cycle' => $cycle]);
        Subscriptions::forget($subscription->tenant_id);

        return ['message' => "El plan {$plan->name} empieza cuando pagues el próximo periodo."];
    }

    public function preview(Request $request)
    {
        $subscription = $this->subscription($request);
        [$plan, $cycle] = $this->choice($request, $subscription);

        return response()->json(\App\Support\PlanChanges::preview($subscription, $plan, $cycle));
    }

    public function cancelPending(Request $request)
    {
        $subscription = $this->subscription($request);
        abort_unless(Subscriptions::ready() && $subscription->pending_plan_id, 422, 'No hay un cambio de plan programado.');
        $before = $subscription->only(['pending_plan_id', 'pending_billing_cycle']);
        $subscription->update(['pending_plan_id' => null, 'pending_billing_cycle' => null]);
        Subscriptions::forget($subscription->tenant_id);
        AuditEvent::record($request, 'subscription.change_cancelled', $request->user()->tenant, $before, ['pending_plan_id' => null]);

        return response()->json(['message' => 'Listo: anulaste el cambio de plan. Sigues con tu plan actual.'] + json_decode($this->show($request)->getContent(), true));
    }

    public function details(Request $request)
    {
        $this->subscription($request);
        $validated = $request->validate([
            'doc_type'      => 'required|in:boleta,factura',
            'ruc'           => 'nullable|string|max:11',
            'business_name' => 'nullable|string|max:200',
            'address'       => 'nullable|string|max:255',
            'dni'           => 'nullable|string|max:8',
            'email'         => 'nullable|email|max:190',
        ], ['doc_type.required' => 'Elige si quieres boleta o factura.']);
        $tenant = $request->user()->tenant;
        $before = \App\Support\PlanInvoices::billingOf($tenant);
        $after = \App\Support\PlanInvoices::saveBilling($tenant, $validated);
        AuditEvent::record($request, 'subscription.billing_details', $tenant, array_intersect_key($before, $validated), array_intersect_key($after, $validated));

        return response()->json(['message' => 'Listo: tus próximos comprobantes del plan saldrán con estos datos.', 'billing_details' => $after]);
    }

    public function requestExport(Request $request)
    {
        $user = $request->user();
        abort_unless(Subscriptions::manages($user), 403, Subscriptions::NO_PERMISSION);
        $row = \App\Support\DataExports::request($user->tenant, $user, $request);

        return response()->json([
            'message' => 'Estamos preparando el archivo con todos tus datos. Te avisaremos en la campana cuando esté listo; el enlace sirve por ' . \App\Support\DataExports::DAYS . ' días.',
            'export'  => \App\Support\DataExports::present($row),
            'exports' => \App\Support\DataExports::listFor($user->tenant_id),
        ], 202);
    }

    public function exports(Request $request)
    {
        $user = $request->user();
        abort_unless(Subscriptions::manages($user), 403, Subscriptions::NO_PERMISSION);

        return response()->json(['items' => \App\Support\DataExports::listFor($user->tenant_id), 'ready' => \App\Support\DataExports::ready()]);
    }

    public function transferOptions(Request $request)
    {
        $subscription = $this->subscription($request, false);
        abort_unless(PlanTransfers::enabled(), 404, 'La plataforma no recibe pagos por transferencia por ahora.');
        $config = PlanTransfers::config();

        return response()->json([
            'review_text' => $config['review_text'],
            'accounts'    => collect($config['accounts'])->map(fn ($account) => $account + ['label' => PlanTransfers::label($account)])->values(),
            'pending'     => ($row = PlanTransfers::pendingFor($subscription->id)) ? PlanTransfers::present($row) : null,
        ]);
    }

    public function transfer(Request $request)
    {
        $subscription = $this->subscription($request);
        [$plan, $cycle] = $this->choice($request, $subscription);

        $validated = $request->validate([
            'method_id'        => 'required|string|max:40',
            'operation_number' => 'required|string|max:60',
            'paid_on'          => 'required|date|before_or_equal:today|after:' . now()->subDays(60)->toDateString(),
            'voucher'          => 'required|string',
        ], [
            'method_id.required'        => 'Elige a dónde pagaste.',
            'operation_number.required' => 'Escribe el número de operación que aparece en tu comprobante.',
            'paid_on.required'          => 'Escribe la fecha en que pagaste.',
            'paid_on.before_or_equal'   => 'La fecha no puede ser de un día que todavía no llega.',
            'paid_on.after'             => 'La fecha es muy antigua.',
            'voucher.required'          => 'Sube la foto o captura del comprobante.',
        ]);

        $row = PlanTransfers::report($subscription, $request->user(), $validated + ['plan_id' => $plan->id, 'billing_cycle' => $cycle]);
        \App\Support\Audit::log($request, 'subscription.transfer_sent', [
            'tenant'      => $subscription->tenant_id,
            'entity_type' => 'subscription',
            'entity_id'   => $subscription->id,
            'after'       => ['amount' => (float) $row->amount, 'method' => $row->method_label, 'operation_number' => $row->operation_number],
        ]);

        return response()->json([
            'message' => 'Recibimos tu comprobante. Lo revisaremos en ' . PlanTransfers::config()['review_text'] . ' y te avisaremos.',
        ] + json_decode($this->show($request)->getContent(), true));
    }

    public function transferVoucher(Request $request, string $id)
    {
        $subscription = $this->subscription($request, false);
        $row = \Illuminate\Support\Facades\DB::table('plan_payments')->where('id', $id)->where('subscription_id', $subscription->id)->where('kind', 'transfer')->first();
        abort_unless($row, 404);

        return PlanTransfers::voucher($row);
    }

    public function simulatedView(string $id)
    {
        $view = PlanBilling::simulatedView($id);
        abort_unless($view, 404, 'Este pago no existe.');

        return response()->json($view);
    }

    public function simulate(Request $request, string $id)
    {
        $validated = $request->validate(['result' => ['required', Rule::in(['approved', 'rejected', 'nofunds'])]]);

        return response()->json(PlanBilling::simulate($id, $validated['result']) + ['view' => PlanBilling::simulatedView($id)]);
    }

    private function addonChoice(Request $request): array
    {
        $validated = $request->validate([
            'addon_id' => 'required|uuid',
            'quantity' => 'nullable|integer|min:1|max:100',
        ], ['addon_id.required' => 'Elige un complemento.']);

        return [$validated['addon_id'], (int) ($validated['quantity'] ?? 1)];
    }

    public function addonPreview(Request $request)
    {
        $subscription = $this->subscription($request);
        [$addonId, $quantity] = $this->addonChoice($request);

        return response()->json(\App\Support\Addons::preview($subscription, $addonId, $quantity));
    }

    public function addAddon(Request $request)
    {
        $subscription = $this->subscription($request);
        [$addonId, $quantity] = $this->addonChoice($request);
        $result = \App\Support\Addons::contract($subscription, $addonId, $quantity, $request->user());
        \App\Support\Audit::log($request, 'subscription.addon_added', [
            'tenant' => $subscription->tenant_id, 'entity_type' => 'addon', 'entity_id' => $addonId, 'entity_label' => $result['preview']['addon']['name'],
            'after'  => ['quantity' => $quantity, 'amount' => $result['preview']['today']],
        ]);

        return response()->json(['message' => $result['message']] + json_decode($this->show($request)->getContent(), true));
    }

    public function removeAddon(Request $request, string $addon)
    {
        $subscription = $this->subscription($request);
        $result = \App\Support\Addons::remove($subscription, $addon, $request->user());
        \App\Support\Audit::log($request, 'subscription.addon_removed', ['tenant' => $subscription->tenant_id, 'entity_type' => 'addon', 'entity_id' => $addon, 'entity_label' => $result['name']]);

        return response()->json(['message' => $result['message']] + json_decode($this->show($request)->getContent(), true));
    }

    public function keepAddon(Request $request, string $addon)
    {
        $subscription = $this->subscription($request);
        $name = \App\Support\Addons::keep($subscription, $addon);
        \App\Support\Audit::log($request, 'subscription.addon_kept', ['tenant' => $subscription->tenant_id, 'entity_type' => 'addon', 'entity_id' => $addon, 'entity_label' => $name]);

        return response()->json(['message' => "Listo: «{$name}» sigue en tu plan."] + json_decode($this->show($request)->getContent(), true));
    }
}
