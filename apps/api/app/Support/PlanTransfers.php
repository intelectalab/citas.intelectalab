<?php

namespace App\Support;

use App\Models\SaaSPlan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlanTransfers
{
    public const TYPES = ['bank' => 'Cuenta bancaria', 'yape' => 'Yape', 'plin' => 'Plin'];

    public const MAX_VOUCHER = 5 * 1024 * 1024;

    public const MAX_QR = 700 * 1024;

    private static ?array $loaded = null;

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Subscriptions::ready() && Schema::hasColumn('plan_payments', 'method_label');
    }

    public static function row(): ?object
    {
        self::$loaded ??= [Schema::hasTable('platform_integrations') ? DB::table('platform_integrations')->where('kind', 'transfer')->first() : null];

        return self::$loaded[0];
    }

    public static function forget(): void
    {
        self::$loaded = null;
    }

    public static function config(): array
    {
        $row = self::row();
        $settings = $row ? (json_decode($row->settings ?? '{}', true) ?: []) : [];

        return [
            'enabled'     => (bool) ($settings['enabled'] ?? false),
            'review_text' => $settings['review_text'] ?? 'hasta 1 día hábil',
            'accounts'    => array_values($settings['accounts'] ?? []),
        ];
    }

    public static function enabled(): bool
    {
        $config = self::config();

        return self::ready() && $config['enabled'] && count($config['accounts']) > 0;
    }

    public static function account(string $id): ?array
    {
        return collect(self::config()['accounts'])->firstWhere('id', $id);
    }

    public static function label(array $account): string
    {
        return match ($account['type']) {
            'bank'  => trim(($account['bank'] ?? 'Banco') . ' · ' . ($account['currency'] ?? 'PEN')),
            default => self::TYPES[$account['type']] . (!empty($account['phone']) ? ' · ' . $account['phone'] : ''),
        };
    }

    public static function save(array $values): array
    {
        $accounts = collect($values['accounts'] ?? [])->map(function ($account) {
            $type = $account['type'];

            if (!empty($account['qr']) && (!preg_match('#^data:image/(png|jpe?g|webp);base64,[A-Za-z0-9+/=]+$#', $account['qr']) || strlen($account['qr']) > self::MAX_QR * 4 / 3)) {
                throw ValidationException::withMessages(['accounts' => 'El QR debe ser una imagen PNG, JPG o WEBP de menos de 700 KB.']);
            }

            if ($type === 'bank' && (empty($account['bank']) || empty($account['account_number']) || empty($account['holder']))) {
                throw ValidationException::withMessages(['accounts' => 'Cada cuenta bancaria necesita banco, titular y número de cuenta.']);
            }

            if ($type !== 'bank' && empty($account['phone'])) {
                throw ValidationException::withMessages(['accounts' => 'Yape y Plin necesitan el número de celular.']);
            }

            return array_filter([
                'id'             => $account['id'] ?? Str::lower(Str::random(10)),
                'type'           => $type,
                'bank'           => $type === 'bank' ? trim((string) $account['bank']) : null,
                'holder'         => trim((string) ($account['holder'] ?? '')) ?: null,
                'account_number' => $type === 'bank' ? trim((string) $account['account_number']) : null,
                'cci'            => $type === 'bank' ? (trim((string) ($account['cci'] ?? '')) ?: null) : null,
                'currency'       => $type === 'bank' ? ($account['currency'] ?? 'PEN') : null,
                'phone'          => $type !== 'bank' ? trim((string) $account['phone']) : null,
                'qr'             => $type !== 'bank' ? ($account['qr'] ?? null) : null,
            ], fn ($value) => $value !== null);
        })->values()->all();

        $settings = [
            'enabled'     => (bool) ($values['enabled'] ?? false),
            'review_text' => trim((string) ($values['review_text'] ?? '')) ?: 'hasta 1 día hábil',
            'accounts'    => $accounts,
        ];

        if ($settings['enabled'] && !$accounts) {
            throw ValidationException::withMessages(['accounts' => 'Agrega al menos una cuenta o un número de Yape o Plin antes de encender la transferencia.']);
        }

        $row = self::row();
        $data = ['provider' => 'manual', 'mode' => 'production', 'settings' => json_encode($settings), 'status' => 'active', 'updated_at' => now()];
        $row
            ? DB::table('platform_integrations')->where('id', $row->id)->update($data)
            : DB::table('platform_integrations')->insert($data + ['id' => (string) Str::uuid(), 'kind' => 'transfer', 'created_at' => now()]);

        self::forget();

        return self::config();
    }

    public static function pendingFor(string $subscriptionId): ?object
    {
        return self::ready() ? DB::table('plan_payments')->where('subscription_id', $subscriptionId)->where('kind', 'transfer')->where('status', 'review')->orderByDesc('created_at')->first() : null;
    }

    public static function lastRejected(string $subscriptionId): ?object
    {
        if (!self::ready()) {
            return null;
        }

        $last = DB::table('plan_payments')->where('subscription_id', $subscriptionId)->where('kind', 'transfer')->orderByDesc('created_at')->first();

        return $last && $last->status === 'rejected' ? $last : null;
    }

    public static function report(Subscription $subscription, User $user, array $values): object
    {
        abort_unless(self::enabled(), 422, 'La plataforma no recibe pagos por transferencia por ahora.');
        $account = self::account($values['method_id']);

        if (!$account) {
            throw ValidationException::withMessages(['method_id' => 'Elige a dónde pagaste.']);
        }

        if (self::pendingFor($subscription->id)) {
            throw ValidationException::withMessages(['voucher' => 'Ya enviaste un comprobante que estamos revisando. Espera la respuesta antes de enviar otro.']);
        }

        if (!preg_match('#^data:(image/(png|jpe?g|webp)|application/pdf);base64,([A-Za-z0-9+/=]+)$#', $values['voucher'], $match)) {
            throw ValidationException::withMessages(['voucher' => 'Sube una foto (PNG, JPG o WEBP) o un PDF del comprobante.']);
        }

        $bytes = base64_decode($match[3], true);

        if ($bytes === false || strlen($bytes) > self::MAX_VOUCHER) {
            throw ValidationException::withMessages(['voucher' => 'El comprobante pesa demasiado: usa uno de menos de 5 MB (una captura basta).']);
        }

        $plan = !empty($values['plan_id']) ? SaaSPlan::findOrFail($values['plan_id']) : ($subscription->pending_plan_id ? SaaSPlan::find($subscription->pending_plan_id) : $subscription->plan);
        $cycle = $values['billing_cycle'] ?? ($subscription->pending_plan_id ? $subscription->pending_billing_cycle : $subscription->billing_cycle) ?? 'monthly';
        $amount = Subscriptions::price($plan, $cycle);
        abort_unless($amount > 0, 422, 'Este plan no tiene costo.');

        $id = (string) Str::uuid();
        $extension = $match[1] === 'application/pdf' ? 'pdf' : ($match[2] === 'jpeg' ? 'jpg' : $match[2]);
        $path = "plan-vouchers/{$subscription->tenant_id}/{$id}.{$extension}";
        Storage::disk('local')->put($path, $bytes);

        DB::table('plan_payments')->insert([
            'id'               => $id,
            'tenant_id'        => $subscription->tenant_id,
            'subscription_id'  => $subscription->id,
            'plan_id'          => $plan->id,
            'billing_cycle'    => $cycle,
            'kind'             => 'transfer',
            'status'           => 'review',
            'amount'           => $amount,
            'currency'         => substr($plan->currency ?? 'PEN', 0, 3),
            'provider'         => 'transfer',
            'method_id'        => $account['id'],
            'method_label'     => mb_substr(self::label($account), 0, 120),
            'operation_number' => mb_substr(trim($values['operation_number']), 0, 60),
            'paid_on'          => $values['paid_on'],
            'voucher_path'     => $path,
            'created_by'       => $user->id,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return DB::table('plan_payments')->where('id', $id)->first();
    }

    public static function approve(string $id, User $reviewer): object
    {
        $row = DB::table('plan_payments')->where('id', $id)->where('kind', 'transfer')->lockForUpdate()->first();
        abort_unless($row, 404);
        abort_unless($row->status === 'review', 422, 'Este comprobante ya fue revisado.');

        $subscription = Subscription::findOrFail($row->subscription_id);
        $invoice = Subscriptions::recordPayment($subscription, [
            'plan_id'       => $row->plan_id,
            'billing_cycle' => $row->billing_cycle,
            'amount'        => (float) $row->amount,
            'reason'        => 'transfer',
            'reference'     => $row->operation_number,
            'paid_at'       => Carbon::parse($row->paid_on)->endOfDay()->min(now()),
            'notes'         => "Transferencia a {$row->method_label}",
        ]);

        DB::table('plan_payments')->where('id', $row->id)->update([
            'status' => 'paid', 'paid_at' => now(), 'invoice_id' => $invoice->id, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'message' => null, 'updated_at' => now(),
        ]);

        self::notify($row->tenant_id, 'subscription.charged', 'Pago confirmado', 'Confirmamos tu pago de ' . Currency::format((float) $row->amount, $row->currency) . '. Tu plan está vigente hasta el ' . Carbon::parse($invoice->period_end)->locale('es')->isoFormat('D [de] MMMM [de] YYYY') . '.', "transfer-ok:{$row->id}");

        return DB::table('plan_payments')->where('id', $row->id)->first();
    }

    public static function reject(string $id, User $reviewer, string $reason): object
    {
        $row = DB::table('plan_payments')->where('id', $id)->where('kind', 'transfer')->first();
        abort_unless($row, 404);
        abort_unless($row->status === 'review', 422, 'Este comprobante ya fue revisado.');

        DB::table('plan_payments')->where('id', $row->id)->update([
            'status' => 'rejected', 'message' => mb_substr($reason, 0, 255), 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'updated_at' => now(),
        ]);

        self::notify($row->tenant_id, 'subscription.charge_failed', 'No pudimos confirmar tu pago', "{$reason} Revisa los datos y vuelve a enviar tu comprobante desde Tu plan.", "transfer-no:{$row->id}");

        return DB::table('plan_payments')->where('id', $row->id)->first();
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

    public static function pendingCount(): int
    {
        return self::ready() ? DB::table('plan_payments')->where('kind', 'transfer')->where('status', 'review')->count() : 0;
    }

    public static function present(object $row, bool $platform = false): array
    {
        $tenant = $platform ? DB::table('tenants')->where('id', $row->tenant_id)->first(['id', 'name']) : null;
        $plan = SaaSPlan::find($row->plan_id);
        $reviewer = $row->reviewed_by ? DB::table('users')->where('id', $row->reviewed_by)->first(['first_name', 'last_name']) : null;

        return array_filter([
            'id'               => $row->id,
            'status'           => $row->status,
            'amount'           => round((float) $row->amount, 2),
            'currency'         => $row->currency,
            'plan'             => $plan?->name,
            'billing_cycle'    => $row->billing_cycle,
            'method'           => $row->method_label,
            'operation_number' => $row->operation_number,
            'paid_on'          => $row->paid_on ? Carbon::parse($row->paid_on)->format('Y-m-d') : null,
            'message'          => $row->message,
            'sent_at'          => Carbon::parse($row->created_at)->toIso8601String(),
            'reviewed_at'      => $row->reviewed_at ? Carbon::parse($row->reviewed_at)->toIso8601String() : null,
            'reviewer'         => $reviewer ? trim("{$reviewer->first_name} {$reviewer->last_name}") : null,
            'tenant'           => $tenant ? ['id' => $tenant->id, 'name' => $tenant->name] : null,
            'voucher_type'     => $row->voucher_path && str_ends_with($row->voucher_path, '.pdf') ? 'pdf' : 'image',
            'period'           => $row->status === 'review' && ($subscription = \App\Models\Subscription::find($row->subscription_id))
                ? array_map(fn ($date) => $date->setTimezone(\App\Models\Tenant::find($row->tenant_id)?->zone() ?? config('app.timezone'))->format('Y-m-d'), Subscriptions::periodFor($subscription, $row->billing_cycle, $row->paid_on ? Carbon::parse($row->paid_on)->endOfDay()->min(now()) : now()))
                : null,
        ], fn ($value) => $value !== null);
    }

    public static function voucher(object $row)
    {
        abort_unless($row->voucher_path && Storage::disk('local')->exists($row->voucher_path), 404, 'No encontramos el comprobante.');

        return Storage::disk('local')->response($row->voucher_path, null, ['Cache-Control' => 'private, no-store']);
    }
}
