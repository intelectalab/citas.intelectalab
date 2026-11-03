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
use App\Models\WaitlistEntry;
use App\Support\AppointmentFlow;
use App\Support\AppointmentScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Support\Currency;

class PortalController extends AppointmentController
{
    private function customer(Request $request): Customer
    {
        return $request->user();
    }

    private function policies(Tenant $tenant): array
    {
        $rule = fn (string $key) => AppointmentFlow::rule($tenant, $key);

        return [
            'booking_enabled'    => $tenant->hasModule('booking') && (bool) $rule('booking.enabled'),
            'choose_professional' => (bool) $rule('booking.choose_professional'),
            'min_lead_minutes'   => (int) $rule('booking.min_lead_minutes'),
            'max_days_ahead'     => (int) $rule('booking.max_days_ahead'),
            'auto_confirm'       => (bool) $rule('booking.auto_confirm'),
            'cancel_allowed'     => (bool) $rule('cancel.client_allowed'),
            'cancel_window_hours' => (int) $rule('cancel.window_hours'),
            'late_cancel_counts' => $tenant->hasModule('booking') && (bool) $rule('booking.count_late_cancel') && \App\Support\OnlineBooking::policy($tenant)['action'] !== \App\Support\OnlineBooking::NONE,
            'reschedule_max'     => (int) $rule('reschedule.max_times'),
            'confirm_deadline_hours' => \App\Support\Confirmations::deadlineHours($tenant),
            'confirm_on_miss'    => \App\Support\Confirmations::mode($tenant),
            'confirm_release_hours' => \App\Support\Confirmations::releaseHours($tenant),
            'pay_before_service' => \App\Support\Sales::mustPayFirst($tenant),
            'arrive_minutes'     => \App\Support\Sales::rules($tenant)['arrive_minutes'],
            'deposit_minutes'    => max(5, (int) AppointmentFlow::rule($tenant, 'booking.deposit_minutes')),
            'deposit_refund_hours' => (int) AppointmentFlow::rule($tenant, 'booking.deposit_refund_hours'),
            'miss'               => \App\Support\OnlineBooking::policy($tenant),
        ];
    }

    private function assertBookingEnabled(Tenant $tenant): void
    {
        abort_unless(AppointmentFlow::rule($tenant, 'booking.enabled'), 403, 'La reserva en línea no está disponible. Comunícate con la sede.');
    }

    private function ownAppointment(Request $request, string $id): Appointment
    {
        return Appointment::where('tenant_id', $this->tenant($request)->id)
            ->where('customer_id', $this->customer($request)->id)
            ->with(self::RELATIONS)
            ->findOrFail($id);
    }

    private function insideWindow(Tenant $tenant, Appointment $appointment): bool
    {
        return now()->gt($appointment->starts_at->copy()->subHours((int) AppointmentFlow::rule($tenant, 'cancel.window_hours')));
    }

    public function overview(Request $request)
    {
        $tenant = $this->tenant($request);
        $customer = $this->customer($request);
        $dependents = $tenant->dependents();

        \App\Support\Confirmations::process($tenant);

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
                'must_change_password' => (bool) $customer->must_change_password,
            ],
            'policies'     => $this->policies($tenant),
            'look'         => \App\Support\PortalLook::forPortal($tenant),
            'privacy_accepted_at' => self::privacyAccepted($customer->id),
            'consents'     => \App\Support\Messaging::ready() ? MessageController::consentsOf($customer->id) : null,
            'booking_block' => ($state = \App\Support\OnlineBooking::state($tenant, $customer->id))
                ? $state + ['message' => $state['blocked'] ? \App\Support\OnlineBooking::blockedMessage($tenant, $state) : null]
                : null,
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

