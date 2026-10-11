<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Tenant;
use App\Models\WaitlistEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Cómo se mueve la lista de espera con las citas: una cita agendada desde la
 * lista deja a la persona "con cita"; si esa cita se cancela o no asiste,
 * vuelve a esperar. Y al liberarse un horario se sabe a quiénes les sirve.
 */
class Waitlist
{
    /**
     * La cita se agendó para esta persona de la lista.
     */
    public static function book(WaitlistEntry $entry, Appointment $appointment): void
    {
        $entry->update(['status' => WaitlistEntry::BOOKED, 'appointment_id' => $appointment->id]);
    }

    /**
     * Llamar después de cambiar la fase de una cita.
     */
    public static function appointmentChanged(Tenant $tenant, Appointment $appointment): void
    {
        if (!in_array($appointment->status, AppointmentFlow::RELEASED, true)) {
            return;
        }

        // La cita se cayó: la persona sigue necesitando un horario.
        WaitlistEntry::where('tenant_id', $tenant->id)
            ->where('appointment_id', $appointment->id)
            ->where('status', WaitlistEntry::BOOKED)
            ->update(['status' => WaitlistEntry::WAITING, 'appointment_id' => null, 'notified_at' => null, 'updated_at' => now()]);

        self::slotFreed($tenant, $appointment);
    }

    /**
     * Una cita futura se cayó y dejó libre su horario: si a alguien de la lista
     * le sirve, se avisa a la administración. La persona lo ve en su portal, que
     * le muestra en vivo los horarios libres que calzan con lo que pidió.
     */
    public static function slotFreed(Tenant $tenant, Appointment $appointment): void
    {
        try {
            if (!$appointment->starts_at->isFuture()) {
                return;
            }

            $count = self::matching($tenant, $appointment)->count();

            if ($count > 0) {
                Notifier::waitlistSlotFreed($tenant, $appointment, $count);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Quienes ya no pueden recibir un horario porque su última fecha pasó.
     */
    public static function expire(Tenant $tenant): void
    {
        WaitlistEntry::where('tenant_id', $tenant->id)
            ->whereIn('status', WaitlistEntry::OPEN)
            ->whereNotNull('date_to')
            ->where('date_to', '<', now($tenant->timezone ?: 'America/Lima')->format('Y-m-d'))
            ->update(['status' => WaitlistEntry::EXPIRED, 'updated_at' => now()]);
    }

    /**
     * Personas en espera a las que les sirve el horario que deja libre una
     * cita: misma sede y servicio, ese profesional o cualquiera, y el día y la
     * hora dentro de lo que pidieron.
     */
    public static function matching(Tenant $tenant, Appointment $appointment): Builder
    {
        $timezone = AppointmentScheduler::timezone($appointment->branch ?? new \App\Models\Branch(), $tenant);
        $start = $appointment->starts_at->copy()->setTimezone($timezone);
        $end = $appointment->ends_at->copy()->setTimezone($timezone);
        $date = $start->format('Y-m-d');

        return WaitlistEntry::where('tenant_id', $tenant->id)
            ->whereIn('status', WaitlistEntry::OPEN)
            ->where('branch_id', $appointment->branch_id)
            ->where('service_id', $appointment->service_id)
            ->where('customer_id', '!=', $appointment->customer_id)
            ->where(fn ($q) => $q->whereNull('professional_id')->orWhere('professional_id', $appointment->professional_id))
            ->where('date_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('date_to')->orWhere('date_to', '>=', $date))
            ->where(fn ($q) => $q->whereNull('time_from')->orWhere('time_from', '<=', $start->format('H:i:s')))
            ->where(fn ($q) => $q->whereNull('time_to')->orWhere('time_to', '>=', $end->format('H:i:s')));
    }

    /**
     * Horarios libres que le sirven a alguien de la lista, día por día, desde
     * hoy (o desde su primera fecha). Se detiene al juntar unos cuantos días
     * con horarios, o al revisar tres semanas.
     *
     * @return array{days: array<int, array{date: string, groups: array}>, searched_until: string}
     */
    public static function slots(Tenant $tenant, WaitlistEntry $entry, int $maxDays = 5, int $scan = 21): array
    {
        $branch = $entry->branch;
        $service = $entry->service?->loadMissing('status');
        $professional = $entry->professional?->loadMissing('status');
        $timezone = AppointmentScheduler::timezone($branch, $tenant);

        $today = now($timezone)->startOfDay();
        $from = Carbon::parse($entry->date_from->format('Y-m-d'), $timezone)->startOfDay();
        $cursor = $from->gt($today) ? $from : $today->copy();
        $limit = $cursor->copy()->addDays($scan - 1);

        if ($entry->date_to) {
            $to = Carbon::parse($entry->date_to->format('Y-m-d'), $timezone)->startOfDay();
            $limit = $to->lt($limit) ? $to : $limit;
        }

        $timeFrom = $entry->time_from ? substr($entry->time_from, 0, 5) : null;
        $timeTo = $entry->time_to ? substr($entry->time_to, 0, 5) : null;
        $duration = (int) $service->duration_minutes;
        $scheduler = new AppointmentScheduler($tenant);
        $days = [];

        while ($cursor->lte($limit) && count($days) < $maxDays) {
            $groups = collect($scheduler->slots($service, $branch, $cursor, $professional))
                ->map(function (array $group) use ($timeFrom, $timeTo, $duration) {
                    $group['slots'] = array_values(array_filter($group['slots'], function (string $slot) use ($timeFrom, $timeTo, $duration) {
                        [$h, $m] = array_map('intval', explode(':', $slot));
                        $endMinutes = $h * 60 + $m + $duration;
                        $end = sprintf('%02d:%02d', intdiv($endMinutes, 60), $endMinutes % 60);

                        return (!$timeFrom || $slot >= $timeFrom) && (!$timeTo || $end <= $timeTo);
                    }));

                    return $group;
                })
                ->filter(fn (array $group) => $group['slots'] !== [])
                ->values()
                ->all();

            if ($groups) {
                $days[] = ['date' => $cursor->format('Y-m-d'), 'groups' => $groups];
            }

            $cursor->addDay();
        }

        return ['days' => $days, 'searched_until' => $cursor->copy()->subDay()->format('Y-m-d')];
    }
}
