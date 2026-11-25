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
use App\Support\Audit;
use App\Support\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\Access;
use App\Support\Currency;

class AppointmentController extends Controller
{
    protected const LOCAL_FORMAT = 'Y-m-d\TH:i';
    protected const RELATIONS = ['customer', 'dependent', 'service', 'professional', 'branch', 'record'];
    protected const MAX_ROWS = 500;

    public const ORIGINS = [
        'staff'        => 'Recepción',
        'online'       => 'En línea',
        'portal'       => 'Portal de clientes',
        'walkin'       => 'Sin cita',
        'kiosk'        => 'Sin cita',
        'payment_link' => 'Link de pago',
        'package'      => 'Paquete',
        'api'          => 'Integración',
    ];

    public const CANCEL_REASONS = [
        'Lo pidió la persona',
        'Inconveniente de salud',
        'Quien atiende no está disponible',
        'Se agendó por error',
        'Reprogramará más adelante',
    ];

    public static function originLabel(?string $source, ?string $channel = null): string
    {
        $label = self::ORIGINS[$source] ?? ($source ? ucfirst($source) : 'Sin dato');

        if ($channel && isset(\App\Support\Channels::CHANNELS[$channel]) && !in_array($channel, ['direct', 'portal'], true)) {
            return $label . ' · ' . \App\Support\Channels::CHANNELS[$channel];
        }

        return $label;
    }