    public function catalog(Request $request)
    {
        $tenant = $this->tenant($request);
        $this->assertBookingEnabled($tenant);

        $assigned = DB::table('service_professionals')->where('tenant_id', $tenant->id)->get()->groupBy('service_id');
        $worksAt = ProfessionalSchedule::where('tenant_id', $tenant->id)->get()->groupBy('professional_id');
        $depositRule = (bool) AppointmentFlow::rule($tenant, 'booking.require_deposit') && \App\Support\PaymentLinks::ready();
        $customer = $this->customer($request);
        $packaged = \App\Support\Packages::linkable($tenant)
            ? collect(\App\Support\Packages::presentMany(DB::table('customer_packages')->where('tenant_id', $tenant->id)->where('customer_id', $customer->id)->where('status', 'active')->where('expires_at', '>', now()->toIso8601String())->get(), false))
                ->flatMap(fn (array $p) => collect($p['items'])->filter(fn ($i) => $i['available'] > 0)->map(fn ($i) => ['service_id' => $i['service_id'], 'available' => $i['available'], 'code' => $p['code'], 'name' => $p['name']]))
                ->groupBy('service_id')
            : collect();

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
                    'branch_ids'       => $s->branch_ids,
                    'package'          => ($found = $packaged->get($s->id)) ? ['code' => $found->first()['code'], 'name' => $found->first()['name'], 'available' => $found->sum('available')] : null,
                    'needs_deposit'    => !$packaged->has($s->id) && $depositRule && $s->requires_deposit && \App\Support\PaymentLinks::depositAmount($s) > 0,
                    'deposit_amount'   => $depositRule && $s->requires_deposit ? \App\Support\PaymentLinks::depositAmount($s) : 0,
                    'deposit_label'    => $depositRule && $s->requires_deposit ? \App\Support\PaymentLinks::depositLabel($s) : null,
                    'professional_ids' => ($assigned[$s->id] ?? collect())->pluck('professional_id')->values(),
                ])->values(),
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

        $ignoreId = empty($validated['ignore_id']) ? null : $this->ownAppointment($request, $validated['ignore_id'])->id;

        $date = Carbon::createFromFormat('Y-m-d', $validated['date'], AppointmentScheduler::timezone($branch, $tenant));
        $groups = (new AppointmentScheduler($tenant, true))->slots($service, $branch, $date, $professional, $ignoreId);

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
            'waitlist_id'           => 'nullable|uuid',
        ]);

        $strikes = \App\Support\OnlineBooking::state($tenant, $customer->id);

        if ($strikes && $strikes['blocked']) {
            return response()->json(['message' => \App\Support\OnlineBooking::blockedMessage($tenant, $strikes), 'code' => 'BOOKING_BLOCKED'], 403);
        }

        $service = Service::where('tenant_id', $tenant->id)->with('status')->findOrFail($validated['service_id']);
        $branch = Branch::where('tenant_id', $tenant->id)->where('status', 'active')->findOrFail($validated['branch_id']);

        if ($service->kind === Service::KIND_GROUP || !$service->online_booking) {
            throw ValidationException::withMessages(['service_id' => 'Este servicio no se reserva en línea. Comunícate con la sede.']);
        }

        $start = Carbon::createFromFormat(self::LOCAL_FORMAT, $validated['starts_at'], AppointmentScheduler::timezone($branch, $tenant))->startOfMinute();
        $package = \App\Support\Packages::linkable($tenant) ? (\App\Support\Packages::usable($tenant->id, $customer->id, $service->id, $start)[0] ?? null) : null;
        $needsDeposit = !$package && $service->requires_deposit && AppointmentFlow::rule($tenant, 'booking.require_deposit') && \App\Support\PaymentLinks::depositAmount($service) > 0;

        if ($needsDeposit && !\App\Support\PaymentLinks::ready()) {
            throw ValidationException::withMessages(['service_id' => 'Este servicio se reserva con un adelanto. Comunícate con la sede para agendarlo.']);
        }

        $dependentId = $this->resolveDependent($tenant, $customer, $validated['customer_dependent_id'] ?? null);

        $duration = (int) $service->duration_minutes;
        $end = $start->copy()->addMinutes($duration);

        return DB::transaction(function () use ($tenant, $user, $customer, $validated, $service, $branch, $dependentId, $start, $end, $duration, $needsDeposit, $package) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['appointments:' . $tenant->id]);

            if ($response = $this->limitReached($tenant, $start)) {
                return response()->json(['message' => 'No hay cupos en línea para esa fecha. Comunícate con la sede.', 'code' => 'UNAVAILABLE'], 422);
            }

            $scheduler = new AppointmentScheduler($tenant, true);
            [$professional, $check] = $this->pickProfessional($tenant, $scheduler, $service, $branch, $start, $duration, $validated['professional_id'] ?? null);

            if (!$professional) {
                return $this->unavailableForCustomer($check);
            }

            $this->assertCustomerFree($tenant, $customer, $start, $end);

            if ($needsDeposit) {
                $link = \App\Support\PaymentLinks::forDeposit($tenant, $customer, $service, $branch, $professional, $start, $end, $dependentId, $validated['notes'] ?? null);

                return response()->json([
                    'deposit' => \App\Support\PaymentLinks::present($link) + [
                        'token'   => $link->token,
                        'minutes' => max(5, (int) AppointmentFlow::rule($tenant, 'booking.deposit_minutes')),
                    ],
                ], 202);
            }

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
            ] + Currency::column($tenant) + [
                'notes'                 => $validated['notes'] ?? null,
                'confirmed_at'          => $status === AppointmentFlow::CONFIRMED ? now() : null,
                'created_by'            => null,
            ]);

            $this->log($appointment, null, $status, $user, 'Reserva en línea');
            $this->auditAppointment(request(), 'appointment.created', $appointment, $this->auditFacts($appointment));

            if ($package) {
                \App\Support\Packages::link($tenant, $appointment->load('service'), $package['id'], null);
            }

            if (!empty($validated['waitlist_id'])) {
                $waiting = WaitlistEntry::where('tenant_id', $tenant->id)
                    ->where('customer_id', $customer->id)
                    ->whereIn('status', WaitlistEntry::OPEN)
                    ->find($validated['waitlist_id']);

                if ($waiting) {
                    \App\Support\Waitlist::book($waiting, $appointment);
                }
            }

            return response()->json($this->presentForCustomer($appointment->fresh(self::RELATIONS), $tenant), 201);
        });
    }

    private function depositOf(Tenant $tenant, Appointment $appointment): ?array
    {
        if (!\App\Support\PaymentLinks::ready() || $appointment->source !== 'online') {
            return null;
        }

        $sale = \App\Support\Sales::ofAppointment($tenant->id, $appointment->id);
        $paid = $sale ? \App\Support\PaymentLinks::depositPaid($sale->id) : 0.0;

        if ($paid <= 0) {
            return null;
        }

        return [
            'amount'      => $paid,
            'currency'    => $sale->currency ?? Currency::of($tenant),
            'balance'     => round((float) $sale->balance, 2),
            'outcome'     => $sale->deposit_outcome,
            'refund_hours' => (int) AppointmentFlow::rule($tenant, 'booking.deposit_refund_hours'),
        ];
    }

    public function paymentLinks(Request $request)
    {
        $tenant = $this->tenant($request);
        $customer = $this->customer($request);

        return response()->json(['links' => \App\Support\PaymentLinks::forCustomer($tenant->id, $customer->id)]);
    }

    public function cancelPaymentLink(Request $request, string $link)
    {
        $tenant = $this->tenant($request);
        $customer = $this->customer($request);
        $row = DB::table('payment_links')->where('tenant_id', $tenant->id)->where('customer_id', $customer->id)->whereIn('purpose', ['deposit', 'sale'])->where('id', $link)->first();
        $online = $row && $row->purpose === 'sale' && $row->sale_id && DB::table('sales')->where('id', $row->sale_id)->value('notes') === \App\Support\Packages::ONLINE_NOTE;
        abort_unless($row && ($row->purpose === 'deposit' || $online), 404);

        \App\Support\PaymentLinks::cancel($row, $online ? 'El cliente decidió no comprar el paquete.' : 'El cliente decidió no reservar.');

        if ($online) {
            \App\Support\PaymentLinks::dropAbandoned($tenant->id);
        }

        return response()->json(['links' => \App\Support\PaymentLinks::forCustomer($tenant->id, $customer->id)]);
    }

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
        $late = $this->insideWindow($tenant, $appointment);

        $appointment->update([
            'status'        => AppointmentFlow::CANCELLED,
            'cancelled_at'  => now(),
            'cancel_reason' => $reason,
            'cancelled_by'  => 'customer',
        ]);
        $this->log($appointment, $from, AppointmentFlow::CANCELLED, $request->user(), $reason);
        $this->auditAppointment($request, 'appointment.cancelled', $appointment, $this->auditFacts($appointment) + ['reason' => $reason, 'late_cancel' => $late]);
        \App\Support\Recalls::appointmentChanged($tenant, $appointment);
        \App\Support\Waitlist::appointmentChanged($tenant, $appointment);
        \App\Support\Walkin::appointmentChanged($tenant, $appointment);

        return response()->json($this->presentForCustomer($appointment, $tenant));
    }

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

            $scheduler = new AppointmentScheduler($tenant, true);
            [$professional, $check] = $this->pickProfessional(
                $tenant, $scheduler, $service, $branch, $start, $duration,
                $validated['professional_id'] ?? $appointment->professional_id, $appointment->id,
            );

            if (!$professional) {
                return $this->unavailableForCustomer($check, 'Tu cita sigue como estaba. ');
            }

            $this->assertCustomerFree($tenant, $appointment->customer, $start, $end, $appointment->id);
            $auditBefore = $this->auditPlace($appointment);

            $appointment->update([
                'starts_at'        => $start,
                'ends_at'          => $end,
                'professional_id'  => $professional->id,
                'reschedule_count' => $appointment->reschedule_count + 1,
            ]);

            $this->log($appointment, $appointment->status, $appointment->status, $user,
                'Reprogramada por el cliente de ' . $previous->format('d/m/Y H:i') . ' a ' . $start->format('d/m/Y H:i'));
            $this->auditRescheduled($request, $appointment->fresh(self::RELATIONS), $auditBefore, null);

            return response()->json($this->presentForCustomer($appointment->fresh(self::RELATIONS), $tenant));
        });
    }

    public function waitlist(Request $request)
    {
        $tenant = $this->tenant($request);
        $customer = $this->customer($request);

        \App\Support\Waitlist::expire($tenant);

        $entries = WaitlistEntry::where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->whereIn('status', WaitlistEntry::OPEN)
            ->with(['service.status', 'professional.status', 'branch', 'dependent'])
            ->orderBy('created_at')
            ->get();

        return response()->json($entries->map(fn (WaitlistEntry $entry) => $this->presentWaiting($tenant, $entry))->values());
    }

    public function joinWaitlist(Request $request)
    {
        $tenant = $this->tenant($request);
        $this->assertBookingEnabled($tenant);
        $customer = $this->customer($request);

        $validated = $request->validate([
            'service_id'            => 'required|uuid',
            'branch_id'             => 'required|uuid',
            'professional_id'       => 'nullable|uuid',
            'customer_dependent_id' => 'nullable|uuid',
            'time_of_day'           => 'nullable|in:any,morning,afternoon',
            'within_days'           => 'nullable|integer|min:1|max:120',
            'notes'                 => 'nullable|string|max:500',
        ]);

        $service = Service::where('tenant_id', $tenant->id)->with('status')->findOrFail($validated['service_id']);
        $branch = Branch::where('tenant_id', $tenant->id)->where('status', 'active')->findOrFail($validated['branch_id']);

        if ($service->kind === Service::KIND_GROUP || !$service->online_booking || $service->status?->slug === 'inactive') {
            throw ValidationException::withMessages(['service_id' => 'Este servicio no se reserva en línea. Comunícate con la sede.']);
        }

        $professional = !empty($validated['professional_id'])
            ? Professional::where('tenant_id', $tenant->id)->findOrFail($validated['professional_id'])
            : null;

        $dependentId = $this->resolveDependent($tenant, $customer, $validated['customer_dependent_id'] ?? null);

        $duplicate = WaitlistEntry::where('tenant_id', $tenant->id)
            ->whereIn('status', WaitlistEntry::OPEN)
            ->where('customer_id', $customer->id)
            ->where('service_id', $service->id)
            ->where('branch_id', $branch->id)
            ->where(fn ($q) => $dependentId ? $q->where('customer_dependent_id', $dependentId) : $q->whereNull('customer_dependent_id'))
            ->exists();

        if ($duplicate) {
            return response()->json(['message' => 'Ya estás en la lista de espera para este servicio en esta sede.', 'code' => 'DUPLICATE'], 409);
        }

        $today = now(AppointmentScheduler::timezone($branch, $tenant))->startOfDay();
        [$timeFrom, $timeTo] = match ($validated['time_of_day'] ?? 'any') {
            'morning'   => [null, '13:00'],
            'afternoon' => ['13:00', null],
            default     => [null, null],
        };

        $entry = WaitlistEntry::create([
            'tenant_id'             => $tenant->id,
            'branch_id'             => $branch->id,
            'customer_id'           => $customer->id,
            'customer_dependent_id' => $dependentId,
            'service_id'            => $service->id,
            'professional_id'       => $professional?->id,
            'date_from'             => $today->format('Y-m-d'),
            'date_to'               => !empty($validated['within_days']) ? $today->copy()->addDays((int) $validated['within_days'])->format('Y-m-d') : null,
            'time_from'             => $timeFrom,
            'time_to'               => $timeTo,
            'notes'                 => trim((string) ($validated['notes'] ?? '')) ?: 'Se anotó desde el portal.',
            'status'                => WaitlistEntry::WAITING,
            'created_by'            => null,
        ]);

        return response()->json($this->presentWaiting($tenant, $entry->fresh(['service.status', 'professional.status', 'branch', 'dependent'])), 201);
    }

    public function leaveWaitlist(Request $request, string $entry)
    {
        $tenant = $this->tenant($request);
        $customer = $this->customer($request);

        $entry = WaitlistEntry::where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->whereIn('status', WaitlistEntry::OPEN)
            ->findOrFail($entry);

        $entry->update(['status' => WaitlistEntry::CANCELLED]);
        $entry->loadMissing(['service', 'branch']);
        \App\Support\Audit::log($request, 'waitlist.withdrawn', [
            'tenant'       => $tenant->id,
            'entity_type'  => 'waitlist_entry',
            'entity_id'    => $entry->id,
            'entity_label' => \App\Support\Person::name($customer),
            'branch_id'    => $entry->branch_id,
            'after'        => array_filter(['service' => $entry->service?->name, 'branch' => $entry->branch?->name]),
        ]);

        return response()->json(['ok' => true]);
    }

    private function presentWaiting(Tenant $tenant, WaitlistEntry $entry): array
    {
        $found = $entry->service && $entry->branch
            ? \App\Support\Waitlist::slots($tenant, $entry, 3)
            : ['days' => []];

        $timezone = $entry->branch ? AppointmentScheduler::timezone($entry->branch, $tenant) : ($tenant->zone());
        $duration = (int) ($entry->service?->duration_minutes ?? 0);
        $own = Appointment::where('tenant_id', $tenant->id)
            ->where('customer_id', $entry->customer_id)
            ->whereNotIn('status', AppointmentFlow::RELEASED)
            ->where('ends_at', '>', now()->toIso8601String())
            ->get(['starts_at', 'ends_at']);

        $isFree = function (string $date, string $slot) use ($own, $timezone, $duration): bool {
            $start = Carbon::createFromFormat('Y-m-d H:i', "{$date} {$slot}", $timezone);
            $end = $start->copy()->addMinutes($duration);

            return !$own->contains(fn (Appointment $a) => $a->starts_at->lt($end) && $a->ends_at->gt($start));
        };

        $found['days'] = collect($found['days'])
            ->map(function (array $day) use ($isFree) {
                $day['groups'] = collect($day['groups'])
                    ->map(function (array $group) use ($day, $isFree) {
                        $group['slots'] = array_values(array_filter($group['slots'], fn (string $slot) => $isFree($day['date'], $slot)));

                        return $group;
                    })
                    ->filter(fn (array $group) => $group['slots'] !== [])
                    ->values()
                    ->all();

                return $day;
            })
            ->filter(fn (array $day) => $day['groups'] !== [])
            ->values()
            ->all();

        return [
            'id'           => $entry->id,
            'service'      => $entry->service ? ['id' => $entry->service->id, 'name' => $entry->service->name] : null,
            'professional' => $entry->professional ? ['id' => $entry->professional->id, 'display_name' => $entry->professional->display_name] : null,
            'branch'       => $entry->branch ? ['id' => $entry->branch->id, 'name' => $entry->branch->name] : null,
            'dependent'    => $entry->dependent ? ['id' => $entry->dependent->id, 'name' => $entry->dependent->name] : null,
            'date_from'    => $entry->date_from?->format('Y-m-d'),
            'date_to'      => $entry->date_to?->format('Y-m-d'),
            'time_from'    => $entry->time_from ? substr($entry->time_from, 0, 5) : null,
            'time_to'      => $entry->time_to ? substr($entry->time_to, 0, 5) : null,
            'created_at'   => $entry->created_at?->format('Y-m-d'),
            'days'         => collect($found['days'])->map(fn (array $day) => [
                'date'   => $day['date'],
                'groups' => collect($day['groups'])->map(fn (array $group) => [
                    'professional' => ['id' => $group['professional']['id'], 'display_name' => $group['professional']['display_name']],
                    'slots'        => $group['slots'],
                ])->values(),
            ])->values(),
        ];
    }

    public function updatePassword(Request $request)
    {
        $customer = $this->customer($request);

        $validated = $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|max:72|confirmed',
        ], [
            'current_password.required' => 'Escribe tu contraseña actual.',
            'password.required'         => 'Escribe tu nueva contraseña.',
            'password.min'              => 'La nueva contraseña debe tener al menos 8 caracteres.',
            'password.confirmed'        => 'Las dos contraseñas no coinciden.',
        ]);

        if (!Hash::check($validated['current_password'], $customer->password_hash)) {
            throw ValidationException::withMessages(['current_password' => 'Tu contraseña actual no es correcta.']);
        }

        if ($validated['password'] === trim((string) $customer->document_number)) {
            throw ValidationException::withMessages(['password' => 'Elige una contraseña distinta de tu número de documento.']);
        }

        $customer->forceFill([
            'password_hash'        => Hash::make($validated['password']),
            'must_change_password' => false,
            'password_changed_at'  => now(),
        ])->save();

        $customer->tokens()->where('id', '!=', $customer->currentAccessToken()->id)->delete();

        return response()->json(['message' => 'Tu contraseña quedó guardada.']);
    }

    public function updateProfile(Request $request)
    {
        $customer = $this->customer($request);

        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+ ()-]{6,30}$/'],
        ], ['phone.regex' => 'Escribe un número de celular válido.', 'phone.required' => 'Escribe tu celular.']);

        $previous = $customer->phone;
        $customer->update(['phone' => trim($validated['phone'])]);

        if ($previous !== $customer->phone) {
            \App\Support\Audit::log($request, 'customer.updated', [
                'tenant'       => $customer->tenant_id,
                'entity_type'  => 'customer',
                'entity_id'    => $customer->id,
                'entity_label' => \App\Support\Person::name($customer),
                'before'       => ['phone' => $previous],
                'after'        => ['phone' => $customer->phone],
            ]);
        }

        return response()->json(['phone' => $customer->phone]);
    }

    public function storeDependent(Request $request)
    {
        $tenant = $this->tenant($request);
        $customer = $this->customer($request);
        $config = $tenant->dependents();

        abort_unless($config['enabled'], 403, 'Esta empresa no usa este registro.');

        $validated = $request->validate(CustomerDependent::rules());

        $dependent = $customer->dependents()->create($validated + ['tenant_id' => $tenant->id, 'kind' => $config['kind']]);
        CustomerDependentController::audit($request, 'customer_dependent.created', $customer, $dependent, [], array_filter(CustomerDependentController::auditable($dependent)));

        return response()->json($dependent->only(['id', 'name', 'species', 'breed']), 201);
    }

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

    private function pickProfessional(Tenant $tenant, AppointmentScheduler $scheduler, Service $service, Branch $branch, Carbon $start, int $duration, ?string $professionalId, ?string $ignoreId = null): array
    {
        $candidates = Professional::where('tenant_id', $tenant->id)->with('status')
            ->when($professionalId && AppointmentFlow::rule($tenant, 'booking.choose_professional'), fn ($q) => $q->where('id', $professionalId))
            ->whereIn('id', ProfessionalSchedule::where('branch_id', $branch->id)->where('weekday', $start->dayOfWeek)->select('professional_id'))
            ->orderBy('display_name')
            ->get();

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

    public function updateConsents(Request $request)
    {
        $tenant = $this->tenant($request);
        $customer = $this->customer($request);
        abort_unless(\App\Support\Messaging::ready(), 503, \App\Support\Messaging::NOT_READY);
        $validated = $request->validate(['email' => 'nullable|boolean', 'whatsapp' => 'nullable|boolean']);

        return response()->json(['consents' => MessageController::saveConsents($tenant->id, $customer->id, $validated, 'portal')]);
    }

    public static function privacyAccepted(string $customerId): ?string
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('customer_consents')) {
            return null;
        }

        $row = DB::table('customer_consents')->where('customer_id', $customerId)->where('channel', 'data_processing')->where('granted', true)->first();

        return $row?->granted_at ? Carbon::parse($row->granted_at)->toIso8601String() : null;
    }

    public static function acceptPrivacy(string $tenantId, string $customerId, string $source): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('customer_consents')) {
            return;
        }

        DB::table('customer_consents')->updateOrInsert(
            ['customer_id' => $customerId, 'channel' => 'data_processing'],
            ['tenant_id' => $tenantId, 'granted' => true, 'source' => $source, 'granted_at' => now(), 'revoked_at' => null, 'updated_at' => now()],
        );
    }

    public function privacy(Request $request)
    {
        $tenant = $this->tenant($request);

        return response()->json(\App\Support\PortalLook::privacyHtml($tenant) + [
            'accepted_at' => self::privacyAccepted($this->customer($request)->id),
        ]);
    }

    public function acceptPrivacyNow(Request $request)
    {
        $customer = $this->customer($request);
        self::acceptPrivacy($this->tenant($request)->id, $customer->id, 'portal');

        return response()->json(['accepted_at' => self::privacyAccepted($customer->id)]);
    }

    public function packages(Request $request)
    {
        $tenant = $this->tenant($request);
        $customer = $this->customer($request);
        abort_unless(\App\Support\Packages::ready(), 503, \App\Support\Packages::NOT_READY);
        \App\Support\Packages::expireDue($tenant->id);

        $rows = DB::table('customer_packages')->where('tenant_id', $tenant->id)->where('customer_id', $customer->id)->orderByDesc('purchased_at')->get();

        return response()->json(['packages' => collect(\App\Support\Packages::presentMany($rows, false))->map(function (array $p) use ($tenant) {
            $c = $p['conditions'];
            $items = array_map(fn ($i) => ['name' => $i['name'], 'sessions' => $i['total'], 'price' => $i['unit_price']], $p['items']);

            return [
                'id'                 => $p['id'],
                'code'               => $p['code'],
                'name'               => $p['name'],
                'status'             => $p['status'],
                'status_label'       => $p['status_label'],
                'price'              => $p['price'],
                'currency'           => $p['currency'],
                'purchased_at'       => $p['purchased_at'],
                'expires_at'         => $p['expires_at'],
                'days_left'          => $p['days_left'],
                'items'              => $p['items'],
                'sessions_total'     => $p['sessions_total'],
                'sessions_used'      => $p['sessions_used'],
                'sessions_scheduled' => $p['sessions_scheduled'],
                'sessions_available' => $p['sessions_available'],
                'schedule_label'     => $p['schedule_label'],
                'terms_text'         => $c['terms_text'] ?? \App\Support\Packages::terms($tenant, $c, $items, $p['currency']),
            ];
        })->values()]);
    }

    private function presentForCustomer(Appointment $appointment, Tenant $tenant): array
    {
        $full = $this->present($appointment, $tenant);
        $policies = $this->policies($tenant);
        $upcoming = $appointment->starts_at->isFuture() && in_array($appointment->status, [AppointmentFlow::PENDING, AppointmentFlow::CONFIRMED], true);
        $open = in_array($appointment->status, AppointmentFlow::RESCHEDULABLE, true)
            && $appointment->starts_at->isFuture()
            && !$this->insideWindow($tenant, $appointment);
        $package = \App\Support\Packages::ready() && $appointment->customer_package_id
            ? DB::table('customer_packages')->where('id', $appointment->customer_package_id)->first()
            : null;

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
            'confirm_by'       => $appointment->status === AppointmentFlow::PENDING ? $full['confirm_by'] : null,
            'confirmation_overdue' => $full['confirmation_overdue'],
            'is_upcoming'      => $appointment->starts_at->isFuture() && in_array($appointment->status, [AppointmentFlow::PENDING, AppointmentFlow::CONFIRMED], true),
            'is_virtual'       => (bool) $appointment->service?->is_virtual,
            'pay_on_arrival'   => $upcoming && (float) $appointment->price > 0 && $policies['pay_before_service']
                && !\App\Support\Sales::appointmentPaid($tenant->id, $appointment->id),
            'deposit'          => $this->depositOf($tenant, $appointment),
            'package'          => $package ? \App\Support\Packages::summary($package) + ['late_policy' => \App\Support\Packages::conditions($package)['late_policy']] : null,
            'can_confirm'      => $policies['booking_enabled'] && $appointment->status === AppointmentFlow::PENDING && $appointment->starts_at->isFuture(),
            'can_cancel'       => $policies['booking_enabled'] && $open && $policies['cancel_allowed'],
            'can_reschedule'   => $policies['booking_enabled'] && $open && $appointment->reschedule_count < $policies['reschedule_max'],
        ];
    }
}
