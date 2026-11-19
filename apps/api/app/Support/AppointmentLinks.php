<?php

namespace App\Support;

use App\Models\Appointment;

class AppointmentLinks
{
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
