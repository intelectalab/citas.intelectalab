<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Support\Dunning;
use App\Support\PaymentLinks;
use App\Support\Payments\CulqiClient;
use App\Support\Payments\MercadoPagoClient;
use App\Support\PlanBilling;
use App\Support\PlatformLook;
use App\Support\Subscriptions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlatformBillingController extends Controller
{
    private const KEY_LABELS = [
        'culqi'       => ['public_key' => 'Llave pública', 'secret_key' => 'Llave secreta'],
        'mercadopago' => ['public_key' => 'Public Key', 'secret_key' => 'Access Token'],
    ];

    private function ready(): void
    {
        abort_unless(Subscriptions::ready(), 503, 'Falta actualizar la base de datos para el cobro de planes: ejecuta php artisan migrate.');
    }

    private function provider(string $key): array
    {
        $row = PlanBilling::integration($key);
        $saved = PlanBilling::credentials($key);
        $settings = $row?->settings ? (json_decode($row->settings, true) ?: []) : [];
        $mask = fn (?string $value) => $value ? str_repeat('•', 8) . mb_substr($value, -4) : null;

        return [
            'key'         => $key,
            'label'       => PlanBilling::PROVIDERS[$key],
            'connected'   => PlanBilling::connected($key),
            'mode'        => $row?->mode,
            'account'     => $settings['account'] ?? null,
            'currency'    => $settings['currency'] ?? null,
            'saved'       => $row ? [
                'public_key'     => $mask($saved['public_key'] ?? null),
                'secret_key'     => $mask($saved['secret_key'] ?? null),
                'webhook_secret' => $mask($saved['webhook_secret'] ?? null),
                'saved_at'       => Carbon::parse($row->updated_at)->toIso8601String(),
            ] : null,
            'webhook_url' => rtrim((string) config('app.url'), '/') . "/api/webhooks/payments/{$key}/platform",
        ];
    }

    public function show()
    {
        $this->ready();

        return response()->json([
            'providers'  => [$this->provider('culqi'), $this->provider('mercadopago')],
            'primary'    => PlanBilling::primary(),
            'active'     => PlanBilling::active(),
            'connected'  => PlanBilling::connected(),
            'simulation' => PaymentLinks::simulationAllowed(),
            'dunning'    => Dunning::config(),
            'dunning_ready' => Dunning::ready(),
            'cancel_offer' => \App\Support\Cancellations::ready() ? \App\Support\Cancellations::offerConfig() + ['reason_list' => collect(\App\Support\Cancellations::REASONS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()] : null,
            'methods'    => [
                'mercadopago' => (bool) (PlatformSetting::value('plan_mercadopago') ?? true),
                'transfer'    => \App\Support\PlanTransfers::config(),
            ],
            'summary'    => [
                'cards'   => DB::table('subscriptions')->where('card_status', 'authorized')->count(),
                'failed'  => DB::table('subscriptions')->whereNotNull('last_charge_failed_at')->count(),
                'online'  => DB::table('plan_payments')->where('status', 'paid')->whereIn('kind', ['link', 'charge'])->where('paid_at', '>', now()->subDays(30))->count(),
                'overdue' => Dunning::ready() ? count(Dunning::overdueList()) : 0,
            ],
        ]);
    }

    public function update(Request $request)
    {
        $this->ready();

        $validated = $request->validate([
            'provider'       => ['nullable', Rule::in(array_keys(PlanBilling::PROVIDERS))],
            'connect'        => 'nullable|boolean',
            'mode'           => ['nullable', Rule::in(['sandbox', 'production'])],
            'public_key'     => 'nullable|string|max:255',
            'secret_key'     => 'nullable|string|max:255',
            'webhook_secret' => 'nullable|string|max:255',
            'primary'        => ['nullable', Rule::in(array_keys(PlanBilling::PROVIDERS))],
        ]);

        $after = [];

        if (isset($validated['primary']) && $validated['primary'] !== PlanBilling::primary()) {
            PlatformSetting::put(['plan_primary' => $validated['primary']]);
            $after['plan_primary'] = PlanBilling::PROVIDERS[$validated['primary']];
        }

        if (isset($validated['provider'], $validated['connect'])) {
            $after += $this->saveProvider($validated['provider'], $validated);
        }

        PlanBilling::forgetIntegration();
        PlatformLook::forget();

        if ($after) {
            AuditEvent::record($request, 'platform.billing_changed', null, [], $after);
        }

        return $this->show();
    }

    private function saveProvider(string $key, array $validated): array
    {
        $row = PlanBilling::integration($key);
        $label = PlanBilling::PROVIDERS[$key];

        if (!$validated['connect']) {
            if (!$row) {
                return [];
            }

            DB::table('platform_integrations')->where('id', $row->id)->delete();

            return ['payment_provider' => "{$label}: desconectado"];
        }

        $old = PlanBilling::credentials($key);
        $keys = [
            'public_key'     => trim((string) ($validated['public_key'] ?? '')) ?: ($old['public_key'] ?? null),
            'secret_key'     => trim((string) ($validated['secret_key'] ?? '')) ?: ($old['secret_key'] ?? null),
            'webhook_secret' => trim((string) ($validated['webhook_secret'] ?? '')) ?: ($old['webhook_secret'] ?? null),
        ];

        if (!$keys['public_key'] || !$keys['secret_key']) {
            $names = self::KEY_LABELS[$key];
            throw ValidationException::withMessages(['public_key' => "Escribe la {$names['public_key']} y la {$names['secret_key']} de la cuenta de {$label} de la plataforma."]);
        }

        if ($key === 'culqi') {
            if (!str_starts_with($keys['public_key'], 'pk_') || !str_starts_with($keys['secret_key'], 'sk_')) {
                throw ValidationException::withMessages(['public_key' => 'La llave pública de Culqi empieza con pk_ y la secreta con sk_. Revisa que no estén al revés.']);
            }

            $check = (new CulqiClient($keys['secret_key']))->account();
            $settings = ['account' => null, 'currency' => 'PEN'];
            $mode = str_contains($keys['secret_key'], '_live_') ? 'production' : 'sandbox';
        } else {
            $check = (new MercadoPagoClient($keys['secret_key']))->account();
            $settings = ['account' => $check['name'] ?? null, 'currency' => $check['currency'] ?? null];
            $mode = $validated['mode'] ?? ($row?->mode ?? 'sandbox');
        }

        if (!$check['ok']) {
            throw ValidationException::withMessages(['secret_key' => $check['message']]);
        }

        $values = [
            'provider'    => $key,
            'mode'        => $mode,
            'credentials' => Crypt::encryptString(json_encode($keys)),
            'settings'    => json_encode($settings),
            'status'      => 'active',
            'updated_at'  => now(),
        ];

        $row
            ? DB::table('platform_integrations')->where('id', $row->id)->update($values)
            : DB::table('platform_integrations')->insert($values + ['id' => (string) Str::uuid(), 'kind' => 'payments', 'created_at' => now()]);

        return array_filter([
            'payment_provider' => $row ? null : "{$label}: conectado",
            'payment_mode'     => ($row?->mode ?? null) !== $mode ? ($mode === 'production' ? 'Producción' : 'Pruebas') : null,
            'public_key'       => trim((string) ($validated['public_key'] ?? '')) !== '' ? 'Cambiada' : null,
            'secret_key'       => trim((string) ($validated['secret_key'] ?? '')) !== '' ? 'Cambiada' : null,
            'webhook_secret'   => trim((string) ($validated['webhook_secret'] ?? '')) !== '' ? 'Cambiada' : null,
        ]);
    }

    public function dunning(Request $request)
    {
        $this->ready();
        $validated = $request->validate([
            'retry_days'    => 'required|array|max:8',
            'retry_days.*'  => 'integer|min:0|max:60',
            'remind_days'   => 'required|array|max:8',
            'remind_days.*' => 'integer|min:0|max:60',
            'readonly_day'  => 'required|integer|min:1|max:60',
            'cancel_day'    => 'required|integer|min:2|max:180',
        ], [
            'readonly_day.min' => 'La empresa debe tener al menos un día para pagar antes de quedar en solo lectura.',
        ]);

        if ($validated['cancel_day'] <= $validated['readonly_day']) {
            throw ValidationException::withMessages(['cancel_day' => 'El plan debe cancelarse después de quedar en solo lectura.']);
        }

        $before = Dunning::config();
        PlatformSetting::put(['dunning' => [
            'retry_days'   => array_values(array_unique(array_map('intval', $validated['retry_days']))),
            'remind_days'  => array_values(array_unique(array_map('intval', $validated['remind_days']))),
            'readonly_day' => (int) $validated['readonly_day'],
            'cancel_day'   => (int) $validated['cancel_day'],
        ]]);
        PlatformLook::forget();
        $after = Dunning::config();
        $text = fn (array $config) => [
            'Reintentos de cobro' => 'días ' . implode(', ', $config['retry_days']),
            'Recordatorios'       => 'días ' . implode(', ', $config['remind_days']),
            'Solo lectura'        => "día {$config['readonly_day']}",
            'Cancelación'         => "día {$config['cancel_day']}",
        ];
        AuditEvent::record($request, 'platform.billing_changed', null, $text($before), $text($after));

        return $this->show();
    }

    public function cancelOffer(Request $request)
    {
        $this->ready();
        abort_unless(\App\Support\Cancellations::ready(), 503, \App\Support\Cancellations::NOT_READY);
        $validated = $request->validate([
            'enabled'   => 'boolean',
            'percent'   => 'required|integer|min:5|max:100',
            'months'    => 'required|integer|min:1|max:12',
            'reasons'   => 'array',
            'reasons.*' => ['string', Rule::in(array_keys(\App\Support\Cancellations::REASONS))],
        ], ['percent.min' => 'El descuento debe ser de al menos 5 %.', 'months.max' => 'El descuento puede durar como mucho 12 meses.']);

        $text = fn (array $c) => ['Oferta' => $c['enabled'] ? "{$c['percent']} % por {$c['months']} meses" : 'Apagada'];
        $before = \App\Support\Cancellations::offerConfig();
        $after = \App\Support\Cancellations::saveOffer($validated);
        AuditEvent::record($request, 'subscription.cancel_offer_changed', null, $text($before), $text($after));

        return $this->show();
    }

    public function receipt(string $invoice)
    {
        $row = \App\Models\SubscriptionInvoice::findOrFail($invoice);

        return response(\App\Support\PlanReceipts::pdf($row), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . \App\Support\PlanReceipts::fileName($row) . '"',
            'Cache-Control'       => 'private, no-store',
        ]);
    }

    public function cancellations()
    {
        $this->ready();

        return response()->json(['cancellations' => \App\Support\Cancellations::forPlatform()]);
    }

    public function overdue()
    {
        $this->ready();

        $list = Dunning::overdueList();

        return response()->json(['overdue' => $list, 'summary' => Dunning::summary($list), 'dunning' => Dunning::config(), 'ready' => Dunning::ready()]);
    }

    public function overdueAction(Request $request, string $subscription, string $action)
    {
        abort_unless(Dunning::ready(), 503, 'Falta actualizar la base de datos para los pagos vencidos: ejecuta php artisan migrate.');
        $subscription = Subscription::with('plan')->findOrFail($subscription);
        abort_unless(Subscriptions::latest($subscription->tenant_id)?->id === $subscription->id, 422, 'Esta no es la suscripción vigente de la empresa.');

        $result = match ($action) {
            'retry'  => Dunning::retryNow($subscription, $request->user()),
            'remind' => Dunning::remindNow($subscription, $request->user()),
            'extend' => Dunning::extend($subscription, $request->user()),
        };

        $labels = ['retry' => 'Reintentar cobro', 'remind' => 'Recordar pago', 'extend' => 'Prórroga de ' . Dunning::EXTEND_DAYS . ' días'];
        AuditEvent::record($request, 'subscription.dunning_action', \App\Models\Tenant::find($subscription->tenant_id), [], ['accion' => $labels[$action], 'resultado' => $result['message']]);

        $list = Dunning::overdueList();

        return response()->json($result + ['overdue' => $list, 'summary' => Dunning::summary($list)], $result['ok'] ? 200 : 422);
    }

    public function methods(Request $request)
    {
        $validated = $request->validate([
            'mercadopago'           => 'required|boolean',
            'transfer'              => 'required|boolean',
            'review_text'           => 'nullable|string|max:60',
            'accounts'              => 'present|array|max:10',
            'accounts.*.id'         => 'nullable|string|max:40',
            'accounts.*.type'       => ['required', Rule::in(array_keys(\App\Support\PlanTransfers::TYPES))],
            'accounts.*.bank'       => 'nullable|string|max:60',
            'accounts.*.holder'     => 'nullable|string|max:120',
            'accounts.*.account_number' => 'nullable|string|max:40',
            'accounts.*.cci'        => 'nullable|string|max:40',
            'accounts.*.currency'   => 'nullable|string|size:3',
            'accounts.*.phone'      => 'nullable|string|max:20',
            'accounts.*.qr'         => 'nullable|string',
        ]);

        $before = ['tarjeta' => (bool) (PlatformSetting::value('plan_mercadopago') ?? true), 'transfer' => \App\Support\PlanTransfers::config()['enabled']];

        if (!$validated['mercadopago'] && !$validated['transfer']) {
            throw ValidationException::withMessages(['transfer' => 'Deja encendido al menos un medio de pago.']);
        }

        PlatformSetting::put(['plan_mercadopago' => $validated['mercadopago']]);
        PlatformLook::forget();
        $config = \App\Support\PlanTransfers::save(['enabled' => $validated['transfer'], 'review_text' => $validated['review_text'] ?? null, 'accounts' => $validated['accounts']]);
        AuditEvent::record($request, 'platform.billing_changed', null, $before, ['tarjeta' => $validated['mercadopago'], 'transfer' => $config['enabled'], 'cuentas' => count($config['accounts'])]);

        return $this->show();
    }

    public function transfers(Request $request)
    {
        $status = $request->query('status', 'review');
        $rows = DB::table('plan_payments')->where('kind', 'transfer')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByRaw("status = 'review' desc")->orderByDesc('created_at')->limit(300)->get();

        return response()->json([
            'transfers' => $rows->map(fn ($row) => \App\Support\PlanTransfers::present($row, true))->values(),
            'counts'    => DB::table('plan_payments')->where('kind', 'transfer')->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function approveTransfer(Request $request, string $id)
    {
        $row = \App\Support\PlanTransfers::approve($id, $request->user());
        AuditEvent::record($request, 'subscription.transfer_approved', \App\Models\Tenant::find($row->tenant_id), [], ['amount' => (float) $row->amount, 'operation_number' => $row->operation_number]);

        return response()->json(['transfer' => \App\Support\PlanTransfers::present($row, true), 'pending' => \App\Support\PlanTransfers::pendingCount()]);
    }

    public function rejectTransfer(Request $request, string $id)
    {
        $reason = $request->validate(['reason' => 'required|string|max:200'], ['reason.required' => 'Escribe por qué no se pudo confirmar, para que la empresa lo corrija.'])['reason'];
        $row = \App\Support\PlanTransfers::reject($id, $request->user(), trim($reason));
        AuditEvent::record($request, 'subscription.transfer_rejected', \App\Models\Tenant::find($row->tenant_id), [], ['amount' => (float) $row->amount, 'reason' => $row->message]);

        return response()->json(['transfer' => \App\Support\PlanTransfers::present($row, true), 'pending' => \App\Support\PlanTransfers::pendingCount()]);
    }

    public function transferVoucher(string $id)
    {
        $row = DB::table('plan_payments')->where('id', $id)->where('kind', 'transfer')->first();
        abort_unless($row, 404);

        return \App\Support\PlanTransfers::voucher($row);
    }

    public function test(Request $request)
    {
        $key = $request->input('provider', PlanBilling::active() ?? PlanBilling::primary());
        abort_unless(array_key_exists($key, PlanBilling::PROVIDERS), 422);
        $label = PlanBilling::PROVIDERS[$key];
        $credentials = PlanBilling::credentials($key);
        $client = $key === 'culqi' ? CulqiClient::fromCredentials($credentials) : MercadoPagoClient::fromCredentials($credentials);

        if (!$client) {
            return response()->json(['ok' => false, 'message' => PaymentLinks::simulationAllowed() ? "No hay cuenta de {$label} conectada: en estas pruebas se usa la simulación." : PlanBilling::NOT_CONNECTED]);
        }

        $account = $client->account();

        if ($key === 'culqi') {
            return response()->json(['ok' => $account['ok'], 'message' => $account['ok'] ? 'Culqi respondió: la llave secreta funciona.' : $account['message']]);
        }

        return response()->json([
            'ok'      => $account['ok'],
            'message' => $account['ok'] ? 'Mercado Pago respondió: la cuenta ' . ($account['name'] ?? '') . ' está conectada' . ($account['currency'] ? " y cobra en {$account['currency']}." : '.') : $account['message'],
        ]);
    }
}
