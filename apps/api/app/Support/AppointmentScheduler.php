<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\AvailabilityBlock;
use App\Models\Branch;
use App\Models\Professional;
use App\Models\ProfessionalSchedule;
use App\Models\Service;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AppointmentScheduler
{
    public const MESSAGES = [
        'SERVICE_INACTIVE' => 'El servicio no está activo',
        'PRO_INACTIVE'     => ':name no está disponible para agendar',
        'PRO_NO_SERVICE'   => ':name no realiza este servicio',
        'PAST'             => 'El horario ya pasó',
        'NOT_ONLINE'       => 'Este servicio no se reserva en línea',
        'LEAD_TIME'        => 'Ese horario está demasiado cerca para reservarlo en línea',
        'TOO_FAR'          => 'Aún no se puede reservar con tanta anticipación',
        'BRANCH_CLOSED'    => 'La sede está cerrada a esa hora',
        'OUT_OF_SCHEDULE'  => ':name no atiende a esa hora',
        'HOLIDAY'          => 'Es feriado',
        'BLOCKED'          => 'Horario bloqueado',
        'BLOCKED_SOFT'     => 'Horario bloqueado',
        'DOUBLE_BOOKING'   => ':name ya tiene una cita en ese intervalo',
        'BUFFER'           => 'Choca con el tiempo de preparación o limpieza de otra cita',
    ];

    public const OVERRIDABLE = ['BRANCH_CLOSED', 'OUT_OF_SCHEDULE', 'BUFFER', 'BLOCKED_SOFT'];

    public function __construct(private Tenant $tenant, private bool $online = false)
    {
    }

    public static function timezone(Branch $branch, Tenant $tenant): string
    {
        return $branch->timezone ?: ($tenant->zone());
    }

    public function check(Service $service, Professional $professional, Branch $branch, Carbon $start, int $duration, ?string $ignoreId = null): array
    {
        $day = $this->dayContext($professional, $branch, $start->copy()->startOfDay(), $ignoreId);

        return $this->evaluate($day, $service, $professional, $start, $duration);
    }

    public function slots(Service $service, Branch $branch, Carbon $date, ?Professional $professional = null, ?string $ignoreId = null): array
    {
        $interval = max(5, (int) AppointmentFlow::rule($this->tenant, 'agenda.slot_interval'));
        $duration = $service->duration_minutes;
        $date = $date->copy()->startOfDay();

        $professionals = $professional
            ? collect([$professional])
            : Professional::where('tenant_id', $this->tenant->id)
                ->whereIn('id', ProfessionalSchedule::where('branch_id', $branch->id)->where('weekday', $date->dayOfWeek)->select('professional_id'))
                ->with('status')
                ->orderBy('display_name')
                ->get();

        $result = [];

        foreach ($professionals as $candidate) {
            $day = $this->dayContext($candidate, $branch, $date, $ignoreId);
            $slots = [];

            foreach ($day['schedules'] as $range) {
                $cursor = $date->copy()->setTimeFromTimeString($range['starts']);
                $limit = $date->copy()->setTimeFromTimeString($range['ends']);

                while ($cursor->copy()->addMinutes($duration)->lte($limit)) {
                    if ($this->evaluate($day, $service, $candidate, $cursor, $duration)['ok']) {
                        $slots[] = $cursor->format('H:i');
                    }
                    $cursor->addMinutes($interval);
                }
            }

            if ($slots || $professional) {
                $result[] = [
                    'professional' => ['id' => $candidate->id, 'display_name' => $candidate->display_name],
                    'slots'        => $slots,
                ];
            }
        }

        return $result;
    }

    private function dayContext(Professional $professional, Branch $branch, Carbon $dayStart, ?string $ignoreId): array
    {
        $timezone = $dayStart->getTimezone();
        $dayEnd = $dayStart->copy()->addDay();

        $schedules = ProfessionalSchedule::where('professional_id', $professional->id)
            ->where('branch_id', $branch->id)
            ->where('weekday', $dayStart->dayOfWeek)
            ->orderBy('starts_local')
            ->get()
            ->map(fn (ProfessionalSchedule $s) => ['starts' => substr($s->starts_local, 0, 5), 'ends' => substr($s->ends_local, 0, 5)])
            ->all();

        $blocks = AvailabilityBlock::where('tenant_id', $this->tenant->id)
            ->where(fn ($q) => $q->where('professional_id', $professional->id)
                ->orWhere(fn ($q) => $q->whereNull('professional_id')->where('branch_id', $branch->id)))
            ->where('starts_at', '<', $dayEnd->toIso8601String())
            ->where('ends_at', '>', $dayStart->toIso8601String())
            ->get()
            ->map(fn (AvailabilityBlock $b) => [
                'starts' => Carbon::parse($b->starts_at)->setTimezone($timezone),
                'ends'   => Carbon::parse($b->ends_at)->setTimezone($timezone),
                'reason' => (string) $b->reason,
                'soft'   => $b->isOverridable(),
            ])
            ->all();

        $appointments = Appointment::where('tenant_id', $this->tenant->id)
            ->where('professional_id', $professional->id)
            ->whereNotIn('status', AppointmentFlow::RELEASED)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('starts_at', '<', $dayEnd->toIso8601String())
            ->where('ends_at', '>', $dayStart->toIso8601String())
            ->with('service:id,buffer_before_minutes,buffer_after_minutes')
            ->get()
            ->map(fn (Appointment $a) => [
                'code'   => $a->code,
                'starts' => $a->starts_at->copy()->setTimezone($timezone),
                'ends'   => AppointmentTiming::occupiedUntil($a)->copy()->setTimezone($timezone),
                'before' => (int) ($a->service?->buffer_before_minutes ?? 0),
                'after'  => (int) ($a->service?->buffer_after_minutes ?? 0),
            ])
            ->all();

        $holiday = Holidays::on($this->tenant, $branch->id, $dayStart->format('Y-m-d'), true)->first()?->name;

        $branchHours = BranchHours::of($branch->id);

        return compact('schedules', 'blocks', 'appointments', 'holiday', 'branchHours');
    }

    private function evaluate(array $day, Service $service, Professional $professional, Carbon $start, int $duration): array
    {
        $reasons = [];
        $add = function (string $code, string $detail = '') use (&$reasons, $professional) {
            $reasons[] = [
                'code'    => $code,
                'message' => str_replace(':name', $professional->display_name, self::MESSAGES[$code]),
                'detail'  => $detail,
            ];
        };

        $end = $start->copy()->addMinutes($duration);

        if ($service->status?->slug === 'inactive') {
            $add('SERVICE_INACTIVE');
        }

        if ($professional->status?->slug === 'inactive') {
            $add('PRO_INACTIVE');
        }

        $assigned = $this->assignedProfessionals($service);
        if ($assigned && !in_array($professional->id, $assigned, true)) {
            $add('PRO_NO_SERVICE');
        }

        if ($start->lt(now())) {
            $add('PAST');
        } elseif ($this->online) {
            $lead = (int) AppointmentFlow::rule($this->tenant, 'booking.min_lead_minutes');
            $maxDays = (int) AppointmentFlow::rule($this->tenant, 'booking.max_days_ahead');

            if (!$service->online_booking) {
                $add('NOT_ONLINE');
            }
            if ($start->lt(now()->addMinutes($lead))) {
                $add('LEAD_TIME', "mínimo {$lead} min");
            }
            if ($maxDays > 0 && $start->gt(now()->addDays($maxDays)->endOfDay())) {
                $add('TOO_FAR', "máximo {$maxDays} días");
            }
        }

        $startTime = $start->format('H:i');
        $endTime = $end->format('H:i');
        $sameDay = $end->isSameDay($start) || $endTime === '00:00';
        $inSchedule = $sameDay && collect($day['schedules'])->contains(
            fn (array $range) => $range['starts'] <= $startTime && ($endTime === '00:00' ? '24:00' : $endTime) <= $range['ends'],
        );

        if (!$inSchedule) {
            $add('OUT_OF_SCHEDULE', $day['schedules']
                ? 'su horario es ' . collect($day['schedules'])->map(fn (array $range) => "{$range['starts']}–{$range['ends']}")->join(' y ')
                : 'ese día no atiende en esta sede');
        }

        if (!empty($day['holiday'])) {
            $add('HOLIDAY', $day['holiday']);
        }

        $branchHours = $day['branchHours'] ?? [];
        if ($branchHours !== [] && !($sameDay && BranchHours::covers($branchHours, $start->dayOfWeek, $startTime, $endTime === '00:00' ? '24:00' : $endTime))) {
            $add('BRANCH_CLOSED', 'abre ' . BranchHours::describe($branchHours, $start->dayOfWeek));
        }

        foreach ($day['blocks'] as $block) {
            if ($block['starts']->lt($end) && $start->lt($block['ends'])) {
                $add($block['soft'] ? 'BLOCKED_SOFT' : 'BLOCKED', $block['reason']);
            }
        }

        $buffers = (bool) AppointmentFlow::rule($this->tenant, 'agenda.respect_buffers');
        $from = $start->copy()->subMinutes($buffers ? (int) $service->buffer_before_minutes : 0);
        $to = $end->copy()->addMinutes($buffers ? (int) $service->buffer_after_minutes : 0);

        foreach ($day['appointments'] as $other) {
            if ($other['starts']->lt($end) && $start->lt($other['ends'])) {
                $add('DOUBLE_BOOKING', "{$other['code']} {$other['starts']->format('H:i')}–{$other['ends']->format('H:i')}");
                continue;
            }

            if ($buffers
                && $other['starts']->copy()->subMinutes($other['before'])->lt($to)
                && $from->lt($other['ends']->copy()->addMinutes($other['after']))) {
                $add('BUFFER', $other['code']);
            }
        }

        return [
            'ok'          => $reasons === [],
            'reasons'     => $reasons,
            'overridable' => $reasons !== [] && collect($reasons)->every(fn (array $r) => in_array($r['code'], self::OVERRIDABLE, true)),
        ];
    }

    private array $assigned = [];

    private function assignedProfessionals(Service $service): array
    {
        return $this->assigned[$service->id] ??= DB::table('service_professionals')
            ->where('service_id', $service->id)
            ->pluck('professional_id')
            ->all();
    }
}
