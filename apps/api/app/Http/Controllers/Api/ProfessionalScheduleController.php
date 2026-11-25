<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Professional;
use App\Models\ProfessionalSchedule;
use App\Support\BranchHours;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Support\Access;
use App\Support\Audit;

class ProfessionalScheduleController extends Controller
{
    private const WEEKDAYS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    private function professional(Request $request, string $id): Professional
    {
        $tenantId = $request->user()->tenant_id;
        abort_unless($tenantId, 403);

        return Professional::where('tenant_id', $tenantId)->findOrFail($id);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        abort_unless($tenantId, 403);

        $professionals = Professional::where('tenant_id', $tenantId)->orderBy('display_name')->get(['id', 'display_name']);
        $all = ProfessionalSchedule::where('tenant_id', $tenantId)->orderBy('weekday')->orderBy('starts_local')->get();
        $today = now($user->tenant instanceof \App\Models\Tenant ? $user->tenant->zone() : 'America/Lima')->format('Y-m-d');
        $schedules = ProfessionalSchedule::periodOn($all, $today);
        $minutes = fn (ProfessionalSchedule $s) => (strtotime($s->ends_local) - strtotime($s->starts_local)) / 60;

        $branches = Branch::where('tenant_id', $tenantId)->where('status', 'active')->when(Access::branchLimit($user), fn ($q, $id) => $q->where('id', $id))->orderBy('name')->get(['id', 'name'])
            ->map(function (Branch $branch) use ($professionals, $schedules, $minutes, $all, $today) {
                $here = $schedules->where('branch_id', $branch->id);
                $later = $all->where('branch_id', $branch->id)->filter(fn (ProfessionalSchedule $s) => (ProfessionalSchedule::dateOf($s->valid_from ?? null) ?? '') > $today);

                return [
                    'id'    => $branch->id,
                    'name'  => $branch->name,
                    'hours' => (object) BranchHours::of($branch->id),
                    'professionals' => $professionals
                        ->filter(fn (Professional $p) => $here->contains('professional_id', $p->id) || $later->contains('professional_id', $p->id))
                        ->map(function (Professional $p) use ($here, $minutes, $later) {
                            $own = $here->where('professional_id', $p->id);

                            return [
                                'id'           => $p->id,
                                'display_name' => $p->display_name,
                                'weekly_minutes' => (int) $own->sum($minutes),
                                'ranges'       => $own->map(fn (ProfessionalSchedule $s) => [
                                    'weekday' => $s->weekday,
                                    'starts'  => substr($s->starts_local, 0, 5),
                                    'ends'    => substr($s->ends_local, 0, 5),
                                ])->values()->all(),
                                'next_change'  => ProfessionalSchedule::dateOf($later->where('professional_id', $p->id)->min('valid_from')),
                            ];
                        })->values()->all(),
                ];
            });

        $activeBranchIds = $branches->pluck('id');

        return response()->json([
            'can_edit' => $user->allows('schedules.edit'),
            'branches' => $branches,
            'without_schedule' => $professionals
                ->reject(fn (Professional $p) => $all->whereIn('branch_id', $activeBranchIds)->contains('professional_id', $p->id))
                ->map(fn (Professional $p) => ['id' => $p->id, 'display_name' => $p->display_name])
                ->values(),
        ]);
    }

    public function show(Request $request, string $professional)
    {
        return response()->json($this->payload($this->professional($request, $professional)));
    }

