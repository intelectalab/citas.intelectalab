<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Tenant;
use App\Models\WaitlistEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Waitlist
{
    public const ALL = 'all';
    public const FIRST = 'first';
    public const MODES = [self::ALL, self::FIRST];

    public static function enabled(Tenant $tenant): bool
    {
        return (bool) AppointmentFlow::rule($tenant, 'waitlist.enabled');
    }

    public static function mode(Tenant $tenant): string
    {
        return AppointmentFlow::rule($tenant, 'waitlist.notify_mode') === self::FIRST ? self::FIRST : self::ALL;
    }

    public static function replyHours(Tenant $tenant): int
    {
        return max(1, min(72, (int) AppointmentFlow::rule($tenant, 'waitlist.reply_hours')));
    }

    public static function rules(Tenant $tenant): array
    {
        return [
            'enabled'      => self::enabled($tenant),
            'notify_mode'  => self::mode($tenant),
            'reply_hours'  => self::replyHours($tenant),
            'offers_ready' => self::offersReady(),
        ];
    }

    public static function offersReady(): bool
    {
        static $ready = null;

        return $ready ??= \Illuminate\Support\Facades\Schema::hasTable('waitlist_offers');
    }

    public static function book(WaitlistEntry $entry, Appointment $appointment): void
    {
        $entry->update(['status' => WaitlistEntry::BOOKED, 'appointment_id' => $appointment->id]);

        if (self::offersReady()) {
            DB::table('waitlist_offers')->where('waitlist_entry_id', $entry->id)->where('status', 'open')
                ->update(['status' => 'booked', 'closed_at' => now()]);
        }
    }

    public static function openOffer(string $entryId): ?object
    {
        if (!self::offersReady()) {
            return null;
        }

        return DB::table('waitlist_offers')->where('waitlist_entry_id', $entryId)->where('status', 'open')
            ->where('expires_at', '>', now()->toIso8601String())->orderByDesc('expires_at')->first();
    }

    public static function offerNext(Tenant $tenant, Appointment $appointment): ?WaitlistEntry
    {
        if (DB::table('waitlist_offers')->where('appointment_id', $appointment->id)->where('status', 'open')->exists()) {
            return null;
        }

        $offered = DB::table('waitlist_offers')->where('appointment_id', $appointment->id)->pluck('waitlist_entry_id')->all();

        $entry = self::matching($tenant, $appointment)
            ->when($offered, fn ($q) => $q->whereNotIn('id', $offered))
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->first();

        if (!$entry) {
            return null;
        }

        $expires = now()->addHours(self::replyHours($tenant));

        if ($expires->gt($appointment->starts_at)) {
            $expires = $appointment->starts_at->copy();
        }

        DB::table('waitlist_offers')->insertOrIgnore([
            'id'                => (string) Str::uuid(),
            'tenant_id'         => $tenant->id,
            'waitlist_entry_id' => $entry->id,
            'appointment_id'    => $appointment->id,
            'status'            => 'open',
            'notified_at'       => now(),
            'expires_at'        => $expires->copy()->utc(),
            'created_at'        => now(),
        ]);

        $entry->update(['status' => WaitlistEntry::NOTIFIED, 'notified_at' => now()]);
        Messaging::waitlistSlot($tenant, $appointment, $entry);

        return $entry;
    }

    public static function advance(Tenant $tenant): void
    {
        try {
            if (!self::offersReady()) {
                return;
            }

            $due = DB::table('waitlist_offers')->where('tenant_id', $tenant->id)->where('status', 'open')
                ->where('expires_at', '<=', now()->toIso8601String())->orderBy('expires_at')->limit(50)->get();

            foreach ($due as $offer) {
                $closed = DB::table('waitlist_offers')->where('id', $offer->id)->where('status', 'open')
                    ->update(['status' => 'expired', 'closed_at' => now()]);

                if (!$closed) {
                    continue;
                }

                WaitlistEntry::where('id', $offer->waitlist_entry_id)->where('status', WaitlistEntry::NOTIFIED)->whereNull('appointment_id')
                    ->update(['status' => WaitlistEntry::WAITING, 'updated_at' => now()]);

                $appointment = Appointment::where('tenant_id', $tenant->id)->with('branch')->find($offer->appointment_id);

                if ($appointment && self::enabled($tenant) && self::mode($tenant) === self::FIRST && self::stillFree($appointment)) {
                    self::offerNext($tenant, $appointment);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function stillFree(Appointment $appointment): bool
    {
        if (!in_array($appointment->status, AppointmentFlow::RELEASED, true) || !$appointment->starts_at->isFuture()) {
            return false;
        }

        return !Appointment::where('tenant_id', $appointment->tenant_id)
            ->where('professional_id', $appointment->professional_id)
            ->whereNotIn('status', AppointmentFlow::RELEASED)
            ->where('starts_at', '<', $appointment->ends_at->toIso8601String())
            ->where('ends_at', '>', $appointment->starts_at->toIso8601String())
            ->exists();
    }

    public static function appointmentChanged(Tenant $tenant, Appointment $appointment): void
    {
        if (!in_array($appointment->status, AppointmentFlow::RELEASED, true)) {
            return;
        }

        WaitlistEntry::where('tenant_id', $tenant->id)
            ->where('appointment_id', $appointment->id)
            ->where('status', WaitlistEntry::BOOKED)
            ->update(['status' => WaitlistEntry::WAITING, 'appointment_id' => null, 'notified_at' => null, 'updated_at' => now()]);

        self::slotFreed($tenant, $appointment);
    }

    public static function slotFreed(Tenant $tenant, Appointment $appointment): void
    {
        try {
            if (!self::enabled($tenant) || !$appointment->starts_at->isFuture()) {
                return;
            }

            $count = self::matching($tenant, $appointment)->count();

            if ($count > 0) {
                Notifier::waitlistSlotFreed($tenant, $appointment, $count);

                if (self::mode($tenant) === self::FIRST && self::offersReady()) {
                    self::offerNext($tenant, $appointment);
                } else {
                    Messaging::waitlistSlot($tenant, $appointment);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function expire(Tenant $tenant): void
    {
        WaitlistEntry::where('tenant_id', $tenant->id)
            ->whereIn('status', WaitlistEntry::OPEN)
            ->whereNotNull('date_to')
            ->where('date_to', '<', now($tenant->zone())->format('Y-m-d'))
            ->update(['status' => WaitlistEntry::EXPIRED, 'updated_at' => now()]);
    }

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
