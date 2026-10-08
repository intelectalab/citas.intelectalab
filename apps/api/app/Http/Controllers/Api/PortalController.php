<?php

namespace App\Http\Controllers\Api;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerDependent;
use App\Models\Professional;
use App\Models\ProfessionalSchedule;
use App\Models\Service;
use App\Models\Tenant;
use App\Support\AppointmentFlow;
use App\Support\AppointmentScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Portal del cliente: ve sus citas y, si el plan de la empresa lo incluye,
 * reserva, confirma, cancela y reprograma en línea. Usa las mismas reglas de
 * agenda que el panel de la empresa (por eso extiende su controlador), más las
 * de la reserva en línea. Cada cuenta solo alcanza sus propias citas.
 */
class PortalController extends AppointmentController
{
    private function customer(Request $request): Customer
    {
        return $request->user()->customer;
    }

    /**
     * Reglas de la reserva en línea, como las necesita la pantalla.
     */
    private function policies(Tenant $tenant): array
    {
        $rule = fn (string $key) => AppointmentFlow::rule($tenant, $key);

        return [
            // La empresa tiene el módulo en su plan y no apagó la reserva.
            'booking_enabled'    => $tenant->hasModule('booking') && (bool) $rule('booking.enabled'),
            'choose_professional' => (bool) $rule('booking.choose_professional'),
            'min_lead_minutes'   => (int) $rule('booking.min_lead_minutes'),
            'max_days_ahead'     => (int) $rule('booking.max_days_ahead'),
            'auto_confirm'       => (bool) $rule('booking.auto_confirm'),
            'cancel_allowed'     => (bool) $rule('cancel.client_allowed'),
            'cancel_window_hours' => (int) $rule('cancel.window_hours'),
            'reschedule_max'     => (int) $rule('reschedule.max_times'),
        ];
    }

    private function assertBookingEnabled(Tenant $tenant): void
    {
        abort_unless(AppointmentFlow::rule($tenant, 'booking.enabled'), 403, 'La reserva en línea no está disponible. Comunícate con la sede.');
    }

    /**
     * Cita del cliente que consulta. La de otra persona no existe para él.
     */
    private function ownAppointment(Request $request, string $id): Appointment
    {
        return Appointment::where('tenant_id', $this->tenant($request)->id)
            ->where('customer_id', $this->customer($request)->id)
            ->with(self::RELATIONS)
            ->findOrFail($id);
    }

    /**
     * Todavía se puede cambiar en línea: faltan más horas que el plazo de la empresa.
     */
    private function insideWindow(Tenant $tenant, Appointment $appointment): bool
    {
        return now()->gt($appointment->starts_at->copy()->subHours((int) AppointmentFlow::rule($tenant, 'cancel.window_hours')));
    }