    public function update(Request $request, string $professional)
    {
        $professional = $this->professional($request, $professional);

        $validated = $request->validate([
            'branch_id'                   => 'required|uuid',
            'ranges'                      => 'sometimes|array|max:42',
            'ranges.*.weekday'            => 'required|integer|between:0,6',
            'ranges.*.starts'             => 'required|date_format:H:i',
            'ranges.*.ends'               => 'required|date_format:H:i',
            'periods'                     => 'sometimes|array|max:12',
            'periods.*.valid_from'        => 'nullable|date_format:Y-m-d',
            'periods.*.valid_until'       => 'nullable|date_format:Y-m-d',
            'periods.*.ranges'            => 'present|array|max:42',
            'periods.*.ranges.*.weekday'  => 'required|integer|between:0,6',
            'periods.*.ranges.*.starts'   => 'required|date_format:H:i',
            'periods.*.ranges.*.ends'     => 'required|date_format:H:i',
        ]);

        if (!$request->has('ranges') && !$request->has('periods')) {
            throw ValidationException::withMessages(['ranges' => 'Indica los días y horas en que atiende.']);
        }

        $branch = Branch::where('tenant_id', $professional->tenant_id)->findOrFail($validated['branch_id']);
        Access::assertBranch($request->user(), $branch->id, true);

        $periods = collect($validated['periods'] ?? [['valid_from' => null, 'valid_until' => null, 'ranges' => $validated['ranges'] ?? []]])
            ->map(fn (array $period) => [
                'valid_from'  => ProfessionalSchedule::periodsReady() ? ($period['valid_from'] ?? null) : null,
                'valid_until' => ProfessionalSchedule::periodsReady() ? ($period['valid_until'] ?? null) : null,
                'ranges'      => $period['ranges'] ?? [],
            ])
            ->values();

        $this->assertPeriods($periods);

        $taken = ProfessionalSchedule::where('professional_id', $professional->id)
            ->where('branch_id', '!=', $branch->id)
            ->get()
            ->map(fn (ProfessionalSchedule $s) => [
                'weekday'     => $s->weekday,
                'starts'      => substr($s->starts_local, 0, 5),
                'ends'        => substr($s->ends_local, 0, 5),
                'valid_from'  => ProfessionalSchedule::dateOf($s->valid_from ?? null),
                'valid_until' => ProfessionalSchedule::dateOf($s->valid_until ?? null),
                'other'       => true,
            ]);

        foreach ($periods as $period) {
            $label = $periods->count() > 1 ? $this->periodLabel($period['valid_from'], $period['valid_until']) . ': ' : '';
            $byDay = collect($period['ranges'])
                ->map(fn (array $range) => $range + ['other' => false])
                ->concat($taken->filter(fn (array $other) => $this->periodsOverlap($period, $other)))
                ->groupBy('weekday');

            foreach ($byDay as $weekday => $ranges) {
                $day = self::WEEKDAYS[$weekday];
                $previous = null;

                foreach ($ranges->sortBy('starts')->values() as $range) {
                    if (!$range['other'] && $range['ends'] <= $range['starts']) {
                        throw ValidationException::withMessages([
                            'ranges' => "{$label}el {$day}, la hora de fin debe ser posterior a la de inicio.",
                        ]);
                    }

                    if ($previous && $range['starts'] < $previous['ends'] && !($range['other'] && $previous['other'])) {
                        throw ValidationException::withMessages([
                            'ranges' => ucfirst($label . (($range['other'] || $previous['other'])
                                ? "el {$day} se cruza con su horario en otra sede."
                                : "el {$day} tiene tramos que se cruzan.")),
                        ]);
                    }

                    if (!$previous || $range['ends'] > $previous['ends']) {
                        $previous = $range;
                    }
                }
            }
        }

        $previous = $this->summary($professional, $branch);

        DB::transaction(function () use ($professional, $branch, $periods) {
            ProfessionalSchedule::where('professional_id', $professional->id)
                ->where('branch_id', $branch->id)
                ->delete();

            foreach ($periods as $period) {
                foreach ($period['ranges'] as $range) {
                    ProfessionalSchedule::create([
                        'tenant_id'       => $professional->tenant_id,
                        'professional_id' => $professional->id,
                        'branch_id'       => $branch->id,
                        'weekday'         => $range['weekday'],
                        'starts_local'    => $range['starts'],
                        'ends_local'      => $range['ends'],
                    ] + (ProfessionalSchedule::periodsReady() ? ['valid_from' => $period['valid_from'], 'valid_until' => $period['valid_until']] : []));
                }
            }
        });

        [$before, $after] = Audit::changed(['schedule' => $previous], ['schedule' => $this->summary($professional, $branch)]);

        if ($after) {
            Audit::log($request, 'schedule.updated', [
                'tenant'       => $request->user()->tenant_id,
                'entity_type'  => 'professional',
                'entity_id'    => $professional->id,
                'entity_label' => $professional->display_name,
                'branch_id'    => $branch->id,
                'before'       => $before,
                'after'        => $after + ['branch' => $branch->name],
            ]);
        }

        return response()->json($this->payload($professional) + ['outside' => $this->outside($professional, $branch), 'branch_closed' => $this->beyondBranch($branch, $periods)]);
    }

