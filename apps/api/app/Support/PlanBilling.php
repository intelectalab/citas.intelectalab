<?php

namespace App\Support;

use App\Models\SaaSPlan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Payments\MercadoPagoClient;
use App\Support\Payments\MercadoPagoPaymentProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlanBilling
{
    public const NOT_CONNECTED = 'La plataforma todavía no recibe pagos en línea. Escribe a soporte para activar o renovar tu plan.';

    private static ?array $loaded = null;

    public static function integration(): ?object
    {
        self::$loaded ??= [Schema::hasTable('platform_integrations') ? DB::table('platform_integrations')->where('kind', 'payments')->first() : null];

        return self::$loaded[0];
    }

    public static function forgetIntegration(): void
    {
        self::$loaded = null;
    }

    public static function credentials(): array
    {
        $row = self::integration();

        return $row?->credentials ? (json_decode(Crypt::decryptString($row->credentials), true) ?: []) : [];
    }

    public static function connected(): bool
    {
        $row = self::integration();

        return Subscriptions::ready() && $row && $row->status === 'active' && $row->provider === 'mercadopago' && !empty(self::credentials()['secret_key']);
    }

    public static function cardEnabled(): bool
    {
        return (self::connected() || (Subscriptions::ready() && PaymentLinks::simulationAllowed()))
            && (bool) (\App\Models\PlatformSetting::value('plan_mercadopago') ?? true);
    }

    public static function enabled(): bool
    {
        return self::cardEnabled() || PlanTransfers::enabled();
    }

    public static function simulated(): bool
    {
        return !self::connected() && self::cardEnabled();
    }

    private static function client(): MercadoPagoClient
    {
        $client = MercadoPagoClient::fromCredentials(self::credentials());

        if (!$client) {
            throw ValidationException::withMessages(['plan' => self::NOT_CONNECTED]);
        }

        return $client;
    }

    private static function provider(): MercadoPagoPaymentProvider
    {
        return new MercadoPagoPaymentProvider(self::integration());
    }

    private static function url(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . $path;
    }

    private static function insert(Subscription $subscription, array $values): object
    {
        $id = (string) Str::uuid();

        DB::table('plan_payments')->insert($values + [
            'id'              => $id,
            'tenant_id'       => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'status'          => 'pending',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return DB::table('plan_payments')->where('id', $id)->first();
    }

    private static function assertCurrency(SaaSPlan $plan): void
    {
        $currency = self::integration() ? (json_decode(self::integration()->settings ?? '{}', true)['currency'] ?? null) : null;

        if (self::connected() && $currency && strtoupper($plan->currency ?? 'PEN') !== $currency) {
            throw ValidationException::withMessages(['plan' => "El plan {$plan->name} está en {$plan->currency} y la cuenta de cobro de la plataforma cobra en {$currency}. Escribe a soporte."]);
        }
    }

    public static function chargeStart(Subscription $subscription): Carbon
    {
        $end = Subscriptions::endOf($subscription);

        return $end && $end->gt(now()->addMinutes(30)) ? $end->copy() : now()->addMinutes(30);
    }

    public static function cardUrl(Subscription $subscription, User $user, ?SaaSPlan $plan = null, ?string $cycle = null): string
    {
        abort_unless(self::cardEnabled(), 422, self::NOT_CONNECTED);
        $plan ??= $subscription->plan;
        $cycle ??= $subscription->billing_cycle;
        $amount = Subscriptions::price($plan, $cycle);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['plan' => 'Este plan no tiene costo: no hace falta tarjeta.']);
        }

        self::assertCurrency($plan);

        DB::table('plan_payments')->where('subscription_id', $subscription->id)->where('kind', 'card')->where('status', 'pending')
            ->update(['status' => 'cancelled', 'message' => 'Lo reemplazó una autorización nueva.', 'updated_at' => now()]);

        $values = [
            'plan_id'       => $plan->id,
            'billing_cycle' => $cycle,
            'kind'          => 'card',
            'amount'        => $amount,
            'currency'      => substr($plan->currency ?? 'PEN', 0, 3),
            'created_by'    => $user->id,
        ];

        if (self::simulated()) {
            $row = self::insert($subscription, $values + ['provider' => 'simulated']);
            $url = self::url("/plan-pago/{$row->id}");
            DB::table('plan_payments')->where('id', $row->id)->update(['checkout_url' => $url]);

            return $url;
        }

        $tenant = Tenant::find($subscription->tenant_id);
        $result = self::client()->call('post', '/preapproval', [
            'reason'             => mb_substr("Plan {$plan->name} · {$tenant->name}", 0, 250),
            'external_reference' => $subscription->id,
            'payer_email'        => $user->email,
            'auto_recurring'     => [
                'frequency'          => $cycle === 'yearly' ? 12 : 1,
                'frequency_type'     => 'months',
                'start_date'         => self::chargeStart($subscription)->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'transaction_amount' => $amount,
                'currency_id'        => substr($plan->currency ?? 'PEN', 0, 3),
            ],
            'back_url'           => self::url('/companies?plan=tarjeta'),
            'status'             => 'pending',
        ], (string) Str::uuid());

        if (!$result['ok'] || empty($result['data']['init_point'])) {
            throw ValidationException::withMessages(['plan' => $result['message'] ?? 'Mercado Pago no preparó la autorización de la tarjeta.']);
        }

        self::insert($subscription, $values + [
            'provider'     => 'mercadopago',
            'external_id'  => (string) $result['data']['id'],
            'checkout_url' => $result['data']['init_point'],
        ]);

        return $result['data']['init_point'];
    }

    public static function payUrl(Subscription $subscription, User $user, SaaSPlan $plan, string $cycle): string
    {
        abort_unless(self::cardEnabled(), 422, self::NOT_CONNECTED);
        $amount = Subscriptions::price($plan, $cycle);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['plan' => 'Este plan no tiene costo.']);
        }

        self::assertCurrency($plan);

        DB::table('plan_payments')->where('subscription_id', $subscription->id)->where('kind', 'link')->where('status', 'pending')
            ->update(['status' => 'cancelled', 'message' => 'Lo reemplazó un pago nuevo.', 'updated_at' => now()]);

        $row = self::insert($subscription, [
            'plan_id'       => $plan->id,
            'billing_cycle' => $cycle,
            'kind'          => 'link',
            'amount'        => $amount,
            'currency'      => substr($plan->currency ?? 'PEN', 0, 3),
            'provider'      => self::simulated() ? 'simulated' : 'mercadopago',
            'created_by'    => $user->id,
        ]);

        if (self::simulated()) {
            $url = self::url("/plan-pago/{$row->id}");
            DB::table('plan_payments')->where('id', $row->id)->update(['checkout_url' => $url]);

            return $url;
        }

        $tenant = Tenant::find($subscription->tenant_id);
        $order = self::provider()->order([
            'amount'      => $amount,
            'currency'    => $plan->currency ?? 'PEN',
            'reference'   => 'plan:' . $row->id,
            'description' => "Plan {$plan->name} (" . ($cycle === 'yearly' ? 'un año' : 'un mes') . ") · {$tenant->name}",
            'email'       => $user->email,
            'return_url'  => self::url('/companies?plan=pago'),
            'idempotency' => 'plan:' . $row->id,
        ]);

        DB::table('plan_payments')->where('id', $row->id)->update(['external_id' => $order['external_id'], 'checkout_url' => $order['checkout_url'], 'updated_at' => now()]);

        return $order['checkout_url'];
    }

    public static function simulatedView(string $id): ?array
    {
        if (!Subscriptions::ready()) {
            return null;
        }

        $row = DB::table('plan_payments')->where('id', $id)->where('provider', 'simulated')->first();

        if (!$row || !PaymentLinks::simulationAllowed()) {
            return null;
        }

        $plan = SaaSPlan::find($row->plan_id);

        return [
            'id'            => $row->id,
            'kind'          => $row->kind,
            'status'        => $row->status,
            'amount'        => round((float) $row->amount, 2),
            'currency'      => $row->currency,
            'plan'          => $plan?->name,
            'billing_cycle' => $row->billing_cycle,
            'company'       => Tenant::find($row->tenant_id)?->name,
            'back'          => self::url('/companies?plan=' . ($row->kind === 'card' ? 'tarjeta' : 'pago')),
        ];
    }

    public static function simulate(string $id, bool $approved): array
    {
        abort_unless(Subscriptions::ready() && PaymentLinks::simulationAllowed(), 404);
        $row = DB::table('plan_payments')->where('id', $id)->where('provider', 'simulated')->first();
        abort_unless($row, 404);

        if ($row->status !== 'pending') {
            return ['status' => $row->status, 'message' => 'Este pago ya no está pendiente.'];
        }

        $subscription = Subscription::findOrFail($row->subscription_id);

        if (!$approved) {
            DB::table('plan_payments')->where('id', $row->id)->update(['status' => 'failed', 'message' => 'Rechazado en la simulación.', 'updated_at' => now()]);

            return ['status' => 'failed', 'message' => $row->kind === 'card' ? 'La tarjeta fue rechazada. Prueba con otra.' : 'El pago fue rechazado. Inténtalo con otro medio.'];
        }

        if ($row->kind === 'card') {
            self::authorized($subscription, $row, 'visa', '4242');

            return ['status' => 'paid', 'message' => 'Tarjeta autorizada. Se cobrará sola en cada periodo.'];
        }

        self::paid($subscription, $row, 'sim_' . Str::lower(Str::random(10)));

        return ['status' => 'paid', 'message' => 'Pago confirmado: tu plan quedó activo.'];
    }

    private static function authorized(Subscription $subscription, object $row, ?string $brand, ?string $last4): void
    {
        $previous = $subscription->external_subscription_id;

        DB::transaction(function () use ($subscription, $row, $brand, $last4) {
            DB::table('plan_payments')->where('id', $row->id)->update(['status' => 'paid', 'paid_at' => now(), 'updated_at' => now()]);

            $update = [
                'external_provider'        => $row->provider,
                'external_subscription_id' => $row->external_id ?? $row->id,
                'card_status'              => 'authorized',
                'card_brand'               => $brand,
                'card_last4'               => $last4,
                'card_authorized_at'       => now(),
                'last_charge_failed_at'    => null,
            ];

            if ($row->plan_id !== $subscription->plan_id || $row->billing_cycle !== $subscription->billing_cycle) {
                $update += in_array($subscription->status, ['trialing', 'canceled'], true) || !Subscriptions::endOf($subscription)?->isFuture()
                    ? ['plan_id' => $row->plan_id, 'billing_cycle' => $row->billing_cycle, 'pending_plan_id' => null, 'pending_billing_cycle' => null]
                    : ['pending_plan_id' => $row->plan_id, 'pending_billing_cycle' => $row->billing_cycle];
            }

            $subscription->update($update);
        });

        if ($previous && $previous !== ($row->external_id ?? $row->id) && $row->provider === 'mercadopago' && self::connected()) {
            self::client()->call('put', '/preapproval/' . rawurlencode($previous), ['status' => 'cancelled']);
        }

        Subscriptions::forget($subscription->tenant_id);
        self::notify($subscription->tenant_id, 'subscription.card', 'Tarjeta autorizada', 'La tarjeta quedó registrada en Mercado Pago. El plan se cobrará solo en cada periodo.', "card:{$row->id}");
    }

    private static function paid(Subscription $subscription, object $row, string $reference, ?string $reason = null): void
    {
        $invoice = Subscriptions::recordPayment($subscription->fresh(), [
            'plan_id'       => $row->plan_id,
            'billing_cycle' => $row->billing_cycle,
            'amount'        => (float) $row->amount,
            'reason'        => $reason ?? ($row->kind === 'charge' ? 'subscription_cycle' : 'online'),
            'reference'     => $reference,
            'notes'         => $row->kind === 'charge' ? 'Cobro automático a la tarjeta' : 'Pago en línea',
        ]);

        DB::table('plan_payments')->where('id', $row->id)->update(['status' => 'paid', 'paid_at' => now(), 'invoice_id' => $invoice->id, 'message' => null, 'updated_at' => now()]);

        $plan = SaaSPlan::find($row->plan_id);
        self::notify(
            $subscription->tenant_id,
            'subscription.charged',
            'Plan pagado',
            Currency::format((float) $row->amount, $row->currency) . " · Plan {$plan?->name}. Vigente hasta el " . Carbon::parse($invoice->period_end)->locale('es')->isoFormat('D [de] MMMM [de] YYYY') . '.',
            "plan-paid:{$row->id}",
        );
    }

    private static function notify(string $tenantId, string $type, string $title, string $body, string $key): void
    {
        try {
            foreach (Notifier::adminIds($tenantId) as $userId) {
                Notifier::send($tenantId, $userId, $type, $title, $body, '/companies?tab=plan', 'subscription', null, $key);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function cancelCard(Subscription $subscription): void
    {
        if (!Subscriptions::ready()) {
            return;
        }

        if ($subscription->external_provider === 'mercadopago' && $subscription->external_subscription_id && self::connected()) {
            self::client()->call('put', '/preapproval/' . rawurlencode($subscription->external_subscription_id), ['status' => 'cancelled']);
        }

        DB::table('plan_payments')->where('subscription_id', $subscription->id)->where('status', 'pending')
            ->update(['status' => 'cancelled', 'message' => 'Se canceló el cobro automático.', 'updated_at' => now()]);

        $subscription->update(['card_status' => $subscription->card_status ? 'cancelled' : null, 'external_subscription_id' => null]);
        Subscriptions::forget($subscription->tenant_id);
    }

    public static function sync(Subscription $subscription): void
    {
        if (!Subscriptions::ready()) {
            return;
        }

        if (self::connected()) {
            self::syncPending($subscription);

            if ($subscription->external_provider === 'mercadopago' && $subscription->external_subscription_id && $subscription->card_status === 'authorized') {
                self::syncCharges($subscription->fresh());
            }
        }

        if (PaymentLinks::simulationAllowed()) {
            self::simulateCharge($subscription->fresh());
        }
    }

    private static function syncPending(Subscription $subscription): void
    {
        $rows = DB::table('plan_payments')
            ->where('subscription_id', $subscription->id)
            ->where('provider', 'mercadopago')
            ->where('status', 'pending')
            ->whereIn('kind', ['card', 'link'])
            ->whereNotNull('external_id')
            ->where('created_at', '>', now()->subDays(7))
            ->get();

        foreach ($rows as $row) {
            if ($row->kind === 'card') {
                $result = self::client()->call('get', '/preapproval/' . rawurlencode($row->external_id));
                $status = $result['data']['status'] ?? null;

                if ($status === 'authorized') {
                    self::authorized($subscription->fresh(), $row, $result['data']['payment_method_id'] ?? null, null);
                } elseif (in_array($status, ['cancelled', 'canceled'], true)) {
                    DB::table('plan_payments')->where('id', $row->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
                }

                continue;
            }

            $event = self::provider()->status($row->external_id);

            if ($event && $event['type'] === 'payment.approved' && (float) $event['amount'] + 0.001 >= (float) $row->amount) {
                self::paid($subscription->fresh(), $row, 'mp:' . $row->external_id);
            } elseif ($event && $event['type'] === 'payment.rejected') {
                DB::table('plan_payments')->where('id', $row->id)->update(['status' => 'failed', 'message' => 'Mercado Pago rechazó el pago.', 'updated_at' => now()]);
            }
        }
    }

    private static function syncCharges(Subscription $subscription): void
    {
        $client = self::client();
        $preapproval = $client->call('get', '/preapproval/' . rawurlencode($subscription->external_subscription_id));
        $status = $preapproval['data']['status'] ?? null;

        if ($preapproval['ok'] && in_array($status, ['cancelled', 'canceled', 'paused'], true)) {
            $subscription->update(['card_status' => 'cancelled']);
            Subscriptions::forget($subscription->tenant_id);
            self::notify($subscription->tenant_id, 'subscription.charge_failed', 'El cobro automático se detuvo', 'Mercado Pago dejó de cobrar el plan a tu tarjeta. Autoriza una tarjeta otra vez o paga el periodo con el botón "Pagar ahora".', "card-stopped:{$subscription->external_subscription_id}");

            return;
        }

        $search = $client->call('get', '/authorized_payments/search', ['preapproval_id' => $subscription->external_subscription_id, 'limit' => 50]);

        foreach ($search['data']['results'] ?? [] as $charge) {
            self::applyCharge($subscription->fresh(), $charge);
        }
    }

    public static function applyCharge(Subscription $subscription, array $charge): void
    {
        $chargeId = (string) ($charge['id'] ?? '');

        if ($chargeId === '') {
            return;
        }

        $paymentStatus = $charge['payment']['status'] ?? null;
        $existing = DB::table('plan_payments')->where('provider', 'mercadopago')->where('kind', 'charge')->where('external_id', $chargeId)->first();

        if ($existing && $existing->status === 'paid') {
            return;
        }

        $planId = $subscription->pending_plan_id ?? $subscription->plan_id;
        $cycle = $subscription->pending_billing_cycle ?? $subscription->billing_cycle;
        $plan = SaaSPlan::find($planId);
        $amount = (float) ($charge['transaction_amount'] ?? Subscriptions::price($plan, $cycle));

        if ($paymentStatus === 'approved') {
            $row = $existing ?? self::insert($subscription, [
                'plan_id' => $planId, 'billing_cycle' => $cycle, 'kind' => 'charge', 'amount' => $amount,
                'currency' => substr($charge['currency_id'] ?? ($plan->currency ?? 'PEN'), 0, 3), 'provider' => 'mercadopago', 'external_id' => $chargeId,
            ]);
            self::paid($subscription, $row, 'mp_ap:' . $chargeId);

            return;
        }

        if (in_array($paymentStatus, ['rejected', 'cancelled'], true) && !$existing) {
            self::insert($subscription, [
                'plan_id' => $planId, 'billing_cycle' => $cycle, 'kind' => 'charge', 'amount' => $amount, 'status' => 'failed',
                'currency' => substr($charge['currency_id'] ?? ($plan->currency ?? 'PEN'), 0, 3), 'provider' => 'mercadopago', 'external_id' => $chargeId,
                'message' => 'Mercado Pago no pudo cobrar a la tarjeta.',
            ]);
            self::failed($subscription, $amount, $charge['currency_id'] ?? ($plan->currency ?? 'PEN'), "charge-failed:{$chargeId}");
        }
    }

    private static function failed(Subscription $subscription, float $amount, string $currency, string $key): void
    {
        $subscription->update(['last_charge_failed_at' => now()]);
        Subscriptions::forget($subscription->tenant_id);
        $grace = Subscriptions::state($subscription->tenant_id)['grace_ends_at'];
        self::notify(
            $subscription->tenant_id,
            'subscription.charge_failed',
            'No se pudo cobrar tu plan',
            'Mercado Pago no pudo cobrar ' . Currency::format($amount, $currency) . ' a tu tarjeta. Lo volverá a intentar; también puedes cambiar la tarjeta o pagar con "Pagar ahora"'
                . ($grace ? ' antes del ' . Carbon::parse($grace)->locale('es')->isoFormat('D [de] MMMM') : '') . '.',
            $key,
        );
    }

    private static function simulateCharge(Subscription $subscription): void
    {
        if ($subscription->external_provider !== 'simulated' || $subscription->card_status !== 'authorized') {
            return;
        }

        $end = Subscriptions::endOf($subscription);

        if (!$end || $end->isFuture()) {
            return;
        }

        $key = 'sim_ap:' . $subscription->id . ':' . $end->format('YmdHis');

        if (DB::table('plan_payments')->where('provider', 'simulated')->where('kind', 'charge')->where('external_id', $key)->exists()) {
            return;
        }

        $planId = $subscription->pending_plan_id ?? $subscription->plan_id;
        $cycle = $subscription->pending_billing_cycle ?? $subscription->billing_cycle;
        $plan = SaaSPlan::find($planId);
        $row = self::insert($subscription, [
            'plan_id' => $planId, 'billing_cycle' => $cycle, 'kind' => 'charge', 'amount' => Subscriptions::price($plan, $cycle),
            'currency' => substr($plan->currency ?? 'PEN', 0, 3), 'provider' => 'simulated', 'external_id' => $key,
        ]);
        self::paid($subscription, $row, $key);
    }

    public static function syncAll(): int
    {
        if (!Subscriptions::ready()) {
            return 0;
        }

        $ids = DB::table('subscriptions')
            ->where(function ($q) {
                $q->whereIn('id', DB::table('plan_payments')->select('subscription_id')->where('status', 'pending')->where('created_at', '>', now()->subDays(7)))
                    ->orWhere(fn ($c) => $c->where('card_status', 'authorized')->whereNotNull('external_subscription_id')
                        ->where(fn ($d) => $d->whereNotNull('last_charge_failed_at')
                            ->orWhere('ends_at', '<', now()->addDay())
                            ->orWhere('trial_ends_at', '<', now()->addDay())));
            })
            ->pluck('id');

        foreach ($ids as $id) {
            try {
                self::sync(Subscription::with('plan')->find($id));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $ids->count();
    }

    public static function webhook(Request $request): array
    {
        if (!self::connected()) {
            return ['status' => 'ignored'];
        }

        $secret = trim((string) (self::credentials()['webhook_secret'] ?? '')) ?: null;
        abort_unless(MercadoPagoClient::signatureValid($request, $secret), 400, 'Firma no válida.');

        $topic = MercadoPagoClient::topic($request);
        $id = MercadoPagoClient::dataId($request);

        if ($id === '') {
            return ['status' => 'ignored'];
        }

        $subscriptionId = match (true) {
            $topic === 'order' => DB::table('plan_payments')->where('provider', 'mercadopago')->where('kind', 'link')->where('external_id', $id)->value('subscription_id'),
            str_contains($topic, 'authorized_payment') => (function () use ($id) {
                $charge = self::client()->call('get', '/authorized_payments/' . rawurlencode($id));
                $preapproval = $charge['data']['preapproval_id'] ?? null;

                return $preapproval ? self::byPreapproval($preapproval) : null;
            })(),
            str_contains($topic, 'preapproval') => self::byPreapproval($id),
            default => null,
        };

        if (!$subscriptionId) {
            return ['status' => 'ignored'];
        }

        self::sync(Subscription::with('plan')->find($subscriptionId));

        return ['status' => 'processed'];
    }

    private static function byPreapproval(string $preapprovalId): ?string
    {
        return DB::table('subscriptions')->where('external_subscription_id', $preapprovalId)->value('id')
            ?? DB::table('plan_payments')->where('provider', 'mercadopago')->where('kind', 'card')->where('external_id', $preapprovalId)->value('subscription_id');
    }

    public static function history(string $subscriptionId, int $limit = 24): array
    {
        if (!Subscriptions::ready()) {
            return [];
        }

        return DB::table('plan_payments')
            ->where('subscription_id', $subscriptionId)
            ->where(fn ($q) => $q->where('status', '!=', 'cancelled')->orWhere('kind', '!=', 'card'))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id'        => $row->id,
                'kind'      => $row->kind,
                'status'    => $row->status,
                'amount'    => round((float) $row->amount, 2),
                'currency'  => $row->currency,
                'provider'  => $row->provider,
                'message'   => $row->message,
                'at'        => Carbon::parse($row->paid_at ?? $row->created_at)->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