    /**
     * Lo que el cliente ve al entrar: sus datos, las reglas de la reserva y sus citas.
     */
    public function overview(Request $request)
    {
        $tenant = $this->tenant($request);
        $customer = $this->customer($request);
        $dependents = $tenant->dependents();

        $appointments = Appointment::where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->with(self::RELATIONS)
            ->orderByDesc('starts_at')
            ->limit(100)
            ->get();

        return response()->json([
            'customer' => [
                'id'         => $customer->id,
                'code'       => $customer->code,
                'first_name' => $customer->first_name,
                'last_name'  => $customer->last_name,
                'phone'      => $customer->phone,
                'email'      => $customer->email,
            ],
            'policies'     => $this->policies($tenant),
            'dependents'   => [
                'enabled'  => $dependents['enabled'],
                'required' => $dependents['required'],
                'items'    => $dependents['enabled']
                    ? $customer->dependents()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'species', 'breed'])
                    : [],
            ],
            'appointments' => $appointments->map(fn (Appointment $a) => $this->presentForCustomer($a, $tenant)),
        ]);
    }

    /**
     * Lo que se puede reservar en línea: sedes, servicios y quién atiende cada uno.
     */
    public function catalog(Request $request)
    {
        $tenant = $this->tenant($request);
        $this->assertBookingEnabled($tenant);

        $assigned = DB::table('service_professionals')->where('tenant_id', $tenant->id)->get()->groupBy('service_id');
        $worksAt = ProfessionalSchedule::where('tenant_id', $tenant->id)->get()->groupBy('professional_id');
        $depositRule = (bool) AppointmentFlow::rule($tenant, 'booking.require_deposit');

        return response()->json([
            'branches' => Branch::where('tenant_id', $tenant->id)->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'services' => Service::where('tenant_id', $tenant->id)
                ->where('kind', Service::KIND_INDIVIDUAL)
                ->where('online_booking', true)
                ->with(['status', 'category:id,name'])
                ->orderBy('name')
                ->get()
                ->reject(fn (Service $s) => $s->status?->slug === 'inactive')
                ->map(fn (Service $s) => [
                    'id'               => $s->id,
                    'name'             => $s->name,
                    'category'         => $s->category?->name,
                    'duration_minutes' => $s->duration_minutes,
                    'price'            => $s->price,
                    'is_virtual'       => $s->is_virtual,
                    // Exige adelanto y aún no hay pago en línea: se reserva en la sede.
                    'needs_deposit'    => $depositRule && $s->requires_deposit,
                    'professional_ids' => ($assigned[$s->id] ?? collect())->pluck('professional_id')->values(),
                ])->values(),
            // Solo quienes tienen horario en alguna sede.
            'professionals' => Professional::where('tenant_id', $tenant->id)
                ->whereIn('id', $worksAt->keys())
                ->with('status')
                ->orderBy('display_name')
                ->get()
                ->reject(fn (Professional $p) => $p->status?->slug === 'inactive')
                ->map(fn (Professional $p) => [
                    'id'           => $p->id,
                    'display_name' => $p->display_name,
                    'branch_ids'   => ($worksAt[$p->id] ?? collect())->pluck('branch_id')->unique()->values(),
                ])->values(),
        ]);
    }

    /**
     * Horarios que el cliente puede reservar un día. Con ignore_id (al
     * reprogramar) la propia cita no cuenta como ocupada.
     */
    public function portalAvailability(Request $request)
    {
        $tenant = $this->tenant($request);
        $this->assertBookingEnabled($tenant);

        $validated = $request->validate([
            'service_id'      => 'required|uuid',
            'branch_id'       => 'required|uuid',
            'date'            => 'required|date_format:Y-m-d',
            'professional_id' => 'nullable|uuid',
            'ignore_id'       => 'nullable|uuid',
        ]);

        $service = Service::where('tenant_id', $tenant->id)->with('status')->findOrFail($validated['service_id']);
        $branch = Branch::where('tenant_id', $tenant->id)->findOrFail($validated['branch_id']);
        $professional = empty($validated['professional_id']) || !AppointmentFlow::rule($tenant, 'booking.choose_professional')
            ? null
            : Professional::where('tenant_id', $tenant->id)->with('status')->findOrFail($validated['professional_id']);

        // Solo se puede ignorar una cita propia.
        $ignoreId = empty($validated['ignore_id']) ? null : $this->ownAppointment($request, $validated['ignore_id'])->id;

        $date = Carbon::createFromFormat('Y-m-d', $validated['date'], AppointmentScheduler::timezone($branch, $tenant));
        $groups = (new AppointmentScheduler($tenant, true))->slots($service, $branch, $date, $professional, $ignoreId);

        // Sin elegir profesional, el cliente ve solo las horas; al reservar se le asigna quien esté libre.
        $times = collect($groups)->flatMap(fn (array $group) => $group['slots'])->unique()->sort()->values();

        return response()->json([
            'date'          => $validated['date'],
            'times'         => $times,
            'professionals' => AppointmentFlow::rule($tenant, 'booking.choose_professional') ? $groups : [],
        ]);
    }

    public function book(Request $request)
    {
        $tenant = $this->tenant($request);
        $this->assertBookingEnabled($tenant);
        $user = $request->user();
        $customer = $this->customer($request);

        $validated = $request->validate([
            'service_id'            => 'required|uuid',
            'branch_id'             => 'required|uuid',
            'professional_id'       => 'nullable|uuid',
            'customer_dependent_id' => 'nullable|uuid',
            'starts_at'             => 'required|date_format:' . self::LOCAL_FORMAT,
            'notes'                 => 'nullable|string|max:1000',
        ]);

        $service = Service::where('tenant_id', $tenant->id)->with('status')->findOrFail($validated['service_id']);
        $branch = Branch::where('tenant_id', $tenant->id)->where('status', 'active')->findOrFail($validated['branch_id']);

        if ($service->kind === Service::KIND_GROUP || !$service->online_booking) {
            throw ValidationException::withMessages(['service_id' => 'Este servicio no se reserva en línea. Comunícate con la sede.']);
        }

        if ($service->requires_deposit && AppointmentFlow::rule($tenant, 'booking.require_deposit')) {
            throw ValidationException::withMessages(['service_id' => 'Este servicio se reserva con un adelanto. Comunícate con la sede para agendarlo.']);
        }

        $dependentId = $this->resolveDependent($tenant, $customer, $validated['customer_dependent_id'] ?? null);

        $start = Carbon::createFromFormat(self::LOCAL_FORMAT, $validated['starts_at'], AppointmentScheduler::timezone($branch, $tenant))->startOfMinute();
        $duration = (int) $service->duration_minutes;
        $end = $start->copy()->addMinutes($duration);

        return DB::transaction(function () use ($tenant, $user, $customer, $validated, $service, $branch, $dependentId, $start, $end, $duration) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['appointments:' . $tenant->id]);

            if ($response = $this->limitReached($tenant, $start)) {
                // El límite del plan es asunto de la empresa: al cliente no se le explica.
                return response()->json(['message' => 'No hay cupos en línea para esa fecha. Comunícate con la sede.', 'code' => 'UNAVAILABLE'], 422);
            }

            $scheduler = new AppointmentScheduler($tenant, true);
            [$professional, $check] = $this->pickProfessional($tenant, $scheduler, $service, $branch, $start, $duration, $validated['professional_id'] ?? null);

            if (!$professional) {
                return $this->unavailableForCustomer($check);
            }

            $this->assertCustomerFree($tenant, $customer, $start, $end);

            $status = AppointmentFlow::rule($tenant, 'booking.auto_confirm') ? AppointmentFlow::CONFIRMED : AppointmentFlow::PENDING;

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
                'source'                => 'online',
                'price'                 => $service->price,
                'notes'                 => $validated['notes'] ?? null,
                'confirmed_at'          => $status === AppointmentFlow::CONFIRMED ? now() : null,
                'created_by'            => $user->id,
            ]);

            $this->log($appointment, null, $status, $user, 'Reserva en línea');

            return response()->json($this->presentForCustomer($appointment->fresh(self::RELATIONS), $tenant), 201);
        });
    }

    /**
     * El cliente confirma que asistirá.
     */
    public function confirm(Request $request, string $appointment)
    {
        $tenant = $this->tenant($request);
        $appointment = $this->ownAppointment($request, $appointment);

        if ($appointment->status !== AppointmentFlow::PENDING) {
            return response()->json(['message' => 'Esta cita ya no está pendiente de confirmar.', 'code' => 'INVALID_TRANSITION'], 422);
        }

        $appointment->update(['status' => AppointmentFlow::CONFIRMED, 'confirmed_at' => now()]);
        $this->log($appointment, AppointmentFlow::PENDING, AppointmentFlow::CONFIRMED, $request->user(), 'Confirmada por el cliente');

        return response()->json($this->presentForCustomer($appointment, $tenant));
    }

    public function cancel(Request $request, string $appointment)
    {
        $tenant = $this->tenant($request);
        $appointment = $this->ownAppointment($request, $appointment);

        $validated = $request->validate(['reason' => 'nullable|string|max:255']);

        if ($response = $this->cannotChange($tenant, $appointment, 'cancelar')) {
            return $response;
        }

        if (!AppointmentFlow::rule($tenant, 'cancel.client_allowed')) {
            return response()->json(['message' => 'Las citas no se cancelan en línea. Comunícate con la sede.', 'code' => 'POLICY'], 403);
        }

        $from = $appointment->status;
        $reason = trim((string) ($validated['reason'] ?? '')) ?: 'Cancelada por el cliente';

        $appointment->update([
            'status'        => AppointmentFlow::CANCELLED,
            'cancelled_at'  => now(),
            'cancel_reason' => $reason,
            'cancelled_by'  => 'customer',
        ]);
        $this->log($appointment, $from, AppointmentFlow::CANCELLED, $request->user(), $reason);

        return response()->json($this->presentForCustomer($appointment, $tenant));
    }

    /**
     * El cliente mueve su cita a otro horario.
     */
    public function move(Request $request, string $appointment)
    {
        $tenant = $this->tenant($request);
        $this->assertBookingEnabled($tenant);
        $user = $request->user();

        $validated = $request->validate([
            'starts_at'       => 'required|date_format:' . self::LOCAL_FORMAT,
            'professional_id' => 'nullable|uuid',
        ]);

        return DB::transaction(function () use ($request, $appointment, $tenant, $user, $validated) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['appointments:' . $tenant->id]);

            $appointment = $this->ownAppointment($request, $appointment);

            if ($response = $this->cannotChange($tenant, $appointment, 'reprogramar')) {
                return $response;
            }

            $max = (int) AppointmentFlow::rule($tenant, 'reschedule.max_times');

            if ($appointment->reschedule_count >= $max) {
                return response()->json([
                    'message' => $max > 0
                        ? "Ya cambiaste esta cita {$max} " . ($max === 1 ? 'vez' : 'veces') . '. Para moverla otra vez, comunícate con la sede.'
                        : 'Las citas no se reprograman en línea. Comunícate con la sede.',
                    'code'    => 'POLICY',
                ], 403);
            }

            $service = Service::with('status')->findOrFail($appointment->service_id);
            $branch = Branch::findOrFail($appointment->branch_id);
            $timezone = AppointmentScheduler::timezone($branch, $tenant);
            $start = Carbon::createFromFormat(self::LOCAL_FORMAT, $validated['starts_at'], $timezone)->startOfMinute();
            $duration = (int) $appointment->starts_at->diffInMinutes($appointment->ends_at);
            $end = $start->copy()->addMinutes($duration);
            $previous = $appointment->starts_at->copy()->setTimezone($timezone);

            if ($previous->format('Y-m') !== $start->format('Y-m') && $this->limitReached($tenant, $start)) {
                return response()->json(['message' => 'No hay cupos en línea para esa fecha. Comunícate con la sede.', 'code' => 'UNAVAILABLE'], 422);
            }

            // Por defecto sigue con quien ya lo atendía.
            $scheduler = new AppointmentScheduler($tenant, true);
            [$professional, $check] = $this->pickProfessional(
                $tenant, $scheduler, $service, $branch, $start, $duration,
                $validated['professional_id'] ?? $appointment->professional_id, $appointment->id,
            );

            if (!$professional) {
                return $this->unavailableForCustomer($check, 'Tu cita sigue como estaba. ');
            }

            $this->assertCustomerFree($tenant, $appointment->customer, $start, $end, $appointment->id);

            $appointment->update([
                'starts_at'        => $start,
                'ends_at'          => $end,
                'professional_id'  => $professional->id,
                'reschedule_count' => $appointment->reschedule_count + 1,
            ]);

            $this->log($appointment, $appointment->status, $appointment->status, $user,
                'Reprogramada por el cliente de ' . $previous->format('d/m/Y H:i') . ' a ' . $start->format('d/m/Y H:i'));

            return response()->json($this->presentForCustomer($appointment->fresh(self::RELATIONS), $tenant));
        });
    }

    public function updateProfile(Request $request)
    {
        $customer = $this->customer($request);

        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+ ()-]{6,30}$/'],
        ], ['phone.regex' => 'Escribe un número de celular válido.', 'phone.required' => 'Escribe tu celular.']);

        $customer->update(['phone' => trim($validated['phone'])]);

        return response()->json(['phone' => $customer->phone]);
    }

    /**
     * El cliente registra una mascota (o dependiente) para poder reservarle.
     */
    public function storeDependent(Request $request)
    {
        $tenant = $this->tenant($request);
        $customer = $this->customer($request);
        $config = $tenant->dependents();

        abort_unless($config['enabled'], 403, 'Esta empresa no usa este registro.');

        $validated = $request->validate(CustomerDependent::rules());

        $dependent = $customer->dependents()->create($validated + ['tenant_id' => $tenant->id, 'kind' => $config['kind']]);

        return response()->json($dependent->only(['id', 'name', 'species', 'breed']), 201);
    }

    /**
     * Motivo por el que una cita ya no se cambia en línea, si lo hay.
     */
    private function cannotChange(Tenant $tenant, Appointment $appointment, string $verb): ?JsonResponse
    {
        if (!in_array($appointment->status, AppointmentFlow::RESCHEDULABLE, true)) {
            return response()->json(['message' => "Esta cita ya no se puede {$verb}.", 'code' => 'INVALID_TRANSITION'], 422);
        }

        if ($this->insideWindow($tenant, $appointment)) {
            $hours = (int) AppointmentFlow::rule($tenant, 'cancel.window_hours');

            return response()->json([
                'message' => "Solo se puede {$verb} en línea hasta {$hours} h antes de la cita. Comunícate con la sede.",
                'code'    => 'POLICY',
            ], 403);
        }

        return null;
    }

    /**
     * Quién atenderá: el profesional elegido o, si no eligió (o la empresa no
     * deja elegir), el primero que tenga libre ese horario.
     *
     * @return array{0: ?Professional, 1: array}
     */
    private function pickProfessional(Tenant $tenant, AppointmentScheduler $scheduler, Service $service, Branch $branch, Carbon $start, int $duration, ?string $professionalId, ?string $ignoreId = null): array
    {
        $candidates = Professional::where('tenant_id', $tenant->id)->with('status')
            ->when($professionalId && AppointmentFlow::rule($tenant, 'booking.choose_professional'), fn ($q) => $q->where('id', $professionalId))
            ->whereIn('id', ProfessionalSchedule::where('branch_id', $branch->id)->where('weekday', $start->dayOfWeek)->select('professional_id'))
            ->orderBy('display_name')
            ->get();

        // Al reprogramar se prefiere a quien ya lo atendía.
        if ($professionalId) {
            $candidates = $candidates->sortByDesc(fn (Professional $p) => $p->id === $professionalId)->values();
        }

        $check = ['ok' => false, 'reasons' => [], 'overridable' => false];

        foreach ($candidates as $candidate) {
            $check = $scheduler->check($service, $candidate, $branch, $start, $duration, $ignoreId);

            if ($check['ok']) {
                return [$candidate, $check];
            }
        }

        return [null, $check];
    }

    /**
     * Rechazo de un horario, dicho para el cliente: sin códigos de otras
     * citas ni detalles internos de la agenda.
     */
    private function unavailableForCustomer(array $check, string $prefix = ''): JsonResponse
    {
        $codes = array_column($check['reasons'], 'code');

        $message = match (true) {
            in_array('NOT_ONLINE', $codes, true) => 'Este servicio no se reserva en línea. Comunícate con la sede.',
            in_array('PAST', $codes, true)       => 'Ese horario ya pasó. Elige otro.',
            in_array('LEAD_TIME', $codes, true)  => 'Ese horario está demasiado cerca para reservarlo en línea. Elige uno más adelante o comunícate con la sede.',
            in_array('TOO_FAR', $codes, true)    => 'Aún no se puede reservar con tanta anticipación. Elige una fecha más cercana.',
            default                              => 'Ese horario ya no está disponible. Elige otro.',
        };

        return response()->json(['message' => $prefix . $message, 'code' => 'SLOT_UNAVAILABLE'], 409);
    }

    /**
     * La cita como la ve su cliente: sin notas internas ni datos de la agenda.
     */
    private function presentForCustomer(Appointment $appointment, Tenant $tenant): array
    {
        $full = $this->present($appointment, $tenant);
        $policies = $this->policies($tenant);
        $open = in_array($appointment->status, AppointmentFlow::RESCHEDULABLE, true)
            && $appointment->starts_at->isFuture()
            && !$this->insideWindow($tenant, $appointment);

        return [
            'id'               => $full['id'],
            'code'             => $full['code'],
            'status'           => $full['status'],
            'status_name'      => $full['status_name'],
            'starts_at'        => $full['starts_at'],
            'ends_at'          => $full['ends_at'],
            'duration_minutes' => $full['duration_minutes'],
            'price'            => $full['price'],
            'service'          => $full['service'],
            'professional'     => $full['professional'] ? ['id' => $full['professional']['id'], 'display_name' => $full['professional']['display_name']] : null,
            'branch'           => $full['branch'],
            'dependent'        => $full['dependent'],
            'cancel_reason'    => $full['cancel_reason'],
            'is_upcoming'      => $appointment->starts_at->isFuture() && in_array($appointment->status, [AppointmentFlow::PENDING, AppointmentFlow::CONFIRMED], true),
            'can_confirm'      => $policies['booking_enabled'] && $appointment->status === AppointmentFlow::PENDING && $appointment->starts_at->isFuture(),
            'can_cancel'       => $policies['booking_enabled'] && $open && $policies['cancel_allowed'],
            'can_reschedule'   => $policies['booking_enabled'] && $open && $appointment->reschedule_count < $policies['reschedule_max'],
        ];
    }
}
