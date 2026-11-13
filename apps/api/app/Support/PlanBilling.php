<?php

namespace App\Support;

use App\Models\PlatformSetting;
use App\Models\SaaSPlan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Payments\CulqiClient;
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

    public const PROVIDERS = ['culqi' => 'Culqi', 'mercadopago' => 'Mercado Pago'];

    public const SAVED_CARD_PROVIDERS = ['culqi', 'simulated'];

    public const SIMULATED_NO_FUNDS = '0002';

    private static ?array $loaded = null;

    public static function integration(string $provider): ?object
    {
        if (self::$loaded === null) {
            self::$loaded = Schema::hasTable('platform_integrations')
                ? DB::table('platform_integrations')->where('kind', 'payments')->get()->keyBy('provider')->all()
                : [];
        }

        return self::$loaded[$provider] ?? null;
    }

    public static function forgetIntegration(): void
    {
        self::$loaded = null;
    }

    public static function credentials(string $provider): array
    {
        $row = self::integration($provider);

        return $row?->credentials ? (json_decode(Crypt::decryptString($row->credentials), true) ?: []) : [];
    }

    public static function connected(?string $provider = null): bool
    {
        if ($provider === null) {
            return self::connected('culqi') || self::connected('mercadopago');
        }

        $row = self::integration($provider);

        return Subscriptions::ready() && $row && $row->status === 'active' && !empty(self::credentials($provider)['secret_key']);
    }

    public static function primary(): string
    {
        $value = PlatformSetting::value('plan_primary');

        return array_key_exists((string) $value, self::PROVIDERS) ? (string) $value : 'culqi';
    }

    public static function backup(): string
    {
        return self::primary() === 'culqi' ? 'mercadopago' : 'culqi';
    }

    public static function active(): ?string
    {
        if (self::connected(self::primary())) {
            return self::primary();
        }

        return self::connected(self::backup()) ? self::backup() : null;
    }

    public static function cardEnabled(): bool
    {
        return (self::connected() || (Subscriptions::ready() && PaymentLinks::simulationAllowed()))
            && (bool) (PlatformSetting::value('plan_mercadopago') ?? true);
    }

    public static function enabled(): bool
    {
        return self::cardEnabled() || PlanTransfers::enabled();
    }

    public static function simulated(): bool
    {
        return !self::connected() && self::cardEnabled();
    }

    public static function savedCard(Subscription $subscription): bool
    {
        return Subscriptions::ready() && $subscription->card_status === 'authorized'
            && in_array($subscription->external_provider, self::SAVED_CARD_PROVIDERS, true)
            && !empty($subscription->external_subscription_id);
    }

    private static function mpClient(): MercadoPagoClient
    {
        $client = MercadoPagoClient::fromCredentials(self::credentials('mercadopago'));

        if (!$client) {
            throw ValidationException::withMessages(['plan' => self::NOT_CONNECTED]);
        }

        return $client;
    }

    private static function culqiClient(): CulqiClient
    {
        $client = CulqiClient::fromCredentials(self::credentials('culqi'));

        if (!$client) {
            throw ValidationException::withMessages(['plan' => self::NOT_CONNECTED]);
        }

        return $client;
    }

    private static function mpProvider(): MercadoPagoPaymentProvider
    {
        return new MercadoPagoPaymentProvider(self::integration('mercadopago'));
    }

    private static function url(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . $path;
    }

    private static function insert(Subscription $subscription, array $values): object
    {
        $id = (string) Str::uuid();

        if (!Schema::hasColumn('plan_payments', 'intent')) {
            unset($values['intent']);
        }

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
        $integration = self::integration('mercadopago');
        $currency = $integration ? (json_decode($integration->settings ?? '{}', true)['currency'] ?? null) : null;

        if (self::active() === 'mercadopago' && $currency && strtoupper($plan->currency ?? 'PEN') !== $currency) {
            throw ValidationException::withMessages(['plan' => "El plan {$plan->name} está en {$plan->currency} y la cuenta de cobro de la plataforma cobra en {$currency}. Escribe a soporte."]);
        }

        if (self::active() === 'culqi' && !in_array(strtoupper($plan->currency ?? 'PEN'), ['PEN', 'USD'], true)) {
            throw ValidationException::withMessages(['plan' => "Culqi solo cobra en soles o dólares y el plan {$plan->name} está en {$plan->currency}. Escribe a soporte."]);
        }
    }

    public static function chargeStart(Subscription $subscription): Carbon
    {
        $end = Subscriptions::endOf($subscription);

        return $end && $end->gt(now()->addMinutes(30)) ? $end->copy() : now()->addMinutes(30);
    }

    private static function description(SaaSPlan $plan, string $cycle, Tenant $tenant): string
    {
        return "Plan {$plan->name} (" . ($cycle === 'yearly' ? 'un año' : 'un mes') . ") · {$tenant->name}";
    }

    private static function culqiCheckout(object $row, string $action, User $user, SaaSPlan $plan, string $cycle): array
    {
        $tenant = Tenant::find($row->tenant_id);

        return ['culqi' => [
            'action'      => $action,
            'payment_id'  => $row->id,
            'public_key'  => self::credentials('culqi')['public_key'] ?? '',
            'script'      => (string) config('payments.culqi.script'),
            'title'       => PlatformMail::productName(),
            'description' => self::description($plan, $cycle, $tenant),
            'amount'      => CulqiClient::cents((float) $row->amount),
            'currency'    => $row->currency,
            'email'       => $user->email,
        ]];
    }

    private static function pendingRow(Subscription $subscription, string $kind, User $user, SaaSPlan $plan, string $cycle, string $provider, ?string $intent = null): object
    {
        DB::table('plan_payments')->where('subscription_id', $subscription->id)->where('kind', $kind)->where('status', 'pending')
            ->update(['status' => 'cancelled', 'message' => $kind === 'card' ? 'Lo reemplazó una autorización nueva.' : 'Lo reemplazó un pago nuevo.', 'updated_at' => now()]);

        return self::insert($subscription, [
            'plan_id'       => $plan->id,
            'billing_cycle' => $cycle,
            'kind'          => $kind,
            'amount'        => Subscriptions::price($plan, $cycle),
            'currency'      => substr($plan->currency ?? 'PEN', 0, 3),
            'provider'      => $provider,
            'created_by'    => $user->id,
            'intent'        => $intent,
        ]);
    }

    private static function simulatorUrl(object $row): string
    {
        $url = self::url("/plan-pago/{$row->id}");
        DB::table('plan_payments')->where('id', $row->id)->update(['checkout_url' => $url]);

        return $url;
    }

    public static function card(Subscription $subscription, User $user, ?SaaSPlan $plan = null, ?string $cycle = null, ?string $intent = null): array
    {
        abort_unless(self::cardEnabled(), 422, self::NOT_CONNECTED);
        $plan ??= $subscription->plan;
        $cycle ??= $subscription->billing_cycle;

        if (Subscriptions::price($plan, $cycle) <= 0) {
            throw ValidationException::withMessages(['plan' => 'Este plan no tiene costo: no hace falta tarjeta.']);
        }

        self::assertCurrency($plan);
        $provider = self::active();

        if ($provider === null) {
            return ['checkout_url' => self::simulatorUrl(self::pendingRow($subscription, 'card', $user, $plan, $cycle, 'simulated', $intent))];
        }

        if ($provider === 'culqi') {
            return self::culqiCheckout(self::pendingRow($subscription, 'card', $user, $plan, $cycle, 'culqi', $intent), 'card', $user, $plan, $cycle);
        }

        if ($intent === 'activate') {
            return self::pay($subscription, $user, $plan, $cycle);
        }

        $row = self::pendingRow($subscription, 'card', $user, $plan, $cycle, 'mercadopago', $intent);
        $tenant = Tenant::find($subscription->tenant_id);
        $result = self::mpClient()->call('post', '/preapproval', [
            'reason'             => mb_substr("Plan {$plan->name} · {$tenant->name}", 0, 250),
            'external_reference' => $subscription->id,
            'payer_email'        => $user->email,
            'auto_recurring'     => [
                'frequency'          => $cycle === 'yearly' ? 12 : 1,
                'frequency_type'     => 'months',
                'start_date'         => self::chargeStart($subscription)->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'transaction_amount' => (float) $row->amount,
                'currency_id'        => $row->currency,
            ],
            'back_url'           => self::url('/companies?plan=tarjeta'),
            'status'             => 'pending',
        ], (string) Str::uuid());

        if (!$result['ok'] || empty($result['data']['init_point'])) {
            DB::table('plan_payments')->where('id', $row->id)->update(['status' => 'failed', 'message' => mb_substr((string) ($result['message'] ?? 'Mercado Pago no respondió.'), 0, 250), 'updated_at' => now()]);
            throw ValidationException::withMessages(['plan' => $result['message'] ?? 'Mercado Pago no preparó la autorización de la tarjeta.']);
        }

        DB::table('plan_payments')->where('id', $row->id)->update([
            'external_id'  => (string) $result['data']['id'],
            'checkout_url' => $result['data']['init_point'],
            'updated_at'   => now(),
        ]);

        return ['checkout_url' => $result['data']['init_point']];
    }

    public static function pay(Subscription $subscription, User $user, SaaSPlan $plan, string $cycle, ?string $avoid = null): array
    {
        abort_unless(self::cardEnabled(), 422, self::NOT_CONNECTED);

        if (Subscriptions::price($plan, $cycle) <= 0) {
            throw ValidationException::withMessages(['plan' => 'Este plan no tiene costo.']);
        }

        self::assertCurrency($plan);
        $provider = self::active();

        if ($avoid && $provider === $avoid) {
            $provider = self::connected(self::backup()) && self::backup() !== $avoid ? self::backup() : null;
            abort_unless($provider, 422, 'Culqi no respondió y no hay otro medio en línea. Inténtalo en unos minutos.');
        }

        if ($provider === null) {
            return ['checkout_url' => self::simulatorUrl(self::pendingRow($subscription, 'link', $user, $plan, $cycle, 'simulated'))];
        }

        if ($provider === 'culqi') {
            return self::culqiCheckout(self::pendingRow($subscription, 'link', $user, $plan, $cycle, 'culqi'), 'pay', $user, $plan, $cycle);
        }

        $row = self::pendingRow($subscription, 'link', $user, $plan, $cycle, 'mercadopago');
        $order = self::mpProvider()->order([
            'amount'      => (float) $row->amount,
            'currency'    => $row->currency,
            'reference'   => 'plan:' . $row->id,
            'description' => self::description($plan, $cycle, Tenant::find($subscription->tenant_id)),
            'email'       => $user->email,
            'return_url'  => self::url('/companies?plan=pago'),
            'idempotency' => 'plan:' . $row->id,
        ]);

        DB::table('plan_payments')->where('id', $row->id)->update(['external_id' => $order['external_id'], 'checkout_url' => $order['checkout_url'], 'updated_at' => now()]);

        return ['checkout_url' => $order['checkout_url']];
    }

    private static function culqiRow(Subscription $subscription, string $paymentId, string $kind): object
    {
        $row = DB::table('plan_payments')->where('id', $paymentId)->where('subscription_id', $subscription->id)
            ->where('kind', $kind)->where('provider', 'culqi')->first();

        abort_unless($row, 404, 'Este pago no existe.');

        if ($row->status !== 'pending') {
            throw ValidationException::withMessages(['plan' => 'Este pago ya no está pendiente. Vuelve a intentarlo desde Tu plan.']);
        }

        return $row;
    }

    private static function failRow(object $row, string $message): void
    {
        DB::table('plan_payments')->where('id', $row->id)->update(['status' => 'failed', 'message' => mb_substr($message, 0, 250), 'updated_at' => now()]);
    }

    private static function ownerEmail(string $tenantId): ?string
    {
        return DB::table('users')->where('tenant_id', $tenantId)->where('is_owner', true)->value('email')
            ?? DB::table('users')->where('tenant_id', $tenantId)->orderBy('created_at')->value('email');
    }

    public static function confirmCard(Subscription $subscription, string $paymentId, string $token, User $user): array
    {
        $row = self::culqiRow($subscription, $paymentId, 'card');
        $client = self::culqiClient();
        $tenant = Tenant::find($subscription->tenant_id);
        $previous = DB::table('saas_payment_methods')->where('tenant_id', $tenant->id)->where('provider', 'culqi')->where('status', 'active')->get();
        $customerId = $previous->first()?->customer_ref;

        if (!$customerId) {
            $company = DB::table('companies')->where('tenant_id', $tenant->id)->first();
            $customer = $client->customer([
                'first_name'   => mb_substr($user->first_name ?: $tenant->name, 0, 50),
                'last_name'    => mb_substr($user->last_name ?: $tenant->name, 0, 50),
                'email'        => $user->email,
                'address'      => mb_substr($company?->address ?: 'Lima', 0, 100),
                'address_city' => mb_substr($company?->district ?: ($company?->department ?: 'Lima'), 0, 30),
                'country_code' => 'PE',
                'phone_number' => preg_replace('/\D/', '', (string) ($company?->phone ?: $user->phone ?? '')) ?: '900000000',
            ]);

            if (!$customer['ok']) {
                self::failRow($row, $customer['message']);
                throw ValidationException::withMessages(['plan' => $customer['message']]);
            }

            $customerId = $customer['id'];
        }

        $card = $client->saveCard($customerId, $token);

        if (CulqiClient::needsAuthentication($card)) {
            $message = 'Tu banco pide una verificación adicional para esta tarjeta y todavía no podemos hacerla. Prueba con otra tarjeta o paga con otro medio.';
            self::failRow($row, $message);
            throw ValidationException::withMessages(['plan' => $message]);
        }

        if (!$card['ok'] || empty($card['data']['id'])) {
            $message = $card['message'] ?? 'Culqi no guardó la tarjeta. Prueba con otra.';
            self::failRow($row, $message);
            throw ValidationException::withMessages(['plan' => $message]);
        }

        $cardId = (string) $card['data']['id'];
        $info = CulqiClient::cardInfo($card['data']);

        DB::transaction(function () use ($tenant, $subscription, $row, $customerId, $cardId, $info, $user) {
            DB::table('saas_payment_methods')->where('tenant_id', $tenant->id)->where('status', 'active')->update(['status' => 'removed', 'updated_at' => now()]);
            DB::table('saas_payment_methods')->insert([
                'id'              => (string) Str::uuid(),
                'tenant_id'       => $tenant->id,
                'subscription_id' => $subscription->id,
                'provider'        => 'culqi',
                'customer_ref'    => $customerId,
                'card_ref'        => $cardId,
                'brand'           => $info['brand'],
                'last4'           => $info['last4'],
                'status'          => 'active',
                'created_by'      => $user->id,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
            DB::table('plan_payments')->where('id', $row->id)->update(['external_id' => $cardId, 'updated_at' => now()]);
        });

        foreach ($previous as $old) {
            if ($old->card_ref !== $cardId) {
                $client->deleteCard($old->card_ref);
            }
        }

        $row = DB::table('plan_payments')->where('id', $row->id)->first();
        self::authorized($subscription, $row, $info['brand'], $info['last4']);

        return self::afterAuthorized($subscription->fresh('plan'), $row, $user);
    }

    private static function afterAuthorized(Subscription $subscription, object $row, ?User $user = null): array
    {
        $state = Subscriptions::state($subscription->tenant_id)['state'];
        $activate = ($row->intent ?? null) === 'activate';

        if (!self::savedCard($subscription) || (!$activate && !in_array($state, ['grace', 'locked'], true))) {
            return ['status' => 'paid', 'message' => 'Tarjeta guardada. El plan se cobrará solo en cada periodo.'];
        }

        $result = self::chargeSaved($subscription, $activate ? 'activate' : 'retry');

        if (!$activate) {
            Dunning::log($subscription, 'retry', $result['ok'] ? 'ok' : 'failed', $result['message'], $result['payment_id'] ?? null, $user?->id);
        }

        return $result['ok']
            ? ['status' => 'paid', 'message' => $activate ? 'Listo: guardamos tu tarjeta y tu plan quedó activo.' : 'Listo: guardamos tu tarjeta y cobramos lo vencido. Tu plan quedó al día.']
            : ['status' => 'failed', 'message' => 'Guardamos tu tarjeta, pero el cobro no pasó: ' . $result['message']];
    }

    public static function confirmPay(Subscription $subscription, string $paymentId, string $token, User $user): array
    {
        $row = self::culqiRow($subscription, $paymentId, 'link');
        $plan = SaaSPlan::find($row->plan_id);
        $result = self::culqiClient()->charge(
            (float) $row->amount,
            $row->currency,
            $user->email,
            $token,
            self::description($plan, $row->billing_cycle, Tenant::find($subscription->tenant_id)),
            ['plan_payment' => $row->id, 'subscription' => $subscription->id],
        );

        if (CulqiClient::chargeApproved($result)) {
            $chargeId = (string) $result['data']['id'];
            DB::table('plan_payments')->where('id', $row->id)->update(['external_id' => $chargeId, 'updated_at' => now()]);
            self::paid($subscription, DB::table('plan_payments')->where('id', $row->id)->first(), 'culqi:' . $chargeId);

            return ['status' => 'paid', 'message' => 'Pago confirmado: tu plan quedó activo.'];
        }

        if (CulqiClient::unavailable($result) && self::connected('mercadopago')) {
            self::failRow($row, 'Culqi no respondió; se ofreció Mercado Pago.');

            return self::pay($subscription, $user, $plan, $row->billing_cycle, 'culqi') + ['message' => 'Culqi no respondió. Te llevamos a Mercado Pago para que pagues ahí.'];
        }

        $message = $result['message'] ?? 'El pago fue rechazado. Prueba con otra tarjeta.';
        self::failRow($row, $message);
        throw ValidationException::withMessages(['plan' => $message]);
    }

    public static function chargeSaved(Subscription $subscription, string $reason = 'cycle'): array
    {
        if (!self::savedCard($subscription)) {
            return ['ok' => false, 'skipped' => true, 'message' => 'No hay una tarjeta guardada para cobrar.'];
        }

        $planId = $subscription->pending_plan_id ?? $subscription->plan_id;
        $cycle = $subscription->pending_billing_cycle ?? $subscription->billing_cycle;
        $plan = SaaSPlan::find($planId);
        $amount = Subscriptions::price($plan, $cycle);

        if ($amount <= 0) {
            return ['ok' => false, 'skipped' => true, 'message' => 'El plan no tiene costo.'];
        }

        $provider = $subscription->external_provider;
        $row = self::insert($subscription, [
            'plan_id'       => $planId,
            'billing_cycle' => $cycle,
            'kind'          => 'charge',
            'amount'        => $amount,
            'currency'      => substr($plan->currency ?? 'PEN', 0, 3),
            'provider'      => $provider,
            'intent'        => $reason,
        ]);

        if ($provider === 'simulated') {
            if (!PaymentLinks::simulationAllowed()) {
                self::failRow($row, 'La simulación no está disponible.');

                return ['ok' => false, 'payment_id' => $row->id, 'message' => 'La simulación no está disponible.'];
            }

            if ($subscription->card_last4 === self::SIMULATED_NO_FUNDS) {
                return self::chargeFailed($subscription, $row, 'La tarjeta no tiene fondos suficientes.');
            }

            DB::table('plan_payments')->where('id', $row->id)->update(['external_id' => 'sim_ch:' . $row->id]);
            self::paid($subscription, DB::table('plan_payments')->where('id', $row->id)->first(), 'sim_ch:' . $row->id);

            return ['ok' => true, 'payment_id' => $row->id, 'message' => 'Cobro aprobado (simulación).'];
        }

        $client = CulqiClient::fromCredentials(self::credentials('culqi'));

        if (!$client || !self::connected('culqi')) {
            return self::chargeFailed($subscription, $row, 'La cuenta de Culqi de la plataforma no está conectada.', false);
        }

        $result = $client->charge(
            $amount,
            $row->currency,
            self::ownerEmail($subscription->tenant_id) ?? 'pagos@example.com',
            $subscription->external_subscription_id,
            self::description($plan, $cycle, Tenant::find($subscription->tenant_id)),
            ['plan_payment' => $row->id, 'subscription' => $subscription->id],
        );

        if (CulqiClient::chargeApproved($result)) {
            $chargeId = (string) $result['data']['id'];
            DB::table('plan_payments')->where('id', $row->id)->update(['external_id' => $chargeId, 'updated_at' => now()]);
            self::paid($subscription, DB::table('plan_payments')->where('id', $row->id)->first(), 'culqi:' . $chargeId);

            return ['ok' => true, 'payment_id' => $row->id, 'message' => 'Cobro aprobado.'];
        }

        return self::chargeFailed($subscription, $row, $result['message'] ?? 'Culqi no pudo cobrar a la tarjeta.', !CulqiClient::unavailable($result));
    }

    private static function chargeFailed(Subscription $subscription, object $row, string $message, bool $notify = true): array
    {
        self::failRow($row, $message);

        if ($notify) {
            self::failed($subscription, (float) $row->amount, $row->currency, "charge-failed:{$row->id}", $message);
        }

        return ['ok' => false, 'payment_id' => $row->id, 'message' => $message];
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
            'intent'        => $row->intent ?? null,
            'status'        => $row->status,
            'amount'        => round((float) $row->amount, 2),
            'currency'      => $row->currency,
            'plan'          => $plan?->name,
            'billing_cycle' => $row->billing_cycle,
            'company'       => Tenant::find($row->tenant_id)?->name,
            'back'          => self::url('/companies?plan=' . ($row->kind === 'card' ? 'tarjeta' : 'pago')),
        ];
    }

    public static function simulate(string $id, string $result): array
    {
        abort_unless(Subscriptions::ready() && PaymentLinks::simulationAllowed(), 404);
        $row = DB::table('plan_payments')->where('id', $id)->where('provider', 'simulated')->first();
        abort_unless($row, 404);

        if ($row->status !== 'pending') {
            return ['status' => $row->status, 'message' => 'Este pago ya no está pendiente.'];
        }

        $subscription = Subscription::findOrFail($row->subscription_id);

        if ($result === 'rejected') {
            self::failRow($row, 'Rechazado en la simulación.');

            return ['status' => 'failed', 'message' => $row->kind === 'card' ? 'La tarjeta fue rechazada. Prueba con otra.' : 'El pago fue rechazado. Inténtalo con otro medio.'];
        }

        if ($row->kind === 'card') {
            self::authorized($subscription, $row, 'visa', $result === 'nofunds' ? self::SIMULATED_NO_FUNDS : '4242');

            return self::afterAuthorized($subscription->fresh('plan'), $row, $row->created_by ? User::find($row->created_by) : null);
        }

        self::paid($subscription, $row, 'sim_' . Str::lower(Str::random(10)));

        return ['status' => 'paid', 'message' => 'Pago confirmado: tu plan quedó activo.'];
    }

    private static function authorized(Subscription $subscription, object $row, ?string $brand, ?string $last4): void
    {
        $previous = $subscription->external_provider === 'mercadopago' ? $subscription->external_subscription_id : null;

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

        if ($previous && $previous !== ($row->external_id ?? $row->id) && self::connected('mercadopago')) {
            self::mpClient()->call('put', '/preapproval/' . rawurlencode($previous), ['status' => 'cancelled']);
        }

        Subscriptions::forget($subscription->tenant_id);
        self::notify($subscription->tenant_id, 'subscription.card', 'Tarjeta guardada', 'La tarjeta quedó registrada para el cobro del plan. Se cobrará sola en cada periodo.', "card:{$row->id}");
    }

    private static function paid(Subscription $subscription, object $row, string $reference, ?string $reason = null): void
    {
        $intent = $row->intent ?? null;
        $invoice = Subscriptions::recordPayment($subscription->fresh(), [
            'plan_id'       => $row->plan_id,
            'billing_cycle' => $row->billing_cycle,
            'amount'        => (float) $row->amount,
            'reason'        => $reason ?? ($row->kind === 'charge' ? ($intent === 'activate' ? 'subscription_create' : 'subscription_cycle') : 'online'),
            'reference'     => $reference,
            'notes'         => $row->kind === 'charge' ? 'Cobro a la tarjeta guardada' : 'Pago en línea',
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

    public static function notify(string $tenantId, string $type, string $title, string $body, string $key): void
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

        if ($subscription->external_provider === 'mercadopago' && $subscription->external_subscription_id && self::connected('mercadopago')) {
            self::mpClient()->call('put', '/preapproval/' . rawurlencode($subscription->external_subscription_id), ['status' => 'cancelled']);
        }

        if (Schema::hasTable('saas_payment_methods')) {
            $methods = DB::table('saas_payment_methods')->where('tenant_id', $subscription->tenant_id)->where('status', 'active')->get();
            $client = self::connected('culqi') ? CulqiClient::fromCredentials(self::credentials('culqi')) : null;

            foreach ($methods as $method) {
                if ($client && $method->provider === 'culqi') {
                    $client->deleteCard($method->card_ref);
                }
            }

            DB::table('saas_payment_methods')->where('tenant_id', $subscription->tenant_id)->where('status', 'active')->update(['status' => 'removed', 'updated_at' => now()]);
        }

        DB::table('plan_payments')->where('subscription_id', $subscription->id)->where('status', 'pending')
            ->update(['status' => 'cancelled', 'message' => 'Se canceló el cobro automático.', 'updated_at' => now()]);

        $subscription->update(['card_status' => $subscription->card_status ? 'cancelled' : null, 'external_subscription_id' => null]);
        Subscriptions::forget($subscription->tenant_id);
    }

    public static function sync(Subscription $subscription): void
    {
        if (!Subscriptions::ready() || !self::connected('mercadopago')) {
            return;
        }

        self::syncPending($subscription);

        if ($subscription->external_provider === 'mercadopago' && $subscription->external_subscription_id && $subscription->card_status === 'authorized') {
            self::syncCharges($subscription->fresh());
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
                $result = self::mpClient()->call('get', '/preapproval/' . rawurlencode($row->external_id));
                $status = $result['data']['status'] ?? null;

                if ($status === 'authorized') {
                    self::authorized($subscription->fresh(), $row, $result['data']['payment_method_id'] ?? null, null);
                } elseif (in_array($status, ['cancelled', 'canceled'], true)) {
                    DB::table('plan_payments')->where('id', $row->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
                }

                continue;
            }

            $event = self::mpProvider()->status($row->external_id);

            if ($event && $event['type'] === 'payment.approved' && (float) $event['amount'] + 0.001 >= (float) $row->amount) {
                self::paid($subscription->fresh(), $row, 'mp:' . $row->external_id);
            } elseif ($event && $event['type'] === 'payment.rejected') {
                self::failRow($row, 'Mercado Pago rechazó el pago.');
            }
        }
    }

    private static function syncCharges(Subscription $subscription): void
    {
        $client = self::mpClient();
        $preapproval = $client->call('get', '/preapproval/' . rawurlencode($subscription->external_subscription_id));
        $status = $preapproval['data']['status'] ?? null;

        if ($preapproval['ok'] && in_array($status, ['cancelled', 'canceled', 'paused'], true)) {
            $subscription->update(['card_status' => 'cancelled']);
            Subscriptions::forget($subscription->tenant_id);
            self::notify($subscription->tenant_id, 'subscription.charge_failed', 'El cobro automático se detuvo', 'Mercado Pago dejó de cobrar el plan a tu tarjeta. Registra una tarjeta otra vez o paga el periodo con el botón "Pagar ahora".', "card-stopped:{$subscription->external_subscription_id}");

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
            self::failed($subscription, $amount, $charge['currency_id'] ?? ($plan->currency ?? 'PEN'), "charge-failed:{$chargeId}", 'Mercado Pago lo volverá a intentar.');
        }
    }

    private static function failed(Subscription $subscription, float $amount, string $currency, string $key, string $reason): void
    {
        $subscription->update(['last_charge_failed_at' => now()]);
        Subscriptions::forget($subscription->tenant_id);
        $readOnly = Subscriptions::state($subscription->tenant_id)['grace_ends_at'];
        self::notify(
            $subscription->tenant_id,
            'subscription.charge_failed',
            'No se pudo cobrar tu plan',
            'No se pudo cobrar ' . Currency::format($amount, $currency) . ' a tu tarjeta: ' . rtrim($reason, '.') . '. Cambia la tarjeta o paga con "Pagar ahora"'
                . ($readOnly ? ' antes del ' . Carbon::parse($readOnly)->locale('es')->isoFormat('D [de] MMMM') . ' para que tu empresa no quede en solo lectura' : '') . '.',
            $key,
        );
    }

    public static function syncAll(): int
    {
        if (!Subscriptions::ready() || !self::connected('mercadopago')) {
            return 0;
        }

        $ids = DB::table('subscriptions')
            ->where(function ($q) {
                $q->whereIn('id', DB::table('plan_payments')->select('subscription_id')->where('provider', 'mercadopago')->where('status', 'pending')->where('created_at', '>', now()->subDays(7)))
                    ->orWhere(fn ($c) => $c->where('external_provider', 'mercadopago')->where('card_status', 'authorized')->whereNotNull('external_subscription_id')
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

    public static function webhook(Request $request, string $provider = 'mercadopago'): array
    {
        return $provider === 'culqi' ? self::culqiWebhook($request) : self::mpWebhook($request);
    }

    private static function culqiWebhook(Request $request): array
    {
        if (!self::connected('culqi')) {
            return ['status' => 'ignored'];
        }

        $object = CulqiClient::eventObject($request->all());
        $chargeId = (string) ($object['id'] ?? '');

        if (!str_starts_with($chargeId, 'chr_')) {
            return ['status' => 'ignored'];
        }

        $result = self::culqiClient()->getCharge($chargeId);

        if (!CulqiClient::chargeApproved($result)) {
            return ['status' => 'ignored'];
        }

        $paymentId = (string) ($result['data']['metadata']['plan_payment'] ?? '');
        $row = DB::table('plan_payments')->where('provider', 'culqi')->whereIn('kind', ['link', 'charge'])->where('status', 'pending')
            ->where(fn ($q) => $q->where('external_id', $chargeId)->when(Str::isUuid($paymentId), fn ($c) => $c->orWhere('id', $paymentId)))
            ->first();

        if (!$row) {
            return ['status' => 'ignored'];
        }

        DB::table('plan_payments')->where('id', $row->id)->update(['external_id' => $chargeId, 'updated_at' => now()]);
        self::paid(Subscription::findOrFail($row->subscription_id), DB::table('plan_payments')->where('id', $row->id)->first(), 'culqi:' . $chargeId);

        return ['status' => 'processed'];
    }

    private static function mpWebhook(Request $request): array
    {
        if (!self::connected('mercadopago')) {
            return ['status' => 'ignored'];
        }

        $secret = trim((string) (self::credentials('mercadopago')['webhook_secret'] ?? '')) ?: null;
        abort_unless(MercadoPagoClient::signatureValid($request, $secret), 400, 'Firma no válida.');

        $topic = MercadoPagoClient::topic($request);
        $id = MercadoPagoClient::dataId($request);

        if ($id === '') {
            return ['status' => 'ignored'];
        }

        $subscriptionId = match (true) {
            $topic === 'order' => DB::table('plan_payments')->where('provider', 'mercadopago')->where('kind', 'link')->where('external_id', $id)->value('subscription_id'),
            str_contains($topic, 'authorized_payment') => (function () use ($id) {
                $charge = self::mpClient()->call('get', '/authorized_payments/' . rawurlencode($id));
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
