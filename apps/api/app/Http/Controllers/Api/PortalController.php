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

    protected function policies(Tenant $tenant): array
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
            'deposit_on_cancel'  => \App\Support\PaymentLinks::onCancel($tenant),
            'miss'               => \App\Support\OnlineBooking::policy($tenant),
            'waitlist_enabled'   => \App\Support\Waitlist::enabled($tenant),
            'late_fee_percent'   => \App\Support\Penalties::enabled($tenant) ? \App\Support\Penalties::rules($tenant)['late_cancel_percent'] : 0,
            'noshow_fee_percent' => \App\Support\Penalties::enabled($tenant) ? \App\Support\Penalties::rules($tenant)['no_show_percent'] : 0,
            'cancel_reason_required' => (bool) $rule('cancel.require_reason'),
        ];
    }

    protected function assertBookingEnabled(Tenant $tenant): void
    {
        abort_unless(AppointmentFlow::rule($tenant, 'booking.enabled'), 403, 'La reserva en línea no está disponible. Comunícate con la sede.');
    }

    protected function ownAppointment(Request $request, string $id): Appointment
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
        \App\Support\Waitlist::advance($tenant);

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
                'birth_date' => $customer->birth_date?->format('Y-m-d'),
                'district'   => $customer->district,
                'must_change_password' => (bool) $customer->must_change_password,
            ],
            'policies'     => $this->policies($tenant),
            'modules'      => collect(['classes', 'memberships', 'quotes', 'packages'])->filter(fn (string $m) => $tenant->hasModule($m))->values(),
            'look'       => \App\Support\PortalLook::forPortal($tenant),
            'privacy_accepted_at' => self::privacyAccepted($customer->id),
            'consents'     => \App\Support\Messaging::ready() ? self::portalConsents($customer->id) : null,
            'booking_block' => ($state = \App\Support\OnlineBooking::state($tenant, $customer->id))
                ? $state + ['message' => $state['blocked'] ? \App\Support\OnlineBooking::blockedMessage($tenant, $state) : (($state['deposit_required'] ?? false) ? \App\Support\OnlineBooking::depositMessage($tenant, $state) : null)]
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
        $customer = $request->user() instanceof Customer ? $request->user() : null;
        $packaged = $customer && \App\Support\Packages::linkable($tenant)
            ? collect(\App\Support\Packages::presentMany(DB::table('customer_packages')->where('tenant_id', $tenant->id)->where('customer_id', $customer->id)->where('status', 'active')->where('expires_at', '>', now()->toIso8601String())->get(), false))
                ->flatMap(fn (array $p) => collect($p['items'])->filter(fn ($i) => $i['available'] > 0)->map(fn ($i) => ['service_id' => $i['service_id'], 'available' => $i['available'], 'code' => $p['code'], 'name' => $p['name']]))
                ->groupBy('service_id')
            : collect();
        $strike = $customer && (\App\Support\OnlineBooking::state($tenant, $customer->id)['deposit_required'] ?? false) && \App\Support\PaymentLinks::available($tenant);
        $depositAt = fn (Service $s, ?string $branchId) => $depositRule && $s->requires_deposit && \App\Support\PaymentLinks::depositAmount($s, $branchId) > 0
            ? \App\Support\PaymentLinks::depositAmount($s, $branchId)
            : ($strike ? \App\Support\OnlineBooking::strikeDeposit($tenant, $customer->id, $s, $branchId) : 0);

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
                    'description'      => $s->description ? mb_substr(trim((string) $s->description), 0, 200) : null,
                    'duration_minutes' => $s->duration_minutes,
                    'price'            => $s->price,
                    'is_virtual'       => $s->is_virtual,
                    'branch_ids'       => $s->branch_ids,
                    'package'          => ($found = $packaged->get($s->id)) ? ['code' => $found->first()['code'], 'name' => $found->first()['name'], 'available' => $found->sum('available')] : null,
                    'branch_prices'    => (object) $s->branchPrices(),
                    'needs_deposit'    => !$packaged->has($s->id) && (($depositRule && $s->requires_deposit && \App\Support\PaymentLinks::depositAmount($s) > 0) || $strike),
                    'deposit_amount'   => $depositAt($s, null),
                    'deposit_amounts'  => (object) collect($s->branchPrices())->map(fn ($price, $branchId) => $depositAt($s, (string) $branchId))->all(),
                    'deposit_label'    => $depositRule && $s->requires_deposit ? \App\Support\PaymentLinks::depositLabel($s) : null,
                    'deposit_for_misses' => $strike && !($depositRule && $s->requires_deposit && \App\Support\PaymentLinks::depositAmount($s) > 0),
                    'professional_ids' => ($assigned[$s->id] ?? collect())->pluck('professional_id')->values(),
                ])->values(),
            'professionals' => Professional::where('tenant_id', $tenant->id)
                ->whereIn('id', $worksAt->keys())
                ->when(\App\Support\PublicBooking::professionalsFlag(), fn ($q) => $q->where('online_booking', true))
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
        $groups = (new AppointmentScheduler($tenant, true))->ignoringHoldsOf($request->user() instanceof Customer ? $request->user()->id : null)->slots($service, $branch, $date, $professional, $ignoreId);

        $times = collect($groups)->flatMap(fn (array $group) => $group['slots'])->unique()->sort()->values();

        return response()->json([
            'date'          => $validated['date'],
            'times'         => $times,
            'professionals' => AppointmentFlow::rule($tenant, 'booking.choose_professional') ? $groups : [],
        ]);
    }

    public function portalDays(Request $request)
    {
        $tenant = $this->tenant($request);
        $this->assertBookingEnabled($tenant);

        $validated = $request->validate([
            'service_id'      => 'required|uuid',
            'branch_id'       => 'required|uuid',
            'from'            => 'required|date_format:Y-m-d',
            'days'            => 'nullable|integer|min:1|max:14',
            'professional_id' => 'nullable|uuid',
            'ignore_id'       => 'nullable|uuid',
        ]);

        $service = Service::where('tenant_id', $tenant->id)->with('status')->findOrFail($validated['service_id']);
        $branch = Branch::where('tenant_id', $tenant->id)->findOrFail($validated['branch_id']);
        $professional = empty($validated['professional_id']) || !AppointmentFlow::rule($tenant, 'booking.choose_professional')
            ? null
            : Professional::where('tenant_id', $tenant->id)->with('status')->findOrFail($validated['professional_id']);
        $ignoreId = empty($validated['ignore_id']) ? null : $this->ownAppointment($request, $validated['ignore_id'])->id;

        $zone = AppointmentScheduler::timezone($branch, $tenant);
        $today = Carbon::now($zone)->startOfDay();
        $last = $today->copy()->addDays(max(1, (int) AppointmentFlow::rule($tenant, 'booking.max_days_ahead')));
        $scheduler = (new AppointmentScheduler($tenant, true))->ignoringHoldsOf($request->user() instanceof Customer ? $request->user()->id : null);
        $weekdays = ProfessionalSchedule::where('tenant_id', $tenant->id)->where('branch_id', $branch->id)
            ->when($professional, fn ($q) => $q->where('professional_id', $professional->id))
            ->pluck('weekday')->map(fn ($d) => (int) $d)->unique()->all();
        $start = Carbon::createFromFormat('Y-m-d', $validated['from'], $zone)->startOfDay();

        $days = collect(range(0, ($validated['days'] ?? 7) - 1))->map(function (int $offset) use ($start, $today, $last, $scheduler, $service, $branch, $professional, $ignoreId, $tenant, $weekdays) {
            $date = $start->copy()->addDays($offset);

            if ($date->lt($today) || $date->gt($last)) {
                return ['date' => $date->toDateString(), 'free' => 0, 'state' => 'out'];
            }

            $opens = in_array($date->dayOfWeek, $weekdays, true) && \App\Support\PublicHours::opensOn($tenant, $branch, $date);
            $free = $opens
                ? collect($scheduler->slots($service, $branch, $date->copy(), $professional, $ignoreId))->flatMap(fn (array $group) => $group['slots'])->unique()->count()
                : 0;

            return ['date' => $date->toDateString(), 'free' => $free, 'state' => $free > 0 ? 'free' : ($opens ? 'full' : 'closed')];
        })->values();

        return response()->json(['days' => $days]);
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

        \App\Support\Channels::remember('portal');

        return $this->placeBooking($tenant, $customer, $validated);
    }

    protected function placeBooking(Tenant $tenant, Customer $customer, array $validated, ?string $staffNote = null, ?array $newDependent = null)
    {
        $user = $customer;
        $service = Service::where('tenant_id', $tenant->id)->with('status')->findOrFail($validated['service_id']);
        $branch = Branch::where('tenant_id', $tenant->id)->where('status', 'active')->findOrFail($validated['branch_id']);

        if ($service->kind === Service::KIND_GROUP || !$service->online_booking) {
            throw ValidationException::withMessages(['service_id' => 'Este servicio no se reserva en línea. Comunícate con la sede.']);
        }

        $start = Carbon::createFromFormat(self::LOCAL_FORMAT, $validated['starts_at'], AppointmentScheduler::timezone($branch, $tenant))->startOfMinute();
        $package = \App\Support\Packages::linkable($tenant) ? (\App\Support\Packages::usable($tenant->id, $customer->id, $service->id, $start)[0] ?? null) : null;
        $strikeDeposit = $package ? 0.0 : \App\Support\OnlineBooking::strikeDeposit($tenant, $customer->id, $service, $branch->id);
        $needsDeposit = !$package && (($service->requires_deposit && AppointmentFlow::rule($tenant, 'booking.require_deposit') && \App\Support\PaymentLinks::depositAmount($service, $branch->id) > 0) || $strikeDeposit > 0);

        if ($strikeDeposit > 0 && !\App\Support\PaymentLinks::available($tenant)) {
            throw ValidationException::withMessages(['service_id' => 'Por inasistencias anteriores, tu próxima reserva en línea lleva un adelanto, y por ahora no se puede pagar en línea. Comunícate con la sede para agendar.']);
        }

        if ($needsDeposit && !\App\Support\PaymentLinks::available($tenant)) {
            throw ValidationException::withMessages(['service_id' => 'Este servicio se reserva con un adelanto. Comunícate con la sede para agendarlo.']);
        }

        $dependentId = $newDependent ? null : $this->resolveDependent($tenant, $customer, $validated['customer_dependent_id'] ?? null);

        $duration = (int) $service->duration_minutes;
        $end = $start->copy()->addMinutes($duration);

        return DB::transaction(function () use ($tenant, $user, $customer, $validated, $service, $branch, $dependentId, $start, $end, $duration, $needsDeposit, $package, $staffNote, $newDependent, $strikeDeposit) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['appointments:' . $tenant->id]);

            if ($newDependent) {
                $dependentId = \App\Support\PublicBooking::dependentFor($tenant, $customer, $newDependent);
            }

            $validated['notes'] = trim(implode("\n", array_filter([$validated['notes'] ?? null, $staffNote]))) ?: null;

            if ($response = $this->limitReached($tenant, $start)) {
                return response()->json(['message' => 'No hay cupos en línea para esa fecha. Comunícate con la sede.', 'code' => 'UNAVAILABLE'], 422);
            }

            $scheduler = (new AppointmentScheduler($tenant, true))->ignoringHoldsOf($customer->id);
            [$professional, $check] = $this->pickProfessional($tenant, $scheduler, $service, $branch, $start, $duration, $validated['professional_id'] ?? null);

            if (!$professional) {
                return $this->unavailableForCustomer($check);
            }

            $this->assertCustomerFree($tenant, $customer, $start, $end);

            if ($needsDeposit) {
                $link = \App\Support\PaymentLinks::forDeposit($tenant, $customer, $service, $branch, $professional, $start, $end, $dependentId, $validated['notes'] ?? null, $strikeDeposit > 0 && \App\Support\PaymentLinks::depositAmount($service, $branch->id) <= 0 ? $strikeDeposit : null);

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
                'price'                 => $service->priceAt($branch->id),
            ] + Currency::column($tenant) + \App\Support\Channels::column() + [
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
            'on_cancel'   => \App\Support\PaymentLinks::onCancel($tenant),
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

        $reason = trim((string) ($validated['reason'] ?? '')) ?: 'Cancelada por el cliente';
        $response = $this->applyStatus($request, $appointment->id, $tenant, $request->user(), AppointmentFlow::CANCELLED, $reason, true, 'customer');

        if ($response->getStatusCode() >= 400) {
            return $response;
        }

        return response()->json($this->presentForCustomer($this->ownAppointment($request, $appointment->id), $tenant));
    }

    protected function find(Request $request, string $id): Appointment
    {
        return $this->ownAppointment($request, $id);
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
            $freed = $this->freedSlot($appointment);

            $appointment->update([
                'starts_at'        => $start,
                'ends_at'          => $end,
                'professional_id'  => $professional->id,
                'reschedule_count' => $appointment->reschedule_count + 1,
            ]);

            $this->log($appointment, $appointment->status, $appointment->status, $user,
                'Reprogramada por el cliente de ' . $previous->format('d/m/Y H:i') . ' a ' . $start->format('d/m/Y H:i'));
            $this->auditRescheduled($request, $appointment->fresh(self::RELATIONS), $auditBefore, null);
            $this->offerFreedSlot($tenant, $freed);

            return response()->json($this->presentForCustomer($appointment->fresh(self::RELATIONS), $tenant));
        });
    }

    public function waitlist(Request $request)
    {
        $tenant = $this->tenant($request);
        $customer = $this->customer($request);

        if (!\App\Support\Waitlist::enabled($tenant)) {
            return response()->json([]);
        }

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
        abort_unless(\App\Support\Waitlist::enabled($tenant), 403, 'La lista de espera no está disponible. Comunícate con la sede.');
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
            'offer_until'  => ($offer = \App\Support\Waitlist::openOffer($entry->id)) ? Carbon::parse($offer->expires_at)->setTimezone($timezone)->format('Y-m-d\TH:i') : null,
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
            'phone'      => [$request->hasAny(['birth_date', 'district']) ? 'sometimes' : 'required', 'string', 'max:30', 'regex:/^[0-9+ ()-]{6,30}$/'],
            'birth_date' => 'sometimes|nullable|date_format:Y-m-d|before:today|after:1900-01-01',
            'district'   => 'sometimes|nullable|string|max:100',
        ], [
            'phone.regex'       => 'Escribe un número de celular válido.',
            'phone.required'    => 'Escribe tu celular.',
            'birth_date.before' => 'La fecha de nacimiento debe ser anterior a hoy.',
            'birth_date.after'  => 'Revisa el año de tu fecha de nacimiento.',
        ]);

        $fields = ['phone', 'birth_date', 'district'];
        $previous = collect($fields)->mapWithKeys(fn ($f) => [$f => $f === 'birth_date' ? $customer->birth_date?->format('Y-m-d') : $customer->{$f}])->all();
        $changes = [];

        if (array_key_exists('phone', $validated)) {
            $changes['phone'] = \App\Support\Phones::normalize($validated['phone'], \App\Support\Phones::prefixForTenantId($customer->tenant_id)) ?? trim($validated['phone']);
        }

        if (array_key_exists('birth_date', $validated)) {
            $changes['birth_date'] = $validated['birth_date'] ?: null;
        }

        if (array_key_exists('district', $validated)) {
            $changes['district'] = trim((string) $validated['district']) ?: null;
        }

        $customer->update($changes);
        $after = collect($fields)->mapWithKeys(fn ($f) => [$f => $f === 'birth_date' ? $customer->birth_date?->format('Y-m-d') : $customer->{$f}])->all();
        $diff = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $previous)));

        if ($diff) {
            \App\Support\Audit::log($request, 'customer.updated', [
                'tenant'       => $customer->tenant_id,
                'entity_type'  => 'customer',
                'entity_id'    => $customer->id,
                'entity_label' => \App\Support\Person::name($customer),
                'before'       => array_intersect_key($previous, array_flip($diff)),
                'after'        => array_intersect_key($after, array_flip($diff)),
            ]);
        }

        return response()->json($after);
    }

    public function dependentsWithControls(Request $request)
    {
        $tenant = $this->tenant($request);
        $customer = $this->customer($request);
        $config = $tenant->dependents();

        if (!$config['enabled']) {
            return response()->json(['items' => []]);
        }

        $items = $customer->dependents()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'species', 'breed', 'birth_date']);
        $ids = $items->pluck('id');
        $recalls = \Illuminate\Support\Facades\Schema::hasTable('recalls')
            ? \App\Models\Recall::where('tenant_id', $tenant->id)->where('customer_id', $customer->id)->whereIn('customer_dependent_id', $ids)
                ->whereIn('status', [\App\Models\Recall::PENDING, \App\Models\Recall::NOTIFIED, \App\Models\Recall::BOOKED])->orderBy('due_date')->get()->groupBy('customer_dependent_id')
            : collect();
        $last = Appointment::where('tenant_id', $tenant->id)->where('customer_id', $customer->id)->whereIn('customer_dependent_id', $ids)
            ->where('status', AppointmentFlow::COMPLETED)->with('service:id,name')->orderByDesc('starts_at')->get()->groupBy('customer_dependent_id');

        return response()->json(['items' => $items->map(fn (CustomerDependent $d) => [
            'id'         => $d->id,
            'name'       => $d->name,
            'species'    => $d->species,
            'breed'      => $d->breed,
            'birth_date' => $d->birth_date?->format('Y-m-d'),
            'last_visit' => ($row = ($last[$d->id] ?? collect())->first()) ? ['date' => $row->starts_at->toIso8601String(), 'service' => $row->service?->name] : null,
            'controls'   => ($recalls[$d->id] ?? collect())->map(fn (\App\Models\Recall $r) => [
                'id'           => $r->id,
                'label'        => $r->label,
                'due_date'     => $r->due_date instanceof \DateTimeInterface ? $r->due_date->format('Y-m-d') : substr((string) $r->due_date, 0, 10),
                'status'       => $r->status,
                'status_label' => \App\Models\Recall::STATUSES[$r->status] ?? $r->status,
                'overdue'      => $r->status !== \App\Models\Recall::BOOKED && substr((string) ($r->due_date instanceof \DateTimeInterface ? $r->due_date->format('Y-m-d') : $r->due_date), 0, 10) < now($tenant->zone())->format('Y-m-d'),
            ])->values(),
        ])->values()]);
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

    protected function pickProfessional(Tenant $tenant, AppointmentScheduler $scheduler, Service $service, Branch $branch, Carbon $start, int $duration, ?string $professionalId, ?string $ignoreId = null): array
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

    protected function unavailableForCustomer(array $check, string $prefix = ''): JsonResponse
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
        $validated = $request->validate(['email' => 'nullable|boolean', 'whatsapp' => 'nullable|boolean', 'sms' => 'nullable|boolean']);

        MessageController::saveConsents($tenant->id, $customer->id, array_intersect_key($validated, array_flip(['email', 'whatsapp'])), 'portal');

        if (array_key_exists('sms', $validated) && $validated['sms'] !== null) {
            $granted = (bool) $validated['sms'];
            $current = DB::table('customer_consents')->where('customer_id', $customer->id)->where('channel', 'sms')->first();

            if (!$current || (bool) $current->granted !== $granted) {
                DB::table('customer_consents')->updateOrInsert(
                    ['customer_id' => $customer->id, 'channel' => 'sms'],
                    ['tenant_id' => $tenant->id, 'granted' => $granted, 'source' => 'portal', 'granted_at' => $granted ? now() : ($current->granted_at ?? null), 'revoked_at' => $granted ? null : now(), 'updated_at' => now()],
                );
                \App\Support\Audit::log($request, 'customer.updated', [
                    'tenant'       => $tenant->id,
                    'entity_type'  => 'customer',
                    'entity_id'    => $customer->id,
                    'entity_label' => \App\Support\Person::name($customer),
                    'before'       => ['sms' => $current ? (bool) $current->granted : false],
                    'after'        => ['sms' => $granted],
                ]);
            }
        }

        return response()->json(['consents' => self::portalConsents($customer->id)]);
    }

    public static function portalConsents(string $customerId): array
    {
        $sms = DB::table('customer_consents')->where('customer_id', $customerId)->where('channel', 'sms')->first();

        return MessageController::consentsOf($customerId) + ['sms' => [
            'granted' => (bool) ($sms->granted ?? false),
            'source'  => $sms->source ?? null,
            'at'      => $sms ? (($sms->granted ? $sms->granted_at : $sms->revoked_at) ? Carbon::parse($sms->granted ? $sms->granted_at : $sms->revoked_at)->toIso8601String() : null) : null,
        ]];
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

    public function memberships(Request $request)
    {
        $tenant = $this->tenant($request);
        $customer = $this->customer($request);
        abort_unless(\App\Support\Memberships::ready(), 503, \App\Support\Memberships::NOT_READY);
        \App\Support\Memberships::expireDue($tenant);

        $rows = DB::table('memberships')->where('tenant_id', $tenant->id)->where('customer_id', $customer->id)
            ->orderByRaw("CASE WHEN status IN ('active', 'grace', 'paused') THEN 0 ELSE 1 END")
            ->orderByDesc('ends_at')->limit(50)->get();

        return response()->json(['memberships' => collect(\App\Support\Memberships::presentMany($tenant, $rows))->map(fn (array $m) => collect($m)->only([
            'id', 'code', 'name', 'status', 'status_label', 'price', 'currency', 'period_label', 'starts_at', 'ends_at', 'days_left',
            'benefits_text', 'included', 'period_end', 'grace_until', 'paused_at',
        ])->all())->values()]);
    }

    public function benefits(Request $request)
    {
        $tenant = $this->tenant($request);
        $customer = $this->customer($request);
        $iso = fn ($value) => $value ? Carbon::parse($value)->toIso8601String() : null;
        $loyalty = \App\Support\Loyalty::enabled($tenant);
        $rules = $loyalty ? \App\Support\Loyalty::rules($tenant) : null;
        $points = null;
        $giftCards = null;

        if ($loyalty && $rules['points_enabled']) {
            $balance = \App\Support\Loyalty::customerPoints($tenant->id, $customer->id);
            $rows = DB::table('loyalty_transactions as lt')
                ->leftJoin('sales as s', 's.id', '=', 'lt.sale_id')
                ->where('lt.tenant_id', $tenant->id)
                ->where('lt.customer_id', $customer->id)
                ->orderByDesc('lt.created_at')
                ->limit(100)
                ->get(['lt.id', 'lt.type', 'lt.points', 'lt.balance_after', 'lt.reason', 'lt.expires_at', 'lt.created_at', 's.code as sale_code']);
            $lots = \App\Support\Loyalty::lotStates($tenant->id, [$customer->id]);

            $points = [
                'balance'           => $balance,
                'value'             => round($balance * $rules['point_value'], 2),
                'amount_per_point'  => $rules['amount_per_point'],
                'point_value'       => $rules['point_value'],
                'min_redeem_points' => $rules['min_redeem_points'],
                'expiring'          => \App\Support\Loyalty::expiringSoon($tenant->id, $customer->id),
                'movements'         => $rows->map(fn ($m) => [
                    'id'             => $m->id,
                    'type'           => $m->type,
                    'type_label'     => \App\Support\Loyalty::POINT_TYPES[$m->type] ?? $m->type,
                    'points'         => (int) $m->points,
                    'balance_after'  => (int) $m->balance_after,
                    'reason'         => $m->reason,
                    'sale_code'      => $m->sale_code,
                    'expires_at'     => $iso($m->expires_at),
                    'expired_points' => (int) ($lots[$m->id]['expired'] ?? 0),
                    'created_at'     => $iso($m->created_at),
                ])->values(),
            ];
        }

        if ($loyalty && $rules['gift_cards_enabled']) {
            \App\Support\Loyalty::expireDue($tenant->id);
            $giftCards = DB::table('gift_cards')
                ->where('tenant_id', $tenant->id)
                ->where(fn ($q) => $q->where('recipient_customer_id', $customer->id)->orWhere('purchaser_customer_id', $customer->id))
                ->where('code', 'not like', \App\Support\CustomerCredit::PREFIX . '%')
                ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(fn ($gc) => [
                    'id'             => $gc->id,
                    'code'           => $gc->code,
                    'initial_amount' => round((float) $gc->initial_amount, 2),
                    'balance'        => round((float) $gc->balance, 2),
                    'status'         => $gc->status,
                    'status_label'   => \App\Support\Loyalty::GIFT_CARD_STATUSES[$gc->status] ?? $gc->status,
                    'recipient_name' => $gc->recipient_name,
                    'for_me'         => $gc->recipient_customer_id === $customer->id || (!$gc->recipient_customer_id && !$gc->recipient_name),
                    'expires_at'     => $iso($gc->expires_at),
                ])->values();
        }

        $purchases = [];

        if (\App\Support\Sales::ready()) {
            $sales = DB::table('sales')
                ->where('tenant_id', $tenant->id)
                ->where('customer_id', $customer->id)
                ->whereIn('status', ['paid', 'partially_refunded', 'refunded', 'partial'])
                ->orderByDesc('created_at')
                ->limit(100)
                ->get();
            $items = DB::table('sale_items')->whereIn('sale_id', $sales->pluck('id'))->get(['sale_id', 'description', 'quantity'])->groupBy('sale_id');
            $invoices = DB::table('invoices')->whereIn('sale_id', $sales->pluck('id'))->where('status', 'issued')->where('doc_type', '!=', \App\Support\Invoicing::CREDIT_NOTE)->get()->keyBy('sale_id');

            $purchases = $sales->map(fn ($s) => [
                'id'             => $s->id,
                'code'           => $s->code,
                'created_at'     => $iso($s->paid_at ?? $s->created_at),
                'status'         => $s->status,
                'status_label'   => \App\Support\Sales::label($s),
                'detail'         => ($items[$s->id] ?? collect())->map(fn ($i) => ((float) $i->quantity > 1 ? (int) $i->quantity . ' × ' : '') . $i->description)->implode(', '),
                'total'          => round((float) $s->total, 2),
                'paid'           => round((float) ($s->paid ?? 0), 2),
                'balance'        => $s->status === 'partial' ? round(max(0, (float) ($s->balance ?? 0)), 2) : 0.0,
                'currency'       => $s->currency ?? Currency::DEFAULT,
                'invoice_number' => isset($invoices[$s->id]) ? sprintf('%s-%08d', $invoices[$s->id]->serie, $invoices[$s->id]->number) : null,
                'invoice_label'  => isset($invoices[$s->id]) ? (\App\Support\Invoicing::LABELS[$invoices[$s->id]->doc_type] ?? null) : null,
            ])->values()->all();
        }

        $memberships = \App\Support\Memberships::ready() && $tenant->hasModule('memberships')
            ? DB::table('memberships')->where('tenant_id', $tenant->id)->where('customer_id', $customer->id)->whereIn('status', ['active', 'grace'])->orderBy('ends_at')->get(['id', 'name', 'ends_at'])
                ->map(fn ($m) => ['id' => $m->id, 'name' => $m->name ?? null, 'ends_at' => $iso($m->ends_at)])->values()
            : collect();
        $packagesLeft = \App\Support\Packages::ready()
            ? collect(\App\Support\Packages::presentMany(DB::table('customer_packages')->where('tenant_id', $tenant->id)->where('customer_id', $customer->id)->where('status', 'active')->get(), false))
                ->map(fn (array $p) => ['id' => $p['id'], 'name' => $p['name'], 'available' => $p['sessions_available'], 'expires_at' => $p['expires_at']])->values()
            : collect();

        return response()->json([
            'currency'   => Currency::of($tenant),
            'points'     => $points,
            'gift_cards' => $giftCards,
            'credit'     => \App\Support\CustomerCredit::summary($tenant->id, $customer->id),
            'purchases'  => $purchases,
            'summary'    => [
                'points'          => $points['balance'] ?? null,
                'points_value'    => $points['value'] ?? null,
                'gift_balance'    => $giftCards ? round($giftCards->where('for_me', true)->where('status', 'active')->sum('balance'), 2) : null,
                'balance_due'     => round(collect($purchases)->sum('balance'), 2),
                'credit_balance'  => \App\Support\CustomerCredit::balance($tenant->id, $customer->id),
                'memberships'     => $memberships,
                'packages'        => $packagesLeft,
            ],
        ]);
    }

    public function purchase(Request $request, string $sale)
    {
        $tenant = $this->tenant($request);
        $customer = $this->customer($request);
        abort_unless(\App\Support\Sales::ready(), 503, \App\Support\Sales::NOT_READY);

        $row = DB::table('sales')->where('tenant_id', $tenant->id)->where('customer_id', $customer->id)->where('id', $sale)
            ->whereIn('status', ['paid', 'partially_refunded', 'refunded', 'partial'])->first();
        abort_unless($row, 404);

        $company = DB::table('companies')->where('tenant_id', $tenant->id)->orderBy('created_at')->first();
        $present = \App\Support\Sales::present($row);
        $present['payments'] = collect($present['payments'])->map(fn ($p) => collect($p)->except(['by', 'register'])->all())->values();

        return response()->json($present + [
            'branch'   => ['id' => $row->branch_id, 'name' => DB::table('branches')->where('id', $row->branch_id)->value('name')],
            'customer' => ['id' => $customer->id, 'name' => \App\Support\Person::name($customer)],
            'issuer'   => [
                'name'    => $company->legal_name ?? $company->trade_name ?? $tenant->name,
                'tax_id'  => $company->tax_id ?? null,
                'address' => $company->address ?? null,
            ] + \App\Support\Documents::forReceipt($tenant),
        ]);
    }

    protected function presentForCustomer(Appointment $appointment, Tenant $tenant): array
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
            'video_url'        => in_array($appointment->status, [AppointmentFlow::PENDING, AppointmentFlow::CONFIRMED, AppointmentFlow::CHECKED_IN, AppointmentFlow::IN_PROGRESS], true) ? \App\Support\VideoLinks::for($appointment) : null,
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
