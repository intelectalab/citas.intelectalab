<?php

namespace App\Support;

use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Dunning
{
    public const DEFAULTS = [
        'retry_days'   => [0, 1, 3, 7],
        'remind_days'  => [1, 3, 7, 12],
        'readonly_day' => 15,
        'cancel_day'   => 45,
    ];

    public const EXTEND_DAYS = 7;

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Subscriptions::ready() && Schema::hasTable('saas_dunning_attempts') && Schema::hasColumn('subscriptions', 'grace_extra_days');
    }

    public static function config(): array
    {
        $stored = PlatformSetting::value('dunning');
        $stored = is_array($stored) ? $stored : [];
        $days = fn ($value, $fallback) => is_array($value)
            ? collect($value)->map(fn ($day) => (int) $day)->filter(fn ($day) => $day >= 0 && $day <= 60)->unique()->sort()->values()->all()
            : $fallback;

        $readonly = max(1, min(60, (int) ($stored['readonly_day'] ?? self::DEFAULTS['readonly_day'])));

        return [
            'retry_days'   => $days($stored['retry_days'] ?? null, self::DEFAULTS['retry_days']),
            'remind_days'  => $days($stored['remind_days'] ?? null, self::DEFAULTS['remind_days']),
            'readonly_day' => $readonly,
            'cancel_day'   => max($readonly + 1, min(180, (int) ($stored['cancel_day'] ?? self::DEFAULTS['cancel_day']))),
        ];
    }

    public static function extraDays(Subscription $subscription): int
    {
        return self::ready() ? max(0, (int) ($subscription->grace_extra_days ?? 0)) : 0;
    }

    public static function dayOf(Subscription $subscription): ?int
    {
        $end = Subscriptions::endOf($subscription);

        if (!$end || $end->isFuture()) {
            return null;
        }

        return (int) floor($end->diffInSeconds(now(), true) / 86400);
    }

    public static function attempted(Subscription $subscription, string $kind, int $day): bool
    {
        $end = Subscriptions::endOf($subscription);

        return DB::table('saas_dunning_attempts')->where('subscription_id', $subscription->id)->where('period_end', $end)
            ->where('day', $day)->where('kind', $kind)->whereNull('actor_id')->exists();
    }

    public static function log(Subscription $subscription, string $kind, string $result, ?string $message = null, ?string $paymentId = null, ?string $actorId = null, ?int $day = null): void
    {
        if (!self::ready()) {
            return;
        }

        $end = Subscriptions::endOf($subscription);

        DB::table('saas_dunning_attempts')->insertOrIgnore([
            'id'              => (string) Str::uuid(),
            'tenant_id'       => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'period_end'      => $end ?? now(),
            'day'             => $day ?? (self::dayOf($subscription) ?? 0),
            'kind'            => $kind,
            'result'          => $result,
            'message'         => $message ? mb_substr($message, 0, 250) : null,
            'plan_payment_id' => $paymentId,
            'actor_id'        => $actorId,
            'created_at'      => now(),
        ]);
    }

    private static function due(array $days, int $day): ?int
    {
        $past = array_filter($days, fn ($value) => $value <= $day);

        return $past ? max($past) : null;
    }

    public static function candidates()
    {
        return Subscription::with('plan')
            ->whereIn('status', ['active', 'trialing', 'expired', 'past_due'])
            ->when(Cancellations::ready(), fn ($q) => $q->whereNull('cancel_at'))
            ->where(fn ($q) => $q->where(fn ($c) => $c->where('status', 'trialing')->whereRaw('coalesce(trial_ends_at, ends_at) <= now()'))
                ->orWhere(fn ($c) => $c->where('status', '!=', 'trialing')->where('ends_at', '<=', now())))
            ->get()
            ->filter(fn (Subscription $subscription) => Subscriptions::latest($subscription->tenant_id)?->id === $subscription->id
                && Subscriptions::price($subscription->plan, $subscription->billing_cycle) > 0);
    }

    public static function run(): array
    {
        $counts = ['revisadas' => 0, 'cobros' => 0, 'avisos' => 0, 'canceladas' => 0];

        if (!self::ready()) {
            return $counts;
        }

        foreach (self::candidates() as $subscription) {
            try {
                $counts['revisadas']++;
                $done = self::process($subscription);
                foreach ($done as $key) {
                    $counts[$key]++;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $counts;
    }

    public static function process(Subscription $subscription): array
    {
        $day = self::dayOf($subscription);

        if ($day === null) {
            return [];
        }

        $config = self::config();
        $extra = self::extraDays($subscription);
        $done = [];

        if ($day >= $config['cancel_day'] + $extra) {
            self::cancel($subscription, $day);

            return ['canceladas'];
        }

        $retry = self::due($config['retry_days'], $day);

        if ($retry !== null && PlanBilling::savedCard($subscription) && !self::attempted($subscription, 'retry', $retry)) {
            $result = PlanBilling::chargeSaved($subscription, $retry === 0 ? 'cycle' : 'retry');
            self::log($subscription, 'retry', $result['ok'] ? 'ok' : 'failed', $result['message'], $result['payment_id'] ?? null, null, $retry);
            $done[] = 'cobros';

            if ($result['ok']) {
                return $done;
            }

            $subscription = $subscription->fresh('plan');
        }

        $remind = self::due($config['remind_days'], $day);

        if ($remind !== null && !self::attempted($subscription, 'remind', $remind)) {
            self::remind($subscription, $day);
            self::log($subscription, 'remind', 'sent', null, null, null, $remind);
            $done[] = 'avisos';
        }

        $readonly = $config['readonly_day'] + $extra;

        if ($day >= $readonly && !self::attempted($subscription, 'readonly', $readonly)) {
            self::announceReadOnly($subscription);
            self::log($subscription, 'readonly', 'sent', null, null, null, $readonly);
            $done[] = 'avisos';
        }

        return $done;
    }

    private static function dateText(?Carbon $date): string
    {
        return $date ? $date->copy()->locale('es')->isoFormat('dddd D [de] MMMM') : '';
    }

    public static function readOnlyOn(Subscription $subscription): ?Carbon
    {
        $end = Subscriptions::endOf($subscription);

        return $end?->copy()->addDays(self::config()['readonly_day'] + self::extraDays($subscription));
    }

    public static function cancelOn(Subscription $subscription): ?Carbon
    {
        $end = Subscriptions::endOf($subscription);

        return $end?->copy()->addDays(self::config()['cancel_day'] + self::extraDays($subscription));
    }

    private static function lastFailure(Subscription $subscription): ?string
    {
        return DB::table('plan_payments')->where('subscription_id', $subscription->id)->where('kind', 'charge')->where('status', 'failed')
            ->orderByDesc('created_at')->value('message');
    }

    private static function mailAdmins(Subscription $subscription, string $subject, string $body): void
    {
        $emails = DB::table('users')->where('tenant_id', $subscription->tenant_id)->where('status', 'active')
            ->where(fn ($q) => $q->where('is_owner', true)->orWhereIn('id', Notifier::adminIds($subscription->tenant_id)))
            ->pluck('email')->unique();

        foreach ($emails as $email) {
            PlatformMail::trySend($email, $subject, $body);
        }
    }

    public static function remind(Subscription $subscription, int $day): void
    {
        $tenant = Tenant::find($subscription->tenant_id);
        $amount = Currency::format(Subscriptions::price($subscription->plan, $subscription->billing_cycle), $subscription->plan->currency ?? 'PEN');
        $readOnly = self::readOnlyOn($subscription);
        $failure = self::lastFailure($subscription);
        $locked = $readOnly && $readOnly->isPast();
        $text = $locked
            ? "El plan {$subscription->plan->name} de {$tenant->name} sigue sin pagarse ({$amount}). Tu empresa está en solo lectura: paga para volver a agendar y vender."
            : "El plan {$subscription->plan->name} de {$tenant->name} venció y no está pagado ({$amount})."
                . ($failure ? " El último intento de cobro no pasó: " . rtrim($failure, '.') . '.' : '')
                . " Si no se paga antes del " . self::dateText($readOnly) . ', tu empresa quedará en solo lectura.';

        PlanBilling::notify($subscription->tenant_id, 'subscription.overdue', 'Tu plan está vencido', $text, "overdue:{$subscription->id}:" . Subscriptions::endOf($subscription)?->format('YmdHis') . ":{$day}");
        self::mailAdmins($subscription, 'Tu plan está vencido', '<p>' . e($text) . '</p><p>Págalo en Mi empresa → Plan, con el botón «Pagar ahora».</p>');
        PlatformWhatsApp::notify($subscription->tenant_id, $locked ? 'readonly' : 'overdue', [$tenant->name, (string) $subscription->plan?->getAttribute('name'), $amount, self::dateText($readOnly)]);
    }

    private static function announceReadOnly(Subscription $subscription): void
    {
        $text = 'Tu empresa quedó en solo lectura porque el plan no está pagado. Puedes ver y exportar tus datos; para volver a agendar y vender, paga el plan en Mi empresa → Plan.'
            . ' Si no se paga antes del ' . self::dateText(self::cancelOn($subscription)) . ', el plan se cancelará.';

        SaasEvents::record($subscription->tenant_id, null, ['meta' => ['reason' => 'read_only']]);
        PlanBilling::notify($subscription->tenant_id, 'subscription.locked', 'Tu empresa está en solo lectura', $text, "readonly:{$subscription->id}:" . Subscriptions::endOf($subscription)?->format('YmdHis'));
        self::mailAdmins($subscription, 'Tu empresa está en solo lectura', '<p>' . e($text) . '</p>');
        PlatformWhatsApp::notify($subscription->tenant_id, 'readonly', [(string) Tenant::whereKey($subscription->tenant_id)->value('name')]);
    }

    private static function cancel(Subscription $subscription, int $day): void
    {
        $subscription->update(['status' => 'canceled', 'canceled_at' => now()]);
        self::log($subscription, 'cancel', 'ok', 'Cancelado por falta de pago.', null, null, $day);
        Subscriptions::forget($subscription->tenant_id);
        SaasEvents::record($subscription->tenant_id, null, ['meta' => ['reason' => 'unpaid']]);
        $text = 'Tu plan se canceló por falta de pago. Tus datos se conservan: si pagas el plan, tu empresa vuelve a tener todo como estaba.';
        PlanBilling::notify($subscription->tenant_id, 'subscription.canceled', 'Tu plan se canceló', $text, "canceled:{$subscription->id}");
        self::mailAdmins($subscription, 'Tu plan se canceló', '<p>' . e($text) . '</p>');
        PlatformWhatsApp::notify($subscription->tenant_id, 'canceled', [(string) Tenant::whereKey($subscription->tenant_id)->value('name')]);
    }

    public static function retryNow(Subscription $subscription, User $actor): array
    {
        if (!PlanBilling::savedCard($subscription)) {
            return ['ok' => false, 'message' => 'Esta empresa no tiene una tarjeta guardada para cobrar. Usa «Recordar» para avisarle.'];
        }

        $result = PlanBilling::chargeSaved($subscription, 'retry');
        self::log($subscription, 'retry', $result['ok'] ? 'ok' : 'failed', $result['message'], $result['payment_id'] ?? null, $actor->id);

        return ['ok' => $result['ok'], 'message' => $result['ok'] ? 'Cobro aprobado: la empresa quedó al día.' : 'El cobro no pasó: ' . $result['message']];
    }

    public static function remindNow(Subscription $subscription, User $actor): array
    {
        self::remind($subscription, self::dayOf($subscription) ?? 0);
        self::log($subscription, 'remind', 'sent', null, null, $actor->id);

        return ['ok' => true, 'message' => PlatformWhatsApp::enabled() ? 'Le enviamos el recordatorio por correo, por WhatsApp y dentro del sistema.' : 'Le enviamos el recordatorio por correo y dentro del sistema.'];
    }

    public static function extend(Subscription $subscription, User $actor): array
    {
        $restore = ['past_due' => 'active', 'expired' => 'trialing'][$subscription->status] ?? $subscription->status;
        $subscription->update(['grace_extra_days' => self::extraDays($subscription) + self::EXTEND_DAYS, 'status' => $restore]);
        Subscriptions::forget($subscription->tenant_id);
        self::log($subscription, 'extend', 'ok', 'Prórroga de ' . self::EXTEND_DAYS . ' días.', null, $actor->id);
        $readOnly = self::readOnlyOn($subscription->fresh());
        PlanBilling::notify($subscription->tenant_id, 'subscription.extended', 'Te dimos más días para pagar', 'Tienes ' . self::EXTEND_DAYS . ' días más para pagar tu plan: hasta el ' . self::dateText($readOnly) . '.', "extend:{$subscription->id}:" . now()->format('YmdHis'));

        return ['ok' => true, 'message' => 'Prórroga dada: ahora queda en solo lectura el ' . self::dateText($readOnly) . '.'];
    }

    public static function summary(array $list): array
    {
        $monthStart = now(PlatformReports::ZONE)->startOfMonth()->utc();
        $recovered = ['count' => 0, 'amount' => 0.0];

        if (self::ready()) {
            $periods = DB::table('saas_dunning_attempts')->where('created_at', '>=', now()->subDays(60))
                ->groupBy('subscription_id')->selectRaw('subscription_id, min(period_end) as since')->pluck('since', 'subscription_id');

            if ($periods->isNotEmpty()) {
                $invoices = DB::table('subscription_invoices')->whereIn('subscription_id', $periods->keys())->where('paid_at', '>=', $monthStart)->get(['subscription_id', 'amount', 'paid_at']);

                foreach ($invoices as $invoice) {
                    if (Carbon::parse($invoice->paid_at)->gte(Carbon::parse($periods[$invoice->subscription_id]))) {
                        $recovered['amount'] += (float) $invoice->amount;
                        $recovered['count']++;
                    }
                }
            }
        }

        $rows = collect($list);

        return [
            'to_collect'       => round((float) $rows->sum('amount'), 2),
            'to_collect_count' => $rows->count(),
            'readonly'         => $rows->where('state', 'locked')->count(),
            'grace'            => $rows->where('state', 'grace')->count(),
            'recovered'        => round($recovered['amount'], 2),
            'recovered_count'  => $recovered['count'],
        ];
    }

    public static function overdueList(): array
    {
        if (!self::ready()) {
            return [];
        }

        return self::candidates()
            ->map(function (Subscription $subscription) {
                $tenant = Tenant::find($subscription->tenant_id);
                $zone = $tenant?->zone() ?? 'America/Lima';
                $attempts = DB::table('saas_dunning_attempts')->where('subscription_id', $subscription->id)
                    ->where('period_end', Subscriptions::endOf($subscription))->orderByDesc('created_at')->get();
                $state = Subscriptions::state($subscription->tenant_id);

                return [
                    'subscription_id' => $subscription->id,
                    'tenant'          => ['id' => $tenant?->id, 'name' => $tenant?->name],
                    'plan'            => $subscription->plan?->name,
                    'amount'          => Subscriptions::charge($subscription),
                    'currency'        => $subscription->plan?->currency ?? 'PEN',
                    'state'           => $state['state'],
                    'ended_at'        => Subscriptions::endOf($subscription)?->setTimezone($zone)->format('Y-m-d'),
                    'day'             => self::dayOf($subscription),
                    'readonly_on'     => self::readOnlyOn($subscription)?->setTimezone($zone)->format('Y-m-d'),
                    'cancel_on'       => self::cancelOn($subscription)?->setTimezone($zone)->format('Y-m-d'),
                    'extra_days'      => self::extraDays($subscription),
                    'saved_card'      => PlanBilling::savedCard($subscription),
                    'card'            => $subscription->card_last4 ? trim(ucfirst((string) $subscription->card_brand) . ' ' . $subscription->card_last4) : null,
                    'last_failure'    => self::lastFailure($subscription),
                    'retries'         => $attempts->where('kind', 'retry')->count(),
                    'reminders'       => $attempts->where('kind', 'remind')->count(),
                    'attempts'        => $attempts->take(12)->map(fn ($row) => [
                        'kind'    => $row->kind,
                        'result'  => $row->result,
                        'day'     => (int) $row->day,
                        'message' => $row->message,
                        'manual'  => (bool) $row->actor_id,
                        'at'      => Carbon::parse($row->created_at)->toIso8601String(),
                    ])->values()->all(),
                ];
            })
            ->sortByDesc('day')
            ->values()
            ->all();
    }
}
