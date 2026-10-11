<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AvailabilityBlock;
use App\Models\ProfessionalSchedule;
use App\Models\AppointmentStatusHistory;
use App\Models\Branch;
use App\Models\CareRecord;
use App\Models\Customer;
use App\Models\CustomerDependent;
use App\Models\Professional;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\Recall;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\AppointmentFlow;
use App\Support\AppointmentScheduler;
use App\Support\AppointmentTiming;
use App\Support\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Citas de la empresa: alta, consulta, cambio de fase y reprogramación.
 * Las horas se reciben y se entregan en la zona horaria de la sede.
 */
class AppointmentController extends Controller
{
    protected const LOCAL_FORMAT = 'Y-m-d\TH:i';
    protected const RELATIONS = ['customer', 'dependent', 'service', 'professional', 'branch', 'record'];
    protected const MAX_ROWS = 500;

    protected function tenant(Request $request): Tenant
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 403);

        return $tenant->loadMissing(['settings', 'activeSubscription.plan']);
    }

    /**
     * Si quien consulta es un profesional (y no administra la empresa), solo
     * ve y gestiona sus propias citas. Devuelve su id de profesional.
     */
    protected function ownProfessionalId(User $user): ?string
    {
        if ($user->isTenantAdmin() || !$user->roles()->where('slug', 'professional')->exists()) {
            return null;
        }

        return Professional::where('tenant_id', $user->tenant_id)->where('user_id', $user->id)->value('id') ?? 'none';
    }

    protected function find(Request $request, string $id): Appointment
    {
        $tenant = $this->tenant($request);
        $own = $this->ownProfessionalId($request->user());

        $appointment = Appointment::where('tenant_id', $tenant->id)->with(self::RELATIONS)->findOrFail($id);

        abort_if($own && $appointment->professional_id !== $own, 403, 'Solo puedes gestionar tus propias citas.');

        return $appointment;
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);
        $timezone = $tenant->timezone ?: 'America/Lima';

        $validated = $request->validate([
            'date'            => 'nullable|date_format:Y-m-d',
            'from'            => 'nullable|date_format:Y-m-d',
            'to'              => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'branch_id'       => 'nullable|uuid',
            'professional_id' => 'nullable|uuid',
            'customer_id'     => 'nullable|uuid',
            'status'          => 'nullable|string',
            'search'          => 'nullable|string|max:100',
        ]);

        // Sin fechas ni cliente se entrega el día de hoy.
        $from = $validated['date'] ?? $validated['from'] ?? null;
        $to = $validated['date'] ?? $validated['to'] ?? null;

        if (!$from && !$to && empty($validated['customer_id']) && empty($validated['search'])) {
            $from = $to = now($timezone)->format('Y-m-d');
        }

        $own = $this->ownProfessionalId($request->user());
        $statuses = array_values(array_intersect(explode(',', (string) ($validated['status'] ?? '')), AppointmentFlow::keys()));

        $appointments = Appointment::where('tenant_id', $tenant->id)
            ->with(self::RELATIONS)
            ->when($from, fn ($q) => $q->where('starts_at', '>=', Carbon::parse($from, $timezone)->startOfDay()->toIso8601String()))
            ->when($to, fn ($q) => $q->where('starts_at', '<', Carbon::parse($to, $timezone)->addDay()->startOfDay()->toIso8601String()))
            ->when($validated['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($validated['customer_id'] ?? null, fn ($q, $id) => $q->where('customer_id', $id))
            ->when($own ?? ($validated['professional_id'] ?? null), fn ($q, $id) => $q->where('professional_id', $id))
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->when($validated['search'] ?? null, function ($q, $search) {
                // Sin distinguir mayúsculas ni tildes.
                $q->where(fn ($q) => Search::contains($q, 'code', $search)
                    ->orWhereHas('customer', function ($q) use ($search) {
                        $q->where(function ($q) use ($search) {
                            Search::contains($q, "coalesce(first_name, '') || ' ' || coalesce(last_name, '')", $search);
                            Search::orContains($q, 'document_number', $search);
                            Search::orContains($q, 'phone', $search);
                        });
                    }));
            })
            ->orderBy('starts_at')
            ->limit(self::MAX_ROWS)
            ->get();

        return response()->json($appointments->map(fn (Appointment $a) => $this->present($a, $tenant)));
    }

    /**
     * Lo que necesita el formulario de cita: sedes, profesionales y servicios
     * que se pueden agendar.
     */
    public function context(Request $request)
    {
        $tenant = $this->tenant($request);
        $own = $this->ownProfessionalId($request->user());

        $assigned = DB::table('service_professionals')->where('tenant_id', $tenant->id)->get()->groupBy('service_id');
        $worksAt = ProfessionalSchedule::where('tenant_id', $tenant->id)->get()->groupBy('professional_id');

        return response()->json([
            'branches' => Branch::where('tenant_id', $tenant->id)->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'professionals' => Professional::where('tenant_id', $tenant->id)
                ->when($own, fn ($q) => $q->where('id', $own))
                ->with('status')
                ->orderBy('display_name')
                ->get()
                ->reject(fn (Professional $p) => $p->status?->slug === 'inactive')
                ->map(fn (Professional $p) => [
                    'id'           => $p->id,
                    'display_name' => $p->display_name,
                    'color_code'   => $p->color_code,
                    // Sedes donde tiene horario.
                    'branch_ids'   => ($worksAt[$p->id] ?? collect())->pluck('branch_id')->unique()->values(),
                ])->values(),
            'services' => Service::where('tenant_id', $tenant->id)
                ->where('kind', Service::KIND_INDIVIDUAL)
                ->with('status')
                ->orderBy('name')
                ->get()
                ->reject(fn (Service $s) => $s->status?->slug === 'inactive')
                ->map(fn (Service $s) => [
                    'id'               => $s->id,
                    'name'             => $s->name,
                    'duration_minutes' => $s->duration_minutes,
                    'price'            => $s->price,
                    // Vacío = lo atiende cualquier profesional.
                    'professional_ids' => ($assigned[$s->id] ?? collect())->pluck('professional_id')->values(),
                ])->values(),
            'can_override' => (bool) AppointmentFlow::rule($tenant, 'agenda.allow_override') && $request->user()->isTenantAdmin(),
            'slot_interval' => (int) AppointmentFlow::rule($tenant, 'agenda.slot_interval'),
        ]);
    }

    /**
     * La agenda de un día en una sede: una columna por profesional con su
     * horario, sus bloqueos y sus citas.
     */
    public function day(Request $request)
    {
        $tenant = $this->tenant($request);

        $validated = $request->validate([
            'date'      => 'required|date_format:Y-m-d',
            'branch_id' => 'required|uuid',
        ]);

        $branch = Branch::where('tenant_id', $tenant->id)->findOrFail($validated['branch_id']);
        $timezone = AppointmentScheduler::timezone($branch, $tenant);
        $dayStart = Carbon::createFromFormat('Y-m-d', $validated['date'], $timezone)->startOfDay();
        $dayEnd = $dayStart->copy()->addDay();
        $own = $this->ownProfessionalId($request->user());

        $appointments = Appointment::where('tenant_id', $tenant->id)
            ->where('branch_id', $branch->id)
            ->when($own, fn ($q) => $q->where('professional_id', $own))
            ->where('starts_at', '>=', $dayStart->toIso8601String())
            ->where('starts_at', '<', $dayEnd->toIso8601String())
            ->with(self::RELATIONS)
            ->orderBy('starts_at')
            ->get();

        $schedules = ProfessionalSchedule::where('tenant_id', $tenant->id)
            ->where('branch_id', $branch->id)
            ->where('weekday', $dayStart->dayOfWeek)
            ->orderBy('starts_local')
            ->get()
            ->groupBy('professional_id');

        $blocks = AvailabilityBlock::where('tenant_id', $tenant->id)
            ->where('starts_at', '<', $dayEnd->toIso8601String())
            ->where('ends_at', '>', $dayStart->toIso8601String())
            ->get();

        // Hora del día, recortada a ese día: un bloqueo de varios días ocupa el día entero.
        $clip = function (string $value) use ($dayStart, $dayEnd, $timezone) {
            $date = Carbon::parse($value)->setTimezone($timezone);

            return $date->lte($dayStart) ? '00:00' : ($date->gte($dayEnd) ? '24:00' : $date->format('H:i'));
        };

        // Feriados del día. Si la sede cierra, la columna de cada profesional sale bloqueada.
        $holidays = \App\Support\Holidays::on($tenant, $branch->id, $validated['date']);
        $closedBy = $holidays->firstWhere('closed', true);

        $professionals = Professional::where('tenant_id', $tenant->id)
            ->when($own, fn ($q) => $q->where('id', $own))
            ->whereIn('id', $schedules->keys()->merge($appointments->pluck('professional_id'))->unique())
            ->orderBy('display_name')
            ->get()
            ->map(fn (Professional $p) => [
                'id'           => $p->id,
                'display_name' => $p->display_name,
                'color_code'   => $p->color_code,
                'schedules'    => ($schedules[$p->id] ?? collect())->map(fn (ProfessionalSchedule $s) => [
                    'starts' => substr($s->starts_local, 0, 5),
                    'ends'   => substr($s->ends_local, 0, 5),
                ])->values(),
                'blocks'       => $blocks
                    ->filter(fn (AvailabilityBlock $b) => $b->professional_id === $p->id || (!$b->professional_id && $b->branch_id === $branch->id))
                    ->map(fn (AvailabilityBlock $b) => ['starts' => $clip($b->starts_at), 'ends' => $clip($b->ends_at), 'reason' => $b->reason, 'kind' => $b->kindKey()])
                    ->values()
                    ->when($closedBy, fn ($blocks) => $blocks->prepend(['starts' => '00:00', 'ends' => '24:00', 'reason' => $closedBy->name, 'kind' => 'holiday'])),
            ])->values();

        return response()->json([
            'date'          => $validated['date'],
            'now'           => now($timezone)->format(self::LOCAL_FORMAT),
            'holidays'      => $holidays->map(fn ($h) => ['name' => $h->name, 'closed' => (bool) $h->closed])->values(),
            'professionals' => $professionals,
            'appointments'  => $appointments->map(fn (Appointment $a) => $this->present($a, $tenant)),
        ]);
    }

    public function show(Request $request, string $appointment)
    {
        $appointment = $this->find($request, $appointment);

        return response()->json($this->present($appointment, $this->tenant($request), true) + [
            // Cuánto suelen durar las atenciones de este cliente.
            'customer_timing' => AppointmentTiming::summarize(
                Appointment::where('tenant_id', $appointment->tenant_id)
                    ->where('customer_id', $appointment->customer_id)
                    ->where('status', AppointmentFlow::COMPLETED)
                    ->get()
            ),
        ]);
    }

    /**
     * Tiempos reales de atención de un periodo: en general y por profesional,
     * por servicio y por cliente. Solo cuentan las citas atendidas.
     */
    public function metrics(Request $request)
    {
        $tenant = $this->tenant($request);
        $timezone = $tenant->timezone ?: 'America/Lima';

        $validated = $request->validate([
            'from'      => 'nullable|date_format:Y-m-d',
            'to'        => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'branch_id' => 'nullable|uuid',
        ]);

        $to = $validated['to'] ?? now($timezone)->format('Y-m-d');
        $from = $validated['from'] ?? Carbon::parse($to, $timezone)->subDays(29)->format('Y-m-d');
        $own = $this->ownProfessionalId($request->user());

        $appointments = Appointment::where('tenant_id', $tenant->id)
            ->where('status', AppointmentFlow::COMPLETED)
            ->where('starts_at', '>=', Carbon::parse($from, $timezone)->startOfDay()->toIso8601String())
            ->where('starts_at', '<', Carbon::parse($to, $timezone)->addDay()->startOfDay()->toIso8601String())
            ->when($validated['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($own, fn ($q) => $q->where('professional_id', $own))
            ->with(['customer', 'service', 'professional'])
            ->get();

        // Agrupa y ordena: primero quien más atenciones tiene.
        $group = fn (string $key, callable $label) => $appointments
            ->groupBy($key)
            ->map(fn ($items, $id) => ['id' => $id, 'name' => $label($items->first())] + AppointmentTiming::summarize($items))
            ->sortByDesc('completed')
            ->values();

        return response()->json([
            'from'             => $from,
            'to'               => $to,
            'summary'          => AppointmentTiming::summarize($appointments),
            'by_professional'  => $group('professional_id', fn (Appointment $a) => $a->professional?->display_name ?? '—'),
            'by_service'       => $group('service_id', fn (Appointment $a) => $a->service?->name ?? '—'),
            'by_customer'      => $group('customer_id', fn (Appointment $a) => $a->customer ? trim("{$a->customer->first_name} {$a->customer->last_name}") : '—')->take(100)->values(),
        ]);
    }

    /**
     * Horarios libres de un día para un servicio en una sede.
     */
    public function availability(Request $request)
    {
        $tenant = $this->tenant($request);

        $validated = $request->validate([
            'service_id'      => 'required|uuid',
            'branch_id'       => 'required|uuid',
            'date'            => 'required|date_format:Y-m-d',
            'professional_id' => 'nullable|uuid',
            'ignore_id'       => 'nullable|uuid',
        ]);

        $service = Service::where('tenant_id', $tenant->id)->with('status')->findOrFail($validated['service_id']);
        $branch = Branch::where('tenant_id', $tenant->id)->findOrFail($validated['branch_id']);
        $professional = empty($validated['professional_id'])
            ? null
            : Professional::where('tenant_id', $tenant->id)->with('status')->findOrFail($validated['professional_id']);

        $date = Carbon::createFromFormat('Y-m-d', $validated['date'], AppointmentScheduler::timezone($branch, $tenant));

        return response()->json([
            'date'             => $validated['date'],
            'duration_minutes' => $service->duration_minutes,
            'professionals'    => (new AppointmentScheduler($tenant))->slots($service, $branch, $date, $professional, $validated['ignore_id'] ?? null),
        ]);
    }

    public function store(Request $request)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();

        $validated = $request->validate([
            'customer_id'           => 'required|uuid',
            'customer_dependent_id' => 'nullable|uuid',
            'service_id'            => 'required|uuid',
            'professional_id'       => 'required|uuid',
            'branch_id'             => 'required|uuid',
            'starts_at'             => 'required|date_format:' . self::LOCAL_FORMAT,
            'duration_minutes'      => 'nullable|integer|min:5|max:720',
            'notes'                 => 'nullable|string|max:2000',
            'override_reason'       => 'nullable|string|max:255',
            // Control pendiente que esta cita viene a cumplir.
            'recall_id'             => 'nullable|uuid',
            // Persona de la lista de espera a la que se le consiguió este horario.
            'waitlist_id'           => 'nullable|uuid',
        ]);

        $customer = Customer::where('tenant_id', $tenant->id)->findOrFail($validated['customer_id']);
        $recall = $this->recallToBook($tenant, $customer, $validated['recall_id'] ?? null);
        $waiting = $this->waitlistToBook($tenant, $customer, $validated['waitlist_id'] ?? null);
        $service = Service::where('tenant_id', $tenant->id)->with('status')->findOrFail($validated['service_id']);
        $professional = Professional::where('tenant_id', $tenant->id)->with('status')->findOrFail($validated['professional_id']);
        $branch = Branch::where('tenant_id', $tenant->id)->findOrFail($validated['branch_id']);

        $own = $this->ownProfessionalId($user);
        abort_if($own && $professional->id !== $own, 403, 'Solo puedes agendar citas para ti.');

        if ($service->kind === Service::KIND_GROUP) {
            throw ValidationException::withMessages(['service_id' => 'Las clases grupales se reservan desde la clase, por cupos.']);
        }

        $dependentId = $this->resolveDependent($tenant, $customer, $validated['customer_dependent_id'] ?? null);

        $start = Carbon::createFromFormat(self::LOCAL_FORMAT, $validated['starts_at'], AppointmentScheduler::timezone($branch, $tenant))->startOfMinute();
        $duration = (int) ($validated['duration_minutes'] ?? $service->duration_minutes);
        $end = $start->copy()->addMinutes($duration);

        return DB::transaction(function () use ($tenant, $user, $validated, $customer, $service, $professional, $branch, $dependentId, $start, $end, $duration, $recall, $waiting) {
            // Una cita a la vez por empresa: evita dobles reservas y códigos repetidos.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['appointments:' . $tenant->id]);

            if ($response = $this->limitReached($tenant, $start)) {
                return $response;
            }

            $check = (new AppointmentScheduler($tenant))->check($service, $professional, $branch, $start, $duration);
            $override = $this->override($tenant, $user, $check, $validated['override_reason'] ?? null);

            if (!$check['ok'] && !$override) {
                return $this->unavailable($check);
            }

            $this->assertCustomerFree($tenant, $customer, $start, $end);

            $status = AppointmentFlow::rule($tenant, 'agenda.require_confirmation') ? AppointmentFlow::PENDING : AppointmentFlow::CONFIRMED;

            $appointment = Appointment::create([
                'tenant_id'             => $tenant->id,
                'code'                  => Appointment::nextCode($tenant->id),
                'branch_id'             => $branch->id,
                'customer_id'           => $customer->id,
                'customer_dependent_id' => $dependentId,
                'service_id'            => $service->id,
                'professional_id'       => $professional->id,
                'starts_at'             => $start,
                'ends_at'               => $end,
                'status'                => $status,
                'source'                => 'staff',
                'price'                 => $service->price,
                'notes'                 => $validated['notes'] ?? null,
                'override_reason'       => $override,
                'confirmed_at'          => $status === AppointmentFlow::CONFIRMED ? now() : null,
                'created_by'            => $user->id,
            ]);

            $this->log($appointment, null, $status, $user, $override ? "Sobre-agenda: {$override}" : null);

            if ($recall) {
                \App\Support\Recalls::book($recall, $appointment);
            }

            if ($waiting) {
                \App\Support\Waitlist::book($waiting, $appointment);
            }

            return response()->json($this->present($appointment->fresh(self::RELATIONS), $tenant, true), 201);
        });
    }

    /**
     * Solo las notas. La fecha, la hora y el profesional se cambian reprogramando.
     */
    public function update(Request $request, string $appointment)
    {
        $appointment = $this->find($request, $appointment);

        $validated = $request->validate(['notes' => 'present|nullable|string|max:2000']);
        $appointment->update(['notes' => $validated['notes']]);

        return response()->json($this->present($appointment, $this->tenant($request), true));
    }

    public function changeStatus(Request $request, string $appointment)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();

        $validated = $request->validate([
            'status' => ['required', Rule::in(AppointmentFlow::keys())],
            'reason' => 'nullable|string|max:255',
        ]);

        $to = $validated['status'];
        $reason = trim((string) ($validated['reason'] ?? '')) ?: null;

        return DB::transaction(function () use ($request, $appointment, $tenant, $user, $to, $reason) {
            $appointment = $this->find($request, $appointment);
            $appointment = Appointment::whereKey($appointment->id)->lockForUpdate()->first()->load(self::RELATIONS);
            $from = $appointment->status;

            if (!in_array($to, AppointmentFlow::next($tenant, $from), true)) {
                return response()->json([
                    'message' => 'No se puede pasar de «' . AppointmentFlow::name($tenant, $from) . '» a «' . AppointmentFlow::name($tenant, $to) . '».',
                    'code'    => 'INVALID_TRANSITION',
                ], 422);
            }

            if ($to === AppointmentFlow::CANCELLED && !$reason && AppointmentFlow::rule($tenant, 'cancel.require_reason')) {
                throw ValidationException::withMessages(['reason' => 'Indica el motivo de la cancelación.']);
            }

            // En los rubros que lo exigen, no se da por atendida sin la ficha firmada.
            if ($to === AppointmentFlow::COMPLETED
                && AppointmentFlow::rule($tenant, 'records.require_on_complete')
                && $this->usesRecord($tenant, $appointment)
                && (!$appointment->record || $appointment->record->status === CareRecord::DRAFT)) {
                return response()->json([
                    'message' => "Este servicio exige la ficha de atención firmada antes de darla por atendida ({$appointment->service?->name}).",
                    'code'    => 'RECORD_REQUIRED',
                ], 422);
            }

            if ($to === AppointmentFlow::NO_SHOW) {
                $grace = (int) AppointmentFlow::rule($tenant, 'noshow.grace_minutes');

                if (now()->lt($appointment->starts_at->copy()->addMinutes($grace))) {
                    return response()->json([
                        'message' => $grace > 0
                            ? "Aún no pasa la tolerancia de {$grace} min desde el inicio de la cita."
                            : 'La cita todavía no empieza.',
                        'code'    => 'POLICY',
                    ], 422);
                }
            }

            $now = now();
            $changes = ['status' => $to];

            // Cancelación tardía (pasado el plazo sin costo): por ahora solo se informa;
            // el cargo se aplicará cuando exista la caja.
            $lateCancel = $to === AppointmentFlow::CANCELLED
                && $now->lt($appointment->starts_at)
                && $now->diffInMinutes($appointment->starts_at) < 60 * (float) AppointmentFlow::rule($tenant, 'cancel.window_hours');

            switch ($to) {
                case AppointmentFlow::PENDING:
                    $changes['confirmed_at'] = null;
                    break;
                case AppointmentFlow::CONFIRMED:
                    $changes['confirmed_at'] = $appointment->confirmed_at ?? $now;
                    $changes['checked_in_at'] = null;
                    break;
                case AppointmentFlow::CHECKED_IN:
                    $changes['checked_in_at'] = $now;
                    break;
                case AppointmentFlow::IN_PROGRESS:
                    $changes['started_at'] = $now;
                    break;
                case AppointmentFlow::COMPLETED:
                    $changes['started_at'] = $appointment->started_at ?? $now;
                    $changes['completed_at'] = $now;
                    break;
                case AppointmentFlow::CANCELLED:
                    $changes['cancelled_at'] = $now;
                    $changes['cancel_reason'] = $reason;
                    $changes['cancelled_by'] = 'staff';
                    break;
            }

            $appointment->update($changes);
            $this->log($appointment, $from, $to, $user, $reason);
            // Los controles ligados a la cita siguen su suerte, y un servicio puede dejar el suyo.
            \App\Support\Recalls::appointmentChanged($tenant, $appointment);
            \App\Support\Waitlist::appointmentChanged($tenant, $appointment);

            return response()->json($this->present($appointment, $tenant, true) + [
                'late_cancel' => $lateCancel,
                // Al liberarse el horario: cuántas personas de la lista de espera podrían tomarlo.
                'waitlist_matches' => $to === AppointmentFlow::CANCELLED && $appointment->starts_at->gt($now)
                    ? \App\Support\Waitlist::matching($tenant, $appointment)->count()
                    : 0,
            ]);
        });
    }

    public function reschedule(Request $request, string $appointment)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();

        $validated = $request->validate([
            'starts_at'       => 'required|date_format:' . self::LOCAL_FORMAT,
            'professional_id' => 'nullable|uuid',
            'branch_id'       => 'nullable|uuid',
            'reason'          => 'nullable|string|max:255',
            'override_reason' => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($request, $appointment, $tenant, $user, $validated) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['appointments:' . $tenant->id]);

            $appointment = $this->find($request, $appointment);

            if (!in_array($appointment->status, AppointmentFlow::RESCHEDULABLE, true)) {
                return response()->json([
                    'message' => 'Una cita «' . AppointmentFlow::name($tenant, $appointment->status) . '» no se puede reprogramar.',
                    'code'    => 'INVALID_TRANSITION',
                ], 422);
            }

            $professional = Professional::where('tenant_id', $tenant->id)->with('status')
                ->findOrFail($validated['professional_id'] ?? $appointment->professional_id);
            $branch = Branch::where('tenant_id', $tenant->id)->findOrFail($validated['branch_id'] ?? $appointment->branch_id);
            $service = Service::with('status')->findOrFail($appointment->service_id);

            $own = $this->ownProfessionalId($user);
            abort_if($own && $professional->id !== $own, 403, 'Solo puedes agendar citas para ti.');

            $timezone = AppointmentScheduler::timezone($branch, $tenant);
            $start = Carbon::createFromFormat(self::LOCAL_FORMAT, $validated['starts_at'], $timezone)->startOfMinute();
            $duration = (int) $appointment->starts_at->diffInMinutes($appointment->ends_at);
            $end = $start->copy()->addMinutes($duration);
            $previous = $appointment->starts_at->copy()->setTimezone($timezone);

            // Solo cuenta contra el límite si la cita pasa a otro mes.
            if ($previous->format('Y-m') !== $start->format('Y-m') && ($response = $this->limitReached($tenant, $start))) {
                return $response;
            }

            $check = (new AppointmentScheduler($tenant))->check($service, $professional, $branch, $start, $duration, $appointment->id);
            $override = $this->override($tenant, $user, $check, $validated['override_reason'] ?? null);

            if (!$check['ok'] && !$override) {
                return $this->unavailable($check, 'Se mantiene la cita original. ');
            }

            $this->assertCustomerFree($tenant, $appointment->customer, $start, $end, $appointment->id);

            $appointment->update([
                'starts_at'        => $start,
                'ends_at'          => $end,
                'professional_id'  => $professional->id,
                'branch_id'        => $branch->id,
                'override_reason'  => $override,
                'reschedule_count' => $appointment->reschedule_count + 1,
            ]);

            $detail = 'Reprogramada de ' . $previous->format('d/m/Y H:i') . ' a ' . $start->format('d/m/Y H:i');
            $reason = trim((string) ($validated['reason'] ?? ''));
            $this->log($appointment, $appointment->status, $appointment->status, $user, $reason ? "{$detail} · {$reason}" : $detail);

            return response()->json($this->present($appointment->fresh(self::RELATIONS), $tenant, true));
        });
    }

    /**
     * Valida la mascota (o dependiente) de la cita según el rubro: obligatoria
     * si el rubro la exige, y siempre del mismo cliente.
     */
    protected function resolveDependent(Tenant $tenant, Customer $customer, ?string $dependentId): ?string
    {
        $config = $tenant->dependents();

        if (!$config['enabled']) {
            return null;
        }

        $term = mb_strtolower($tenant->vocabulary()['dependent']);

        if (!$dependentId) {
            if ($config['required']) {
                throw ValidationException::withMessages(['customer_dependent_id' => "Indica para qué {$term} es la cita."]);
            }

            return null;
        }

        $dependent = CustomerDependent::where('tenant_id', $tenant->id)->where('customer_id', $customer->id)->find($dependentId);

        if (!$dependent) {
            throw ValidationException::withMessages(['customer_dependent_id' => "Ese registro de {$term} no pertenece a este cliente."]);
        }

        if ($dependent->status !== 'active') {
            throw ValidationException::withMessages(['customer_dependent_id' => "{$dependent->name} no está activo."]);
        }

        return $dependent->id;
    }

    /**
     * Respuesta de rechazo si la empresa ya llegó al límite de citas de su
     * plan en el mes de la cita. Las canceladas no cuentan.
     */
    protected function limitReached(Tenant $tenant, Carbon $start): ?JsonResponse
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

        if ($used < (int) $limit) {
            return null;
        }

        return response()->json([
            'message' => "Tu plan permite {$limit} citas por mes y ese mes ya las tiene. Elige otro mes o mejora tu plan.",
            'code'    => 'PLAN_LIMIT',
            'limit'   => (int) $limit,
            'used'    => $used,
        ], 422);
    }

    /**
     * Registro de la lista de espera que la cita viene a resolver: de la
     * empresa, del mismo cliente y todavía en espera.
     */
    protected function waitlistToBook(Tenant $tenant, Customer $customer, ?string $id): ?WaitlistEntry
    {
        if (!$id) {
            return null;
        }

        $entry = WaitlistEntry::where('tenant_id', $tenant->id)->findOrFail($id);

        if ($entry->customer_id !== $customer->id) {
            throw ValidationException::withMessages(['waitlist_id' => 'Ese registro de la lista de espera es de otra persona.']);
        }

        if (!$entry->isOpen()) {
            throw ValidationException::withMessages(['waitlist_id' => 'Esa persona ya no está en la lista de espera.']);
        }

        return $entry;
    }

    /**
     * Control que la cita viene a cumplir: de la empresa, del mismo cliente y
     * todavía sin cita.
     */
    protected function recallToBook(Tenant $tenant, Customer $customer, ?string $id): ?Recall
    {
        if (!$id) {
            return null;
        }

        $recall = Recall::where('tenant_id', $tenant->id)->findOrFail($id);

        if ($recall->customer_id !== $customer->id) {
            throw ValidationException::withMessages(['recall_id' => 'Ese control es de otra persona.']);
        }

        if (!$recall->isOpen()) {
            throw ValidationException::withMessages(['recall_id' => 'Ese control ya está ' . mb_strtolower(Recall::STATUSES[$recall->status]) . '.']);
        }

        return $recall;
    }

    /**
     * Motivo de la sobre-agenda si se puede forzar el horario: la regla lo
     * permite, lo pide el administrador de la empresa y no hay doble reserva.
     */
    protected function override(Tenant $tenant, User $user, array $check, ?string $reason): ?string
    {
        $reason = trim((string) $reason);

        if ($check['ok'] || !$check['overridable'] || $reason === '') {
            return null;
        }

        if (!AppointmentFlow::rule($tenant, 'agenda.allow_override') || !$user->isTenantAdmin()) {
            return null;
        }

        return $reason;
    }

    protected function unavailable(array $check, string $prefix = ''): JsonResponse
    {
        $message = collect($check['reasons'])
            ->map(fn (array $r) => $r['message'] . ($r['detail'] !== '' ? " ({$r['detail']})" : ''))
            ->join(' · ');

        return response()->json([
            'message'     => $prefix . $message,
            'code'        => 'SLOT_UNAVAILABLE',
            'reasons'     => $check['reasons'],
            'overridable' => $check['overridable'],
        ], 409);
    }

    /**
     * Un cliente no puede tener dos citas que se crucen (regla agenda.client_overlap).
     */
    protected function assertCustomerFree(Tenant $tenant, Customer $customer, Carbon $start, Carbon $end, ?string $ignoreId = null): void
    {
        if (!AppointmentFlow::rule($tenant, 'agenda.client_overlap')) {
            return;
        }

        $other = Appointment::where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->whereIn('status', [AppointmentFlow::PENDING, AppointmentFlow::CONFIRMED, AppointmentFlow::CHECKED_IN, AppointmentFlow::IN_PROGRESS])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('starts_at', '<', $end->toIso8601String())
            ->where('ends_at', '>', $start->toIso8601String())
            ->with('service:id,name')
            ->first();

        if ($other) {
            $timezone = $start->getTimezone();

            abort(response()->json([
                'message' => trim("{$customer->first_name} {$customer->last_name}") . " ya tiene la cita {$other->code} de "
                    . $other->starts_at->copy()->setTimezone($timezone)->format('H:i') . ' a '
                    . $other->ends_at->copy()->setTimezone($timezone)->format('H:i')
                    . " ese día ({$other->service?->name}).",
                'code'    => 'CLIENT_OVERLAP',
            ], 409));
        }
    }

    /**
     * La cita lleva ficha: la empresa tiene el módulo y el servicio tiene una
     * plantilla asignada (o la cita ya tiene una ficha llenada).
     */
    protected function usesRecord(Tenant $tenant, Appointment $appointment): bool
    {
        if (!$tenant->hasModule('records')) {
            return false;
        }

        // Servicios con ficha: se consultan una vez por petición (se guardan en ella,
        // no en el controlador, que puede vivir más que una petición).
        $attributes = request()->attributes;

        if (!$attributes->has('record_services')) {
            $attributes->set('record_services', DB::table('record_template_services')
                ->join('record_templates', 'record_templates.id', '=', 'record_template_services.record_template_id')
                ->where('record_template_services.tenant_id', $tenant->id)
                ->where('record_templates.status', 'active')
                ->pluck('record_template_services.service_id')
                ->all());
        }

        return $appointment->record !== null || in_array($appointment->service_id, $attributes->get('record_services'), true);
    }

    /**
     * @param User|Customer $actor Quien da el paso: el personal, o el cliente desde su portal.
     */
    protected function log(Appointment $appointment, ?string $from, string $to, User|Customer $actor, ?string $reason): void
    {
        AppointmentStatusHistory::create([
            'tenant_id'      => $appointment->tenant_id,
            'appointment_id' => $appointment->id,
            'from_status'    => $from,
            'to_status'      => $to,
            'reason'         => $reason ? mb_substr($reason, 0, 255) : null,
            'actor_user_id'  => $actor instanceof User ? $actor->id : null,
            'actor_customer_id' => $actor instanceof Customer ? $actor->id : null,
        ]);

        // Avisa en la campana a quien deba enterarse (el profesional, la administración).
        \App\Support\Notifier::appointmentStep($appointment, $from, $to, $actor);
    }

    protected function present(Appointment $appointment, Tenant $tenant, bool $withHistory = false): array
    {
        $timezone = AppointmentScheduler::timezone($appointment->branch ?? new Branch(), $tenant);
        $local = fn (?Carbon $date) => $date?->copy()->setTimezone($timezone)->format(self::LOCAL_FORMAT);
        $customer = $appointment->customer;
        $dependent = $appointment->dependent;

        $data = [
            'id'               => $appointment->id,
            'code'             => $appointment->code,
            'status'           => $appointment->status,
            'status_name'      => AppointmentFlow::name($tenant, $appointment->status),
            'next_statuses'    => AppointmentFlow::next($tenant, $appointment->status),
            'can_reschedule'   => in_array($appointment->status, AppointmentFlow::RESCHEDULABLE, true),
            'starts_at'        => $local($appointment->starts_at),
            'ends_at'          => $local($appointment->ends_at),
            'duration_minutes' => (int) $appointment->starts_at->diffInMinutes($appointment->ends_at),
            'price'            => $appointment->price,
            'source'           => $appointment->source,
            'notes'            => $appointment->notes,
            'reschedule_count' => $appointment->reschedule_count,
            'override_reason'  => $appointment->override_reason,
            'cancel_reason'    => $appointment->cancel_reason,
            'confirmed_at'     => $local($appointment->confirmed_at),
            'checked_in_at'    => $local($appointment->checked_in_at),
            'started_at'       => $local($appointment->started_at),
            'completed_at'     => $local($appointment->completed_at),
            'cancelled_at'     => $local($appointment->cancelled_at),
            'timing'           => AppointmentTiming::of($appointment),
            // Ficha de atención: si el servicio la usa y en qué estado está la de esta cita.
            'record'           => [
                'uses'   => $this->usesRecord($tenant, $appointment),
                'status' => $appointment->record?->currentStatus(),
            ],
            // Hasta qué hora ocupa al profesional: antes de lo asignado si terminó antes.
            'occupied_until'   => $local(AppointmentTiming::occupiedUntil($appointment)),
            'customer'         => $customer ? [
                'id'    => $customer->id,
                'code'  => $customer->code,
                'name'  => trim("{$customer->first_name} {$customer->last_name}"),
                'phone' => $customer->phone,
            ] : null,
            'dependent'        => $dependent ? [
                'id'      => $dependent->id,
                'name'    => $dependent->name,
                'species' => $dependent->species,
            ] : null,
            'service'          => $appointment->service ? [
                'id'   => $appointment->service->id,
                'name' => $appointment->service->name,
            ] : null,
            'professional'     => $appointment->professional ? [
                'id'           => $appointment->professional->id,
                'display_name' => $appointment->professional->display_name,
                'color_code'   => $appointment->professional->color_code,
            ] : null,
            'branch'           => $appointment->branch ? [
                'id'   => $appointment->branch->id,
                'name' => $appointment->branch->name,
            ] : null,
        ];

        if ($withHistory) {
            $data['history'] = $appointment->history()->with(['actor:id,first_name,last_name', 'customerActor:id,first_name,last_name'])->get()
                ->map(fn (AppointmentStatusHistory $step) => [
                    'from'      => $step->from_status,
                    'to'        => $step->to_status,
                    'to_name'   => AppointmentFlow::name($tenant, $step->to_status),
                    'reason'    => $step->reason,
                    'by'        => ($who = $step->actor ?? $step->customerActor) ? trim("{$who->first_name} {$who->last_name}") : null,
                    'at'        => $local($step->created_at),
                ]);
        }

        return $data;
    }
}
