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
                'username'       => $saved['username'] ?? null,
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
                'izipay_webhook_url' => rtrim((string) config('app.url'), '/') . '/api/webhooks/payments/izipay/' . $tenant->id,
                'log'        => \App\Support\IntegrationLog::recent($tenant->id, 'payments', 30),
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
            'username'       => 'nullable|string|max:255',
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
                'username'       => trim((string) ($validated['username'] ?? '')) ?: ($old['username'] ?? null),
            ];

            if (!$keys['public_key'] || !$keys['secret_key']) {
                throw ValidationException::withMessages(['public_key' => match ($provider) {
                    'mercadopago' => 'Escribe la Public Key y el Access Token de tu aplicación de Mercado Pago.',
                    'niubiz'      => 'Escribe el código de comercio, el usuario y la contraseña de la integración que te dio Niubiz.',
                    'izipay'      => 'Escribe el usuario (identificador de la tienda) y la contraseña que aparecen en el Back Office de Izipay.',
                    default       => 'Escribe la llave pública y la llave secreta que te dio ' . PaymentLinks::PROVIDERS[$provider]['label'] . '.',
                }]);
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

            if (in_array($provider, ['niubiz', 'izipay'], true) && !in_array(\App\Support\Currency::of($tenant), ['PEN', 'USD'], true)) {
                throw ValidationException::withMessages(['secret_key' => PaymentLinks::PROVIDERS[$provider]['label'] . ' solo cobra en soles o dólares y tu empresa trabaja en ' . \App\Support\Currency::of($tenant) . '.']);
            }

            if ($provider === 'niubiz') {
                if (!preg_match('/^\d{6,12}$/', $keys['public_key'])) {
                    throw ValidationException::withMessages(['public_key' => 'El código de comercio de Niubiz va solo con números (te lo dio Niubiz al afiliarte).']);
                }

                if (!$keys['username']) {
                    throw ValidationException::withMessages(['username' => 'Escribe el usuario de la integración que te dio Niubiz (suele ser un correo).']);
                }

                $validated['mode'] = ($validated['mode'] ?? null) === 'production' ? 'production' : 'sandbox';
                $check = (new \App\Support\Payments\NiubizClient($keys['public_key'], $keys['username'], $keys['secret_key'], $validated['mode'] === 'production'))->accessToken(true);
                \App\Support\IntegrationLog::write($tenant->id, 'payments', 'niubiz', 'test', $check['ok'], $check['ok'] ? 'Credenciales guardadas y aceptadas por Niubiz.' : $check['message']);

                if (!$check['ok']) {
                    throw ValidationException::withMessages(['secret_key' => $check['message']]);
                }

                $settings = ['currency' => \App\Support\Currency::of($tenant)];
            }

            if ($provider === 'izipay') {
                if (!preg_match('/^(test|prod)password_/', $keys['secret_key'])) {
                    throw ValidationException::withMessages(['secret_key' => 'La contraseña de Izipay empieza con testpassword_ (pruebas) o prodpassword_ (producción). Cópiala del Back Office → Configuración → Tienda → Claves de API REST.']);
                }

                $check = (new \App\Support\Payments\IzipayClient($keys['public_key'], $keys['secret_key']))->test();
                \App\Support\IntegrationLog::write($tenant->id, 'payments', 'izipay', 'test', $check['ok'], $check['ok'] ? 'Credenciales guardadas y aceptadas por Izipay.' : $check['message']);

                if (!$check['ok']) {
                    throw ValidationException::withMessages(['secret_key' => $check['message'] ?? 'Izipay no respondió como se esperaba.']);
                }

                $settings = ['currency' => \App\Support\Currency::of($tenant)];
                $validated['mode'] = \App\Support\Payments\IzipayClient::production($keys['secret_key']) ? 'production' : 'sandbox';
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
            $label = in_array($key, PaymentLinks::PREFERENCE, true) ? (PaymentLinks::PROVIDERS[$key]['label'] ?? $key) : null;

            return response()->json(['ok' => false, 'message' => $label ? "Primero escribe las claves de {$label} y pulsa «Guardar y conectar»; después puedes probar la conexión." : PaymentLinks::NOT_CONNECTED]);
        }

        try {
            $result = $provider->test();
        } catch (ValidationException $e) {
            $result = ['ok' => false, 'message' => collect($e->errors())->flatten()->first()];
        }

        if (!in_array($provider->key(), ['niubiz', 'izipay'], true)) {
            \App\Support\IntegrationLog::write($tenant->id, 'payments', $provider->key(), 'test', (bool) $result['ok'], $result['message']);
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

        $extra = $request->validate([
            'authentication_3ds'   => 'nullable|array',
            'authentication_3ds.*' => 'nullable|string|max:200',
            'device'               => 'nullable|string|max:120',
        ]);

        $result = PaymentLinks::payWithCulqi($token, $validated['token'], $validated['email'] ?? null, $extra['authentication_3ds'] ?? null, $extra['device'] ?? null);

        return response()->json(['result' => $result, 'link' => PaymentLinks::publicView($token)]);
    }

    private static function niubizTrouble(string $token, string $title, string $message, ?string $retryUrl, string $retryLabel, int $status = 200)
    {
        $back = e(PaymentLinks::url($token));
        $retry = $retryUrl
            ? '<p style="margin-top:24px"><a href="' . e($retryUrl) . '" data-niubiz-retry style="display:inline-block;padding:12px 20px;border-radius:12px;background:#0f172a;color:#fff;font-weight:bold;font-size:14px;text-decoration:none">' . e($retryLabel) . '</a></p>'
            : '';

        $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($title) . '</title></head>'
            . '<body style="margin:0;padding:32px 16px;font-family:Arial,Helvetica,sans-serif;background:#f1f5f9;color:#0f172a;text-align:center">'
            . '<div style="max-width:420px;margin:0 auto;background:#fff;border-radius:16px;padding:24px 20px;box-shadow:0 1px 3px rgba(15,23,42,.08)">'
            . '<h1 style="font-size:18px;margin:0 0 8px" data-niubiz-trouble>' . e($title) . '</h1><p style="font-size:14px;color:#475569;line-height:1.5;margin:0">' . e($message) . '</p>'
            . $retry
            . '<p style="margin-top:16px;font-size:13px"><a href="' . $back . '" style="color:#475569">Volver a la página del pago</a></p></div></body></html>';

        return response($html, $status)->header('Content-Type', 'text/html; charset=utf-8');
    }

    public function niubizForm(Request $request, string $token)
    {
        try {
            return response(PaymentLinks::niubizForm($token, (string) $request->ip()))->header('Content-Type', 'text/html; charset=utf-8');
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?: 'Niubiz no abrió el formulario de pago.';

            if ($message === 'Este link ya no está esperando pago.') {
                return redirect()->away(PaymentLinks::url($token) . '?volver=1');
            }

            return self::niubizTrouble($token, 'No se pudo abrir el pago', 'Niubiz no respondió a tiempo y no se cobró nada. Espera un momento y vuelve a intentarlo.', \App\Support\Payments\NiubizPaymentProvider::formUrl($token), 'Volver a intentar');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return self::niubizTrouble($token, 'No se pudo abrir el pago', $e->getMessage() ?: 'Este link de pago no está disponible.', null, '', $e->getStatusCode());
        } catch (\Throwable $e) {
            report($e);

            return self::niubizTrouble($token, 'No se pudo abrir el pago', 'Se cortó la conexión con Niubiz y no se cobró nada. Espera un momento y vuelve a intentarlo.', \App\Support\Payments\NiubizPaymentProvider::formUrl($token), 'Volver a intentar');
        }
    }

    public function niubiz(Request $request, string $token)
    {
        $transaction = (string) $request->input('transactionToken', '');

        if ($transaction !== '' && preg_match('/^[A-Za-z0-9_-]{8,120}$/', $transaction)) {
            try {
                PaymentLinks::payWithNiubiz($token, $transaction);
            } catch (ValidationException $e) {
                report($e);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
                return self::niubizTrouble($token, 'No se pudo completar el pago', $e->getMessage() ?: 'Este link de pago no está disponible.', null, '', $e->getStatusCode());
            } catch (\Throwable $e) {
                report($e);

                return self::niubizTrouble($token, 'No pudimos confirmar tu pago', 'Se cortó la conexión con Niubiz mientras se revisaba tu pago. Antes de pagar otra vez, revisa el estado: si el cobro pasó, aparecerá como pagado en unos minutos.', PaymentLinks::url($token) . '?volver=1', 'Ver el estado de mi pago');
            }
        }

        return redirect()->away(PaymentLinks::url($token) . '?volver=1');
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

        if (\App\Support\GatewayWebhooks::tracks($provider, $owner)) {
            return \App\Support\GatewayWebhooks::receive($request, $provider, $owner, fn (Request $req) => $this->processWebhook($req, $provider, $owner));
        }

        return $this->processWebhook($request, $provider, $owner);
    }

    public function processWebhook(Request $request, string $provider, ?string $owner = null)
    {
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

        if ($provider === 'izipay') {
            abort_unless($owner && Str::isUuid($owner), 404);
            $gateway = PaymentLinks::providerFor($owner, 'izipay');
            abort_unless($gateway, 404);
            $notice = $gateway->parse($request);

            if (!$notice) {
                \App\Support\IntegrationLog::write($owner, 'payments', 'izipay', 'webhook', false, 'Llegó un aviso con una firma que no coincide: no se tomó en cuenta.');

                return response()->json(['message' => 'Firma no válida.'], 400);
            }

            $link = $notice['id'] !== '' ? DB::table('payment_links')->where('tenant_id', $owner)->where('provider', 'izipay')->where('token', $notice['id'])->first() : null;
            $event = $link && $link->external_id ? $gateway->status($link->external_id) : null;
            \App\Support\IntegrationLog::write($owner, 'payments', 'izipay', 'webhook', true, $event ? 'Aviso de pago recibido y comprobado con Izipay.' : 'Aviso recibido sin cambios.');

            return response()->json($event && $event['type'] !== 'payment.pending' ? PaymentLinks::handle('izipay', $event, $owner) : ['status' => 'ignored']);
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
