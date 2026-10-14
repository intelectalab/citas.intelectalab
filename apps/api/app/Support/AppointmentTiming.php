<?php

namespace App\Support;

use App\Models\Appointment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AppointmentTiming
{
    private const MIN_SECONDS = 60;

    private const MAX_TIMES_PLANNED = 4;
    private const MAX_FLOOR_SECONDS = 4 * 3600;

    private static function seconds(?Carbon $from, ?Carbon $to): ?int
    {
        return $from && $to ? max(0, (int) round($from->diffInSeconds($to))) : null;
    }

    public static function of(Appointment $appointment): array
    {
        $now = now();
        $waiting = $appointment->status === AppointmentFlow::CHECKED_IN;
        $attending = $appointment->status === AppointmentFlow::IN_PROGRESS;

        return [
            'planned_seconds' => self::seconds($appointment->starts_at, $appointment->ends_at),
            'wait_seconds'    => self::seconds($appointment->checked_in_at, $appointment->started_at ?? ($waiting ? $now : null)),
            'service_seconds' => self::seconds($appointment->started_at, $appointment->completed_at ?? ($attending ? $now : null)),
            'running'         => $attending ? 'service' : ($waiting ? 'wait' : null),
            'measured'        => self::isMeasured($appointment),
        ];
    }

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

    public static function occupiedUntil(Appointment $appointment): Carbon
    {
        $finished = $appointment->completed_at;

        if ($appointment->status === AppointmentFlow::COMPLETED
            && $finished
            && $finished->lt($appointment->ends_at)
            && $finished->gt($appointment->starts_at)) {
            return $finished->copy()->startOfMinute()->addMinute()->min($appointment->ends_at);
        }

        return $appointment->ends_at;
    }

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
            'diff_minutes'    => $actual === null ? null : $minutes($actual - $planned),
            'total_minutes'   => $minutes($measured->sum(fn (Appointment $a) => self::seconds($a->started_at, $a->completed_at))),
            'avg_wait_minutes' => $waits->isEmpty() ? null : $minutes($waits->avg()),
        ];
    }
}
