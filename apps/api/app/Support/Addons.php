<?php

namespace App\Support;

use App\Models\SaaSPlan;
use App\Models\Status;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Addons
{
    public const NOT_READY = 'Falta actualizar la base de datos para los complementos: ejecuta php artisan migrate.';

    public const LIMITS = [
        'branches'       => 'Sedes',
        'users'          => 'Usuarios',
        'professionals'  => 'Profesionales',
        'whatsapp_month' => 'Mensajes de WhatsApp al mes',
        'appointments_month' => 'Citas al mes',
        'campaign_emails_month' => 'Correos de campañas al mes',
    ];

    public const YEAR_MONTHS = 10;

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('saas_addons') && Schema::hasTable('tenant_addons');
    }

    public static function catalog(bool $onlyActive = true): Collection
    {
        if (!self::ready()) {
            return collect();
        }

        return DB::table('saas_addons')->when($onlyActive, fn ($q) => $q->where('status', 'active'))
            ->orderBy('presentation_order')->orderBy('name')->get();
    }

    public static function active(string $tenantId): Collection
    {
        if (!self::ready()) {
            return collect();
        }

        return Rls::bypass(fn () => DB::table('tenant_addons as t')
            ->join('saas_addons as a', 'a.id', '=', 't.addon_id')
            ->where('t.tenant_id', $tenantId)
            ->where('t.status', 'active')
            ->where(fn ($q) => $q->whereNull('t.ends_at')->orWhere('t.ends_at', '>', now()))
            ->orderBy('a.presentation_order')
            ->get(['t.*', 'a.code', 'a.name', 'a.description', 'a.module_key', 'a.limit_key', 'a.limit_amount', 'a.max_quantity', 'a.currency']));
    }

    public static function extend(string $tenantId, ?array $limits): array
    {
        $limits ??= [];

        foreach (self::active($tenantId) as $row) {
            $key = $row->limit_key;

            if (!$key || !isset($limits[$key]) || !is_numeric($limits[$key]) || (int) $limits[$key] < 0) {
                continue;
            }

            $limits[$key] = (int) $limits[$key] + (int) $row->limit_amount * (int) $row->quantity;
        }

        return $limits;
    }

    public static function moduleKeys(string $tenantId): array
    {
        return self::active($tenantId)->pluck('module_key')->filter()->unique()->values()->all();
    }

    public static function unitPrice(float $monthly, string $cycle): float
    {
        return round($cycle === 'yearly' ? $monthly * self::YEAR_MONTHS : $monthly, 2);
    }

    public static function total(string $tenantId, string $cycle): float
    {
        return round(self::active($tenantId)->whereNull('ends_at')
            ->sum(fn ($row) => self::unitPrice((float) $row->monthly_price, $cycle) * (int) $row->quantity), 2);
    }

    public static function monthlyTotal(string $tenantId): float
    {
        return self::total($tenantId, 'monthly');
    }

    private static function addon(string $addonId): object
    {
        $addon = DB::table('saas_addons')->where('id', $addonId)->first();

        if (!$addon || $addon->status !== 'active') {
            throw ValidationException::withMessages(['addon_id' => 'Ese complemento no está disponible.']);
        }

        return $addon;
    }

    private static function current(string $tenantId, string $addonId): ?object
    {
        return Rls::bypass(fn () => DB::table('tenant_addons')->where('tenant_id', $tenantId)->where('addon_id', $addonId)->first());
    }

    private static function owned(?object $row): int
    {
        return $row && $row->status === 'active' && (!$row->ends_at || Carbon::parse($row->ends_at)->isFuture()) ? (int) $row->quantity : 0;
    }

    public static function preview(Subscription $subscription, string $addonId, int $quantity): array
    {
        abort_unless(self::ready(), 503, self::NOT_READY);
        $addon = self::addon($addonId);
        $quantity = max(1, $quantity);
        $row = self::current($subscription->tenant_id, $addon->id);
        $owned = self::owned($row);
        $state = Subscriptions::state($subscription->tenant_id)['state'];
        $cycle = $subscription->billing_cycle ?: 'monthly';
        $end = Subscriptions::endOf($subscription);
        $currency = $addon->currency ?: 'PEN';
        $unit = self::unitPrice((float) $addon->monthly_price, $cycle);
        $base = ['addon' => ['id' => $addon->id, 'name' => $addon->name, 'currency' => $currency], 'quantity' => $quantity, 'owned' => $owned, 'today' => 0.0, 'next_charge' => round($unit * $quantity, 2), 'billing_cycle' => $cycle, 'days_left' => null, 'blocked' => false];

        if ($owned + $quantity > (int) $addon->max_quantity) {
            return ['kind' => 'blocked', 'blocked' => true, 'message' => "Puedes tener hasta {$addon->max_quantity} de «{$addon->name}». Ya tienes {$owned}."] + $base;
        }

        if ($subscription->status === 'canceled' || in_array($state, ['grace', 'locked', 'none'], true)) {
            return ['kind' => 'blocked', 'blocked' => true, 'message' => 'Primero paga tu plan; después puedes sumar complementos.'] + $base;
        }

        if ($state === 'trial') {
            return ['kind' => 'trial', 'message' => "Se suma hoy y no se cobra nada durante tu prueba. Cuando actives tu plan, se cobra junto con él: " . Currency::format($unit * $quantity, $currency) . ' ' . ($cycle === 'yearly' ? 'al año' : 'al mes') . '.'] + $base;
        }

        if (!$end || !$end->isFuture() || Subscriptions::price(SaaSPlan::find($subscription->plan_id), $cycle) <= 0) {
            return ['kind' => 'blocked', 'blocked' => true, 'message' => 'Tu plan no tiene un periodo pagado para sumarle complementos. Escribe a soporte de la plataforma.'] + $base;
        }

        $periodStart = $cycle === 'yearly' ? $end->copy()->subYear() : $end->copy()->subMonth();
        $daysPeriod = max(1, (int) round($periodStart->diffInSeconds($end) / 86400));
        $daysLeft = max(0, (int) ceil(now()->diffInSeconds($end, false) / 86400));
        $today = max(0, round($unit * $quantity * min($daysLeft, $daysPeriod) / $daysPeriod, 2));
        $card = PlanBilling::savedCard($subscription);

        return [
            'kind'      => 'charge',
            'today'     => $today,
            'days_left' => $daysLeft,
            'blocked'   => !$card,
            'message'   => $card
                ? 'Se suma hoy. Cobramos a tu tarjeta ' . Currency::format($today, $currency) . " por los {$daysLeft} días que faltan de este periodo; desde el próximo cobro se suma " . Currency::format($unit * $quantity, $currency) . '.'
                : 'Para sumar un complemento hoy, primero guarda una tarjeta en «Guardar tarjeta»: cobramos solo los días que faltan de este periodo.',
        ] + $base;
    }

    public static function contract(Subscription $subscription, string $addonId, int $quantity, User $user): array
    {
        $preview = self::preview($subscription, $addonId, $quantity);

        if ($preview['blocked']) {
            throw ValidationException::withMessages(['addon_id' => $preview['message']]);
        }

        $addon = self::addon($addonId);
        $result = [];

        if ($preview['kind'] === 'charge' && $preview['today'] > 0) {
            $result = PlanBilling::chargeCard($subscription, SaaSPlan::findOrFail($subscription->plan_id), $subscription->billing_cycle ?: 'monthly', (float) $preview['today'], 'addon');

            if (!$result['ok']) {
                throw ValidationException::withMessages(['addon_id' => 'El cobro no pasó: ' . rtrim($result['message'], '.') . '. Cambia la tarjeta e inténtalo de nuevo.']);
            }

            self::linkPayment($result['payment_id'] ?? null, $addon->name, $preview['quantity']);
        }

        self::apply($subscription->tenant_id, $addon, $preview['quantity'], $user, $result['payment_id'] ?? null);
        Subscriptions::forget($subscription->tenant_id);
        SaasEvents::record($subscription->tenant_id, null, ['meta' => ['reason' => 'addon']]);
        PlanBilling::notify($subscription->tenant_id, 'subscription.addon', 'Sumaste un complemento', "«{$addon->name}» × {$preview['quantity']} ya está en tu plan.", 'addon:' . $addon->id . ':' . now()->format('YmdHis'));

        return ['message' => $preview['kind'] === 'charge' && $preview['today'] > 0
            ? "Listo: sumaste «{$addon->name}». Cobramos " . Currency::format((float) $preview['today'], $preview['addon']['currency']) . ' por los días que faltan.'
            : "Listo: sumaste «{$addon->name}».", 'preview' => $preview];
    }

    private static function linkPayment(?string $paymentId, string $name, int $quantity): void
    {
        if (!$paymentId) {
            return;
        }

        $invoiceId = DB::table('plan_payments')->where('id', $paymentId)->value('invoice_id');

        if ($invoiceId) {
            SubscriptionInvoice::where('id', $invoiceId)->update(['notes' => mb_substr("Complemento {$name} × {$quantity} por los días que faltan", 0, 255)]);
        }
    }

    private static function apply(string $tenantId, object $addon, int $quantity, User $user, ?string $paymentId): void
    {
        Rls::bypass(function () use ($tenantId, $addon, $quantity, $user, $paymentId) {
            $row = self::current($tenantId, $addon->id);
            $owned = self::owned($row);

            $values = [
                'quantity'        => $owned + $quantity,
                'status'          => 'active',
                'ends_at'         => null,
                'removed_at'      => null,
                'plan_payment_id' => $paymentId ?? $row?->plan_payment_id,
                'requested_by'    => $user->id,
                'updated_at'      => now(),
            ];

            if ($row) {
                DB::table('tenant_addons')->where('id', $row->id)->update($values + ($owned ? [] : ['since' => now(), 'monthly_price' => $addon->monthly_price]));
            } else {
                DB::table('tenant_addons')->insert($values + ['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'addon_id' => $addon->id, 'monthly_price' => $addon->monthly_price, 'since' => now(), 'created_at' => now()]);
            }
        });
    }

    public static function invoice(Subscription $subscription, object $payment, string $reference): SubscriptionInvoice
    {
        $paidStatusId = Status::whereHas('module', fn ($q) => $q->where('name', 'payments'))->whereNull('tenant_id')->where('slug', 'paid')->value('id');
        abort_unless($paidStatusId, 500, 'Falta el estado "Pagado" del módulo de pagos.');

        $invoice = SubscriptionInvoice::create([
            'subscription_id'     => $subscription->id,
            'tenant_id'           => $subscription->tenant_id,
            'status_id'           => $paidStatusId,
            'amount'              => (float) $payment->amount,
            'currency'            => $payment->currency,
            'billing_reason'      => 'addon',
            'invoice_date'        => now(),
            'paid_at'             => now(),
            'period_start'        => now(),
            'period_end'          => $subscription->ends_at,
            'external_invoice_id' => $reference,
            'notes'               => 'Complemento por los días que faltan',
        ]);

        Subscriptions::afterPaid($subscription, $invoice);

        return $invoice;
    }

    public static function remove(Subscription $subscription, string $addonId, User $user): array
    {
        abort_unless(self::ready(), 503, self::NOT_READY);
        $row = self::current($subscription->tenant_id, $addonId);
        abort_unless($row && self::owned($row) > 0, 404, 'Tu empresa no tiene ese complemento.');
        $name = DB::table('saas_addons')->where('id', $addonId)->value('name');
        $state = Subscriptions::state($subscription->tenant_id)['state'];
        $end = Subscriptions::endOf($subscription);
        $now = $state === 'trial' || !$end || !$end->isFuture();

        Rls::bypass(fn () => DB::table('tenant_addons')->where('id', $row->id)->update($now
            ? ['status' => 'removed', 'removed_at' => now(), 'ends_at' => now(), 'updated_at' => now()]
            : ['ends_at' => $end, 'updated_at' => now()]));

        Subscriptions::forget($subscription->tenant_id);
        SaasEvents::record($subscription->tenant_id, null, ['meta' => ['reason' => 'addon_removed']]);

        return [
            'message' => $now
                ? "Listo: quitaste «{$name}»."
                : "Listo: «{$name}» sigue hasta el " . $end->copy()->setTimezone(Tenant::find($subscription->tenant_id)?->zone() ?? 'America/Lima')->locale('es')->isoFormat('D [de] MMMM') . ' y ya no se cobra desde el próximo periodo. Lo pagado no se devuelve.',
            'name'    => $name,
            'now'     => $now,
        ];
    }

    public static function keep(Subscription $subscription, string $addonId): string
    {
        abort_unless(self::ready(), 503, self::NOT_READY);
        $row = self::current($subscription->tenant_id, $addonId);
        abort_unless($row && self::owned($row) > 0 && $row->ends_at, 422, 'Ese complemento no tiene un retiro programado.');
        Rls::bypass(fn () => DB::table('tenant_addons')->where('id', $row->id)->update(['ends_at' => null, 'updated_at' => now()]));
        Subscriptions::forget($subscription->tenant_id);
        SaasEvents::record($subscription->tenant_id, null, ['meta' => ['reason' => 'addon_kept']]);

        return (string) DB::table('saas_addons')->where('id', $addonId)->value('name');
    }

    public static function expireDue(): int
    {
        if (!self::ready()) {
            return 0;
        }

        return Rls::bypass(fn () => DB::table('tenant_addons')->where('status', 'active')->whereNotNull('ends_at')->where('ends_at', '<=', now())
            ->update(['status' => 'removed', 'removed_at' => now(), 'updated_at' => now()]));
    }

    public static function present(Tenant $tenant, ?Subscription $subscription): ?array
    {
        if (!self::ready()) {
            return null;
        }

        $cycle = $subscription?->billing_cycle ?: 'monthly';
        $zone = $tenant->zone();
        $mine = self::active($tenant->id);

        return [
            'mine' => $mine->map(fn ($row) => [
                'id'        => $row->addon_id,
                'name'      => $row->name,
                'quantity'  => (int) $row->quantity,
                'price'     => self::unitPrice((float) $row->monthly_price, $cycle),
                'currency'  => $row->currency ?: 'PEN',
                'ends_at'   => $row->ends_at ? Carbon::parse($row->ends_at)->setTimezone($zone)->format('Y-m-d') : null,
                'extra'     => $row->limit_key ? ['label' => self::LIMITS[$row->limit_key] ?? $row->limit_key, 'amount' => (int) $row->limit_amount * (int) $row->quantity] : null,
            ])->values()->all(),
            'available' => self::catalog()->map(fn ($addon) => [
                'id'           => $addon->id,
                'name'         => $addon->name,
                'description'  => $addon->description,
                'price'        => self::unitPrice((float) $addon->monthly_price, $cycle),
                'currency'     => $addon->currency ?: 'PEN',
                'max_quantity' => (int) $addon->max_quantity,
                'owned'        => (int) ($mine->firstWhere('addon_id', $addon->id)->quantity ?? 0),
            ])->values()->all(),
            'billing_cycle' => $cycle,
            'total'         => self::total($tenant->id, $cycle),
        ];
    }

    public static function presentAdmin(object $addon): array
    {
        $tenants = Rls::bypass(fn () => DB::table('tenant_addons')->where('addon_id', $addon->id)->where('status', 'active')->count());

        return [
            'id'                 => $addon->id,
            'code'               => $addon->code,
            'name'               => $addon->name,
            'description'        => $addon->description,
            'monthly_price'      => round((float) $addon->monthly_price, 2),
            'currency'           => $addon->currency ?: 'PEN',
            'module_key'         => $addon->module_key,
            'limit_key'          => $addon->limit_key,
            'limit_amount'       => (int) $addon->limit_amount,
            'max_quantity'       => (int) $addon->max_quantity,
            'status'             => $addon->status,
            'presentation_order' => (int) $addon->presentation_order,
            'tenants'            => $tenants,
        ];
    }
}
