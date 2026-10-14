<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OnlineBooking
{
    public const NONE = 'none';
    public const NOTE = 'note';
    public const BLOCK = 'block';

    public static function canForgive(): bool
    {
        static $has = null;

        return $has ??= Schema::hasColumn('customers', 'booking_strikes_reset_at');
    }

    public static function policy(Tenant $tenant): array
    {
        $action = (string) AppointmentFlow::rule($tenant, 'booking.miss_action');

        return [
            'action'     => in_array($action, [self::NOTE, self::BLOCK], true) ? $action : self::NONE,
            'limit'      => max(1, (int) AppointmentFlow::rule($tenant, 'booking.miss_limit')),
            'block_days' => max(0, (int) AppointmentFlow::rule($tenant, 'booking.block_days')),
        ];
    }

    public static function states(Tenant $tenant, array $customerIds): array
    {
        $policy = self::policy($tenant);

        if ($policy['action'] === self::NONE || $customerIds === []) {
            return [];
        }

        $rows = DB::table('appointments as a')
            ->join('customers as c', 'c.id', '=', 'a.customer_id')
            ->where('a.tenant_id', $tenant->id)
            ->whereIn('a.customer_id', $customerIds)
            ->where('a.source', 'online')
            ->where('a.status', AppointmentFlow::NO_SHOW)
            ->when(self::canForgive(), fn ($q) => $q->where(fn ($w) => $w->whereNull('c.booking_strikes_reset_at')->orWhereColumn('a.starts_at', '>', 'c.booking_strikes_reset_at')))
            ->groupBy('a.customer_id')
            ->selectRaw('a.customer_id, count(*) as misses, max(a.starts_at) as last_miss')
            ->get();

        $states = [];

        foreach ($rows as $row) {
            $last = Carbon::parse($row->last_miss);
            $reached = $policy['action'] === self::BLOCK && (int) $row->misses >= $policy['limit'];
            $until = $reached && $policy['block_days'] > 0 ? $last->copy()->addDays($policy['block_days']) : null;
            $blocked = $reached && ($until === null || $until->isFuture());

            $states[$row->customer_id] = [
                'misses'        => (int) $row->misses,
                'last_miss_at'  => $last->toIso8601String(),
                'blocked'       => $blocked,
                'blocked_until' => $blocked && $until ? $until->toIso8601String() : null,
                'limit'         => $policy['action'] === self::BLOCK ? $policy['limit'] : null,
            ];
        }

        return $states;
    }

    public static function state(Tenant $tenant, string $customerId): ?array
    {
        return self::states($tenant, [$customerId])[$customerId] ?? null;
    }

    public static function blockedMessage(Tenant $tenant, array $state): string
    {
        $until = $state['blocked_until'] ? Carbon::parse($state['blocked_until'])->setTimezone($tenant->timezone ?? 'America/Lima') : null;

        return 'Por ' . ($state['misses'] === 1 ? 'una inasistencia' : "{$state['misses']} inasistencias") . ' a citas reservadas en línea, '
            . ($until ? 'no puedes reservar por aquí hasta el ' . $until->format('d/m/Y') . '.' : 'ya no puedes reservar por aquí.')
            . ' Comunícate con la sede para agendar.';
    }
}
