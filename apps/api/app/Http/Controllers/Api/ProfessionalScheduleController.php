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
        $schedules = ProfessionalSchedule::where('tenant_id', $tenantId)->orderBy('weekday')->orderBy('starts_local')->get();
        $minutes = fn (ProfessionalSchedule $s) => (strtotime($s->ends_local) - strtotime($s->starts_local)) / 60;

        $branches = Branch::where('tenant_id', $tenantId)->where('status', 'active')->when(Access::branchLimit($user), fn ($q, $id) => $q->where('id', $id))->orderBy('name')->get(['id', 'name'])
            ->map(function (Branch $branch) use ($professionals, $schedules, $minutes) {
                $here = $schedules->where('branch_id', $branch->id);

                return [
                    'id'    => $branch->id,
                    'name'  => $branch->name,
                    'hours' => (object) BranchHours::of($branch->id),
                    'professionals' => $professionals
                        ->filter(fn (Professional $p) => $here->contains('professional_id', $p->id))
                        ->map(function (Professional $p) use ($here, $minutes) {
                            $own = $here->where('professional_id', $p->id);

                            return [
                                'id'           => $p->id,
                                'display_name' => $p->display_name,
                                'weekly_minutes' => (int) $own->sum($minutes),
                                'ranges'       => $own->map(fn (ProfessionalSchedule $s) => [
                                    'weekday' => $s->weekday,
                                    'starts'  => substr($s->starts_local, 0, 5),
                                    'ends'    => substr($s->ends_local, 0, 5),
                                ])->values(),
                            ];
                        })->values(),
                ];
            });

        $activeBranchIds = $branches->pluck('id');

        return response()->json([
            'can_edit' => $user->allows('schedules.edit'),
            'branches' => $branches,
            'without_schedule' => $professionals
                ->reject(fn (Professional $p) => $schedules->whereIn('branch_id', $activeBranchIds)->contains('professional_id', $p->id))
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
            'branch_id'        => 'required|uuid',
            'ranges'           => 'present|array|max:42',
            'ranges.*.weekday' => 'required|integer|between:0,6',
            'ranges.*.starts'  => 'required|date_format:H:i',
            'ranges.*.ends'    => 'required|date_format:H:i',
        ]);

        $branch = Branch::where('tenant_id', $professional->tenant_id)->findOrFail($validated['branch_id']);
        Access::assertBranch($request->user(), $branch->id, true);

        $taken = ProfessionalSchedule::where('professional_id', $professional->id)
            ->where('branch_id', '!=', $branch->id)
            ->get()
            ->map(fn (ProfessionalSchedule $s) => [
                'weekday' => $s->weekday,
                'starts'  => substr($s->starts_local, 0, 5),
                'ends'    => substr($s->ends_local, 0, 5),
                'other'   => true,
            ]);

        $byDay = collect($validated['ranges'])
            ->map(fn (array $range) => $range + ['other' => false])
            ->concat($taken)
            ->groupBy('weekday');

        foreach ($byDay as $weekday => $ranges) {
            $day = self::WEEKDAYS[$weekday];
            $previous = null;

            foreach ($ranges->sortBy('starts')->values() as $range) {
                if (!$range['other'] && $range['ends'] <= $range['starts']) {
                    throw ValidationException::withMessages([
                        'ranges' => "El {$day}, la hora de fin debe ser posterior a la de inicio.",
                    ]);
                }

                if ($previous && $range['starts'] < $previous['ends'] && !($range['other'] && $previous['other'])) {
                    throw ValidationException::withMessages([
                        'ranges' => ($range['other'] || $previous['other'])
                            ? "El {$day} se cruza con su horario en otra sede."
                            : "El {$day} tiene tramos que se cruzan.",
                    ]);
                }

                if (!$previous || $range['ends'] > $previous['ends']) {
                    $previous = $range;
                }
            }
        }

        DB::transaction(function () use ($professional, $branch, $validated) {
            ProfessionalSchedule::where('professional_id', $professional->id)
                ->where('branch_id', $branch->id)
                ->delete();

            foreach ($validated['ranges'] as $range) {
                ProfessionalSchedule::create([
                    'tenant_id'       => $professional->tenant_id,
                    'professional_id' => $professional->id,
                    'branch_id'       => $branch->id,
                    'weekday'         => $range['weekday'],
                    'starts_local'    => $range['starts'],
                    'ends_local'      => $range['ends'],
                ]);
            }
        });

        return response()->json($this->payload($professional));
    }

    private function payload(Professional $professional): array
    {
        return [
            'professional' => ['id' => $professional->id, 'display_name' => $professional->display_name],
            'branches'     => Branch::where('tenant_id', $professional->tenant_id)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name']),
            'schedules'    => ProfessionalSchedule::where('professional_id', $professional->id)
                ->orderBy('weekday')
                ->orderBy('starts_local')
                ->get()
                ->map(fn (ProfessionalSchedule $s) => [
                    'branch_id' => $s->branch_id,
                    'weekday'   => $s->weekday,
                    'starts'    => substr($s->starts_local, 0, 5),
                    'ends'      => substr($s->ends_local, 0, 5),
                ]),
        ];
    }
}
