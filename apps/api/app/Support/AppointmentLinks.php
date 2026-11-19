<?php

namespace App\Support;

use App\Models\Appointment;

class AppointmentLinks
{
    public const DAYS_AFTER = 3;

    public static function expiresAt(Appointment $appointment): ?\Carbon\CarbonInterface
    {
        $end = $appointment->ends_at ?? $appointment->starts_at;

        return $end?->copy()->addDays(self::DAYS_AFTER);
    }

    public static function expiredReason(Appointment $appointment): ?string
    {
        if ($appointment->status === 'cancelled') {
            return 'cancelled';
        }

        $expires = self::expiresAt($appointment);

        return $expires && $expires->isPast() ? 'past' : null;
    }

    public static function signature(string $appointmentId): string
    {
        return substr(hash_hmac('sha256', 'appointment-link|' . $appointmentId, (string) config('app.key')), 0, 32);
    }

    public static function token(Appointment $appointment): string
    {
        return $appointment->id . '.' . self::signature($appointment->id);
    }

    public static function url(Appointment $appointment): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . '/cita/' . self::token($appointment);
    }

    public static function idFrom(string $token): ?string
    {
        if (!preg_match('/^([0-9a-f-]{36})\.([0-9a-f]{32})$/', $token, $match)) {
            return null;
        }

        return hash_equals(self::signature($match[1]), $match[2]) ? $match[1] : null;
    }
}
