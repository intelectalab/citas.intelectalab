<?php

namespace App\Support;

use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Referrals
{
    public const KINDS = [
        'days'     => 'Días gratis',
        'discount' => 'Descuento en el siguiente cobro',
    ];

    public const DEFAULTS = ['enabled' => false, 'kind' => 'days', 'days' => 30, 'percent' => 50, 'referred_percent' => 20, 'referred_months' => 3];

    public const STATUSES = [
        'pending'  => 'Aún no paga',
        'rewarded' => 'Premio entregado',
        'void'     => 'Sin premio',
    ];

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('saas_referrals') && Schema::hasColumn('tenants', 'referral_code');
    }

    public static function config(): array
    {
        $stored = PlatformSetting::value('referral');
        $stored = is_array($stored) ? $stored : [];

        return [
            'enabled' => (bool) ($stored['enabled'] ?? self::DEFAULTS['enabled']),
            'kind'    => isset(self::KINDS[$stored['kind'] ?? '']) ? $stored['kind'] : self::DEFAULTS['kind'],
            'days'    => max(1, min(365, (int) ($stored['days'] ?? self::DEFAULTS['days']))),
            'percent' => max(5, min(100, (int) ($stored['percent'] ?? self::DEFAULTS['percent']))),
            'referred_percent' => max(0, min(100, (int) ($stored['referred_percent'] ?? self::DEFAULTS['referred_percent']))),
            'referred_months'  => max(0, min(24, (int) ($stored['referred_months'] ?? self::DEFAULTS['referred_months']))),
        ];
    }

    public static function referredText(?array $config = null): ?string
    {
        $config ??= self::config();

        if ($config['referred_percent'] <= 0 || $config['referred_months'] <= 0) {
            return null;
        }

        $months = $config['referred_months'] === 1 ? 'tu primer mes' : "tus primeros {$config['referred_months']} meses";

        return "{$config['referred_percent']} % de descuento en {$months} con el plan mensual";
    }

    private static function welcomeDiscount(Tenant $referred): ?string
    {
        $config = self::config();
        $text = self::referredText($config);
        $subscription = Subscriptions::latest($referred->id);

        if (!$text || !$subscription || !Cancellations::ready() || (int) $subscription->discount_cycles > 0) {
            return null;
        }

        if ($subscription->billing_cycle !== 'monthly') {
            return 'El descuento por recomendación es para el plan mensual: tu plan se paga por año, así que no se aplica.';
        }

        $subscription->update(['discount_percent' => $config['referred_percent'], 'discount_cycles' => $config['referred_months']]);
        Subscriptions::forget();

        return "Te recomendaron: tienes {$text}.";
    }

    public static function saveConfig(array $values): array
    {
        PlatformSetting::put(['referral' => [
            'enabled' => (bool) ($values['enabled'] ?? false),
            'kind'    => $values['kind'],
            'days'    => (int) $values['days'],
            'percent' => (int) $values['percent'],
            'referred_percent' => (int) ($values['referred_percent'] ?? self::DEFAULTS['referred_percent']),
            'referred_months'  => (int) ($values['referred_months'] ?? self::DEFAULTS['referred_months']),
        ]]);

        return self::config();
    }

    public static function rewardText(?array $config = null): string
    {
        $config ??= self::config();

        return $config['kind'] === 'discount'
            ? "{$config['percent']} % de descuento en tu siguiente cobro mensual"
            : "{$config['days']} " . ($config['days'] === 1 ? 'día gratis' : 'días gratis') . ' de tu plan';
    }

    public static function codeFor(Tenant $tenant): ?string
    {
        if (!self::ready()) {
            return null;
        }

        if ($tenant->referral_code) {
            return $tenant->referral_code;
        }

        do {
            $code = Str::upper(Str::random(8));
            $code = preg_replace('/[^A-Z0-9]/', 'X', $code);
        } while (DB::table('tenants')->where('referral_code', $code)->exists());

        DB::table('tenants')->where('id', $tenant->id)->whereNull('referral_code')->update(['referral_code' => $code]);

        return $tenant->referral_code = DB::table('tenants')->where('id', $tenant->id)->value('referral_code');
    }

    public static function referrerOf(?string $code): ?Tenant
    {
        $code = Str::upper(trim((string) $code));

        return self::ready() && $code !== '' ? Tenant::whereNull('deleted_at')->where('referral_code', $code)->first() : null;
    }

    public static function attach(Tenant $referred, ?string $code, ?string $ownerEmail = null): bool
    {
        if (!self::config()['enabled'] || !($referrer = self::referrerOf($code)) || $referrer->id === $referred->id) {
            return false;
        }

        $referrerEmails = User::where('tenant_id', $referrer->id)->where('is_owner', true)->pluck('email')->map(fn ($e) => Str::lower((string) $e))->all();

        if ($ownerEmail && in_array(Str::lower($ownerEmail), $referrerEmails, true)) {
            return false;
        }

        $saved = DB::table('saas_referrals')->insertOrIgnore([
            'id'                 => (string) Str::uuid(),
            'referrer_tenant_id' => $referrer->id,
            'referred_tenant_id' => $referred->id,
            'code'               => $referrer->referral_code,
            'status'             => 'pending',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]) > 0;

        if ($saved && ($note = self::welcomeDiscount($referred))) {
            PlanBilling::notify($referred->id, 'referral.welcome', 'Descuento por recomendación', $note, "referral-welcome:{$referred->id}");
        }

        return $saved;
    }

    public static function onPaid(Subscription $subscription, SubscriptionInvoice $invoice): void
    {
        if (!self::ready() || (float) $invoice->amount <= 0 || !self::config()['enabled']) {
            return;
        }

        try {
            DB::transaction(function () use ($subscription) {
                $row = DB::table('saas_referrals')->where('referred_tenant_id', $subscription->tenant_id)->where('status', 'pending')->lockForUpdate()->first();

                if (!$row) {
                    return;
                }

                [$status, $kind, $value, $note] = self::reward($row->referrer_tenant_id);

                DB::table('saas_referrals')->where('id', $row->id)->update([
                    'status'       => $status,
                    'reward_kind'  => $kind,
                    'reward_value' => $value,
                    'reward_note'  => $note,
                    'rewarded_at'  => $status === 'rewarded' ? now() : null,
                    'updated_at'   => now(),
                ]);

                if ($status === 'rewarded') {
                    $name = Tenant::where('id', $subscription->tenant_id)->value('name');
                    PlanBilling::notify($row->referrer_tenant_id, 'referral.rewarded', 'Ganaste un premio por recomendar', "{$name} pagó su primer periodo. {$note}", "referral:{$row->id}");
                    Audit::log(request(), 'referral.rewarded', [
                        'actor'        => null,
                        'impersonator' => null,
                        'actor_type'   => 'system',
                        'tenant'       => $row->referrer_tenant_id,
                        'entity_type'  => 'tenant',
                        'entity_id'    => $row->referrer_tenant_id,
                        'after'        => ['text' => $note],
                    ]);
                }
            });
        } catch (\Throwable $e) {
            report($e);
        }

        Subscriptions::forget();
    }

    private static function reward(string $referrerId): array
    {
        $config = self::config();
        $subscription = Subscriptions::latest($referrerId);

        if (!$subscription || $subscription->status === 'canceled') {
            return ['void', null, null, 'No se entregó: la empresa que recomendó ya no tiene un plan vigente.'];
        }

        if ($config['kind'] === 'discount' && $subscription->billing_cycle === 'monthly' && $subscription->status !== 'trialing'
            && Cancellations::ready() && (int) $subscription->discount_cycles <= 0) {
            $subscription->update(['discount_percent' => $config['percent'], 'discount_cycles' => 1]);

            return ['rewarded', 'discount', $config['percent'], "Tu siguiente cobro mensual tendrá {$config['percent']} % de descuento."];
        }

        $days = $config['days'];
        $field = $subscription->status === 'trialing' && $subscription->trial_ends_at ? 'trial_ends_at' : 'ends_at';
        $base = $subscription->{$field} ? Carbon::parse($subscription->{$field}) : null;
        $base = $base && $base->isFuture() ? $base : now();
        $subscription->update([$field => $base->copy()->addDays($days)]);
        $fallback = $config['kind'] === 'discount' ? ' (en vez del descuento, porque tu plan no se cobra por mes o ya tiene un descuento)' : '';

        return ['rewarded', 'days', $days, "Te sumamos {$days} " . ($days === 1 ? 'día' : 'días') . " gratis a tu plan{$fallback}."];
    }

    public static function forTenant(Tenant $tenant): array
    {
        $config = self::config();

        if (!self::ready()) {
            return ['ready' => false, 'enabled' => false];
        }

        $rows = DB::table('saas_referrals as r')->join('tenants as t', 't.id', '=', 'r.referred_tenant_id')
            ->where('r.referrer_tenant_id', $tenant->id)->orderByDesc('r.created_at')->get(['r.*', 't.name']);

        return [
            'ready'   => true,
            'enabled' => $config['enabled'],
            'code'    => $config['enabled'] ? self::codeFor($tenant) : null,
            'reward'  => self::rewardText($config),
            'stats'   => [
                'referred'   => $rows->count(),
                'subscribed' => $rows->where('status', 'rewarded')->count(),
                'days'       => (int) $rows->where('status', 'rewarded')->where('reward_kind', 'days')->sum('reward_value'),
                'discounts'  => $rows->where('status', 'rewarded')->where('reward_kind', 'discount')->count(),
            ],
            'share_text' => 'Te recomiendo este sistema para tu agenda de citas, ventas y clientes. Regístrate con mi enlace: ',
            'rows'    => $rows->map(fn ($r) => [
                'id'           => $r->id,
                'name'         => $r->name,
                'status'       => $r->status,
                'status_label' => self::STATUSES[$r->status] ?? $r->status,
                'note'         => $r->reward_note,
                'created_at'   => Carbon::parse($r->created_at)->toIso8601String(),
            ])->all(),
        ];
    }

    public static function forPlatform(): array
    {
        if (!self::ready()) {
            return ['ready' => false, 'config' => self::config(), 'rows' => []];
        }

        $rows = DB::table('saas_referrals as r')
            ->join('tenants as a', 'a.id', '=', 'r.referrer_tenant_id')
            ->join('tenants as b', 'b.id', '=', 'r.referred_tenant_id')
            ->orderByDesc('r.created_at')->limit(300)
            ->get(['r.*', 'a.name as referrer_name', 'b.name as referred_name']);

        return [
            'ready'  => true,
            'config' => self::config(),
            'kinds'  => collect(self::KINDS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'rows'   => $rows->map(fn ($r) => [
                'id'            => $r->id,
                'referrer'      => ['id' => $r->referrer_tenant_id, 'name' => $r->referrer_name],
                'referred'      => ['id' => $r->referred_tenant_id, 'name' => $r->referred_name],
                'status'        => $r->status,
                'status_label'  => self::STATUSES[$r->status] ?? $r->status,
                'note'          => $r->reward_note,
                'created_at'    => Carbon::parse($r->created_at)->toIso8601String(),
                'rewarded_at'   => $r->rewarded_at ? Carbon::parse($r->rewarded_at)->toIso8601String() : null,
            ])->all(),
        ];
    }
}
