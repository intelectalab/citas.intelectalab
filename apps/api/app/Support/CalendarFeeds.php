<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CalendarFeeds
{
    public const DETAILS = [
        'service'    => 'Solo el servicio (sin datos del cliente)',
        'first_name' => 'Servicio y nombre del cliente',
        'full'       => 'Servicio, nombre completo y teléfono del cliente',
    ];

    public const DAYS_BACK = 60;

    public const DAYS_AHEAD = 365;

    public static function newToken(): string
    {
        return Str::random(48);
    }

    public static function url(object $feed): string
    {
        return rtrim((string) config('app.url'), '/') . '/api/calendar/' . $feed->token . '.ics';
    }

    public static function present(object $feed): array
    {
        return [
            'id'               => $feed->id,
            'professional_id'  => $feed->professional_id,
            'branch_id'        => $feed->branch_id,
            'detail'           => $feed->detail,
            'url'              => self::url($feed),
            'webcal_url'       => preg_replace('#^https?://#', 'webcal://', self::url($feed)),
            'last_accessed_at' => $feed->last_accessed_at ? Carbon::parse($feed->last_accessed_at)->toIso8601String() : null,
            'created_at'       => Carbon::parse($feed->created_at)->toIso8601String(),
        ];
    }

    public static function render(object $feed, Tenant $tenant): string
    {
        $appointments = Appointment::where('tenant_id', $tenant->id)
            ->when($feed->professional_id, fn ($q, $id) => $q->where('professional_id', $id))
            ->when($feed->branch_id, fn ($q, $id) => $q->where('branch_id', $id))
            ->where('status', '!=', AppointmentFlow::CANCELLED)
            ->where('starts_at', '>=', now()->subDays(self::DAYS_BACK)->toIso8601String())
            ->where('starts_at', '<=', now()->addDays(self::DAYS_AHEAD)->toIso8601String())
            ->with(['customer', 'service', 'professional', 'branch'])
            ->orderBy('starts_at')
            ->limit(3000)
            ->get();

        $owner = $feed->professional_id
            ? DB::table('professionals')->where('id', $feed->professional_id)->value('display_name')
            : DB::table('branches')->where('id', $feed->branch_id)->value('name');
        $company = Messaging::companyName($tenant);
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'agenda.local';

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Agenda//Citas//ES',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::text("Citas de {$owner} · {$company}"),
            'X-WR-TIMEZONE:' . $tenant->zone(),
            'REFRESH-INTERVAL;VALUE=DURATION:PT30M',
            'X-PUBLISHED-TTL:PT30M',
        ];

        foreach ($appointments as $appointment) {
            $lines = array_merge($lines, self::event($appointment, $feed, $tenant, $host));
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    private static function event(Appointment $appointment, object $feed, Tenant $tenant, string $host): array
    {
        $service = $appointment->service?->name ?? 'Cita';
        $customer = $appointment->customer;
        $who = match ($feed->detail) {
            'first_name' => $customer?->first_name,
            'full'       => trim(($customer?->first_name ?? '') . ' ' . ($customer?->last_name ?? '')),
            default      => null,
        };
        $summary = $who ? "{$service} · {$who}" : $service;

        if ($feed->branch_id && $appointment->professional) {
            $summary .= ' (' . $appointment->professional->display_name . ')';
        }

        $status = AppointmentFlow::name($tenant, $appointment->status);
        $details = array_filter([
            'Código: ' . $appointment->code,
            'Estado: ' . $status,
            $feed->branch_id && $appointment->professional ? 'Profesional: ' . $appointment->professional->display_name : null,
            $feed->detail === 'full' && $customer?->phone ? 'Teléfono: ' . $customer->phone : null,
            ($video = VideoLinks::for($appointment)) ? 'Videollamada: ' . $video : null,
        ]);
        $location = $video ?: trim(($appointment->branch?->name ?? '') . ($appointment->branch?->address ? ', ' . $appointment->branch->address : ''));

        return array_values(array_filter([
            'BEGIN:VEVENT',
            'UID:' . $appointment->id . '@' . $host,
            'DTSTAMP:' . self::stamp($appointment->updated_at ?? now()),
            'DTSTART:' . self::stamp($appointment->starts_at),
            'DTEND:' . self::stamp($appointment->ends_at),
            'SUMMARY:' . self::text($summary),
            'DESCRIPTION:' . self::text(implode("\n", $details)),
            $location !== '' ? 'LOCATION:' . self::text($location) : null,
            $video ? 'URL:' . $video : null,
            'STATUS:' . ($appointment->status === AppointmentFlow::PENDING ? 'TENTATIVE' : 'CONFIRMED'),
            'TRANSP:OPAQUE',
            'END:VEVENT',
        ]));
    }

    private static function stamp($date): string
    {
        return Carbon::parse($date)->utc()->format('Ymd\THis\Z');
    }

    private static function text(string $value): string
    {
        return str_replace(["\\", "\r\n", "\n", ',', ';'], ["\\\\", '\\n', '\\n', '\\,', '\\;'], $value);
    }

    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = [];
        $current = '';

        foreach (mb_str_split($line) as $char) {
            if (strlen($current . $char) > ($out ? 74 : 75)) {
                $out[] = $current;
                $current = '';
            }

            $current .= $char;
        }

        $out[] = $current;

        return implode("\r\n ", $out);
    }
}
