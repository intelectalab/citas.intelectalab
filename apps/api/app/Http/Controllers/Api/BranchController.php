<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Professional;
use App\Models\ProfessionalSchedule;
use App\Models\Tenant;
use App\Support\AppointmentFlow;
use App\Support\BranchHours;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sucursales de la empresa: sus datos, su horario de atención, quiénes
 * atienden en cada una y sus salas y equipos. Todo el personal las ve; las
 * cambia el administrador de la empresa.
 */
class BranchController extends Controller
{
    // Citas que siguen en pie: una sede con ellas no se desactiva.
    private const ACTIVE_STATUSES = [
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

    private function assertCanManage(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->allows('branches.edit') || $user->allows('branches.create'), 403, 'Tu cuenta no tiene permiso para cambiar las sucursales.');
    }

    /**
     * Cuántas sedes activas permite el plan. Sin el módulo Multisede, una sola.
     */
    private function limit(Tenant $tenant): ?int
    {
        if (!$tenant->hasModule('multi_branch')) {
            return 1;
        }

        $limit = $tenant->activeSubscription?->plan?->limits['branches'] ?? null;

        return is_numeric($limit) ? (int) $limit : null;
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);

        $branches = Branch::where('tenant_id', $tenant->id)->orderByRaw("status = 'active' desc")->orderBy('created_at')->get();
        $active = $branches->where('status', 'active')->count();
        $limit = $this->limit($tenant);

