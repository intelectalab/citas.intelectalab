<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\AppointmentStatusHistory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class Confirmations
{
    public const NOTIFY = 'notify';
    public const NOTIFY_RELEASE = 'notify_release';
    public const RELEASE = 'release';

    public const MODES = [self::NOTIFY, self::NOTIFY_RELEASE, self::RELEASE];

    public const RELEASE_REASON = 'No se confirmó a tiempo';

    public static function deadlineHours(Tenant $tenant): int
    {
        if (!AppointmentFlow::rule($tenant, 'agenda.require_confirmation')) {
            return 0;
        }

        return max(0, (int) AppointmentFlow::rule($tenant, 'confirm.deadline_hours'));
    }

    public static function mode(Tenant $tenant): string
    {
        $mode = (string) AppointmentFlow::rule($tenant, 'confirm.on_miss');

        return in_array($mode, self::MODES, true) ? $mode : self::NOTIFY;
    }

    public static function releaseHours(Tenant $tenant): int
    {
        return max(0, min((int) AppointmentFlow::rule($tenant, 'confirm.release_hours'), self::deadlineHours($tenant)));
    }

    public static function deadline(Tenant $tenant, Appointment $appointment): ?Carbon
    {
        $hours = self::deadlineHours($tenant);

        if ($hours <= 0) {
            return null;
        }

        $deadline = $appointment->starts_at->copy()->subHours($hours);

        return $appointment->created_at && $appointment->created_at->gt($deadline) ? null : $deadline;
    }

    public static function overdue(Tenant $tenant, Appointment $appointment): bool
    {
        if ($appointment->status !== AppointmentFlow::PENDING || !$appointment->starts_at->isFuture()) {
            return false;
        }

        $deadline = self::deadline($tenant, $appointment);

        return $deadline !== null && now()->gte($deadline);
    }

    public static function process(Tenant $tenant): void
    {
        try {
            $hours = self::deadlineHours($tenant);

            if ($hours <= 0) {
                return;
            }

            $mode = self::mode($tenant);
            $releaseHours = self::releaseHours($tenant);
            $now = now();

            $overdue = Appointment::where('tenant_id', $tenant->id)
                ->where('status', AppointmentFlow::PENDING)
                ->where('starts_at', '>', $now->toIso8601String())
                ->where('starts_at', '<=', $now->copy()->addHours($hours)->toIso8601String())
                ->with(['customer', 'service', 'professional', 'branch'])
                ->orderBy('starts_at')
                ->get()
                ->filter(fn (Appointment $a) => self::overdue($tenant, $a));

            if ($overdue->isEmpty()) {
                return;
            }

            $toRelease = match ($mode) {
                self::RELEASE        => $overdue,
                self::NOTIFY_RELEASE => $overdue->filter(fn (Appointment $a) => $now->gte($a->starts_at->copy()->subHours($releaseHours))),
                default              => collect(),
            };

            foreach ($toRelease as $appointment) {
                self::release($tenant, $appointment);
            }

            $toNotify = $overdue->reject(fn (Appointment $a) => $toRelease->contains('id', $a->id));

            if ($toNotify->isNotEmpty()) {
                Notifier::unconfirmed($tenant, $toNotify, $mode === self::NOTIFY_RELEASE ? $releaseHours : null);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private static function release(Tenant $tenant, Appointment $appointment): void
    {
        $released = DB::transaction(function () use ($tenant, $appointment) {
            $fresh = Appointment::whereKey($appointment->id)->lockForUpdate()->first();

            if (!$fresh || !self::overdue($tenant, $fresh)) {
                return false;
            }

            $fresh->update([
                'status'        => AppointmentFlow::CANCELLED,
                'cancelled_at'  => now(),
                'cancel_reason' => self::RELEASE_REASON,
                'cancelled_by'  => 'system',
            ]);

            AppointmentStatusHistory::create([
                'tenant_id'      => $fresh->tenant_id,
                'appointment_id' => $fresh->id,
                'from_status'    => AppointmentFlow::PENDING,
                'to_status'      => AppointmentFlow::CANCELLED,
                'reason'         => self::RELEASE_REASON . ' (liberada automáticamente)',
            ]);

            Recalls::appointmentChanged($tenant, $fresh);
            Waitlist::appointmentChanged($tenant, $fresh);

            if (Packages::ready() && $fresh->customer_package_id) {
                Packages::unlink($tenant, $fresh, null, "La cita {$fresh->code} se liberó porque no se confirmó a tiempo: la sesión vuelve a estar libre.");
            }

            return true;
        });

        if ($released) {
            Notifier::released($tenant, $appointment);
        }
    }
}
