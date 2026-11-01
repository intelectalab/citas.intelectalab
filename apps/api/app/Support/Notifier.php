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

class Notifier
{
    public const ABSENCE_DAYS_AHEAD = 3;

    public static function available(): bool
    {
        static $available = null;

        return $available ??= Schema::hasTable('user_notifications');
    }

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
            $who = $appointment->customer ? \App\Support\Person::name($appointment->customer) : null;
            $with = $appointment->professional?->display_name;

            foreach (array_unique($recipients) as $userId) {
                $parts = [$who, $appointment->service?->name, $when, $userId === $professionalUser ? null : $with];

                self::send($appointment->tenant_id, $userId, $type, $title, implode(' · ', array_filter($parts)), '/agenda', 'appointment', $appointment->id);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

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
            $timezone = $tenant->zone();
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

    public static function unconfirmed(Tenant $tenant, $appointments, ?int $releaseHours = null): void
    {
        try {
            if (!self::available()) {
                return;
            }

            $timezone = $tenant->zone();
            $local = fn (Appointment $a) => Carbon::parse($a->starts_at)->setTimezone($a->branch?->timezone ?: $timezone)->locale('es');
            $warning = $releaseHours !== null ? " · Se liberará {$releaseHours} h antes si sigue sin confirmar" : '';

            foreach ($appointments as $appointment) {
                $userId = $appointment->professional?->user_id;

                if (!$userId) {
                    continue;
                }

                $who = $appointment->customer ? \App\Support\Person::name($appointment->customer) : null;

                self::send(
                    $tenant->id,
                    $userId,
                    'appointment.unconfirmed',
                    'Cita sin confirmar',
                    implode(' · ', array_filter([$who, $appointment->service?->name, $local($appointment)->isoFormat('ddd D [de] MMM, HH:mm')])) . $warning,
                    '/agenda',
                    'appointment',
                    $appointment->id,
                    "confirm-due:{$appointment->id}",
                );
            }

            $admins = self::adminIds($tenant->id);

            foreach ($appointments->groupBy(fn (Appointment $a) => $local($a)->format('Y-m-d')) as $date => $group) {
                $count = $group->count();
                $day = Carbon::parse($date)->locale('es')->isoFormat('dddd D [de] MMMM');

                foreach ($admins as $userId) {
                    self::send(
                        $tenant->id,
                        $userId,
                        'appointment.unconfirmed',
                        $count === 1 ? 'Una cita sigue sin confirmar' : "{$count} citas siguen sin confirmar",
                        ($count === 1 ? 'Es del ' : 'Son del ') . $day . '. Conviene llamar para confirmar.' . $warning,
                        '/appointments',
                        null,
                        null,
                        "confirm-due-day:{$date}",
                    );
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function released(Tenant $tenant, Appointment $appointment): void
    {
        try {
            if (!self::available()) {
                return;
            }

            $appointment->loadMissing(['customer', 'service', 'professional', 'branch']);
            $professionalUser = $appointment->professional?->user_id;
            $timezone = $appointment->branch?->timezone ?: ($tenant->zone());
            $when = Carbon::parse($appointment->starts_at)->setTimezone($timezone)->locale('es')->isoFormat('ddd D [de] MMM, HH:mm');
            $who = $appointment->customer ? \App\Support\Person::name($appointment->customer) : null;

            foreach (array_unique(array_filter([$professionalUser, ...self::adminIds($tenant->id)])) as $userId) {
                $parts = [$who, $appointment->service?->name, $when, $userId === $professionalUser ? null : $appointment->professional?->display_name];

                self::send(
                    $tenant->id,
                    $userId,
                    'appointment.released',
                    'Cita liberada por falta de confirmación',
                    implode(' · ', array_filter($parts)) . ' · El horario quedó libre',
                    '/agenda',
                    'appointment',
                    $appointment->id,
                    "confirm-released:{$appointment->id}",
                );
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function waitlistSlotFreed(Tenant $tenant, Appointment $appointment, int $count): void
    {
        try {
            if (!self::available()) {
                return;
            }

            $appointment->loadMissing(['service', 'professional', 'branch']);
            $timezone = $appointment->branch?->timezone ?: ($tenant->zone());
            $when = Carbon::parse($appointment->starts_at)->setTimezone($timezone)->locale('es')->isoFormat('ddd D [de] MMM, HH:mm');
            $people = $count === 1 ? 'Una persona de la lista de espera puede tomarlo' : "{$count} personas de la lista de espera pueden tomarlo";

            foreach (self::adminIds($tenant->id) as $userId) {
                self::send(
                    $tenant->id,
                    $userId,
                    'waitlist.slot_freed',
                    'Se liberó un horario',
                    implode(' · ', array_filter([$appointment->service?->name, $when, $appointment->professional?->display_name])) . '. ' . $people . '.',
                    '/waitlist',
                    null,
                    null,
                    "waitlist-freed:{$appointment->id}",
                );
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function walkinAdded(Tenant $tenant, \App\Models\WalkinEntry $entry, ?int $waitMinutes = null): void
    {
        try {
            if (!self::available()) {
                return;
            }
            $entry->loadMissing(['customer', 'service', 'professional']);
            $customerName = trim(($entry->customer?->first_name ?? '') . ' ' . ($entry->customer?->last_name ?? ''));
            $waitLabel = $waitMinutes === null
                ? 'Sin hueco estimado hoy'
                : ($waitMinutes === 0 ? 'Para atender ahora' : "Espera estimada ~{$waitMinutes} min");
            $origin = $entry->source === 'kiosk' ? 'Se anotó en el kiosko' : 'En la cola sin cita';

            $body = implode(' · ', array_filter([
                "{$entry->ticket} {$customerName}",
                $entry->service?->name,
                $entry->professional ? "Prefiere a {$entry->professional->display_name}" : null,
                $waitLabel,
            ]));

            $recipientIds = User::where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->where(function ($q) use ($entry) {
                    $q->where('is_owner', true)
                        ->orWhereNull('branch_id')
                        ->orWhere('branch_id', $entry->branch_id);
                })
                ->pluck('id')
                ->all();

            foreach ($recipientIds as $userId) {
                self::send(
                    $tenant->id,
                    $userId,
                    'walkin.added',
                    "{$origin}: {$entry->ticket}",
                    $body,
                    '/walkin',
                    'walkin_entry',
                    $entry->id,
                    "walkin-added:{$entry->id}:{$userId}",
                );
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function adminIds(string $tenantId): array
    {
        return User::where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->where('is_owner', true)->orWhereHas('roles', fn ($r) => $r->whereIn('slug', ['owner', 'admin'])))
            ->pluck('id')
            ->all();
    }

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
