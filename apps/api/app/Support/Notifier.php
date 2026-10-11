<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\AvailabilityBlock;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Avisos de la campana. Decide quién se entera de qué:
 *
 *  - El profesional: cuando otra persona le agenda, reprograma o cancela una cita,
 *    y lo que su cliente hace desde el portal (reservar, confirmar, cambiar, cancelar).
 *  - Los administradores: lo que hacen los clientes desde el portal y las
 *    cancelaciones. No reciben cada cita que agenda la recepción.
 *  - Ambos: una ausencia que empieza dentro de los próximos días.
 *
 * Nadie recibe un aviso de lo que hizo él mismo. Un fallo al avisar nunca
 * impide la acción que lo originó.
 */
class Notifier
{
    // Con cuántos días de anticipación se avisa de una ausencia.
    public const ABSENCE_DAYS_AHEAD = 3;

    public static function available(): bool
    {
        static $available = null;

        return $available ??= Schema::hasTable('user_notifications');
    }

    /**
     * Un paso en una cita: creación ($from nulo), reprogramación ($from igual a $to) o cambio de fase.
     */
    public static function appointmentStep(Appointment $appointment, ?string $from, string $to, User|Customer $actor): void
    {
        try {
            if (!self::available()) {
                return;
            }

            $byCustomer = $actor instanceof Customer;
            $created = $from === null;
            $moved = !$created && $from === $to;
            $cancelled = !$moved && $to === AppointmentFlow::CANCELLED;
            $confirmed = !$moved && !$created && $to === AppointmentFlow::CONFIRMED;

            if (!$created && !$moved && !$cancelled && !($confirmed && $byCustomer)) {
                return;
            }

            $appointment->loadMissing(['customer', 'service', 'professional', 'branch']);
            $professionalUser = $appointment->professional?->user_id;
            $admins = self::adminIds($appointment->tenant_id);

            if ($byCustomer) {
                [$type, $title] = match (true) {
                    $created   => ['appointment.portal_booked', 'Nueva reserva desde el portal'],
                    $moved     => ['appointment.portal_moved', 'Un cliente cambió su cita'],
                    $cancelled => ['appointment.portal_cancelled', 'Un cliente canceló su cita'],
                    default    => ['appointment.portal_confirmed', 'Cita confirmada por el cliente'],
                };
                // La confirmación le interesa a quien atiende; lo demás, también a la administración.
                $recipients = $confirmed ? [$professionalUser] : [$professionalUser, ...$admins];
            } else {
                [$type, $title] = match (true) {
                    $created => ['appointment.booked', 'Te agendaron una cita'],
                    $moved   => ['appointment.moved', 'Reprogramaron una cita'],
                    default  => ['appointment.cancelled', 'Cancelaron una cita'],
                };
                $recipients = $cancelled ? [$professionalUser, ...$admins] : [$professionalUser];
            }

            $recipients = array_diff(array_filter($recipients), [$byCustomer ? null : $actor->id]);

            $timezone = $appointment->branch?->timezone ?: (Tenant::find($appointment->tenant_id)?->timezone ?: 'America/Lima');
            $when = Carbon::parse($appointment->starts_at)->setTimezone($timezone)->locale('es')->isoFormat('ddd D [de] MMM, HH:mm');
            $who = $appointment->customer ? trim("{$appointment->customer->first_name} {$appointment->customer->last_name}") : null;
            $with = $appointment->professional?->display_name;

            foreach (array_unique($recipients) as $userId) {
                // A quien atiende no hace falta decirle con quién es la cita.
                $parts = [$who, $appointment->service?->name, $when, $userId === $professionalUser ? null : $with];

                self::send($appointment->tenant_id, $userId, $type, $title, implode(' · ', array_filter($parts)), '/agenda', 'appointment', $appointment->id);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Ausencias de profesionales que empiezan pronto. Se puede llamar cuantas
     * veces haga falta: cada persona recibe el aviso de cada ausencia una sola vez.
     */
    public static function upcomingAbsences(Tenant $tenant): void
    {
        try {
            if (!self::available()) {
                return;
            }

            $blocks = AvailabilityBlock::where('tenant_id', $tenant->id)
                ->whereNotNull('professional_id')
                ->where('starts_at', '>', now()->toIso8601String())
                ->where('starts_at', '<=', now()->addDays(self::ABSENCE_DAYS_AHEAD)->toIso8601String())
                ->with('professional')
                ->get();

            if ($blocks->isEmpty()) {
                return;
            }

            $admins = self::adminIds($tenant->id);
            $timezone = $tenant->timezone ?: 'America/Lima';
            $format = fn ($date) => Carbon::parse($date)->setTimezone($timezone)->locale('es')->isoFormat('ddd D [de] MMM, HH:mm');

            foreach ($blocks as $block) {
                $professionalUser = $block->professional?->user_id;
                $kind = AvailabilityBlock::KINDS[$block->kind] ?? 'Ausencia';
                $range = "{$format($block->starts_at)} a {$format($block->ends_at)}";

                foreach (array_unique(array_filter([$professionalUser, ...$admins])) as $userId) {
                    $own = $userId === $professionalUser;

                    self::send(
                        $tenant->id,
                        $userId,
                        'block.soon',
                        $own ? 'Tienes una ausencia próxima' : 'Ausencia próxima en el equipo',
                        implode(' · ', array_filter([$own ? null : $block->professional?->display_name, $kind, $range, $block->reason])),
                        '/blocks',
                        'block',
                        $block->id,
                        "block-soon:{$block->id}",
                    );
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Cuentas que administran la empresa: el dueño y quien tenga rol de dueño o administrador.
     *
     * @return array<int, string>
     */
    public static function adminIds(string $tenantId): array
    {
        return User::where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->where('is_owner', true)->orWhereHas('roles', fn ($r) => $r->whereIn('slug', ['owner', 'admin'])))
            ->pluck('id')
            ->all();
    }

    /**
     * Con $dedupeKey, la misma persona no recibe dos veces el mismo aviso.
     */
    public static function send(string $tenantId, string $userId, string $type, string $title, ?string $body, ?string $link, ?string $entityType = null, ?string $entityId = null, ?string $dedupeKey = null): void
    {
        DB::table('user_notifications')->insertOrIgnore([
            'id'          => (string) Str::uuid(),
            'tenant_id'   => $tenantId,
            'user_id'     => $userId,
            'type'        => $type,
            'title'       => $title,
            'body'        => $body ? mb_substr($body, 0, 500) : null,
            'link'        => $link,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'dedupe_key'  => $dedupeKey,
            'created_at'  => now(),
        ]);
    }
}