    protected function tenant(Request $request): Tenant
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 403);

        return $tenant->loadMissing(['settings', 'activeSubscription.plan']);
    }

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
        Access::assertBranch($request->user(), $appointment->branch_id);

        return $appointment;
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);
        $timezone = $tenant->zone();

        $validated = $request->validate([
            'date'            => 'nullable|date_format:Y-m-d',
            'from'            => 'nullable|date_format:Y-m-d',
            'to'              => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'branch_id'       => 'nullable|uuid',
            'professional_id' => 'nullable|uuid',
            'customer_id'     => 'nullable|uuid',
            'status'          => 'nullable|string',
            'search'          => 'nullable|string|max:100',
            'source'          => 'nullable|string|max:30',
            'all'             => 'nullable|boolean',
        ]);

        $from = $validated['date'] ?? $validated['from'] ?? null;
        $to = $validated['date'] ?? $validated['to'] ?? null;
        $sources = !empty($validated['source']) ? ($validated['source'] === 'walkin' ? ['walkin', 'kiosk'] : [$validated['source']]) : [];

        if (!$from && !$to && empty($validated['customer_id']) && empty($validated['search']) && empty($validated['all'])) {
            $from = $to = now($timezone)->format('Y-m-d');
        }

        $own = $this->ownProfessionalId($request->user());
        $statuses = array_values(array_intersect(explode(',', (string) ($validated['status'] ?? '')), AppointmentFlow::keys()));

        $appointments = Appointment::where('tenant_id', $tenant->id)
            ->with(self::RELATIONS)
            ->when($from, fn ($q) => $q->where('starts_at', '>=', Carbon::parse($from, $timezone)->startOfDay()->toIso8601String()))
            ->when($to, fn ($q) => $q->where('starts_at', '<', Carbon::parse($to, $timezone)->addDay()->startOfDay()->toIso8601String()))
            ->when($validated['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when(Access::branchLimit($request->user()), fn ($q, $id) => $q->where('branch_id', $id))
            ->when($validated['customer_id'] ?? null, fn ($q, $id) => $q->where('customer_id', $id))
            ->when($own ?? ($validated['professional_id'] ?? null), fn ($q, $id) => $q->where('professional_id', $id))
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->when($sources, fn ($q) => $q->whereIn('source', $sources))
            ->when($validated['search'] ?? null, function ($q, $search) {
                $q->where(fn ($q) => Search::contains($q, 'code', $search)
                    ->orWhereHas('customer', function ($q) use ($search) {
                        $q->where(function ($q) use ($search) {
                            Search::contains($q, "coalesce(first_name, '') || ' ' || coalesce(last_name, '')", $search);
                            Search::orContains($q, 'document_number', $search);
                            Search::orContains($q, 'phone', $search);
                        });
                    }));
            })
            ->orderBy('starts_at', !$from && !$to && !empty($validated['all']) ? 'desc' : 'asc')
            ->limit(self::MAX_ROWS + 1)
            ->get();
        $cut = $appointments->count() > self::MAX_ROWS;

        $withPaid = $request->boolean('with_paid') && \App\Support\Sales::ready();

        return response()->json($appointments->take(self::MAX_ROWS)->map(fn (Appointment $a) => $this->present($a, $tenant) + ($withPaid
            ? ['paid' => $a->status === AppointmentFlow::COMPLETED ? \App\Support\Sales::appointmentPaid($tenant->id, $a->id) : null]
            : []))->values())
            ->withHeaders($cut ? ['X-Rows-Cut' => (string) self::MAX_ROWS] : []);
    }

    public function context(Request $request)
    {
        $tenant = $this->tenant($request);
        $own = $this->ownProfessionalId($request->user());
        $limit = Access::branchLimit($request->user());

        $assigned = DB::table('service_professionals')->where('tenant_id', $tenant->id)->get()->groupBy('service_id');
        $worksAt = ProfessionalSchedule::where('tenant_id', $tenant->id)->get()->groupBy('professional_id');

        return response()->json([
            'branches' => Branch::where('tenant_id', $tenant->id)->where('status', 'active')->when($limit, fn ($q, $id) => $q->where('id', $id))->orderBy('name')->get(['id', 'name']),
            'professionals' => Professional::where('tenant_id', $tenant->id)
                ->when($own, fn ($q) => $q->where('id', $own))
                ->when($limit, fn ($q, $id) => $q->whereIn('id', ProfessionalSchedule::where('branch_id', $id)->select('professional_id')))
                ->with('status')
                ->orderBy('display_name')
                ->get()
                ->reject(fn (Professional $p) => $p->status?->slug === 'inactive')
                ->map(fn (Professional $p) => [
                    'id'           => $p->id,
                    'display_name' => $p->display_name,
                    'color_code'   => $p->color_code,
                    'branch_ids'   => ($worksAt[$p->id] ?? collect())->pluck('branch_id')->unique()->values(),
                ])->values(),
            'services' => Service::where('tenant_id', $tenant->id)
                ->where('kind', Service::KIND_INDIVIDUAL)
                ->with(['status', 'category'])
                ->orderBy('name')
                ->get()
                ->reject(fn (Service $s) => $s->status?->slug === 'inactive')
                ->map(fn (Service $s) => [
                    'id'               => $s->id,
                    'name'             => $s->name,
                    'duration_minutes' => $s->duration_minutes,
                    'price'            => $s->price,
                    'professional_ids' => ($assigned[$s->id] ?? collect())->pluck('professional_id')->values(),
                    'branch_ids'       => $s->branch_ids,
                    'category'         => $s->category?->name,
                    'buffer_before'    => (int) $s->buffer_before_minutes,
                    'buffer_after'     => (int) $s->buffer_after_minutes,
                    'deposit'          => $s->requires_deposit && AppointmentFlow::rule($tenant, 'booking.require_deposit')
                        ? ['label' => \App\Support\PaymentLinks::depositLabel($s), 'amount' => \App\Support\PaymentLinks::depositAmount($s)]
                        : null,
                ])->values(),
            'can_override' => (bool) AppointmentFlow::rule($tenant, 'agenda.allow_override') && $request->user()->allows('agenda.override'),
            'slot_interval' => (int) AppointmentFlow::rule($tenant, 'agenda.slot_interval'),
            'respect_buffers' => (bool) AppointmentFlow::rule($tenant, 'agenda.respect_buffers'),
            'origins'      => collect(self::ORIGINS)->except('kiosk')->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])->values(),
            'cancel_reasons' => self::CANCEL_REASONS,
            'rooms'        => \App\Support\RoomBooking::enabled($tenant)
                ? DB::table('rooms')->where('tenant_id', $tenant->id)->where('kind', 'room')->where('status', 'active')
                    ->when($limit, fn ($q, $id) => $q->where('branch_id', $id))->orderBy('name')->get(['id', 'name', 'branch_id'])
                : [],
        ]);
    }

    public function day(Request $request)
    {
        $tenant = $this->tenant($request);

        $validated = $request->validate([
            'date'      => 'required|date_format:Y-m-d',
            'branch_id' => 'required|uuid',
        ]);

        $branch = Branch::where('tenant_id', $tenant->id)->findOrFail($validated['branch_id']);
        Access::assertBranch($request->user(), $branch->id);
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

        $schedules = ProfessionalSchedule::effective(ProfessionalSchedule::where('tenant_id', $tenant->id)
            ->where('branch_id', $branch->id)
            ->orderBy('starts_local')
            ->get(), $validated['date'])
            ->groupBy('professional_id');

        $blocks = AvailabilityBlock::where('tenant_id', $tenant->id)
            ->where('starts_at', '<', $dayEnd->toIso8601String())
            ->where('ends_at', '>', $dayStart->toIso8601String())
            ->get();

        $held = \App\Support\CustomerTransfers::held($tenant->id, $branch->id, $dayStart, $dayEnd, $own);
        $classes = $tenant->hasModule('classes') ? \App\Support\ClassSessions::agendaRows($tenant->id, $branch->id, $dayStart, $dayEnd, $own) : collect();

        $clip = function (string $value) use ($dayStart, $dayEnd, $timezone) {
            $date = Carbon::parse($value)->setTimezone($timezone);

            return $date->lte($dayStart) ? '00:00' : ($date->gte($dayEnd) ? '24:00' : $date->format('H:i'));
        };

        $holidays = \App\Support\Holidays::on($tenant, $branch->id, $validated['date']);
        $closedBy = $holidays->firstWhere('closed', true);
        $deposits = $this->paidDeposits($tenant->id, $appointments->pluck('id')->all());

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
                'occupancy'    => $this->occupancy($schedules[$p->id] ?? collect(), $appointments->where('professional_id', $p->id)),
                'blocks'       => $blocks
                    ->filter(fn (AvailabilityBlock $b) => $b->professional_id === $p->id || (!$b->professional_id && $b->branch_id === $branch->id))
                    ->map(fn (AvailabilityBlock $b) => ['starts' => $clip($b->starts_at), 'ends' => $clip($b->ends_at), 'reason' => $b->reason, 'kind' => $b->kindKey()])
                    ->values()
                    ->concat($held->where('professional_id', $p->id)->map(fn ($h) => ['starts' => $clip($h->starts_at), 'ends' => $clip($h->ends_at), 'reason' => $h->reason, 'kind' => 'held'])->values())
                    ->concat($classes->where('professional_id', $p->id)->map(fn ($c) => ['starts' => $clip($c->starts_at), 'ends' => $clip($c->ends_at), 'reason' => $c->reason, 'kind' => 'class', 'class_id' => $c->id])->values())
                    ->when($closedBy, fn ($blocks) => $blocks->prepend(['starts' => '00:00', 'ends' => '24:00', 'reason' => $closedBy->name, 'kind' => 'holiday'])),
            ])->values();

        return response()->json([
            'date'          => $validated['date'],
            'now'           => now($timezone)->format(self::LOCAL_FORMAT),
            'holidays'      => $holidays->map(fn ($h) => ['name' => $h->name, 'closed' => (bool) $h->closed])->values(),
            'professionals' => $professionals,
            'rooms'         => $own ? [] : $this->agendaDayRooms($tenant, $branch, $dayStart, $dayEnd, $clip, $closedBy),
            'respect_buffers' => (bool) AppointmentFlow::rule($tenant, 'agenda.respect_buffers'),
            'my_professional_id' => Professional::where('tenant_id', $tenant->id)->where('user_id', $request->user()->id)->value('id'),
            'appointments'  => $appointments->map(fn (Appointment $a) => $this->present($a, $tenant) + ['deposit_paid' => in_array($a->id, $deposits, true)]),
        ]);
    }

    protected function paidDeposits(string $tenantId, array $appointmentIds): array
    {
        if ($appointmentIds === [] || !Schema::hasTable('payment_links')) {
            return [];
        }

        return DB::table('payment_links')->where('tenant_id', $tenantId)->whereIn('appointment_id', $appointmentIds)
            ->where('purpose', 'deposit')->where('status', 'paid')->pluck('appointment_id')->all();
    }

    protected function agendaDayRooms(Tenant $tenant, Branch $branch, Carbon $dayStart, Carbon $dayEnd, callable $clip, ?object $closedBy): array
    {
        if (!\App\Support\RoomBooking::enabled($tenant)) {
            return [];
        }

        $rooms = DB::table('rooms')->where('tenant_id', $tenant->id)->where('branch_id', $branch->id)->where('kind', 'room')->orderBy('name')->get(['id', 'name', 'status', 'description']);

        if ($rooms->isEmpty()) {
            return [];
        }

        $blocks = \App\Support\RoomBlocks::between($tenant->id, $rooms->pluck('id')->all(), $dayStart, $dayEnd);

        return $rooms->map(fn (object $room) => [
            'id'          => $room->id,
            'name'        => $room->name,
            'description' => $room->description,
            'active'      => $room->status === 'active',
            'blocks'      => collect($blocks[$room->id] ?? [])
                ->map(fn (object $b) => ['starts' => $clip($b->starts_at), 'ends' => $clip($b->ends_at), 'reason' => $b->reason, 'kind' => 'room', 'id' => $b->id])
                ->when($room->status !== 'active', fn ($list) => $list->prepend(['starts' => '00:00', 'ends' => '24:00', 'reason' => 'Fuera de uso', 'kind' => 'room']))
                ->when($closedBy, fn ($list) => $list->prepend(['starts' => '00:00', 'ends' => '24:00', 'reason' => $closedBy->name, 'kind' => 'holiday']))
                ->values()
                ->all(),
        ])->values()->all();
    }

    protected function occupancy($schedules, $appointments): array
    {
        $minutes = fn (string $time) => ((int) substr($time, 0, 2)) * 60 + (int) substr($time, 3, 2);
        $scheduled = (int) $schedules->sum(fn (ProfessionalSchedule $s) => max(0, $minutes(substr($s->ends_local, 0, 5)) - $minutes(substr($s->starts_local, 0, 5))));
        $booked = (int) $appointments->whereNotIn('status', AppointmentFlow::RELEASED)->sum(fn (Appointment $a) => $a->starts_at->diffInMinutes($a->ends_at));

        return [
            'scheduled_minutes' => $scheduled,
            'booked_minutes'    => $booked,
            'percent'           => $scheduled > 0 ? min(100, (int) round($booked * 100 / $scheduled)) : null,
        ];
    }

    public function range(Request $request)
    {
        $tenant = $this->tenant($request);

        $validated = $request->validate([
            'from'      => 'required|date_format:Y-m-d',
            'to'        => 'required|date_format:Y-m-d|after_or_equal:from',
            'branch_id' => 'required|uuid',
        ]);

        $branch = Branch::where('tenant_id', $tenant->id)->findOrFail($validated['branch_id']);
        Access::assertBranch($request->user(), $branch->id);
        $timezone = AppointmentScheduler::timezone($branch, $tenant);
        $from = Carbon::createFromFormat('Y-m-d', $validated['from'], $timezone)->startOfDay();
        $to = Carbon::createFromFormat('Y-m-d', $validated['to'], $timezone)->startOfDay()->addDay();
        abort_if($from->diffInDays($to) > 42, 422, 'Se pueden ver hasta 6 semanas a la vez.');
        $own = $this->ownProfessionalId($request->user());

        $appointments = Appointment::where('tenant_id', $tenant->id)
            ->where('branch_id', $branch->id)
            ->when($own, fn ($q) => $q->where('professional_id', $own))
            ->where('starts_at', '>=', $from->toIso8601String())
            ->where('starts_at', '<', $to->toIso8601String())
            ->with(self::RELATIONS)
            ->orderBy('starts_at')
            ->get();

        $schedules = ProfessionalSchedule::where('tenant_id', $tenant->id)
            ->where('branch_id', $branch->id)
            ->when($own, fn ($q) => $q->where('professional_id', $own))
            ->orderBy('starts_local')
            ->get()
            ->groupBy('professional_id');

        $blocks = AvailabilityBlock::where('tenant_id', $tenant->id)
            ->where(fn ($q) => $q->whereIn('professional_id', $schedules->keys())->orWhere(fn ($q) => $q->whereNull('professional_id')->where('branch_id', $branch->id)))
            ->where('starts_at', '<', $to->toIso8601String())
            ->where('ends_at', '>', $from->toIso8601String())
            ->get();

        $local = fn ($value) => Carbon::parse($value)->setTimezone($timezone)->format(self::LOCAL_FORMAT);

        $holidays = \App\Support\Holidays::available()
            ? \App\Models\Holiday::where('tenant_id', $tenant->id)
                ->where('date', '>=', $from->toDateString())
                ->where('date', '<', $to->toDateString())
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch->id))
                ->orderBy('name')
                ->get()
            : collect();

        $dated = $schedules->flatten(1)->contains(fn (ProfessionalSchedule $s) => ProfessionalSchedule::periodKey($s) !== '|');
        $perDay = $dated
            ? collect(\Carbon\CarbonPeriod::create($from, '1 day', $to->copy()->subDay()))->flatMap(fn ($day) => ProfessionalSchedule::effective($schedules->flatten(1), $day->format('Y-m-d'))
                ->map(fn (ProfessionalSchedule $s) => ['row' => $s, 'date' => $day->format('Y-m-d')]))->groupBy(fn (array $item) => $item['row']->professional_id)
            : collect();

        $professionals = Professional::where('tenant_id', $tenant->id)
            ->when($own, fn ($q) => $q->where('id', $own))
            ->whereIn('id', $schedules->keys()->merge($appointments->pluck('professional_id'))->unique())
            ->orderBy('display_name')
            ->get()
            ->map(fn (Professional $p) => [
                'id'           => $p->id,
                'display_name' => $p->display_name,
                'color_code'   => $p->color_code,
                'schedules'    => $dated
                    ? ($perDay[$p->id] ?? collect())->map(fn (array $item) => [
                        'weekday' => (int) $item['row']->weekday,
                        'starts'  => substr($item['row']->starts_local, 0, 5),
                        'ends'    => substr($item['row']->ends_local, 0, 5),
                        'date'    => $item['date'],
                    ])->values()
                    : ($schedules[$p->id] ?? collect())->map(fn (ProfessionalSchedule $s) => [
                        'weekday' => (int) $s->weekday,
                        'starts'  => substr($s->starts_local, 0, 5),
                        'ends'    => substr($s->ends_local, 0, 5),
                    ])->values(),
            ])->values();

        return response()->json([
            'from'          => $validated['from'],
            'to'            => $validated['to'],
            'now'           => now($timezone)->format(self::LOCAL_FORMAT),
            'holidays'      => $holidays->map(fn ($h) => ['date' => Carbon::parse($h->date)->toDateString(), 'name' => $h->name, 'closed' => (bool) $h->closed])->values(),
            'professionals' => $professionals,
            'blocks'        => $blocks->map(fn (AvailabilityBlock $b) => [
                'professional_id' => $b->professional_id,
                'starts'          => $local($b->starts_at),
                'ends'            => $local($b->ends_at),
                'reason'          => $b->reason,
                'kind'            => 'block',
            ])->concat(\App\Support\CustomerTransfers::held($tenant->id, $branch->id, $from, $to, $own)->map(fn ($h) => [
                'professional_id' => $h->professional_id,
                'starts'          => $local($h->starts_at),
                'ends'            => $local($h->ends_at),
                'reason'          => $h->reason,
                'kind'            => 'held',
            ]))->concat(($tenant->hasModule('classes') ? \App\Support\ClassSessions::agendaRows($tenant->id, $branch->id, $from, $to, $own) : collect())->map(fn ($c) => [
                'professional_id' => $c->professional_id,
                'starts'          => $local($c->starts_at),
                'ends'            => $local($c->ends_at),
                'reason'          => $c->reason,
                'kind'            => 'class',
                'class_id'        => $c->id,
            ]))->values(),
            'my_professional_id' => Professional::where('tenant_id', $tenant->id)->where('user_id', $request->user()->id)->value('id'),
            'appointments'  => $appointments->map(fn (Appointment $a) => $this->present($a, $tenant)),
        ]);
    }

    public function show(Request $request, string $appointment)
    {
        $appointment = $this->find($request, $appointment);

        return response()->json($this->present($appointment, $this->tenant($request), true) + [
            'late_cancel_strike' => \App\Support\OnlineBooking::lateCancelPreview($this->tenant($request), $appointment),
            'penalty_preview'    => \App\Support\Penalties::preview($this->tenant($request), $appointment),
            'penalty'            => \App\Support\Penalties::present(\App\Support\Penalties::find($appointment->tenant_id, $appointment->id)),
            'customer_timing' => AppointmentTiming::summarize(
                Appointment::where('tenant_id', $appointment->tenant_id)
                    ->where('customer_id', $appointment->customer_id)
                    ->where('status', AppointmentFlow::COMPLETED)
                    ->get()
            ),
            'customer_profile' => $this->appointmentCustomerProfile($request, $this->tenant($request), $appointment),
            'dependent_detail' => $this->appointmentDependentDetail($appointment),
            'deposit'          => $this->appointmentDeposit($this->tenant($request), $appointment),
            'messages'         => $this->appointmentMessages($appointment),
            'can_waive_penalty' => $request->user()->allows('sales.delete'),
            'cancel_reasons'   => self::CANCEL_REASONS,
        ]);
    }

    protected function appointmentCustomerProfile(Request $request, Tenant $tenant, Appointment $appointment): ?array
    {
        $customer = $appointment->customer;

        if (!$customer) {
            return null;
        }

        $user = $request->user();
        $seesMoney = $user->allows('sales.view') || $user->allows('customers.view');
        $money = $seesMoney ? \App\Support\CustomerProfile::money($tenant, $customer->id) : null;

        $spend = $seesMoney && \App\Support\Sales::ready()
            ? round((float) DB::table('sales')->where('tenant_id', $tenant->id)->where('customer_id', $customer->id)
                ->whereIn('status', ['paid', 'partial', 'partially_refunded'])
                ->where('created_at', '>=', now()->subMonths(12)->toIso8601String())
                ->sum('paid'), 2)
            : null;

        try {
            $segments = $user->allows('customers.view') ? \App\Support\CustomerProfile::segments($tenant, $customer->id) : [];
        } catch (\Throwable $e) {
            report($e);
            $segments = [];
        }

        return [
            'visits'      => Appointment::where('tenant_id', $tenant->id)->where('customer_id', $customer->id)->where('status', AppointmentFlow::COMPLETED)->count(),
            'no_shows'    => Appointment::where('tenant_id', $tenant->id)->where('customer_id', $customer->id)->where('status', AppointmentFlow::NO_SHOW)->count(),
            'spend_12m'   => $spend,
            'balance'     => $money ? $money['balance'] : null,
            'points'      => \App\Support\Loyalty::ready() && \App\Support\Loyalty::enabled($tenant) ? \App\Support\Loyalty::customerPoints($tenant->id, $customer->id) : null,
            'segments'    => collect($segments)->map(fn (array $s) => ['key' => $s['key'], 'label' => $s['label']])->values()->all(),
            'tags'        => $user->allows('customers.view') ? \App\Support\CustomerProfile::tagsOf($customer->id) : [],
            'alert'       => Schema::hasColumn('customers', 'alert') && trim((string) $customer->alert) !== '' ? trim((string) $customer->alert) : null,
            'block_after' => (int) AppointmentFlow::rule($tenant, 'noshow.block_after') ?: null,
            'document'    => trim(($customer->document_number ?? '')) ?: null,
        ];
    }

    protected function appointmentDependentDetail(Appointment $appointment): ?array
    {
        $dependent = $appointment->dependent;

        if (!$dependent) {
            return null;
        }

        $birth = $dependent->birth_date ? Carbon::parse($dependent->birth_date) : null;
        $age = null;

        if ($birth) {
            $years = (int) $birth->diffInYears(now());
            $months = (int) $birth->diffInMonths(now());
            $age = $years >= 1 ? ($years === 1 ? '1 año' : "{$years} años") : ($months === 1 ? '1 mes' : "{$months} meses");
        }

        return [
            'species' => $dependent->species,
            'breed'   => $dependent->breed,
            'sex'     => ['M' => 'Macho', 'F' => 'Hembra', 'O' => 'Otro'][$dependent->biological_sex ?? ''] ?? null,
            'age'     => $age,
            'kind'    => $dependent->kind,
        ];
    }

    protected function appointmentDeposit(Tenant $tenant, Appointment $appointment): ?array
    {
        $service = $appointment->service;

        if (!$service) {
            return null;
        }

        $link = Schema::hasTable('payment_links')
            ? DB::table('payment_links')->where('tenant_id', $tenant->id)->where('appointment_id', $appointment->id)->where('purpose', 'deposit')->where('status', 'paid')->orderByDesc('paid_at')->first(['amount', 'currency'])
            : null;

        if ($link) {
            return ['status' => 'paid', 'amount' => round((float) $link->amount, 2), 'currency' => $link->currency];
        }

        if (!$service->requires_deposit || !AppointmentFlow::rule($tenant, 'booking.require_deposit') || in_array($appointment->status, [AppointmentFlow::COMPLETED, ...AppointmentFlow::RELEASED], true)) {
            return null;
        }

        $sale = \App\Support\Sales::ready() ? \App\Support\Sales::ofAppointment($tenant->id, $appointment->id) : null;

        if ($sale && (float) $sale->paid > 0) {
            return (float) $sale->balance > 0 ? ['status' => 'paid', 'amount' => round((float) $sale->paid, 2), 'currency' => $sale->currency ?? $appointment->currency] : null;
        }

        $amount = \App\Support\PaymentLinks::depositAmount($service, $appointment->branch_id);

        return $amount > 0 ? ['status' => 'pending', 'amount' => $amount, 'currency' => $appointment->currency] : null;
    }

    protected function appointmentMessages(Appointment $appointment): array
    {
        if (!\App\Support\Messaging::ready()) {
            return [];
        }

        $timezone = AppointmentScheduler::timezone($appointment->branch ?? new Branch(), $this->tenant(request()));
        $statuses = \App\Support\Messaging::STATUS_LABELS;

        return DB::table('messages')
            ->where('tenant_id', $appointment->tenant_id)
            ->where('entity_type', 'appointment')
            ->where('entity_id', $appointment->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'channel', 'kind', 'status', 'error', 'sent_at', 'created_at'])
            ->map(fn (object $m) => [
                'id'           => $m->id,
                'channel'      => $m->channel === 'whatsapp' ? 'WhatsApp' : 'Correo',
                'kind'         => \App\Support\Messaging::KINDS[$m->kind]['label'] ?? 'Mensaje',
                'status'       => $m->status,
                'status_label' => $statuses[$m->status] ?? $m->status,
                'error'        => in_array($m->status, ['failed', 'not_sent'], true) ? $m->error : null,
                'at'           => Carbon::parse($m->sent_at ?? $m->created_at)->setTimezone($timezone)->format(self::LOCAL_FORMAT),
            ])
            ->all();
    }

    public function customerDuplicates(Request $request)
    {
        $tenant = $this->tenant($request);

        $validated = $request->validate([
            'phone'           => 'nullable|string|max:50',
            'email'           => 'nullable|string|max:255',
            'document_number' => 'nullable|string|max:50',
        ]);

        $digits = preg_replace('/\D+/', '', (string) ($validated['phone'] ?? ''));
        $email = mb_strtolower(trim((string) ($validated['email'] ?? '')));
        $document = trim((string) ($validated['document_number'] ?? ''));

        if (strlen($digits) < 6 && $email === '' && $document === '') {
            return response()->json(['matches' => []]);
        }

        $matches = Customer::visibleTo($request->user())
            ->where(function ($q) use ($digits, $email, $document) {
                if (strlen($digits) >= 6) {
                    $q->orWhereRaw("right(regexp_replace(coalesce(customers.phone, ''), '\\D', '', 'g'), 9) = ?", [substr($digits, -9)]);
                }
                if ($email !== '') {
                    $q->orWhereRaw('lower(customers.email) = ?', [$email]);
                }
                if ($document !== '') {
                    $q->orWhere('customers.document_number', $document);
                }
            })
            ->orderBy('customers.first_name')
            ->limit(5)
            ->get(['customers.id', 'customers.code', 'customers.first_name', 'customers.last_name', 'customers.phone', 'customers.email', 'customers.document_number']);

        return response()->json(['matches' => $matches->map(fn (Customer $c) => [
            'id'              => $c->id,
            'code'            => $c->code,
            'first_name'      => $c->first_name,
            'last_name'       => $c->last_name,
            'name'            => \App\Support\Person::name($c),
            'phone'           => $c->phone,
            'document_number' => $c->document_number,
            'dependents'      => $tenant->dependents()['enabled']
                ? CustomerDependent::where('tenant_id', $tenant->id)->where('customer_id', $c->id)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'species'])
                : [],
            'by'              => array_values(array_filter([
                strlen($digits) >= 6 && substr(preg_replace('/\D+/', '', (string) $c->phone), -9) === substr($digits, -9) ? 'celular' : null,
                $email !== '' && mb_strtolower((string) $c->email) === $email ? 'correo' : null,
                $document !== '' && $c->document_number === $document ? 'documento' : null,
            ])),
        ])->values()]);
    }

    public function customerConsent(Request $request)
    {
        $tenant = $this->tenant($request);
        $validated = $request->validate(['customer_id' => 'required|uuid']);
        $customer = Customer::where('tenant_id', $tenant->id)->findOrFail($validated['customer_id']);

        abort_if($customer->created_at && $customer->created_at->lt(now()->subHour()), 403, 'Los permisos de una ficha que ya existía se cambian desde su página.');

        $after = \App\Http\Controllers\Api\MessageController::saveConsents($tenant->id, $customer->id, ['email' => true, 'whatsapp' => true], 'staff');

        Audit::log($request, 'customer.consent_changed', [
            'tenant'       => $tenant->id,
            'entity_type'  => 'customer',
            'entity_id'    => $customer->id,
            'entity_label' => \App\Support\Person::name($customer),
            'after'        => ['Correo' => 'Aceptado', 'WhatsApp' => 'Aceptado'],
        ]);

        return response()->json(['consents' => $after]);
    }

    public function waivePenalty(Request $request, string $appointment)
    {
        $tenant = $this->tenant($request);
        abort_unless($request->user()->allows('sales.delete'), 403, 'Tu cuenta no tiene permiso para perdonar penalidades.');

        $validated = $request->validate(['reason' => 'required|string|max:255'], ['reason.required' => 'Elige o escribe por qué se perdona.']);

        return DB::transaction(function () use ($request, $appointment, $tenant, $validated) {
            $appointment = $this->find($request, $appointment);
            Access::assertBranch($request->user(), $appointment->branch_id, true);
            $penalty = \App\Support\Penalties::find($tenant->id, $appointment->id);

            if (!$penalty) {
                return response()->json(['message' => 'Esta cita no tiene penalidad.', 'code' => 'INVALID_STATE'], 422);
            }

            $penalty = DB::table('sales')->where('id', $penalty->id)->lockForUpdate()->first();

            if ($blocked = \App\Support\Refunds::voidBlocked($penalty)) {
                return response()->json(['message' => $penalty->status === 'void' ? 'La penalidad ya está perdonada.' : 'La penalidad ya tiene un pago: devuélvelo desde Ventas.', 'code' => 'INVALID_STATE'], 422);
            }

            $reason = trim($validated['reason']);
            \App\Support\Refunds::void($penalty, 'Penalidad perdonada: ' . $reason);
            $this->auditAppointment($request, 'appointment.penalty_waived', $appointment, array_filter([
                'reason'  => $reason,
                'total'   => round((float) $penalty->total, 2),
                'penalty' => $penalty->code,
            ]));

            return response()->json($this->present($appointment->fresh(self::RELATIONS), $tenant, true) + [
                'penalty' => \App\Support\Penalties::present(\App\Support\Penalties::find($tenant->id, $appointment->id)),
            ]);
        });
    }

    public function metrics(Request $request)
    {
        $tenant = $this->tenant($request);
        $timezone = $tenant->zone();

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
            ->when(Access::branchLimit($request->user()), fn ($q, $id) => $q->where('branch_id', $id))
            ->when($own, fn ($q) => $q->where('professional_id', $own))
            ->with(['customer', 'service', 'professional'])
            ->get();

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
            'by_customer'      => $group('customer_id', fn (Appointment $a) => $a->customer ? \App\Support\Person::name($a->customer) : '—')->take(100)->values(),
        ]);
    }

    public function availability(Request $request)
    {
        $tenant = $this->tenant($request);

        $validated = $request->validate([
            'service_id'      => 'required|uuid',
            'branch_id'       => 'required|uuid',
            'date'            => 'required|date_format:Y-m-d',
            'professional_id' => 'nullable|uuid',
            'ignore_id'       => 'nullable|uuid',
            'with_busy'       => 'nullable|boolean',
        ]);

        $service = Service::where('tenant_id', $tenant->id)->with('status')->findOrFail($validated['service_id']);
        $branch = Branch::where('tenant_id', $tenant->id)->findOrFail($validated['branch_id']);
        Access::assertBranch($request->user(), $branch->id);
        $professional = empty($validated['professional_id'])
            ? null
            : Professional::where('tenant_id', $tenant->id)->with('status')->findOrFail($validated['professional_id']);

        $date = Carbon::createFromFormat('Y-m-d', $validated['date'], AppointmentScheduler::timezone($branch, $tenant));
        $scheduler = new AppointmentScheduler($tenant);
        $withBusy = !empty($validated['with_busy']);
        $groups = $scheduler->slots($service, $branch, $date, $professional, $validated['ignore_id'] ?? null, null, $withBusy);
        $free = collect($groups)->sum(fn (array $group) => count($group['slots']));

        return response()->json([
            'date'             => $validated['date'],
            'duration_minutes' => $service->duration_minutes,
            'professionals'    => $groups,
        ] + ($withBusy ? [
            'next_days' => $free === 0 ? $scheduler->nextDays($service, $branch, $date, $professional, $validated['ignore_id'] ?? null) : [],
        ] : []));
    }

    public function store(Request $request)
    {
        $key = $request->input('request_key');

        if (!is_string($key) || !\Illuminate\Support\Str::isUuid($key)) {
            return $this->create($request);
        }

        $cacheKey = 'appointment-store:' . $this->tenant($request)->id . ':' . $request->user()->id . ':' . $key;
        $repeat = fn (array $previous) => response()->json($previous['body'] + ['repeated' => true], $previous['status']);

        if ($previous = Cache::get($cacheKey)) {
            return $repeat($previous);
        }

        $lock = Cache::lock($cacheKey . ':lock', 30);

        try {
            $lock->block(20);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            return response()->json(['message' => 'Todavía estamos guardando esta cita. Espera unos segundos y revisa la agenda.', 'code' => 'IN_PROGRESS'], 409);
        }

        try {
            if ($previous = Cache::get($cacheKey)) {
                return $repeat($previous);
            }

            $response = $this->create($request);

            if ($response->getStatusCode() < 400) {
                Cache::put($cacheKey, ['status' => $response->getStatusCode(), 'body' => $response->getData(true)], now()->addMinutes(30));
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    public function series(Request $request)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();

        if (!\App\Support\AppointmentSeries::ready()) {
            return response()->json([
                'message' => 'Las citas que se repiten todavía no están listas en tu sistema. Pide que se actualice la base de datos.',
                'code'    => 'NOT_READY',
            ], 409);
        }

        $validated = $request->validate([
            'customer_id'           => 'required|uuid',
            'customer_dependent_id' => 'nullable|uuid',
            'service_id'            => 'required|uuid',
            'professional_id'       => 'required|uuid',
            'branch_id'             => 'required|uuid',
            'starts_at'             => 'required|date_format:' . self::LOCAL_FORMAT,
            'notes'                 => 'nullable|string|max:2000',
            'frequency'             => ['required', Rule::in(\App\Support\AppointmentSeries::FREQUENCIES)],
            'every'                 => 'nullable|integer|min:1|max:90',
            'until_date'            => 'nullable|required_without:occurrences|date_format:Y-m-d',
            'occurrences'           => 'nullable|required_without:until_date|integer|min:2|max:' . \App\Support\AppointmentSeries::MAX,
            'preview'               => 'nullable|boolean',
        ], [
            'until_date.required_without'  => 'Indica hasta qué fecha se repite o cuántas veces.',
            'occurrences.required_without' => 'Indica cuántas veces se repite o hasta qué fecha.',
            'occurrences.min'              => 'Una cita que se repite tiene al menos 2 fechas.',
            'occurrences.max'              => 'Se pueden agendar hasta ' . \App\Support\AppointmentSeries::MAX . ' fechas de una vez.',
        ]);

        $customer = Customer::where('tenant_id', $tenant->id)->findOrFail($validated['customer_id']);
        $service = Service::where('tenant_id', $tenant->id)->with('status')->findOrFail($validated['service_id']);
        $professional = Professional::where('tenant_id', $tenant->id)->with('status')->findOrFail($validated['professional_id']);
        $branch = Branch::where('tenant_id', $tenant->id)->findOrFail($validated['branch_id']);
        Access::assertBranch($user, $branch->id, true);

        $own = $this->ownProfessionalId($user);
        abort_if($own && $professional->id !== $own, 403, 'Solo puedes agendar citas para ti.');

        $timezone = AppointmentScheduler::timezone($branch, $tenant);
        $first = Carbon::createFromFormat(self::LOCAL_FORMAT, $validated['starts_at'], $timezone)->startOfMinute();
        $time = $first->format('H:i');
        $every = (int) ($validated['every'] ?? 1);

        if (!empty($validated['until_date']) && $validated['until_date'] < $first->format('Y-m-d')) {
            throw ValidationException::withMessages(['until_date' => 'La fecha final debe ser después de la primera cita.']);
        }

        $dates = \App\Support\AppointmentSeries::dates($validated['frequency'], $every, $first->format('Y-m-d'), $validated['until_date'] ?? null, $validated['occurrences'] ?? null);

        if (count($dates) < 2) {
            throw ValidationException::withMessages(['until_date' => 'Con esa fecha final solo sale una cita. Elige una fecha más lejana o usa «Agendar cita».']);
        }

        if (!empty($validated['preview'])) {
            $scheduler = new AppointmentScheduler($tenant);
            $duration = (int) $service->duration_minutes;

            return response()->json([
                'label' => \App\Support\AppointmentSeries::describe($validated['frequency'], $every),
                'dates' => array_map(function (string $date) use ($scheduler, $service, $professional, $branch, $timezone, $time, $duration, $tenant, $customer) {
                    $start = Carbon::createFromFormat('Y-m-d H:i', "{$date} {$time}", $timezone);
                    $check = $scheduler->check($service, $professional, $branch, $start, $duration);
                    $reason = $check['ok'] ? null : collect($check['reasons'])->map(fn (array $r) => $r['message'] . ($r['detail'] !== '' ? " ({$r['detail']})" : ''))->join(' · ');

                    if (!$reason) {
                        try {
                            $this->assertCustomerFree($tenant, $customer, $start, $start->copy()->addMinutes($duration));
                        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                            $reason = $e->getResponse()->getData(true)['message'] ?? 'Choca con otra cita';
                        }
                    }

                    return ['date' => $date, 'starts_at' => "{$date}T{$time}", 'ok' => $reason === null, 'reason' => $reason];
                }, $dates),
            ]);
        }

        $seriesId = (string) \Illuminate\Support\Str::uuid();
        DB::table('appointment_series')->insert([
            'id'              => $seriesId,
            'tenant_id'       => $tenant->id,
            'customer_id'     => $customer->id,
            'service_id'      => $service->id,
            'professional_id' => $professional->id,
            'branch_id'       => $branch->id,
            'frequency'       => $validated['frequency'],
            'every'           => $every,
            'first_date'      => $dates[0],
            'time_local'      => $time,
            'until_date'      => $validated['until_date'] ?? null,
            'occurrences'     => $validated['occurrences'] ?? null,
            'created_by'      => $user->id,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $base = array_intersect_key($validated, array_flip(['customer_id', 'customer_dependent_id', 'service_id', 'professional_id', 'branch_id', 'notes']));
        $created = [];
        $skipped = [];

        $held = \App\Support\Messaging::hold(function () use ($dates, $base, $time, $request, $seriesId, &$created, &$skipped) {
            foreach ($dates as $index => $date) {
                $payload = $base + ['starts_at' => "{$date}T{$time}"];
                $sub = $request->duplicate($request->query->all(), $payload);
                $sub->setJson(new \Symfony\Component\HttpFoundation\InputBag($payload));

                try {
                    $response = $this->create($sub);
                    $data = $response->getData(true);
                    $ok = $response->getStatusCode() < 400;
                    $reason = $ok ? null : ($data['message'] ?? 'No se pudo agendar');
                } catch (ValidationException $e) {
                    $ok = false;
                    $reason = collect($e->errors())->flatten()->first() ?? 'No se pudo agendar';
                } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                    $ok = false;
                    $reason = $e->getResponse()->getData(true)['message'] ?? 'No se pudo agendar';
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                    $ok = false;
                    $reason = $e->getMessage() ?: 'No se pudo agendar';
                }

                if ($ok) {
                    Appointment::whereKey($data['id'])->update(['series_id' => $seriesId, 'series_number' => $index + 1]);
                    $created[] = ['id' => $data['id'], 'code' => $data['code'], 'date' => $date, 'starts_at' => "{$date}T{$time}"];
                } else {
                    $skipped[] = ['date' => $date, 'starts_at' => "{$date}T{$time}", 'reason' => $reason];
                }
            }
        });

        \App\Support\Messaging::releaseSeries($tenant, $held, $seriesId, \App\Support\AppointmentSeries::describe($validated['frequency'], $every));

        if ($created === []) {
            DB::table('appointment_series')->where('id', $seriesId)->delete();

            return response()->json([
                'message' => 'No se pudo agendar ninguna de las fechas.',
                'code'    => 'SERIES_EMPTY',
                'skipped' => $skipped,
            ], 409);
        }

        DB::table('appointment_series')->where('id', $seriesId)->update([
            'created_count' => count($created),
            'skipped'       => json_encode($skipped, JSON_UNESCAPED_UNICODE),
            'updated_at'    => now(),
        ]);

        Audit::log($request, 'appointment.series_created', [
            'tenant'       => $tenant->id,
            'entity_type'  => 'appointment_series',
            'entity_id'    => $seriesId,
            'entity_label' => \App\Support\Person::name($customer) . ' · ' . $service->name,
            'after'        => [
                'frequency' => \App\Support\AppointmentSeries::describe($validated['frequency'], $every),
                'created'   => count($created),
                'skipped'   => count($skipped),
            ],
        ]);

        return response()->json([
            'series_id' => $seriesId,
            'label'     => \App\Support\AppointmentSeries::describe($validated['frequency'], $every),
            'created'   => $created,
            'skipped'   => $skipped,
            'first'     => $this->present(Appointment::with(self::RELATIONS)->findOrFail($created[0]['id']), $tenant, true),
        ], 201);
    }

    protected function create(Request $request): JsonResponse
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
            'recall_id'             => 'nullable|uuid',
            'waitlist_id'           => 'nullable|uuid',
            'walkin_id'             => 'nullable|uuid',
            'video_url'             => \App\Support\VideoLinks::rule(),
        ]);

        $customer = Customer::where('tenant_id', $tenant->id)->findOrFail($validated['customer_id']);
        $recall = $this->recallToBook($tenant, $customer, $validated['recall_id'] ?? null);
        $waiting = $this->waitlistToBook($tenant, $customer, $validated['waitlist_id'] ?? null);
        $walkin = $this->walkinToBook($tenant, $customer, $validated['walkin_id'] ?? null);
        $service = Service::where('tenant_id', $tenant->id)->with('status')->findOrFail($validated['service_id']);
        $professional = Professional::where('tenant_id', $tenant->id)->with('status')->findOrFail($validated['professional_id']);
        $branch = Branch::where('tenant_id', $tenant->id)->findOrFail($validated['branch_id']);
        Access::assertBranch($user, $branch->id, true);

        $own = $this->ownProfessionalId($user);
        abort_if($own && $professional->id !== $own, 403, 'Solo puedes agendar citas para ti.');

        if ($service->kind === Service::KIND_GROUP) {
            throw ValidationException::withMessages(['service_id' => 'Las clases grupales se reservan desde la clase, por cupos.']);
        }

        $dependentId = $this->resolveDependent($tenant, $customer, $validated['customer_dependent_id'] ?? null);

        $start = Carbon::createFromFormat(self::LOCAL_FORMAT, $validated['starts_at'], AppointmentScheduler::timezone($branch, $tenant))->startOfMinute();
        $duration = (int) ($validated['duration_minutes'] ?? $service->duration_minutes);
        $end = $start->copy()->addMinutes($duration);

        return DB::transaction(function () use ($request, $tenant, $user, $validated, $customer, $service, $professional, $branch, $dependentId, $start, $end, $duration, $recall, $waiting, $walkin) {
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
                'source'                => $walkin ? 'walkin' : ($request->attributes->get('appointment_source') ?: 'staff'),
                'price'                 => $service->priceAt($branch->id),
            ] + Currency::column($tenant) + [
                'notes'                 => $validated['notes'] ?? null,
                'override_reason'       => $override,
                'confirmed_at'          => $status === AppointmentFlow::CONFIRMED ? now() : null,
                'created_by'            => $user->id,
            ] + (\App\Support\VideoLinks::ready() && !empty($validated['video_url']) ? ['video_url' => $validated['video_url']] : []));

            $this->log($appointment, null, $status, $user, $override ? "Sobre-agenda: {$override}" : null);
            $this->auditAppointment($request, 'appointment.created', $appointment, $this->auditFacts($appointment));

            if ($override) {
                $this->auditAppointment($request, 'appointment.forced', $appointment, ['override_reason' => $override, 'appointment_at' => $start->toIso8601String()]);
            }

            if ($recall) {
                \App\Support\Recalls::book($recall, $appointment);
            }

            if ($waiting) {
                \App\Support\Waitlist::book($waiting, $appointment);
            }

            if ($walkin) {
                \App\Support\Walkin::book($walkin, $appointment);
            }

            return response()->json($this->present($appointment->fresh(self::RELATIONS), $tenant, true), 201);
        });
    }

    public function update(Request $request, string $appointment)
    {
        $appointment = $this->find($request, $appointment);

        $validated = $request->validate(['notes' => 'present|nullable|string|max:2000', 'video_url' => 'sometimes|' . \App\Support\VideoLinks::rule()]);
        $appointment->update(['notes' => $validated['notes']] + (\App\Support\VideoLinks::ready() && array_key_exists('video_url', $validated) ? ['video_url' => $validated['video_url'] ?: null] : []));

        return response()->json($this->present($appointment, $this->tenant($request), true));
    }

    public function changeRoom(Request $request, string $appointment)
    {
        $tenant = $this->tenant($request);
        abort_unless(\App\Support\RoomBooking::enabled($tenant), 403, 'Tu empresa no tiene encendido el módulo de salas y equipos.');

        $validated = $request->validate(['room_id' => 'required|uuid'], ['room_id.required' => 'Elige la sala.']);

        return DB::transaction(function () use ($request, $appointment, $tenant, $validated) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['appointments:' . $tenant->id]);

            $appointment = $this->find($request, $appointment);
            Access::assertBranch($request->user(), $appointment->branch_id, true);

            if (!in_array($appointment->status, AppointmentFlow::RESCHEDULABLE, true)) {
                return response()->json([
                    'message' => 'Una cita «' . AppointmentFlow::name($tenant, $appointment->status) . '» ya no cambia de sala.',
                    'code'    => 'INVALID_TRANSITION',
                ], 422);
            }

            if ($appointment->room_id === $validated['room_id']) {
                return response()->json($this->present($appointment->fresh(self::RELATIONS), $tenant, true));
            }

            \App\Support\RoomBooking::assertCanUse($tenant, $appointment, $validated['room_id']);

            $before = \App\Support\RoomBooking::name($appointment->room_id);
            $appointment->update(['room_id' => $validated['room_id']]);
            $after = \App\Support\RoomBooking::name($validated['room_id']);

            $this->auditAppointment($request, 'appointment.room_changed', $appointment, ['room' => $after], array_filter(['room' => $before]));

            return response()->json($this->present($appointment->fresh(self::RELATIONS), $tenant, true));
        });
    }

    public function changeStatus(Request $request, string $appointment)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();

        $validated = $request->validate([
            'status' => ['required', Rule::in(AppointmentFlow::keys())],
            'reason' => 'nullable|string|max:255',
            'by_customer' => 'nullable|boolean',
            'series_scope' => ['nullable', Rule::in(['one', 'following', 'all'])],
        ]);

        $to = $validated['status'];
        $reason = trim((string) ($validated['reason'] ?? '')) ?: null;
        $byCustomer = (bool) ($validated['by_customer'] ?? false);
        $scope = $validated['series_scope'] ?? 'one';

        if ($to !== AppointmentFlow::CANCELLED || $scope === 'one') {
            return $this->applyStatus($request, $appointment, $tenant, $user, $to, $reason, $byCustomer);
        }

        $response = null;
        $cancelled = 0;
        $kept = [];
        $seriesKey = $appointment;

        $held = \App\Support\Messaging::hold(function () use ($request, $appointment, $tenant, $user, $to, $reason, $byCustomer, $scope, &$response, &$cancelled, &$kept, &$seriesKey) {
            $response = $this->applyStatus($request, $appointment, $tenant, $user, $to, $reason, $byCustomer);

            if ($response->getStatusCode() >= 400) {
                return;
            }

            $current = Appointment::where('tenant_id', $tenant->id)->find($appointment);
            $seriesKey = ($current?->series_id ?? $appointment) . ':' . now()->timestamp;

            foreach ($current ? \App\Support\AppointmentSeries::siblingsToCancel($current, $scope) : [] as $siblingId) {
                try {
                    $result = $this->applyStatus($request, $siblingId, $tenant, $user, $to, $reason, $byCustomer);
                    $result->getStatusCode() < 400 ? $cancelled++ : $kept[] = $result->getData(true)['message'] ?? '';
                } catch (\Throwable $e) {
                    if (!$e instanceof \Symfony\Component\HttpKernel\Exception\HttpException && !$e instanceof ValidationException && !$e instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                        throw $e;
                    }
                    $kept[] = $e->getMessage();
                }
            }
        });

        \App\Support\Messaging::releaseSeries($tenant, $held, $seriesKey);

        if ($response->getStatusCode() >= 400) {
            return $response;
        }

        return response()->json($response->getData(true) + ['series_cancelled' => $cancelled, 'series_kept' => count($kept)], $response->getStatusCode());
    }

    public function bulkStatus(Request $request)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();

        $validated = $request->validate([
            'ids'    => 'required|array|min:1|max:100',
            'ids.*'  => 'uuid|distinct',
            'status' => ['required', Rule::in([AppointmentFlow::CONFIRMED, AppointmentFlow::CANCELLED])],
            'reason' => 'nullable|string|max:255',
        ], [
            'ids.required' => 'Elige al menos una cita.',
            'ids.max'      => 'Puedes cambiar hasta 100 citas a la vez.',
        ]);

        $reason = trim((string) ($validated['reason'] ?? '')) ?: null;

        if ($validated['status'] === AppointmentFlow::CANCELLED && !$reason && AppointmentFlow::rule($tenant, 'cancel.require_reason')) {
            throw ValidationException::withMessages(['reason' => 'Indica el motivo de la cancelación.']);
        }

        $done = 0;
        $failed = [];

        foreach ($validated['ids'] as $id) {
            try {
                $result = $this->applyStatus($request, $id, $tenant, $user, $validated['status'], $reason, false);
                if ($result->getStatusCode() < 400) {
                    $done++;
                } else {
                    $failed[] = ['id' => $id, 'message' => $result->getData(true)['message'] ?? 'No se pudo cambiar.'];
                }
            } catch (\Throwable $e) {
                $missing = $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                    || ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException && $e->getStatusCode() === 404);
                if (!$missing && !$e instanceof \Symfony\Component\HttpKernel\Exception\HttpException && !$e instanceof ValidationException && !$e instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                    throw $e;
                }
                $failed[] = ['id' => $id, 'message' => $missing ? 'No se encontró la cita.' : $e->getMessage()];
            }
        }

        return response()->json(['done' => $done, 'failed' => $failed]);
    }

    protected function applyStatus(Request $request, string $appointment, Tenant $tenant, mixed $user, string $to, ?string $reason, bool $byCustomer, ?string $cancelledBy = null): JsonResponse
    {
        return DB::transaction(function () use ($request, $appointment, $tenant, $user, $to, $reason, $byCustomer, $cancelledBy) {
            $appointment = $this->find($request, $appointment);
            $appointment = Appointment::whereKey($appointment->id)->lockForUpdate()->first()->load(self::RELATIONS);
            $from = $appointment->status;
            $needed = AppointmentFlow::permission($from, $to);

            if ($user instanceof User && !$user->allows($needed)) {
                return response()->json([
                    'message'    => Access::denial($needed, $tenant),
                    'code'       => 'PERMISSION_DENIED',
                    'permission' => $needed,
                ], 403);
            }

            if (!in_array($to, AppointmentFlow::next($tenant, $from), true)) {
                return response()->json([
                    'message' => 'No se puede pasar de «' . AppointmentFlow::name($tenant, $from) . '» a «' . AppointmentFlow::name($tenant, $to) . '».',
                    'code'    => 'INVALID_TRANSITION',
                ], 422);
            }

            if ($to === AppointmentFlow::CANCELLED && !$reason && AppointmentFlow::rule($tenant, 'cancel.require_reason')) {
                throw ValidationException::withMessages(['reason' => 'Indica el motivo de la cancelación.']);
            }

            if ($to === AppointmentFlow::COMPLETED
                && AppointmentFlow::recordRequired($tenant, $appointment)
                && $this->usesRecord($tenant, $appointment)
                && (!$appointment->record || $appointment->record->status === CareRecord::DRAFT)) {
                return response()->json([
                    'message' => 'Este servicio exige la ficha de atención firmada antes de darla por atendida.',
                    'code'    => 'RECORD_REQUIRED',
                    'appointment_id' => $appointment->id,
                ], 409);
            }

            if (in_array($to, [AppointmentFlow::IN_PROGRESS, AppointmentFlow::COMPLETED], true)
                && (float) $appointment->price > 0
                && \App\Support\Sales::mustPayFirst($tenant)
                && !\App\Support\Sales::appointmentPaid($tenant->id, $appointment->id)) {
                return response()->json([
                    'message' => 'Esta cita aún no está pagada. Cóbrala antes de ' . ($to === AppointmentFlow::IN_PROGRESS ? 'iniciar la atención.' : 'darla por atendida.'),
                    'code'    => 'PAYMENT_REQUIRED',
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
                    $changes['cancelled_by'] = $cancelledBy ?? ($byCustomer && \App\Support\OnlineBooking::lateCancelPreview($tenant, $appointment) ? 'customer' : 'staff');
                    break;
            }

            $appointment->update($changes);
            $this->log($appointment, $from, $to, $user, $reason);

            if ($to === AppointmentFlow::CANCELLED) {
                $this->auditAppointment($request, 'appointment.cancelled', $appointment, $this->auditFacts($appointment) + array_filter(['reason' => $reason]) + ['late_cancel' => $lateCancel]);
            }

            if ($to === AppointmentFlow::NO_SHOW) {
                $this->auditAppointment($request, 'appointment.no_show', $appointment, $this->auditFacts($appointment));
            }

            if ($to === AppointmentFlow::COMPLETED) {
                \App\Support\Inventory::consumeForAppointment($tenant, $appointment, $user);
            }

            $penalty = match (true) {
                $to === AppointmentFlow::NO_SHOW                           => \App\Support\Penalties::apply($tenant, $appointment, \App\Support\Penalties::NO_SHOW, $user),
                $to === AppointmentFlow::CANCELLED && $lateCancel && $byCustomer => \App\Support\Penalties::apply($tenant, $appointment, \App\Support\Penalties::LATE_CANCEL, $user),
                default                                                    => null,
            };

            \App\Support\Recalls::appointmentChanged($tenant, $appointment);
            \App\Support\Waitlist::appointmentChanged($tenant, $appointment);
            \App\Support\Walkin::appointmentChanged($tenant, $appointment);

            return response()->json($this->present($appointment, $tenant, true) + [
                'late_cancel' => $lateCancel,
                'penalty'     => \App\Support\Penalties::present($penalty ?? \App\Support\Penalties::find($tenant->id, $appointment->id)),
                'waitlist_matches' => $to === AppointmentFlow::CANCELLED && $appointment->starts_at->gt($now) && \App\Support\Waitlist::enabled($tenant)
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
            Access::assertBranch($user, $branch->id, true);
            $service = Service::with('status')->findOrFail($appointment->service_id);

            $own = $this->ownProfessionalId($user);
            abort_if($own && $professional->id !== $own, 403, 'Solo puedes agendar citas para ti.');

            $timezone = AppointmentScheduler::timezone($branch, $tenant);
            $start = Carbon::createFromFormat(self::LOCAL_FORMAT, $validated['starts_at'], $timezone)->startOfMinute();
            $duration = (int) $appointment->starts_at->diffInMinutes($appointment->ends_at);
            $end = $start->copy()->addMinutes($duration);
            $previous = $appointment->starts_at->copy()->setTimezone($timezone);

            if ($previous->format('Y-m') !== $start->format('Y-m') && ($response = $this->limitReached($tenant, $start))) {
                return $response;
            }

            $check = (new AppointmentScheduler($tenant))->check($service, $professional, $branch, $start, $duration, $appointment->id);
            $override = $this->override($tenant, $user, $check, $validated['override_reason'] ?? null);

            if (!$check['ok'] && !$override) {
                return $this->unavailable($check, 'Se mantiene la cita original. ');
            }

            $this->assertCustomerFree($tenant, $appointment->customer, $start, $end, $appointment->id);
            $auditBefore = $this->auditPlace($appointment);
            $freed = $this->freedSlot($appointment);

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
            $this->auditRescheduled($request, $appointment->fresh(self::RELATIONS), $auditBefore, $reason);

            $this->offerFreedSlot($tenant, $freed);

            if ($override) {
                $this->auditAppointment($request, 'appointment.forced', $appointment, ['override_reason' => $override, 'appointment_at' => $start->toIso8601String()]);
            }

            return response()->json($this->present($appointment->fresh(self::RELATIONS), $tenant, true));
        });
    }

    protected function freedSlot(Appointment $appointment): Appointment
    {
        $freed = $appointment->replicate();
        $freed->id = $appointment->id;
        $freed->status = AppointmentFlow::CANCELLED;

        return $freed;
    }

    protected function offerFreedSlot(Tenant $tenant, Appointment $freed): void
    {
        if (\App\Support\Waitlist::stillFree($freed)) {
            \App\Support\Waitlist::slotFreed($tenant, $freed);
        }
    }

    public function undoNoShow(Request $request, string $appointment)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();

        return DB::transaction(function () use ($request, $appointment, $tenant, $user) {
            $appointment = $this->find($request, $appointment);
            $appointment = Appointment::whereKey($appointment->id)->lockForUpdate()->first()->load(self::RELATIONS);
            Access::assertBranch($user, $appointment->branch_id, true);

            if ($appointment->status !== AppointmentFlow::NO_SHOW) {
                return response()->json(['message' => 'Esta cita no está marcada como «' . AppointmentFlow::name($tenant, AppointmentFlow::NO_SHOW) . '».', 'code' => 'INVALID_TRANSITION'], 422);
            }

            $blocked = $this->noShowUndoBlocked($tenant, $appointment);

            if ($blocked) {
                return response()->json(['message' => $blocked, 'code' => 'POLICY'], 422);
            }

            $penalty = \App\Support\Penalties::find($tenant->id, $appointment->id);
            $waived = null;

            if ($penalty && in_array($penalty->status, ['pending', 'partial'], true)) {
                \App\Support\Refunds::void($penalty, 'Se deshizo «No asistió» de la cita ' . $appointment->code);
                $waived = $penalty;
            }

            $back = AppointmentStatusHistory::where('appointment_id', $appointment->id)->where('to_status', AppointmentFlow::NO_SHOW)->orderByDesc('created_at')->value('from_status');
            $back = in_array($back, [AppointmentFlow::PENDING, AppointmentFlow::CONFIRMED], true) ? $back : AppointmentFlow::CONFIRMED;

            $appointment->update(['status' => $back] + ($back === AppointmentFlow::CONFIRMED ? ['confirmed_at' => $appointment->confirmed_at ?? now()] : []));
            $this->log($appointment, AppointmentFlow::NO_SHOW, $back, $user, 'Se deshizo «No asistió»' . ($waived ? " y se perdonó la penalidad {$waived->code}" : ''));
            $this->auditAppointment($request, 'appointment.no_show_undone', $appointment, $this->auditFacts($appointment) + array_filter(['penalty_waived' => $waived?->code]));

            return response()->json($this->present($appointment->fresh(self::RELATIONS), $tenant, true) + [
                'penalty'        => \App\Support\Penalties::present(\App\Support\Penalties::find($tenant->id, $appointment->id)),
                'penalty_waived' => $waived ? ['code' => $waived->code, 'total' => round((float) $waived->total, 2), 'currency' => $waived->currency ?? null] : null,
            ]);
        });
    }

    private function noShowUndoBlocked(Tenant $tenant, Appointment $appointment): ?string
    {
        $penalty = \App\Support\Penalties::find($tenant->id, $appointment->id);

        if ($penalty && (float) $penalty->paid > 0) {
            return "La penalidad {$penalty->code} ya tiene un pago. Primero devuélvelo desde Ventas y luego deshaz «No asistió».";
        }

        if (Schema::hasTable('package_usages') && DB::table('package_usages')->where('appointment_id', $appointment->id)->exists()) {
            return 'Por la falta ya se descontó una sesión del paquete. No se puede deshacer desde aquí.';
        }

        $sale = \App\Support\Sales::ready() ? \App\Support\Sales::ofAppointment($tenant->id, $appointment->id) : null;

        if ($sale && ($sale->deposit_outcome ?? null) === 'retained') {
            return "El adelanto de esta cita ya quedó retenido por la falta (venta {$sale->code}). No se puede deshacer desde aquí.";
        }

        return null;
    }

    public function customerNotes(Request $request)
    {
        $tenant = $this->tenant($request);

        $validated = $request->validate([
            'customer_id' => 'required|uuid',
            'service_id'  => 'nullable|uuid',
            'customer_dependent_id' => 'nullable|uuid',
        ]);

        $customer = Customer::where('tenant_id', $tenant->id)->findOrFail($validated['customer_id']);
        $serviceId = $validated['service_id'] ?? null;
        $timezone = $tenant->zone();
        $notes = [];

        if (Schema::hasColumn('customers', 'alert') && trim((string) $customer->alert) !== '') {
            $notes[] = ['kind' => 'alert', 'tone' => 'warn', 'title' => 'Alerta', 'text' => trim((string) $customer->alert)];
        }

        if (!empty($validated['customer_dependent_id']) && $tenant->dependents()['enabled'] && CustomerDependent::alertReady()) {
            $dependent = CustomerDependent::where('tenant_id', $tenant->id)->where('customer_id', $customer->id)->find($validated['customer_dependent_id']);

            if ($dependent && trim((string) $dependent->alert) !== '') {
                $notes[] = ['kind' => 'dependent_alert', 'tone' => 'warn', 'title' => "Alerta de {$dependent->name}", 'text' => trim((string) $dependent->alert)];
            }
        }

        if (\App\Support\Sales::ready()) {
            $debt = DB::table('sales')
                ->where('tenant_id', $tenant->id)
                ->where('customer_id', $customer->id)
                ->whereIn('status', ['pending', 'partial'])
                ->where('balance', '>', 0)
                ->selectRaw('count(*) AS count, coalesce(sum(balance), 0) AS total')
                ->first();

            if ((int) ($debt->count ?? 0) > 0) {
                $count = (int) $debt->count;
                $notes[] = [
                    'kind'  => 'debt',
                    'tone'  => 'bad',
                    'title' => 'Tiene saldo pendiente',
                    'text'  => 'Debe ' . Currency::format((float) $debt->total, Currency::of($tenant)) . ' en ' . ($count === 1 ? '1 venta' : "{$count} ventas") . ' por cobrar.',
                ];
            }
        }

        $since = now()->subDays(90);
        $missed = Appointment::where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->where('status', AppointmentFlow::NO_SHOW)
            ->where('starts_at', '>=', $since->toIso8601String())
            ->orderByDesc('starts_at')
            ->get(['starts_at']);

        if ($missed->isNotEmpty()) {
            $count = $missed->count();
            $notes[] = [
                'kind'  => 'no_show',
                'tone'  => 'warn',
                'title' => 'Faltas recientes',
                'text'  => ($count === 1 ? 'No asistió a 1 cita' : "No asistió a {$count} citas") . ' en los últimos 90 días (la última, el ' . $missed->first()->starts_at->copy()->setTimezone($timezone)->format('d/m/Y') . ').',
            ];
        }

        if ($serviceId && \App\Support\Packages::enabled($tenant)) {
            $package = \App\Support\Packages::usable($tenant->id, $customer->id, $serviceId, now())[0] ?? null;

            if ($package) {
                $left = collect($package['items'])->where('service_id', $serviceId)->sum('available');
                $notes[] = [
                    'kind'  => 'package',
                    'tone'  => 'ok',
                    'title' => 'Tiene un paquete que cubre este servicio',
                    'text'  => "{$package['name']} ({$package['code']}): " . ($left === 1 ? 'le queda 1 sesión' : "le quedan {$left} sesiones") . ' de este servicio.',
                ];
            }
        }

        if (\App\Support\Memberships::enabled($tenant) && ($membership = \App\Support\Memberships::current($tenant, $customer->id))) {
            $summary = \App\Support\Memberships::summary($tenant, $membership);
            $included = $serviceId ? collect($summary['included'])->firstWhere('service_id', $serviceId) : null;
            $grace = $summary['status'] === 'grace' ? ' Está en sus días de gracia: aún no renueva.' : '';

            if ($included && $included['left'] > 0) {
                $notes[] = ['kind' => 'membership', 'tone' => 'ok', 'title' => 'Su membresía cubre este servicio', 'text' => "{$summary['name']}: " . ($included['left'] === 1 ? 'le queda 1 incluido' : "le quedan {$included['left']} incluidos") . ' en este periodo.' . $grace];
            } elseif ($summary['discount_percent'] > 0) {
                $notes[] = ['kind' => 'membership', 'tone' => 'ok', 'title' => 'Tiene membresía vigente', 'text' => "{$summary['name']}: {$summary['benefits_text']}." . $grace];
            } elseif ($included) {
                $notes[] = ['kind' => 'membership', 'tone' => 'info', 'title' => 'Tiene membresía vigente', 'text' => "{$summary['name']}: ya usó los incluidos de este servicio en este periodo." . $grace];
            }
        }

        try {
            $segments = $request->user()->allows('customers.view')
                ? collect(\App\Support\CustomerProfile::segments($tenant, $customer->id))->map(fn (array $s) => ['key' => $s['key'], 'label' => $s['label']])->values()->all()
                : [];
        } catch (\Throwable $e) {
            report($e);
            $segments = [];
        }

        return response()->json(['notes' => $notes, 'segments' => $segments]);
    }

    public function remind(Request $request, string $appointment)
    {
        $tenant = $this->tenant($request);
        $appointment = $this->find($request, $appointment);

        if (!in_array($appointment->status, [AppointmentFlow::PENDING, AppointmentFlow::CONFIRMED], true) || !$appointment->starts_at->isFuture()) {
            return response()->json(['message' => 'Solo se envía recordatorio a citas pendientes o confirmadas que aún no empiezan.', 'code' => 'INVALID_STATE'], 422);
        }

        if (!\App\Support\Messaging::ready()) {
            return response()->json(['message' => 'Los mensajes aún no están disponibles: falta actualizar la base de datos.', 'code' => 'NOT_READY'], 422);
        }

        $customer = $appointment->customer;
        $vars = \App\Support\Messaging::appointmentVars($tenant, $appointment);
        $extra = ['force' => true, 'user' => $request->user(), 'branch_id' => $appointment->branch_id, 'entity_type' => 'appointment', 'entity_id' => $appointment->id];
        $message = \App\Support\Messaging::queue($tenant, 'reminder', $customer, $vars, $extra);

        if (!$message) {
            return response()->json(['message' => 'No se pudo preparar el recordatorio. Inténtalo otra vez.'], 422);
        }

        $this->auditAppointment($request, 'appointment.reminded', $appointment, array_filter([
            'reminder_channel' => ($message->channel ?? 'email') === 'whatsapp' ? 'WhatsApp' : 'Correo',
            'reminder_to'      => $message->to_address ?? null,
            'reminder_result'  => ($message->status ?? '') === 'not_sent' ? 'No se envió: ' . ($message->error ?? '') : 'Enviado',
        ]));

        if (($message->status ?? '') === 'not_sent') {
            return response()->json(['message' => $message->error ?: 'No se pudo enviar el recordatorio.', 'code' => 'NOT_SENT', 'status' => 'not_sent'], 422);
        }

        $channel = ($message->channel ?? 'email') === 'whatsapp' ? 'por WhatsApp' : 'por correo';

        return response()->json([
            'message' => "Recordatorio enviado {$channel} a " . ($message->to_address ?? \App\Support\Person::name($customer)) . '.',
            'status'  => $message->status,
        ]);
    }

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

    protected function limitReached(Tenant $tenant, Carbon $start): ?JsonResponse
    {
        $reached = \App\Support\AppointmentEvents::monthLimit($tenant, $start);

        if (!$reached) {
            return null;
        }

        return response()->json([
            'message' => "Tu plan permite {$reached['limit']} citas por mes y ese mes ya las tiene. Elige otro mes o mejora tu plan.",
            'code'    => 'PLAN_LIMIT',
            'limit'   => $reached['limit'],
            'used'    => $reached['used'],
        ], 422);
    }

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

    protected function walkinToBook(Tenant $tenant, Customer $customer, ?string $id): ?\App\Models\WalkinEntry
    {
        if (!$id || !\App\Support\Walkin::ready()) {
            return null;
        }

        $entry = \App\Models\WalkinEntry::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($id);

        if ($entry->customer_id !== $customer->id) {
            throw ValidationException::withMessages(['walkin_id' => 'Ese turno de la cola es de otra persona.']);
        }

        if ($entry->status !== \App\Models\WalkinEntry::WAITING) {
            throw ValidationException::withMessages(['walkin_id' => 'Esa persona ya no está esperando en la cola.']);
        }

        return $entry;
    }

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

    protected function override(Tenant $tenant, User $user, array $check, ?string $reason): ?string
    {
        $reason = trim((string) $reason);

        if ($check['ok'] || !$check['overridable'] || $reason === '') {
            return null;
        }

        if (!AppointmentFlow::rule($tenant, 'agenda.allow_override') || !$user->allows('agenda.override')) {
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
                'message' => \App\Support\Person::name($customer) . " ya tiene la cita {$other->code} de "
                    . $other->starts_at->copy()->setTimezone($timezone)->format('H:i') . ' a '
                    . $other->ends_at->copy()->setTimezone($timezone)->format('H:i')
                    . " ese día ({$other->service?->name}).",
                'code'    => 'CLIENT_OVERLAP',
            ], 409));
        }
    }

    protected function usesRecord(Tenant $tenant, Appointment $appointment): bool
    {
        if (!$tenant->hasModule('records')) {
            return false;
        }

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

    protected function auditPlace(Appointment $appointment): array
    {
        $appointment->loadMissing(['professional', 'branch']);

        return [
            'appointment_at' => $appointment->starts_at?->toIso8601String(),
            'professional'   => $appointment->professional?->display_name,
            'branch'         => $appointment->branch?->name,
        ];
    }

    protected function auditFacts(Appointment $appointment): array
    {
        $appointment->loadMissing(['service', 'dependent']);

        return array_filter([
            'service'        => $appointment->service?->name,
        ] + $this->auditPlace($appointment) + [
            'dependent_name' => $appointment->dependent?->name,
        ], fn ($value) => $value !== null);
    }

    protected function auditRescheduled(Request $request, Appointment $appointment, array $before, ?string $reason): void
    {
        [$from, $to] = Audit::changed($before, $this->auditPlace($appointment));

        if (!$from && !$to) {
            return;
        }

        $this->auditAppointment($request, 'appointment.rescheduled', $appointment, $to + array_filter(['reason' => $reason]), $from);
    }

    protected function auditAppointment(Request $request, string $action, Appointment $appointment, array $after = [], array $before = []): void
    {
        $appointment->loadMissing('customer');
        $name = \App\Support\Person::name($appointment->customer);

        Audit::log($request, $action, array_filter([
            'tenant'       => $appointment->tenant_id,
            'entity_type'  => 'appointment',
            'entity_id'    => $appointment->id,
            'entity_label' => trim($appointment->code . ($name !== '' ? " · {$name}" : '')),
            'branch_id'    => $appointment->branch_id,
            'before'       => $before ?: null,
            'after'        => $after ?: null,
        ], fn ($value) => $value !== null));
    }

    protected function log(Appointment $appointment, ?string $from, string $to, User|Customer $actor, ?string $reason): void
    {
        \App\Support\AppointmentEvents::record($appointment, $from, $to, $actor, $reason);
    }

    protected function present(Appointment $appointment, Tenant $tenant, bool $withHistory = false): array
    {
        $timezone = AppointmentScheduler::timezone($appointment->branch ?? new Branch(), $tenant);
        $local = fn (?Carbon $date) => $date?->copy()->setTimezone($timezone)->format(self::LOCAL_FORMAT);
        $customer = $appointment->customer;
        $dependent = $appointment->dependent;
        $viewer = request()->user();
        $canEdit = !$viewer instanceof User || $viewer->allows('agenda.edit');

        $data = [
            'id'               => $appointment->id,
            'code'             => $appointment->code,
            'status'           => $appointment->status,
            'status_name'      => AppointmentFlow::name($tenant, $appointment->status),
            'next_statuses'    => AppointmentFlow::allowedNext($tenant, $appointment->status, $viewer),
            'can_remind'       => $viewer instanceof User && $viewer->allows('agenda.confirm') && in_array($appointment->status, [AppointmentFlow::PENDING, AppointmentFlow::CONFIRMED], true) && $appointment->starts_at->isFuture(),
            'can_reschedule'   => $canEdit && in_array($appointment->status, AppointmentFlow::RESCHEDULABLE, true),
            'can_undo_no_show' => $appointment->status === AppointmentFlow::NO_SHOW && $viewer instanceof User && $viewer->allows('agenda.noshow'),
            'starts_at'        => $local($appointment->starts_at),
            'ends_at'          => $local($appointment->ends_at),
            'duration_minutes' => (int) $appointment->starts_at->diffInMinutes($appointment->ends_at),
            'price'            => $appointment->price,
            'currency'         => $appointment->currency ?? Currency::DEFAULT,
            'source'           => $appointment->source,
            'source_label'     => self::originLabel($appointment->source, $appointment->getAttribute('channel')),
            'buffers'          => [
                'before' => (int) ($appointment->service?->buffer_before_minutes ?? 0),
                'after'  => (int) ($appointment->service?->buffer_after_minutes ?? 0),
            ],
            'notes'            => $appointment->notes,
            'video_url'        => \App\Support\VideoLinks::for($appointment),
            'own_video_url'    => \App\Support\VideoLinks::ready() ? $appointment->video_url : null,
            'is_virtual'       => (bool) $appointment->service?->is_virtual,
            'reschedule_count' => $appointment->reschedule_count,
            'series'           => $withHistory
                ? \App\Support\AppointmentSeries::present($appointment)
                : ($appointment->series_id ? ['id' => $appointment->series_id, 'number' => $appointment->series_number] : null),
            'override_reason'  => $appointment->override_reason,
            'cancel_reason'    => $appointment->cancel_reason,
            'confirmed_at'     => $local($appointment->confirmed_at),
            'checked_in_at'    => $local($appointment->checked_in_at),
            'started_at'       => $local($appointment->started_at),
            'completed_at'     => $local($appointment->completed_at),
            'cancelled_at'     => $local($appointment->cancelled_at),
            'confirm_by'       => $local(\App\Support\Confirmations::deadline($tenant, $appointment)),
            'confirmation_overdue' => \App\Support\Confirmations::overdue($tenant, $appointment),
            'timing'           => AppointmentTiming::of($appointment),
            'record'           => [
                'uses'     => $uses = $this->usesRecord($tenant, $appointment),
                'status'   => $appointment->record?->currentStatus(),
                'required' => $uses && AppointmentFlow::recordRequired($tenant, $appointment),
            ],
            'occupied_until'   => $local(AppointmentTiming::occupiedUntil($appointment)),
            'customer'         => $customer ? [
                'id'    => $customer->id,
                'code'  => $customer->code,
                'name'  => \App\Support\Person::name($customer),
                'phone' => $customer->phone,
            ] : null,
            'dependent'        => $dependent ? [
                'id'      => $dependent->id,
                'name'    => $dependent->name,
                'species' => $dependent->species,
            ] : null,
            'dependent_alert'  => $dependent && trim((string) $dependent->getAttribute('alert')) !== '' ? trim((string) $dependent->getAttribute('alert')) : null,
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
            'room'             => $appointment->room_id && ($roomName = \App\Support\RoomBooking::name($appointment->room_id)) !== null ? [
                'id'   => $appointment->room_id,
                'name' => $roomName,
            ] : null,
        ];

        if ($withHistory) {
            $data['room_options'] = \App\Support\RoomBooking::enabled($tenant) && $canEdit && in_array($appointment->status, AppointmentFlow::RESCHEDULABLE, true)
                ? \App\Support\RoomBooking::options($tenant, $appointment)
                : null;

            $data['history'] = $appointment->history()->with(['actor:id,first_name,last_name', 'customerActor:id,first_name,last_name'])->get()
                ->map(fn (AppointmentStatusHistory $step) => [
                    'from'      => $step->from_status,
                    'to'        => $step->to_status,
                    'to_name'   => AppointmentFlow::name($tenant, $step->to_status),
                    'reason'    => $step->reason,
                    'by'        => ($who = $step->actor ?? $step->customerActor) ? \App\Support\Person::name($who) : null,
                    'at'        => $local($step->created_at),
                ]);
        }

        return $data;
    }
}
