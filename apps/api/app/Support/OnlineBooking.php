<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Qué pasa con un cliente que reserva en línea y no asiste. La empresa elige:
 *
 *  - none:  no pasa nada.
 *  - note:  queda una nota en su ficha y en la lista de clientes.
 *  - block: además, al llegar a cierto número de inasistencias ya no puede
 *           reservar en línea por un tiempo (o hasta que la empresa lo permita).
 *
 * Cuenta como inasistencia una cita reservada desde el portal que terminó en
 * "no asistió". Nada se guarda: se calcula de sus citas, así un cambio en la
 * configuración rige al instante. El veto es solo para reservar en línea: el
 * personal puede seguir agendándole.
 */
class OnlineBooking
{
    public const NONE = 'none';
    public const NOTE = 'note';
    public const BLOCK = 'block';

    // En una base sin resetear la columna aún no existe: no se puede "perdonar", lo demás funciona.
    public static function canForgive(): bool
    {
        static $has = null;

        return $has ??= Schema::hasColumn('customers', 'booking_strikes_reset_at');
    }

    /**
     * @return array{action: string, limit: int, block_days: int}
     */
    public static function policy(Tenant $tenant): array
    {
        $action = (string) AppointmentFlow::rule($tenant, 'booking.miss_action');

        return [
            'action'     => in_array($action, [self::NOTE, self::BLOCK], true) ? $action : self::NONE,
            'limit'      => max(1, (int) AppointmentFlow::rule($tenant, 'booking.miss_limit')),
            // 0 = sin fecha de fin: hasta que la empresa lo vuelva a permitir.
            'block_days' => max(0, (int) AppointmentFlow::rule($tenant, 'booking.block_days')),
        ];
    }

    /**
     * Estado de varios clientes a la vez (para listas).
     *
     * @param array<int, string> $customerIds
     * @return array<string, array<string, mixed>>
     */
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
            // Lo anterior al último "perdón" de la empresa ya no cuenta.
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
                // Bloqueado y sin fecha: hasta que la empresa lo permita.
                'blocked_until' => $blocked && $until ? $until->toIso8601String() : null,
                'limit'         => $policy['action'] === self::BLOCK ? $policy['limit'] : null,
            ];
        }

        return $states;
    }

    /**
     * Estado de un cliente; null si no tiene inasistencias que cuenten.
     */
    public static function state(Tenant $tenant, string $customerId): ?array
    {
        return self::states($tenant, [$customerId])[$customerId] ?? null;
    }

    /**
     * Lo que se le dice al cliente cuando no puede reservar en línea.
     */
    public static function blockedMessage(Tenant $tenant, array $state): string
    {
        $until = $state['blocked_until'] ? Carbon::parse($state['blocked_until'])->setTimezone($tenant->timezone ?? 'America/Lima') : null;

        return 'Por ' . ($state['misses'] === 1 ? 'una inasistencia' : "{$state['misses']} inasistencias") . ' a citas reservadas en línea, '
            . ($until ? 'no puedes reservar por aquí hasta el ' . $until->format('d/m/Y') . '.' : 'ya no puedes reservar por aquí.')
            . ' Comunícate con la sede para agendar.';
    }
}
