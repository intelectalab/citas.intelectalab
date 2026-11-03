<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Holiday;
use App\Models\Tenant;
use App\Support\AppointmentFlow;
use App\Support\AppointmentScheduler;
use App\Support\Audit;
use App\Support\Holidays;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use App\Support\Access;

class HolidayController extends Controller
{
    private const AFFECTED_STATUSES = [
        AppointmentFlow::PENDING,
        AppointmentFlow::CONFIRMED,
        AppointmentFlow::CHECKED_IN,
        AppointmentFlow::IN_PROGRESS,
    ];

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 403);

        return $tenant;
    }

    private function timezone(Tenant $tenant): string
    {
        return $tenant->zone();
    }

    private function assertCanManage(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->allows('holidays.create') || $user->allows('holidays.edit') || $user->allows('holidays.delete'), 403, 'Tu cuenta no tiene permiso para cambiar los feriados.');
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);

        $validated = $request->validate([
            'year'      => 'nullable|integer|min:2000|max:2100',
            'branch_id' => 'nullable|uuid',
        ]);

        $today = now($this->timezone($tenant))->startOfDay();

        $holidays = Holiday::where('tenant_id', $tenant->id)
            ->with('branch:id,name')
            ->when($validated['year'] ?? null, fn ($q, $year) => $q->whereYear('date', $year), fn ($q) => $q->where('date', '>=', $today->format('Y-m-d')))
            ->when($validated['branch_id'] ?? null, fn ($q, $id) => $q->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $id)))
            ->when(Access::branchLimit($request->user()), fn ($q, $id) => $q->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $id)))
            ->orderBy('date')
            ->orderBy('name')
            ->get();

        $canManage = $request->user()->allows('holidays.edit');

        return response()->json([
            'holidays'   => $holidays->map(fn (Holiday $holiday) => $this->present($holiday, $tenant, $today, $canManage)),
            'years'      => Holiday::where('tenant_id', $tenant->id)->selectRaw('distinct extract(year from date)::int as year')->orderBy('year')->pluck('year'),
            'missing'    => collect([$today->year, $today->year + 1])
                ->filter(fn (int $year) => !Holiday::where('tenant_id', $tenant->id)->whereNull('branch_id')->whereYear('date', $year)->exists())
                ->values(),
            'can_manage' => $canManage,
        ]);
    }

    public function show(Request $request, string $holiday)
    {
        $tenant = $this->tenant($request);
        $holiday = Holiday::where('tenant_id', $tenant->id)->with('branch:id,name')->findOrFail($holiday);
        $today = now($this->timezone($tenant))->startOfDay();

        $affected = $this->affectedQuery($tenant, $holiday)
            ->with(['customer:id,first_name,last_name', 'service:id,name', 'professional:id,display_name', 'branch:id,timezone'])
            ->orderBy('starts_at')
            ->limit(100)
            ->get()
            ->map(function (Appointment $a) use ($tenant) {
                $timezone = AppointmentScheduler::timezone($a->branch ?? new Branch(), $tenant);

                return [
                    'id'           => $a->id,
                    'code'         => $a->code,
                    'status'       => $a->status,
                    'status_name'  => AppointmentFlow::name($tenant, $a->status),
                    'starts_at'    => $a->starts_at->copy()->setTimezone($timezone)->format('Y-m-d\TH:i'),
                    'ends_at'      => $a->ends_at->copy()->setTimezone($timezone)->format('Y-m-d\TH:i'),
                    'customer'     => $a->customer ? \App\Support\Person::name($a->customer) : null,
                    'service'      => $a->service?->name,
                    'professional' => $a->professional?->display_name,
                    'package'      => \App\Support\Packages::ready() && $a->customer_package_id ? \Illuminate\Support\Facades\DB::table('customer_packages')->where('id', $a->customer_package_id)->value('code') : null,
                ];
            });

        return response()->json($this->present($holiday, $tenant, $today, $request->user()->allows('holidays.edit')) + ['affected' => $affected]);
    }

    public function store(Request $request)
    {
        $tenant = $this->tenant($request);
        $this->assertCanManage($request);

        $holiday = Holiday::create($this->validated($request, $tenant) + [
            'tenant_id'  => $tenant->id,
            'created_by' => $request->user()->id,
        ]);
        $holiday = $holiday->fresh('branch:id,name');
        $this->audit($request, 'holiday.created', $holiday, [], array_filter($this->auditable($holiday), fn ($value) => $value !== null));
        $this->notifyAffected($tenant, $holiday, $request->user()->id);

        return response()->json($this->present($holiday, $tenant, now($this->timezone($tenant))->startOfDay(), true), 201);
    }

    public function update(Request $request, string $holiday)
    {
        $tenant = $this->tenant($request);
        $this->assertCanManage($request);
        $holiday = Holiday::where('tenant_id', $tenant->id)->findOrFail($holiday);
        Access::assertBranch($request->user(), $holiday->branch_id, true);

        $auditBefore = $this->auditable($holiday->load('branch:id,name'));
        $holiday->update($this->validated($request, $tenant, $holiday->id));
        $holiday = $holiday->fresh('branch:id,name');
        [$from, $to] = Audit::changed($auditBefore, $this->auditable($holiday));

        if ($from || $to) {
            $this->audit($request, 'holiday.updated', $holiday, $from, $to);
            $this->notifyAffected($tenant, $holiday, $request->user()->id);
        }

        return response()->json($this->present($holiday, $tenant, now($this->timezone($tenant))->startOfDay(), true));
    }

    public function destroy(Request $request, string $holiday)
    {
        $tenant = $this->tenant($request);
        $this->assertCanManage($request);
        $holiday = Holiday::where('tenant_id', $tenant->id)->findOrFail($holiday);
        Access::assertBranch($request->user(), $holiday->branch_id, true);
        $holiday->delete();
        Audit::log($request, 'holiday.deleted', [
            'tenant'       => $tenant->id,
            'entity_type'  => 'holiday',
            'entity_id'    => $holiday->id,
            'entity_label' => $holiday->name,
            'branch_id'    => $holiday->branch_id,
            'before'       => array_filter([
                'holiday_date' => $holiday->date?->format('Y-m-d'),
                'closed'       => (bool) $holiday->closed,
                'branch'       => $holiday->branch_id ? \App\Models\Branch::where('id', $holiday->branch_id)->value('name') : null,
            ], fn ($value) => $value !== null),
        ]);

        return response()->json(['message' => 'Feriado eliminado correctamente']);
    }

    public function loadNational(Request $request)
    {
        $tenant = $this->tenant($request);
        $this->assertCanManage($request);

        $validated = $request->validate(['year' => 'required|integer|min:2000|max:2100']);
        Access::assertBranch($request->user(), null, true);
        $added = Holidays::loadNational($tenant, (int) $validated['year'], $request->user()->id);

        if ($added > 0) {
            foreach (Holiday::where('tenant_id', $tenant->id)->where('closed', true)->whereYear('date', (int) $validated['year'])->where('date', '>=', now($this->timezone($tenant))->toDateString())->get() as $holiday) {
                $this->notifyAffected($tenant, $holiday, $request->user()->id);
            }
        }

        if ($added > 0) {
            Audit::log($request, 'holiday.imported', [
                'tenant'       => $tenant->id,
                'entity_type'  => 'holiday',
                'entity_label' => "Feriados nacionales {$validated['year']}",
                'after'        => ['holiday_year' => (int) $validated['year'], 'holidays_added' => $added],
            ]);
        }

        return response()->json([
            'added'   => $added,
            'message' => $added === 0
                ? "Los feriados nacionales de {$validated['year']} ya estaban cargados."
                : "Se agregaron {$added} feriados nacionales de {$validated['year']}.",
        ]);
    }

    private function auditable(Holiday $holiday): array
    {
        return [
            'name'         => $holiday->name,
            'holiday_date' => $holiday->date?->format('Y-m-d'),
            'closed'       => (bool) $holiday->closed,
            'branch'       => $holiday->branch_id ? $holiday->branch?->name : 'Todas las sedes',
        ];
    }

    private function audit(Request $request, string $action, Holiday $holiday, array $before = [], array $after = []): void
    {
        Audit::log($request, $action, array_filter([
            'tenant'       => $holiday->tenant_id,
            'entity_type'  => 'holiday',
            'entity_id'    => $holiday->id,
            'entity_label' => $holiday->name,
            'branch_id'    => $holiday->branch_id,
            'before'       => $before ?: null,
            'after'        => $after ?: null,
        ], fn ($value) => $value !== null));
    }

    private function validated(Request $request, Tenant $tenant, ?string $ignoreId = null): array
    {
        $validated = $request->validate([
            'name'      => 'required|string|max:150',
            'date'      => 'required|date_format:Y-m-d',
            'branch_id' => 'nullable|uuid',
            'closed'    => 'nullable|boolean',
        ], [
            'name.required'    => 'Escribe el nombre del feriado.',
            'date.required'    => 'Indica la fecha.',
            'date.date_format' => 'La fecha no es válida.',
        ]);

        $branchId = $validated['branch_id'] ?? null;

        if ($branchId) {
            Branch::where('tenant_id', $tenant->id)->findOrFail($branchId);
        }

        Access::assertBranch($request->user(), $branchId, true);

        $duplicate = Holiday::where('tenant_id', $tenant->id)
            ->where('date', $validated['date'])
            ->where(fn ($q) => $branchId ? $q->where('branch_id', $branchId) : $q->whereNull('branch_id'))
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->first();

        if ($duplicate) {
            throw ValidationException::withMessages(['date' => "Ese día ya tiene un feriado registrado: {$duplicate->name}."]);
        }

        return [
            'name'      => trim($validated['name']),
            'date'      => $validated['date'],
            'branch_id' => $branchId,
            'closed'    => array_key_exists('closed', $validated) && $validated['closed'] !== null ? (bool) $validated['closed'] : true,
        ];
    }

    private function notifyAffected(Tenant $tenant, Holiday $holiday, string $actorId): void
    {
        if (!$holiday->closed) {
            return;
        }

        $what = "El feriado {$holiday->name} (" . $holiday->date->locale('es')->isoFormat('D [de] MMMM') . ')';

        \App\Support\DueNotices::closure($tenant, $this->affectedQuery($tenant, $holiday)->get(['id', 'professional_id', 'branch_id']), $what, '/agenda', $actorId, "holiday:{$holiday->id}:" . $holiday->date->format('Ymd') . ':' . ($holiday->branch_id ?? 'all'));
    }

    private function affectedQuery(Tenant $tenant, Holiday $holiday): Builder
    {
        $timezone = $this->timezone($tenant);
        $start = Carbon::parse($holiday->date->format('Y-m-d'), $timezone)->startOfDay();

        return Appointment::where('tenant_id', $tenant->id)
            ->whereIn('status', self::AFFECTED_STATUSES)
            ->when($holiday->branch_id, fn ($q, $id) => $q->where('branch_id', $id))
            ->where('starts_at', '>=', $start->toIso8601String())
            ->where('starts_at', '<', $start->copy()->addDay()->toIso8601String())
            ->where('ends_at', '>', now()->toIso8601String());
    }

    private function present(Holiday $holiday, Tenant $tenant, Carbon $today, bool $canManage): array
    {
        $date = Carbon::parse($holiday->date->format('Y-m-d'), $today->getTimezone());

        return [
            'id'             => $holiday->id,
            'name'           => $holiday->name,
            'date'           => $holiday->date->format('Y-m-d'),
            'days_left'      => (int) round($today->diffInDays($date, false)),
            'branch_id'      => $holiday->branch_id,
            'branch'         => $holiday->branch ? ['id' => $holiday->branch->id, 'name' => $holiday->branch->name] : null,
            'closed'         => (bool) $holiday->closed,
            'affected_count' => $holiday->closed ? $this->affectedQuery($tenant, $holiday)->count() : 0,
            'can_manage'     => $canManage,
        ];
    }
}
