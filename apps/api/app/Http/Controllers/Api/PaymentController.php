<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Access;
use App\Support\Audit;
use App\Support\PaymentLinks;
use App\Support\Sales;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public const QR_METHODS = ['yape' => 'Yape', 'plin' => 'Plin'];

    private const QR_MAX_BYTES = 700 * 1024;

    private function tenant(Request $request): Tenant
    {
        $user = $request->user();
        abort_unless($user->tenant, 403);
        abort_unless(Sales::ready(), 503, Sales::NOT_READY);
        abort_unless(PaymentLinks::ready(), 503, PaymentLinks::NOT_READY);

        return $user->tenant->loadMissing(['settings', 'activeSubscription.plan']);
    }

    public static function qrCodes(string $tenantId): array
    {
        if (!Schema::hasTable('payment_qr_codes')) {
            return [];
        }

        return DB::table('payment_qr_codes')->where('tenant_id', $tenantId)->get()
            ->mapWithKeys(fn ($qr) => [$qr->method => ['image' => $qr->image, 'holder' => $qr->holder, 'updated_at' => Carbon::parse($qr->updated_at)->toIso8601String()]])
            ->all();
    }

    public function createForSale(Request $request, string $sale)
    {
        $tenant = $this->tenant($request);
        $row = DB::table('sales')->where('tenant_id', $tenant->id)->where('id', $sale)->first();
        abort_unless($row, 404);
        Access::assertBranch($request->user(), $row->branch_id, true);

        $link = DB::transaction(function () use ($tenant, $row, $request) {
            $locked = DB::table('sales')->where('id', $row->id)->lockForUpdate()->first();

            return PaymentLinks::forSale($tenant, $locked, $request->user());
        });

        return response()->json(PaymentLinks::present($link), 201);
    }

    public function cancel(Request $request, string $link)
    {
        $tenant = $this->tenant($request);
        $row = DB::table('payment_links')->where('tenant_id', $tenant->id)->where('id', $link)->first();
        abort_unless($row, 404);
        Access::assertBranch($request->user(), $row->branch_id, true);

        if ($row->status !== 'pending') {
            throw ValidationException::withMessages(['link' => 'Este link ya no está esperando pago.']);
        }

        PaymentLinks::cancel($row, 'Lo anuló ' . trim("{$request->user()->first_name} {$request->user()->last_name}") . '.');

        return response()->json(PaymentLinks::present(DB::table('payment_links')->where('id', $row->id)->first()));
    }

    public function integrations(Request $request)
    {
        abort_unless($request->user()->allows('settings.view'), 403, 'Tu cuenta no tiene permiso para ver la configuración de la empresa.');
        $tenant = $this->tenant($request);
        $integration = PaymentLinks::integration($tenant->id);
        $rows = PaymentLinks::integrations($tenant->id);
        $mask = fn (?string $value) => $value ? str_repeat('•', 8) . mb_substr($value, -4) : null;
        $savedFor = function (string $key) use ($rows, $mask) {
            $row = $rows->get($key);

            if (!$row || $key === 'simulated') {
                return null;
            }

            $saved = $row->credentials ? (json_decode(Crypt::decryptString($row->credentials), true) ?: []) : [];

            return [
                'public_key'     => $mask($saved['public_key'] ?? null),
                'secret_key'     => $mask($saved['secret_key'] ?? null),
                'webhook_secret' => $mask($saved['webhook_secret'] ?? null),
                'saved_at'       => Carbon::parse($row->updated_at)->toIso8601String(),
                'mode'           => $row->mode,
            ];
        };

        $events = DB::table('payment_events as e')
            ->leftJoin('payment_links as l', 'l.id', '=', 'e.payment_link_id')
            ->where('e.tenant_id', $tenant->id)
            ->orderByDesc('e.created_at')
            ->limit(60)
            ->get(['e.id', 'e.provider', 'e.event', 'e.status', 'e.message', 'e.created_at', 'l.description', 'l.amount', 'l.currency']);

        $settings = $integration?->settings ? (json_decode($integration->settings, true) ?: []) : [];
        $simulation = PaymentLinks::simulationAllowed();

        return response()->json([
            'payments' => [
                'active'     => PaymentLinks::provider($tenant)?->key() ?? 'none',
                'chosen'     => $integration?->provider ?? ($simulation ? 'simulated' : 'none'),
                'status'     => $integration?->status ?? 'active',
                'mode'       => $integration?->mode ?? null,
                'account'    => $settings['account'] ?? null,
                'currency'   => $settings['currency'] ?? null,
                'simulation' => $simulation,
                'webhook_url' => rtrim((string) config('app.url'), '/') . '/api/webhooks/payments/mercadopago/' . $tenant->id,
                'culqi_webhook_url' => rtrim((string) config('app.url'), '/') . '/api/webhooks/payments/culqi/' . $tenant->id,
                'connected'  => $rows->keys()->filter(fn ($key) => in_array($key, PaymentLinks::PREFERENCE, true))->values(),
                'providers'  => collect(PaymentLinks::PROVIDERS)
                    ->filter(fn ($p, $key) => $key !== 'simulated' || $simulation)
                    ->map(fn ($p, $key) => [
                        'key'         => $key,
                        'label'       => $p['label'],
                        'connectable' => $p['class'] !== null,
                        'saved'       => $savedFor($key),
                    ])->values(),
                'link_hours' => (int) \App\Support\AppointmentFlow::rule($tenant, 'payments.link_hours'),
            ],
            'events' => $events->map(fn ($e) => [
                'id'          => $e->id,
                'provider'    => PaymentLinks::PROVIDERS[$e->provider]['label'] ?? $e->provider,
                'event'       => ['payment.approved' => 'Pago aprobado', 'payment.rejected' => 'Pago rechazado', 'payment.pending' => 'Pago en proceso'][$e->event] ?? $e->event,
                'status'      => $e->status,
                'message'     => $e->message,
                'description' => $e->description,
                'amount'      => $e->amount !== null ? round((float) $e->amount, 2) : null,
                'currency'    => $e->currency,
                'at'          => Carbon::parse($e->created_at)->toIso8601String(),
            ])->values(),
            'qr' => self::qrCodes($tenant->id),
        ]);
    }

    public function savePayments(Request $request)
    {
        abort_unless($request->user()->allows('settings.edit'), 403, 'Tu cuenta no tiene permiso para cambiar la configuración de la empresa.');
        $tenant = $this->tenant($request);

        $choices = array_merge(['none'], array_keys(PaymentLinks::PROVIDERS));

        $validated = $request->validate([
            'provider'       => ['required', Rule::in($choices)],
            'mode'           => ['nullable', Rule::in(['sandbox', 'production'])],
            'public_key'     => 'nullable|string|max:255',
            'secret_key'     => 'nullable|string|max:255',
            'webhook_secret' => 'nullable|string|max:255',
            'disconnect'     => 'nullable|boolean',
        ]);

        $provider = $validated['provider'];
        $rows = PaymentLinks::integrations($tenant->id);
        $current = $rows->get($provider);

        if ($provider === 'simulated' && !PaymentLinks::simulationAllowed()) {
            throw ValidationException::withMessages(['provider' => 'La simulación solo está disponible en las pruebas del sistema.']);
        }

        $audit = fn (array $before, array $after) => Audit::log($request, 'settings.changed', [
            'tenant'       => $tenant->id,
            'entity_type'  => 'settings',
            'entity_label' => 'Pasarela de pagos',
            'before'       => $before,
            'after'        => $after,
        ]);

        if (!empty($validated['disconnect']) && $current) {
            DB::table('tenant_integrations')->where('id', $current->id)->delete();
            $audit(['payment_provider' => (PaymentLinks::PROVIDERS[$provider]['label'] ?? $provider) . ': conectada'], ['payment_provider' => (PaymentLinks::PROVIDERS[$provider]['label'] ?? $provider) . ': desconectada']);

            return $this->integrations($request);
        }

        if ($provider === 'none') {
            if ($rows->isNotEmpty()) {
                DB::table('tenant_integrations')->where('tenant_id', $tenant->id)->where('kind', 'payments')->delete();
                $audit(['payment_provider' => $rows->keys()->map(fn ($key) => PaymentLinks::PROVIDERS[$key]['label'] ?? $key)->implode(' y ')], ['payment_provider' => 'Sin pagos en línea']);
            }

            return $this->integrations($request);
        }

        $real = in_array($provider, PaymentLinks::PREFERENCE, true);
        DB::table('tenant_integrations')->where('tenant_id', $tenant->id)->where('kind', 'payments')
            ->where(fn ($q) => $real
                ? $q->whereNotIn('provider', PaymentLinks::PREFERENCE)
                : $q->where('provider', '!=', $provider))
            ->delete();

        $values = ['provider' => $provider, 'updated_at' => now()];

        if ($provider === 'simulated') {
            $values += ['mode' => 'simulated', 'status' => 'active'];
        } else {
            $old = $current?->credentials && $current->provider === $provider ? json_decode(Crypt::decryptString($current->credentials), true) : [];
            $keys = [
                'public_key'     => trim((string) ($validated['public_key'] ?? '')) ?: ($old['public_key'] ?? null),
                'secret_key'     => trim((string) ($validated['secret_key'] ?? '')) ?: ($old['secret_key'] ?? null),
                'webhook_secret' => trim((string) ($validated['webhook_secret'] ?? '')) ?: ($old['webhook_secret'] ?? null),
            ];

            if (!$keys['public_key'] || !$keys['secret_key']) {
                throw ValidationException::withMessages(['public_key' => $provider === 'mercadopago'
                    ? 'Escribe la Public Key y el Access Token de tu aplicación de Mercado Pago.'
                    : 'Escribe la llave pública y la llave secreta que te dio ' . PaymentLinks::PROVIDERS[$provider]['label'] . '.']);
            }

            $settings = [];

            if ($provider === 'mercadopago') {
                $account = (new \App\Support\Payments\MercadoPagoClient($keys['secret_key']))->account();

                if (!$account['ok']) {
                    throw ValidationException::withMessages(['secret_key' => $account['message']]);
                }

                if ($account['currency'] && $account['currency'] !== \App\Support\Currency::of($tenant)) {
                    throw ValidationException::withMessages(['secret_key' => "Esa cuenta de Mercado Pago cobra en {$account['currency']} y tu empresa trabaja en " . \App\Support\Currency::of($tenant) . ': usa una cuenta del mismo país.']);
                }

                $settings = ['account' => $account['name'], 'currency' => $account['currency']];
            }

            if ($provider === 'culqi') {
                if (!str_starts_with($keys['public_key'], 'pk_') || !str_starts_with($keys['secret_key'], 'sk_')) {
                    throw ValidationException::withMessages(['public_key' => 'La llave pública de Culqi empieza con pk_ y la secreta con sk_. Revisa que no estén al revés.']);
                }

                if (!in_array(\App\Support\Currency::of($tenant), ['PEN', 'USD'], true)) {
                    throw ValidationException::withMessages(['secret_key' => 'Culqi solo cobra en soles o dólares y tu empresa trabaja en ' . \App\Support\Currency::of($tenant) . '.']);
                }

                $check = (new \App\Support\Payments\CulqiClient($keys['secret_key']))->account();

                if (!$check['ok']) {
                    throw ValidationException::withMessages(['secret_key' => $check['message']]);
                }

                $settings = ['currency' => \App\Support\Currency::of($tenant)];
                $validated['mode'] = str_contains($keys['secret_key'], '_live_') ? 'production' : 'sandbox';
            }

            $values += [
                'mode'        => $validated['mode'] ?? ($current?->provider === $provider && $current->mode !== 'simulated' ? $current->mode : 'sandbox'),
                'status'      => PaymentLinks::PROVIDERS[$provider]['class'] ? 'active' : 'pending',
                'credentials' => Crypt::encryptString(json_encode($keys)),
                'settings'    => json_encode($settings),
            ];
        }

        if ($current) {
            DB::table('tenant_integrations')->where('id', $current->id)->update($values);
        } else {
            DB::table('tenant_integrations')->insert($values + ['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'kind' => 'payments', 'created_at' => now()]);
        }

        $label = fn (?string $key) => $key ? (PaymentLinks::PROVIDERS[$key]['label'] ?? $key) : 'Sin configurar';
        $from = $current?->provider !== $provider ? ['payment_provider' => $label($current?->provider)] : [];
        $to = $current?->provider !== $provider ? ['payment_provider' => $label($provider)] : [];
        if ($provider !== 'simulated') {
            if (trim((string) ($validated['public_key'] ?? '')) !== '') {
                $to['public_key'] = 'Cambiada';
            }
            if (trim((string) ($validated['secret_key'] ?? '')) !== '') {
                $to['secret_key'] = 'Cambiada';
            }
            if (trim((string) ($validated['webhook_secret'] ?? '')) !== '') {
                $to['webhook_secret'] = 'Cambiada';
            }
            if (($current?->mode ?? null) !== $values['mode']) {
                $to['payment_mode'] = $values['mode'] === 'production' ? 'Producción' : ($values['mode'] === 'sandbox' ? 'Pruebas' : 'Simulación');
            }
        }
        if ($to) {
            Audit::log($request, 'settings.changed', [
                'tenant'       => $tenant->id,
                'entity_type'  => 'settings',
                'entity_label' => 'Pasarela de pagos',
                'before'       => $from,
                'after'        => $to,
            ]);
        }

        return $this->integrations($request);
    }

    public function test(Request $request)
    {
        abort_unless($request->user()->allows('settings.view'), 403);
        $tenant = $this->tenant($request);
        $key = $request->input('provider');
        $provider = in_array($key, PaymentLinks::PREFERENCE, true) ? PaymentLinks::providerFor($tenant->id, $key) : PaymentLinks::provider($tenant);

        if (!$provider) {
            return response()->json(['ok' => false, 'message' => PaymentLinks::NOT_CONNECTED]);
        }

        try {
            $result = $provider->test();
        } catch (ValidationException $e) {
            $result = ['ok' => false, 'message' => collect($e->errors())->flatten()->first()];
        }

        return response()->json(['ok' => $result['ok'], 'message' => $result['message']]);
    }

    public function saveQr(Request $request, string $method)
    {
        abort_unless($request->user()->allows('settings.edit'), 403, 'Tu cuenta no tiene permiso para cambiar la configuración de la empresa.');
        abort_unless(isset(self::QR_METHODS[$method]), 404);
        $tenant = $this->tenant($request);

        $validated = $request->validate([
            'image'  => 'required|string',
            'holder' => 'nullable|string|max:150',
        ], ['image.required' => 'Elige la imagen del QR.']);

        if (!preg_match('#^data:image/(png|jpe?g|webp);base64,[A-Za-z0-9+/=]+$#', $validated['image'])) {
            throw ValidationException::withMessages(['image' => 'La imagen debe ser PNG, JPG o WEBP.']);
        }

        if (strlen($validated['image']) > self::QR_MAX_BYTES * 4 / 3) {
            throw ValidationException::withMessages(['image' => 'La imagen pesa demasiado: usa una de menos de 700 KB (una captura del QR basta).']);
        }

        $values = ['image' => $validated['image'], 'holder' => trim((string) ($validated['holder'] ?? '')) ?: null, 'updated_by' => $request->user()->id, 'updated_at' => now()];
        $existing = DB::table('payment_qr_codes')->where('tenant_id', $tenant->id)->where('method', $method)->first();

        if ($existing) {
            DB::table('payment_qr_codes')->where('id', $existing->id)->update($values);
        } else {
            DB::table('payment_qr_codes')->insert($values + ['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'method' => $method, 'created_at' => now()]);
        }

        Audit::log($request, 'payment_qr.changed', [
            'tenant'       => $tenant->id,
            'entity_type'  => 'payment_qr',
            'entity_label' => self::QR_METHODS[$method],
            'before'       => $existing ? array_filter(['qr_holder' => $existing->holder]) : [],
            'after'        => array_filter(['qr_image' => 'Cambiada', 'qr_holder' => $values['holder']]),
        ]);

        return response()->json(['qr' => self::qrCodes($tenant->id)]);
    }

    public function deleteQr(Request $request, string $method)
    {
        abort_unless($request->user()->allows('settings.edit'), 403, 'Tu cuenta no tiene permiso para cambiar la configuración de la empresa.');
        $tenant = $this->tenant($request);
        $deleted = DB::table('payment_qr_codes')->where('tenant_id', $tenant->id)->where('method', $method)->delete();

        if ($deleted) {
            Audit::log($request, 'payment_qr.deleted', [
                'tenant'       => $tenant->id,
                'entity_type'  => 'payment_qr',
                'entity_label' => self::QR_METHODS[$method] ?? $method,
            ]);
        }

        return response()->json(['qr' => self::qrCodes($tenant->id)]);
    }

    public function show(string $token)
    {
        $view = PaymentLinks::publicView($token);
        abort_unless($view, 404, 'Este link de pago no existe.');

        return response()->json($view);
    }

    public function simulate(Request $request, string $token)
    {
        $validated = $request->validate(['result' => ['required', Rule::in(['approved', 'rejected'])]]);
        $view = PaymentLinks::publicView($token);
        abort_unless($view, 404, 'Este link de pago no existe.');

        if ($view['status'] !== 'pending') {
            return response()->json(['result' => ['status' => $view['status'], 'message' => $view['reason'] ?? 'Este link ya no está esperando pago.']] + ['link' => $view], 422);
        }

        $result = PaymentLinks::simulate($token, $validated['result']);

        return response()->json(['result' => $result, 'link' => PaymentLinks::publicView($token)]);
    }

    public function start(string $token)
    {
        return response()->json(PaymentLinks::start($token));
    }

    public function culqi(Request $request, string $token)
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:120', 'regex:/^(tkn|ype)_[A-Za-z0-9_]+$/'],
            'email' => 'nullable|email|max:150',
        ], ['token.regex' => 'Culqi no devolvió una tarjeta válida. Inténtalo de nuevo.']);

        $result = PaymentLinks::payWithCulqi($token, $validated['token'], $validated['email'] ?? null);

        return response()->json(['result' => $result, 'link' => PaymentLinks::publicView($token)]);
    }

    public function check(string $token)
    {
        $link = DB::table('payment_links')->where('token', $token)->first();
        abort_unless($link, 404, 'Este link de pago no existe.');
        PaymentLinks::check($link, true);

        return response()->json(PaymentLinks::publicView($token));
    }

    public function webhook(Request $request, string $provider, ?string $owner = null)
    {
        abort_unless(PaymentLinks::ready(), 404);

        if ($owner === 'platform' && in_array($provider, ['mercadopago', 'culqi'], true)) {
            return response()->json(\App\Support\PlanBilling::webhook($request, $provider));
        }

        if ($provider === 'culqi') {
            abort_unless($owner && Str::isUuid($owner), 404);
            $gateway = PaymentLinks::providerFor($owner, 'culqi');
            abort_unless($gateway, 404);
            $notice = $gateway->parse($request);
            $event = $notice ? $gateway->status($notice['id']) : null;

            return response()->json($event ? PaymentLinks::handle('culqi', $event, $owner) : ['status' => 'ignored']);
        }

        if ($provider !== 'mercadopago') {
            $gateway = PaymentLinks::providerByKey($provider);
            abort_unless($gateway && $gateway->simulated(), 404);
            $event = $gateway->parse($request);

            return $event
                ? response()->json(PaymentLinks::handle($provider, $event))
                : response()->json(['message' => 'Firma o contenido no válido.'], 400);
        }

        abort_unless($owner && Str::isUuid($owner), 404);
        $gateway = PaymentLinks::providerFor($owner, 'mercadopago');
        abort_unless($gateway, 404);

        $notice = $gateway->parse($request);

        if (!$notice) {
            return response()->json(['message' => 'Firma no válida.'], 400);
        }

        if ($notice['topic'] !== 'order' || $notice['id'] === '') {
            return response()->json(['status' => 'ignored']);
        }

        $event = $gateway->status($notice['id']);

        return response()->json($event ? PaymentLinks::handle($provider, $event, $owner) : ['status' => 'ignored']);
    }
}