    private function beyondBranch(Branch $branch, \Illuminate\Support\Collection $periods): array
    {
        $hours = \App\Support\BranchHours::of($branch->id);

        if ($hours === []) {
            return [];
        }

        return $periods
            ->flatMap(fn (array $period) => collect($period['ranges'])
                ->reject(fn (array $range) => \App\Support\BranchHours::covers($hours, (int) $range['weekday'], $range['starts'], $range['ends']))
                ->map(fn (array $range) => ($periods->count() > 1 ? ucfirst($this->periodLabel($period['valid_from'], $period['valid_until'])) . ', ' : '')
                    . self::WEEKDAYS[(int) $range['weekday']] . " {$range['starts']}–{$range['ends']}: la sede " . (($hours[(int) $range['weekday']] ?? []) ? 'abre ' . \App\Support\BranchHours::describe($hours, (int) $range['weekday']) : 'está cerrada ese día')))
            ->unique()
            ->values()
            ->all();
    }

    private function assertPeriods(\Illuminate\Support\Collection $periods): void
    {
        $seen = [];

        foreach ($periods as $index => $period) {
            if ($period['valid_from'] && $period['valid_until'] && $period['valid_until'] < $period['valid_from']) {
                throw ValidationException::withMessages(['periods' => 'En un horario con fechas, «hasta» debe ser igual o posterior a «desde».']);
            }

            $key = ($period['valid_from'] ?? '') . '|' . ($period['valid_until'] ?? '');

            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['periods' => $key === '|'
                    ? 'Solo puede haber un horario que vale siempre en cada sede.'
                    : 'Hay dos horarios con las mismas fechas: júntalos en uno.']);
            }

            $seen[$key] = true;

            if ($key === '|') {
                continue;
            }

