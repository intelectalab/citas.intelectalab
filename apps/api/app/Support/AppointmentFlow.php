<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Fases de la cita y pasos permitidos entre ellas. Son fijas para todas las
 * empresas: el flujo, los avisos y las reglas dependen de la clave. Cada
 * empresa solo cambia el nombre con que las ve.
 */
class AppointmentFlow
{
    public const PENDING = 'pending';
    public const CONFIRMED = 'confirmed';
    public const CHECKED_IN = 'checked_in';
    public const IN_PROGRESS = 'in_progress';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';
    public const NO_SHOW = 'no_show';

    // Nombre por defecto y tono (para el color en pantalla), en el orden del flujo.
    public const STATUSES = [
        self::PENDING     => ['name' => 'Pendiente',   'tone' => 'warn'],
        self::CONFIRMED   => ['name' => 'Confirmada',  'tone' => 'info'],
        self::CHECKED_IN  => ['name' => 'Llegó',       'tone' => 'accent'],
        self::IN_PROGRESS => ['name' => 'En atención', 'tone' => 'accent'],
        self::COMPLETED   => ['name' => 'Atendida',    'tone' => 'ok'],
        self::CANCELLED   => ['name' => 'Cancelada',   'tone' => 'muted'],
        self::NO_SHOW     => ['name' => 'No asistió',  'tone' => 'bad'],
    ];

    // A qué fases se puede pasar desde cada una.
    public const TRANSITIONS = [
        self::PENDING     => [self::CONFIRMED, self::CHECKED_IN, self::CANCELLED, self::NO_SHOW],
        self::CONFIRMED   => [self::CHECKED_IN, self::IN_PROGRESS, self::CANCELLED, self::NO_SHOW, self::PENDING],
        self::CHECKED_IN  => [self::IN_PROGRESS, self::COMPLETED, self::CONFIRMED],
        self::IN_PROGRESS => [self::COMPLETED],
        self::COMPLETED   => [],
        self::CANCELLED   => [],
        self::NO_SHOW     => [],
    ];

    // Fases que ya no ocupan el horario del profesional.
    public const RELEASED = [self::CANCELLED, self::NO_SHOW];

    // Fases en las que la cita todavía se puede mover de fecha u hora.
    public const RESCHEDULABLE = [self::PENDING, self::CONFIRMED];

    // Valor de las reglas de agenda cuando ni el rubro ni la empresa las definen.
    public const RULE_DEFAULTS = [
        'agenda.require_confirmation' => true,
        'agenda.checkin_step'         => true,
        'agenda.respect_buffers'      => true,
        'agenda.client_overlap'       => true,
        'agenda.allow_override'       => true,
        'agenda.slot_interval'        => 15,
        'cancel.require_reason'       => true,
        'cancel.window_hours'         => 12,
        'noshow.grace_minutes'        => 15,
        'records.require_on_complete' => false,
        'records.lock_hours'          => 24,
        'records.private_to_author'   => false,
        'booking.enabled'             => true,
        'booking.min_lead_minutes'    => 60,
        'booking.max_days_ahead'      => 45,
        'booking.choose_professional' => true,
        'booking.auto_confirm'        => false,
        'booking.require_deposit'     => true,
        'cancel.client_allowed'       => true,
        'reschedule.max_times'        => 2,
        // Plazo para confirmar: horas antes de la cita (0 = sin plazo), qué pasa si no se
        // confirma (notify, notify_release, release) y a cuántas horas se libera.
        'confirm.deadline_hours'      => 24,
        'confirm.on_miss'             => 'notify',
        'confirm.release_hours'       => 6,
        // Si un equipo instalado en una sala queda fuera de uso: none (no pasa nada),
        // observe (la sala lo muestra como observación) o disable (la sala queda fuera de uso).
        'resources.equipment_down'    => 'none',
    ];

    public static function keys(): array
    {
        return array_keys(self::STATUSES);
    }

    public static function rule(Tenant $tenant, string $key): mixed
    {
        return $tenant->rules()[$key] ?? self::RULE_DEFAULTS[$key] ?? null;
    }

    /**
     * Fases a las que puede pasar una cita. Sin el paso de llegada, una cita
     * pendiente o confirmada se puede dar por atendida directamente.
     */
    public static function next(Tenant $tenant, string $status): array
    {
        $next = self::TRANSITIONS[$status] ?? [];

        if (!self::rule($tenant, 'agenda.checkin_step') && in_array($status, [self::PENDING, self::CONFIRMED], true)) {
            $next[] = self::COMPLETED;
        }

        return $next;
    }

    /**
     * Las fases como las ve la empresa: clave, nombre propio (o el de por
     * defecto) y tono.
     */
    public static function forTenant(Tenant $tenant): array
    {
        $names = $tenant->settings?->appointment_statuses ?? [];

        return collect(self::STATUSES)->map(fn (array $status, string $key) => [
            'key'          => $key,
            'name'         => $names[$key] ?? $status['name'],
            'default_name' => $status['name'],
            'tone'         => $status['tone'],
        ])->values()->all();
    }

    public static function name(Tenant $tenant, string $status): string
    {
        return $tenant->settings?->appointment_statuses[$status] ?? self::STATUSES[$status]['name'] ?? $status;
    }
}
