<?php

namespace App\Support;

use App\Models\Appointment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Tiempos reales de una cita: cuánto esperó el cliente desde que llegó y
 * cuánto duró la atención, frente al tiempo que tenía asignado. Salen de las
 * marcas que deja cada cambio de fase (llegó, en atención, atendida).
 */
class AppointmentTiming
{
    // Una atención de menos de un minuto no se midió: alguien pasó las fases de corrido.
    private const MIN_SECONDS = 60;

    // Ni una que quedó abierta mucho más de lo asignado: alguien olvidó cerrarla.
    private const MAX_TIMES_PLANNED = 4;
    private const MAX_FLOOR_SECONDS = 4 * 3600;

    private static function seconds(?Carbon $from, ?Carbon $to): ?int
    {
        return $from && $to ? max(0, (int) round($from->diffInSeconds($to))) : null;
    }

    /**
     * Tiempos de la cita en este momento. Si está en espera o en atención, el
     * tiempo sigue corriendo: `running` dice cuál.
     */
    public static function of(Appointment $appointment): array
    {
        $now = now();
        $waiting = $appointment->status === AppointmentFlow::CHECKED_IN;
        $attending = $appointment->status === AppointmentFlow::IN_PROGRESS;

        return [
            'planned_seconds' => self::seconds($appointment->starts_at, $appointment->ends_at),
            // Desde que llegó hasta que empezó la atención.
            'wait_seconds'    => self::seconds($appointment->checked_in_at, $appointment->started_at ?? ($waiting ? $now : null)),
            // Desde que empezó la atención hasta que terminó.
            'service_seconds' => self::seconds($appointment->started_at, $appointment->completed_at ?? ($attending ? $now : null)),
            'running'         => $attending ? 'service' : ($waiting ? 'wait' : null),
            'measured'        => self::isMeasured($appointment),
        ];
    }

    /**
     * La duración real de la atención sirve para promedios.
     */
    public static function isMeasured(Appointment $appointment): bool
    {
        if ($appointment->status !== AppointmentFlow::COMPLETED) {
            return false;
        }

        $actual = self::seconds($appointment->started_at, $appointment->completed_at);
        $planned = self::seconds($appointment->starts_at, $appointment->ends_at) ?? 0;

        return $actual !== null
            && $actual >= self::MIN_SECONDS
            && $actual <= max($planned * self::MAX_TIMES_PLANNED, self::MAX_FLOOR_SECONDS);
    }

    /**
     * Hasta cuándo ocupa la cita al profesional. Si se atendió y terminó antes
     * de lo asignado, el resto del horario queda libre.
     */
    public static function occupiedUntil(Appointment $appointment): Carbon
    {
        $finished = $appointment->completed_at;

        if ($appointment->status === AppointmentFlow::COMPLETED
            && $finished
            && $finished->lt($appointment->ends_at)
            && $finished->gt($appointment->starts_at)) {
            // Al minuto siguiente, para no ofrecer un horario que empieza a media atención.
            return $finished->copy()->startOfMinute()->addMinute()->min($appointment->ends_at);
        }

        return $appointment->ends_at;
    }

    /**
     * Promedios de un grupo de citas atendidas, en minutos.
     */
    public static function summarize(Collection $appointments): array
    {
        $measured = $appointments->filter(fn (Appointment $a) => self::isMeasured($a));
        $minutes = fn (?float $seconds) => $seconds === null ? null : round($seconds / 60, 1);

        $actual = $measured->avg(fn (Appointment $a) => self::seconds($a->started_at, $a->completed_at));
        $planned = $measured->avg(fn (Appointment $a) => self::seconds($a->starts_at, $a->ends_at));
        $waits = $appointments
            ->map(fn (Appointment $a) => self::seconds($a->checked_in_at, $a->started_at))
            ->filter(fn ($seconds) => $seconds !== null);

        return [
            'completed'       => $appointments->count(),
            'measured'        => $measured->count(),
            'avg_minutes'     => $minutes($actual),
            'planned_minutes' => $minutes($planned),
            // Positivo: tardan más de lo asignado. Negativo: terminan antes.
            'diff_minutes'    => $actual === null ? null : $minutes($actual - $planned),
            'total_minutes'   => $minutes($measured->sum(fn (Appointment $a) => self::seconds($a->started_at, $a->completed_at))),
            'avg_wait_minutes' => $waits->isEmpty() ? null : $minutes($waits->avg()),
        ];
    }
}