            foreach ($periods->slice($index + 1) as $other) {
                if (($other['valid_from'] || $other['valid_until']) && $this->periodsOverlap($period, $other)) {
                    throw ValidationException::withMessages(['periods' => 'Los horarios «' . $this->periodLabel($period['valid_from'], $period['valid_until']) . '» y «' . $this->periodLabel($other['valid_from'], $other['valid_until']) . '» se cruzan en fechas: elige fechas que no se crucen.']);
                }
            }
        }
    }

    private function periodsOverlap(array $a, array $b): bool
    {
        return (!$a['valid_from'] || !$b['valid_until'] || $a['valid_from'] <= $b['valid_until'])
            && (!$b['valid_from'] || !$a['valid_until'] || $b['valid_from'] <= $a['valid_until']);
    }

    private function periodLabel(?string $from, ?string $until): string
    {
        $format = fn (string $date) => \Illuminate\Support\Carbon::parse($date)->format('d/m/Y');

        return match (true) {
            $from && $until => 'del ' . $format($from) . ' al ' . $format($until),
            (bool) $from    => 'desde el ' . $format($from),
            (bool) $until   => 'hasta el ' . $format($until),
            default         => 'siempre',
        };
    }

    private function outside(Professional $professional, Branch $branch): array
    {
        $tenant = $professional->tenant ?? \App\Models\Tenant::find($professional->tenant_id);
        $timezone = \App\Support\AppointmentScheduler::timezone($branch, $tenant);
        $rows = ProfessionalSchedule::where('professional_id', $professional->id)->where('branch_id', $branch->id)->get();

        return \App\Models\Appointment::where('tenant_id', $professional->tenant_id)
            ->where('professional_id', $professional->id)
            ->where('branch_id', $branch->id)
            ->where('starts_at', '>', now())
            ->whereIn('status', [\App\Support\AppointmentFlow::PENDING, \App\Support\AppointmentFlow::CONFIRMED])
            ->with('customer:id,first_name,last_name')
            ->orderBy('starts_at')
            ->limit(300)
            ->get()
            ->filter(function (\App\Models\Appointment $appointment) use ($timezone, $rows) {
                $start = $appointment->starts_at->copy()->setTimezone($timezone);
                $end = $appointment->ends_at->copy()->setTimezone($timezone);
                $from = $start->format('H:i');
                $to = $end->isSameDay($start) ? $end->format('H:i') : '24:00';

                return !ProfessionalSchedule::effective($rows, $start->format('Y-m-d'))
                    ->contains(fn (ProfessionalSchedule $s) => substr($s->starts_local, 0, 5) <= $from && $to <= substr($s->ends_local, 0, 5));
            })
            ->take(50)
            ->map(fn (\App\Models\Appointment $appointment) => [
                'id'        => $appointment->id,
                'code'      => $appointment->code,
                'starts_at' => $appointment->starts_at->copy()->setTimezone($timezone)->format('Y-m-d\TH:i'),
                'customer'  => $appointment->customer ? \App\Support\Person::name($appointment->customer) : null,
            ])
            ->values()
            ->all();
    }

    private function summary(Professional $professional, Branch $branch): array
    {
        $rows = ProfessionalSchedule::where('professional_id', $professional->id)
            ->where('branch_id', $branch->id)
            ->orderBy('weekday')
            ->orderBy('starts_local')
            ->get();
        $dated = $rows->contains(fn (ProfessionalSchedule $s) => ProfessionalSchedule::periodKey($s) !== '|');

        return $rows
            ->groupBy(fn (ProfessionalSchedule $s) => ProfessionalSchedule::periodKey($s))
            ->sortKeys()
            ->flatMap(fn ($period) => $period->groupBy('weekday')
                ->sortKeys()
                ->map(fn ($ranges, $weekday) => ($dated ? ucfirst($this->periodLabel(ProfessionalSchedule::dateOf($ranges->first()->valid_from ?? null), ProfessionalSchedule::dateOf($ranges->first()->valid_until ?? null))) . ', ' : '')
                    . self::WEEKDAYS[$weekday] . ' ' . $ranges->map(fn (ProfessionalSchedule $s) => substr($s->starts_local, 0, 5) . '–' . substr($s->ends_local, 0, 5))->implode(' y '))
                ->values())
            ->values()
            ->all();
    }

    private function payload(Professional $professional): array
    {
        return [
            'professional' => ['id' => $professional->id, 'display_name' => $professional->display_name],
            'branches'     => Branch::where('tenant_id', $professional->tenant_id)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name']),
            'periods_ready' => ProfessionalSchedule::periodsReady(),
            'branch_hours' => \Illuminate\Support\Facades\DB::table('branch_hours')
                ->where('tenant_id', $professional->tenant_id)
                ->orderBy('weekday')
                ->orderBy('opens_local')
                ->get(['branch_id', 'weekday', 'opens_local', 'closes_local'])
                ->map(fn ($h) => ['branch_id' => $h->branch_id, 'weekday' => (int) $h->weekday, 'starts' => substr($h->opens_local, 0, 5), 'ends' => substr($h->closes_local, 0, 5)]),
            'schedules'    => ProfessionalSchedule::where('professional_id', $professional->id)
                ->orderBy('weekday')
                ->orderBy('starts_local')
                ->get()
                ->map(fn (ProfessionalSchedule $s) => [
                    'branch_id'   => $s->branch_id,
                    'weekday'     => $s->weekday,
                    'starts'      => substr($s->starts_local, 0, 5),
                    'ends'        => substr($s->ends_local, 0, 5),
                    'valid_from'  => ProfessionalSchedule::dateOf($s->valid_from ?? null),
                    'valid_until' => ProfessionalSchedule::dateOf($s->valid_until ?? null),
                ]),
        ];
    }
}
