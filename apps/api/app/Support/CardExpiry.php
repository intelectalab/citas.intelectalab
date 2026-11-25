<?php

namespace App\Support;

use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CardExpiry
{
    public const WARN_DAYS = 30;

    private const PATHS = [
        ['source.expiration_month', 'source.expiration_year'],
        ['source.exp_month', 'source.exp_year'],
        ['source.card.expiration_month', 'source.card.expiration_year'],
        ['expiration_month', 'expiration_year'],
        ['exp_month', 'exp_year'],
        ['card.expiration_month', 'card.expiration_year'],
        ['payment.card.expiration_month', 'payment.card.expiration_year'],
        ['card_data.expiration_month', 'card_data.expiration_year'],
    ];

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasColumn('subscriptions', 'card_exp_month');
    }

    public static function from(array $data): ?array
    {
        foreach (self::PATHS as [$m, $y]) {
            $month = data_get($data, $m);
            $year = data_get($data, $y);

            if (is_numeric($month) && is_numeric($year)) {
                $month = (int) $month;
                $year = (int) $year;
                $year = $year < 100 ? 2000 + $year : $year;

                if ($month >= 1 && $month <= 12 && $year >= 2000 && $year <= 2100) {
                    return ['month' => $month, 'year' => $year];
                }
            }
        }

        $joined = (string) (data_get($data, 'source.expiration_date') ?? data_get($data, 'expiration_date') ?? '');

        if (preg_match('/^(\d{1,2})\s*\/\s*(\d{2}|\d{4})$/', $joined, $m)) {
            return self::from(['expiration_month' => $m[1], 'expiration_year' => $m[2]]);
        }

        return null;
    }

    public static function save(string $subscriptionId, ?array $expiry): void
    {
        if (!self::ready()) {
            return;
        }

        DB::table('subscriptions')->where('id', $subscriptionId)->update([
            'card_exp_month'     => $expiry['month'] ?? null,
            'card_exp_year'      => $expiry['year'] ?? null,
            'card_expiry_notice' => null,
        ]);
    }

    public static function endOf(int $month, int $year): Carbon
    {
        return Carbon::create($year, $month, 1, 0, 0, 0, 'America/Lima')->endOfMonth();
    }

    public static function state(?object $subscription): ?array
    {
        if (!self::ready() || !$subscription || empty($subscription->card_exp_month) || empty($subscription->card_exp_year)) {
            return null;
        }

        $end = self::endOf((int) $subscription->card_exp_month, (int) $subscription->card_exp_year);
        $now = Carbon::now('America/Lima');
        $state = match (true) {
            $end->lt($now)                                => 'expired',
            $end->lt($now->copy()->addDays(self::WARN_DAYS)) => 'soon',
            default                                       => 'ok',
        };

        return [
            'month' => (int) $subscription->card_exp_month,
            'year'  => (int) $subscription->card_exp_year,
            'label' => sprintf('%02d/%02d', $subscription->card_exp_month, $subscription->card_exp_year % 100),
            'state' => $state,
            'text'  => match ($state) {
                'expired' => 'La tarjeta del plan venció: registra una nueva para que el cobro no falle.',
                'soon'    => 'La tarjeta del plan vence a fin de ' . mb_strtolower($end->locale('es')->translatedFormat('F')) . ': registra una nueva antes del próximo cobro.',
                default   => null,
            },
        ];
    }

    public static function run(): array
    {
        if (!self::ready() || !Subscriptions::ready()) {
            return ['avisos' => 0];
        }

        $sent = 0;
        $rows = Rls::bypass(fn () => DB::table('subscriptions')->where('card_status', 'authorized')->whereNotNull('card_exp_month')->whereNotNull('card_exp_year')->get());

        foreach ($rows as $row) {
            $state = self::state($row);

            if (!$state || $state['state'] === 'ok') {
                continue;
            }

            $notice = $state['state'] . ':' . $state['label'];

            if ($row->card_expiry_notice === $notice) {
                continue;
            }

            $card = trim(ucfirst((string) $row->card_brand) . ($row->card_last4 ? " terminada en {$row->card_last4}" : ''));
            $title = $state['state'] === 'expired' ? 'La tarjeta del plan venció' : 'La tarjeta del plan está por vencer';
            $body = ($card !== '' ? "Tu tarjeta {$card} " : 'Tu tarjeta ') . ($state['state'] === 'expired' ? "venció ({$state['label']})." : "vence en {$state['label']}.") . ' Registra una nueva en Mi empresa → Plan para que el cobro automático no falle.';

            Rls::asTenant($row->tenant_id, fn () => PlanBilling::notify($row->tenant_id, 'subscription.card_expiry', $title, $body, "card-expiry:{$row->id}:{$notice}"));
            Rls::bypass(fn () => DB::table('subscriptions')->where('id', $row->id)->update(['card_expiry_notice' => $notice]));
            $sent++;
        }

        return ['avisos' => $sent];
    }

    public static function forSubscription(Subscription $subscription): ?array
    {
        return self::state((object) $subscription->getAttributes());
    }
}