        return response()->json([
            'branches'     => $branches->map(fn (Branch $branch) => $this->present($branch, $tenant)),
            'active'       => $active,
            // null = sin tope.
            'limit'        => $limit,
            'multi_branch' => $tenant->hasModule('multi_branch'),
            'can_add'      => $limit === null || $active < $limit,
            'can_manage'   => $request->user()->allows('branches.edit'),
            'uses_rooms'   => $tenant->hasModule('resources'),
        ]);
    }

    public function show(Request $request, string $branch)
    {
        $tenant = $this->tenant($request);
        $branch = Branch::where('tenant_id', $tenant->id)->findOrFail($branch);

        $schedules = ProfessionalSchedule::where('tenant_id', $tenant->id)->where('branch_id', $branch->id)->orderBy('weekday')->orderBy('starts_local')->get()->groupBy('professional_id');
        $hours = BranchHours::of($branch->id);

        $professionals = Professional::where('tenant_id', $tenant->id)
            ->whereIn('id', $schedules->keys())
            ->with('status')
            ->orderBy('display_name')
            ->get()
            ->map(fn (Professional $p) => [
                'id'           => $p->id,
                'display_name' => $p->display_name,
                'inactive'     => $p->status?->slug === 'inactive',
                'ranges'       => $schedules[$p->id]->map(fn (ProfessionalSchedule $s) => [
                    'weekday' => (int) $s->weekday,
                    'starts'  => substr($s->starts_local, 0, 5),
                    'ends'    => substr($s->ends_local, 0, 5),
                    // Fuera del horario de la sede: ahí no se puede agendar sin forzar.
                    'outside' => !BranchHours::covers($hours, (int) $s->weekday, substr($s->starts_local, 0, 5), substr($s->ends_local, 0, 5)),
                ])->values(),
            ])->values();

        return response()->json($this->present($branch, $tenant) + [
            'hours'         => $hours,
            'professionals' => $professionals,
            // Con la regla de la empresa aplicada: una sala puede verse fuera de uso por su equipo.
            'rooms'         => $tenant->hasModule('resources') ? \App\Support\Rooms::listing($tenant, $branch->id) : [],
            'links_equipment' => \App\Support\Rooms::hasParent(),
            'uses_rooms'    => $tenant->hasModule('resources'),
            'can_manage'    => $request->user()->allows('branches.edit'),
        ]);
    }

    public function store(Request $request)
    {
        $tenant = $this->tenant($request);
        $this->assertCanManage($request);

        $limit = $this->limit($tenant);
        $active = Branch::where('tenant_id', $tenant->id)->where('status', 'active')->count();

        if ($limit !== null && $active >= $limit) {
            return response()->json([
                'message' => $tenant->hasModule('multi_branch')
                    ? "Tu plan permite hasta {$limit} " . ($limit === 1 ? 'sucursal activa' : 'sucursales activas') . '. Para abrir otra, cambia de plan o desactiva una.'
                    : 'Tu plan incluye una sola sucursal. Para abrir otra necesitas un plan con Multisede.',
                'code'    => 'PLAN_LIMIT',
            ], 422);
        }

        $validated = $this->validated($request, $tenant);

        // Toda sede cuelga de una razón social; se usa la de la empresa.
        $company = Company::where('tenant_id', $tenant->id)->orderBy('created_at')->first()
            ?? Company::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'trade_name' => $tenant->name, 'legal_name' => $tenant->name]);

        $branch = Branch::create($validated + [
            'id'         => (string) Str::uuid(),
            'tenant_id'  => $tenant->id,
            'company_id' => $company->id,
            'status'     => 'active',
        ]);

        // Abre con el mismo horario que la primera sede, para no empezar en blanco.
        $first = Branch::where('tenant_id', $tenant->id)->where('id', '!=', $branch->id)->orderBy('created_at')->value('id');
        if ($first) {
            BranchHours::replace($tenant->id, $branch->id, collect(BranchHours::of($first))->flatMap(
                fn (array $ranges, int $weekday) => array_map(fn (array $r) => ['weekday' => $weekday, 'opens' => $r['opens'], 'closes' => $r['closes']], $ranges),
            )->all());
        }

        // Con punto de venta, la sede nace con su caja (si la empresa no eligió crearlas a mano).
        if ($tenant->hasModule('pos') && \App\Support\Sales::ready()) {
            \App\Support\Cash::ensureRegisters($tenant);
        }

        return response()->json($this->present($branch->fresh(), $tenant), 201);
    }

    public function update(Request $request, string $branch)
    {
        $tenant = $this->tenant($request);
        $this->assertCanManage($request);
        $branch = Branch::where('tenant_id', $tenant->id)->findOrFail($branch);

        $branch->update($this->validated($request, $tenant, $branch->id));

        return response()->json($this->present($branch->fresh(), $tenant));
    }

    /**
     * Activar o desactivar. Una sede desactivada no se ofrece para agendar ni
     * para definir horarios, pero conserva su historia.
     */
    public function changeStatus(Request $request, string $branch)
    {
        $tenant = $this->tenant($request);
        $this->assertCanManage($request);
        $branch = Branch::where('tenant_id', $tenant->id)->findOrFail($branch);

        $validated = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);
        $to = $validated['status'];

        if ($to === $branch->status) {
            return response()->json($this->present($branch, $tenant));
        }

        if ($to === 'inactive') {
            if (Branch::where('tenant_id', $tenant->id)->where('status', 'active')->where('id', '!=', $branch->id)->doesntExist()) {
                return response()->json(['message' => 'Es tu única sucursal activa: no se puede desactivar.', 'code' => 'LAST_BRANCH'], 422);
            }

            $pending = $this->upcomingQuery($tenant, $branch)->count();

            if ($pending > 0) {
                return response()->json([
                    'message' => "Esta sucursal tiene {$pending} " . ($pending === 1 ? 'cita próxima' : 'citas próximas') . '. Reprográmalas o cancélalas antes de desactivarla.',
                    'code'    => 'HAS_APPOINTMENTS',
                    'pending' => $pending,
                ], 422);
            }
        } else {
            $limit = $this->limit($tenant);
            $active = Branch::where('tenant_id', $tenant->id)->where('status', 'active')->count();

            if ($limit !== null && $active >= $limit) {
                return response()->json([
                    'message' => "Tu plan permite hasta {$limit} " . ($limit === 1 ? 'sucursal activa' : 'sucursales activas') . '. Desactiva otra o cambia de plan.',
                    'code'    => 'PLAN_LIMIT',
                ], 422);
            }
        }

        $branch->update(['status' => $to]);

        return response()->json($this->present($branch->fresh(), $tenant));
    }

    /**
     * Horario de atención de la sede, completo. Sin ningún tramo, la sede no
     * limita la agenda (vale el horario de cada profesional).
     */
    public function updateHours(Request $request, string $branch)
    {
        $tenant = $this->tenant($request);
        $this->assertCanManage($request);
        $branch = Branch::where('tenant_id', $tenant->id)->findOrFail($branch);

        $validated = $request->validate([
            'ranges'           => 'present|array|max:42',
            'ranges.*.weekday' => 'required|integer|between:0,6',
            'ranges.*.opens'   => 'required|date_format:H:i',
            'ranges.*.closes'  => 'required|date_format:H:i',
        ]);

        foreach (collect($validated['ranges'])->groupBy('weekday') as $ranges) {
            $sorted = $ranges->sortBy('opens')->values();

            foreach ($sorted as $index => $range) {
                if ($range['closes'] <= $range['opens']) {
                    throw ValidationException::withMessages(['ranges' => 'La hora de cierre debe ser posterior a la de apertura.']);
                }
                if ($index > 0 && $range['opens'] < $sorted[$index - 1]['closes']) {
                    throw ValidationException::withMessages(['ranges' => 'Dos tramos del mismo día se cruzan.']);
                }
            }
        }

        BranchHours::replace($tenant->id, $branch->id, $validated['ranges']);
        $hours = BranchHours::of($branch->id);

        // Horarios de profesionales que quedaron fuera: ahí ya no se agenda sin forzar.
        $outside = ProfessionalSchedule::where('tenant_id', $tenant->id)->where('branch_id', $branch->id)
            ->with('professional:id,display_name')
            ->get()
            ->filter(fn (ProfessionalSchedule $s) => !BranchHours::covers($hours, (int) $s->weekday, substr($s->starts_local, 0, 5), substr($s->ends_local, 0, 5)))
            ->map(fn (ProfessionalSchedule $s) => [
                'professional' => $s->professional?->display_name,
                'weekday'      => (int) $s->weekday,
                'starts'       => substr($s->starts_local, 0, 5),
                'ends'         => substr($s->ends_local, 0, 5),
            ])->values();

        return response()->json(['hours' => $hours, 'outside' => $outside]);
    }

    private function validated(Request $request, Tenant $tenant, ?string $ignoreId = null): array
    {
        $validated = $request->validate([
            'name'       => ['required', 'string', 'max:150', Rule::unique('branches', 'name')->where('tenant_id', $tenant->id)->ignore($ignoreId)],
            'address'    => 'nullable|string|max:255',
            'district'   => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'phone'      => 'nullable|string|max:30',
            'timezone'   => 'nullable|timezone',
        ], [
            'name.required' => 'Escribe el nombre de la sucursal.',
            'name.unique'   => 'Ya tienes una sucursal con ese nombre.',
            'timezone.timezone' => 'La zona horaria no es válida.',
        ]);

        return [
            'name'       => trim($validated['name']),
            'address'    => $validated['address'] ?? null,
            'district'   => $validated['district'] ?? null,
            'department' => $validated['department'] ?? null,
            'phone'      => $validated['phone'] ?? null,
            'timezone'   => $validated['timezone'] ?? ($tenant->timezone ?: 'America/Lima'),
        ];
    }

    private function upcomingQuery(Tenant $tenant, Branch $branch)
    {
        return Appointment::where('tenant_id', $tenant->id)
            ->where('branch_id', $branch->id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->where('ends_at', '>', now()->toIso8601String());
    }

    private function present(Branch $branch, Tenant $tenant): array
    {
        $timezone = $branch->timezone ?: ($tenant->timezone ?: 'America/Lima');
        $dayStart = now($timezone)->startOfDay();
        $hours = BranchHours::of($branch->id);

        return [
            'id'                 => $branch->id,
            'name'               => $branch->name,
            'address'            => $branch->address,
            'district'           => $branch->district,
            'department'         => $branch->department,
            'phone'              => $branch->phone,
            'timezone'           => $timezone,
            'status'             => $branch->status,
            // Hoy: tramos en que abre; vacío = cerrada; null = no tiene horario definido.
            'has_hours'          => $hours !== [],
            'today'              => $hours === [] ? null : ($hours[$dayStart->dayOfWeek] ?? []),
            'open_days'          => count($hours),
            'professionals_count' => ProfessionalSchedule::where('branch_id', $branch->id)->distinct()->count('professional_id'),
            'rooms_count'        => DB::table('rooms')->where('branch_id', $branch->id)->where('status', 'active')->count(),
            'appointments_today' => Appointment::where('branch_id', $branch->id)
                ->whereNotIn('status', AppointmentFlow::RELEASED)
                ->where('starts_at', '>=', $dayStart->toIso8601String())
                ->where('starts_at', '<', $dayStart->copy()->addDay()->toIso8601String())
                ->count(),
            'upcoming'           => $this->upcomingQuery($tenant, $branch)->count(),
        ];
    }
}
