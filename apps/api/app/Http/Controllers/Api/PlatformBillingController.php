<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\PlatformSetting;
use App\Support\PaymentLinks;
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
    public function show()
    {
        abort_unless(Subscriptions::ready(), 503, 'Falta actualizar la base de datos para el cobro de planes: ejecuta php artisan migrate.');
        $row = PlanBilling::integration();
        $saved = PlanBilling::credentials();
        $settings = $row?->settings ? (json_decode($row->settings, true) ?: []) : [];
        $mask = fn (?string $value) => $value ? str_repeat('•', 8) . mb_substr($value, -4) : null;

        return response()->json([
            'connected'   => PlanBilling::connected(),
            'simulation'  => PaymentLinks::simulationAllowed(),
            'mode'        => $row?->mode,
            'account'     => $settings['account'] ?? null,
            'currency'    => $settings['currency'] ?? null,
            'saved'       => $row ? [
                'public_key'     => $mask($saved['public_key'] ?? null),
                'secret_key'     => $mask($saved['secret_key'] ?? null),
                'webhook_secret' => $mask($saved['webhook_secret'] ?? null),
                'saved_at'       => Carbon::parse($row->updated_at)->toIso8601String(),
            ] : null,
            'webhook_url' => rtrim((string) config('app.url'), '/') . '/api/webhooks/payments/mercadopago/platform',
            'grace_days'  => Subscriptions::graceDays(),
            'methods'     => [
                'mercadopago' => (bool) (PlatformSetting::value('plan_mercadopago') ?? true),
                'transfer'    => \App\Support\PlanTransfers::config(),
            ],
            'summary'     => [
                'cards'  => DB::table('subscriptions')->where('card_status', 'authorized')->count(),
                'failed' => DB::table('subscriptions')->whereNotNull('last_charge_failed_at')->count(),
                'online' => DB::table('plan_payments')->where('status', 'paid')->whereIn('kind', ['link', 'charge'])->where('paid_at', '>', now()->subDays(30))->count(),
            ],
        ]);
    }

    public function update(Request $request)
    {
        abort_unless(Subscriptions::ready(), 503, 'Falta actualizar la base de datos para el cobro de planes: ejecuta php artisan migrate.');

        $validated = $request->validate([
            'connect'        => 'required|boolean',
            'mode'           => ['nullable', Rule::in(['sandbox', 'production'])],
            'public_key'     => 'nullable|string|max:255',
            'secret_key'     => 'nullable|string|max:255',
            'webhook_secret' => 'nullable|string|max:255',
            'grace_days'     => 'nullable|integer|min:0|max:30',
        ]);

        $row = PlanBilling::integration();
        $after = [];

        if (isset($validated['grace_days']) && $validated['grace_days'] !== Subscriptions::graceDays()) {
            PlatformSetting::put(['grace_days' => (int) $validated['grace_days']]);
            PlatformLook::forget();
            $after['grace_days'] = (int) $validated['grace_days'];
        }

        if (!$validated['connect']) {
            if ($row) {
                DB::table('platform_integrations')->where('id', $row->id)->delete();
                $after['payment_provider'] = 'Sin cobro en línea';
            }
        } else {
            $old = PlanBilling::credentials();
            $keys = [
                'public_key'     => trim((string) ($validated['public_key'] ?? '')) ?: ($old['public_key'] ?? null),
                'secret_key'     => trim((string) ($validated['secret_key'] ?? '')) ?: ($old['secret_key'] ?? null),
                'webhook_secret' => trim((string) ($validated['webhook_secret'] ?? '')) ?: ($old['webhook_secret'] ?? null),
            ];

            if (!$keys['public_key'] || !$keys['secret_key']) {
                throw ValidationException::withMessages(['public_key' => 'Escribe la Public Key y el Access Token de la aplicación de Mercado Pago de la plataforma.']);
            }

            $account = (new MercadoPagoClient($keys['secret_key']))->account();

            if (!$account['ok']) {
                throw ValidationException::withMessages(['secret_key' => $account['message']]);
            }

            $values = [
                'provider'    => 'mercadopago',
                'mode'        => $validated['mode'] ?? ($row?->mode ?? 'sandbox'),
                'credentials' => Crypt::encryptString(json_encode($keys)),
                'settings'    => json_encode(['account' => $account['name'], 'currency' => $account['currency']]),
                'status'      => 'active',
                'updated_at'  => now(),
            ];

            $row
                ? DB::table('platform_integrations')->where('id', $row->id)->update($values)
                : DB::table('platform_integrations')->insert($values + ['id' => (string) Str::uuid(), 'kind' => 'payments', 'created_at' => now()]);

            $after += array_filter([
                'payment_provider' => $row ? null : 'Mercado Pago',
                'payment_mode'     => ($row?->mode ?? null) !== $values['mode'] ? ($values['mode'] === 'production' ? 'Producción' : 'Pruebas') : null,
                'public_key'       => trim((string) ($validated['public_key'] ?? '')) !== '' ? 'Cambiada' : null,
                'secret_key'       => trim((string) ($validated['secret_key'] ?? '')) !== '' ? 'Cambiada' : null,
                'webhook_secret'   => trim((string) ($validated['webhook_secret'] ?? '')) !== '' ? 'Cambiada' : null,
            ]);
        }

        PlanBilling::forgetIntegration();
        PlatformLook::forget();

        if ($after) {
            AuditEvent::record($request, 'platform.billing_changed', null, [], $after);
        }

        return $this->show();
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

        $before = ['mercadopago' => (bool) (PlatformSetting::value('plan_mercadopago') ?? true), 'transfer' => \App\Support\PlanTransfers::config()['enabled']];

        if (!$validated['mercadopago'] && !$validated['transfer']) {
            throw ValidationException::withMessages(['transfer' => 'Deja encendido al menos un medio de pago.']);
        }

        PlatformSetting::put(['plan_mercadopago' => $validated['mercadopago']]);
        PlatformLook::forget();
        $config = \App\Support\PlanTransfers::save(['enabled' => $validated['transfer'], 'review_text' => $validated['review_text'] ?? null, 'accounts' => $validated['accounts']]);
        AuditEvent::record($request, 'platform.billing_changed', null, $before, ['mercadopago' => $validated['mercadopago'], 'transfer' => $config['enabled'], 'cuentas' => count($config['accounts'])]);

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

    public function test()
    {
        $client = MercadoPagoClient::fromCredentials(PlanBilling::credentials());

        if (!$client) {
            return response()->json(['ok' => false, 'message' => PaymentLinks::simulationAllowed() ? 'No hay cuenta conectada: en estas pruebas se usa la simulación.' : PlanBilling::NOT_CONNECTED]);
        }

        $account = $client->account();

        return response()->json([
            'ok'      => $account['ok'],
            'message' => $account['ok'] ? 'Mercado Pago respondió: la cuenta ' . ($account['name'] ?? '') . ' está conectada' . ($account['currency'] ? " y cobra en {$account['currency']}." : '.') : $account['message'],
        ]);
    }
}
