<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\AppointmentStatusHistory;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;

class AppointmentEvents
{
    public const QUIET = ['notify', 'payment_links', 'packages', 'messaging'];

    public static function record(Appointment $appointment, ?string $from, string $to, User|Customer|null $actor, ?string $reason, array $skip = []): void
    {
        AppointmentStatusHistory::create([
            'tenant_id'         => $appointment->tenant_id,
            'appointment_id'    => $appointment->id,
            'from_status'       => $from,
            'to_status'         => $to,
            'reason'            => $reason ? mb_substr($reason, 0, 255) : null,
            'actor_user_id'     => $actor instanceof User ? $actor->id : null,
            'actor_customer_id' => $actor instanceof Customer ? $actor->id : null,
        ]);

        if ($actor && !in_array('notify', $skip, true)) {
            Notifier::appointmentStep($appointment, $from, $to, $actor);
        }

        if (array_diff(['payment_links', 'packages', 'messaging'], $skip) === [] || !($tenant = Tenant::find($appointment->tenant_id))) {
            return;
        }

        if (!in_array('payment_links', $skip, true)) {
            PaymentLinks::appointmentChanged($tenant->loadMissing('settings'), $appointment, $from, $to);
        }

        if (!in_array('packages', $skip, true)) {
            Packages::appointmentChanged($tenant, $appointment, $from, $to, $actor);
        }

        if (!in_array('messaging', $skip, true)) {
            Messaging::appointmentChanged($tenant, $appointment, $from, $to);
        }
    }

    public static function monthLimit(Tenant $tenant, Carbon $start): ?array
    {
        $limit = $tenant->activeSubscription?->plan?->limits['appointments_month'] ?? null;

        if (!is_numeric($limit)) {
            return null;
        }

        $used = Appointment::where('tenant_id', $tenant->id)
            ->where('status', '!=', AppointmentFlow::CANCELLED)
            ->where('starts_at', '>=', $start->copy()->startOfMonth()->toIso8601String())
            ->where('starts_at', '<', $start->copy()->startOfMonth()->addMonth()->toIso8601String())
            ->count();

        return $used >= (int) $limit ? ['limit' => (int) $limit, 'used' => $used] : null;
    }
}
