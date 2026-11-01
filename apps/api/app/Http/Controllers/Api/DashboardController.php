<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentStatusHistory;
use App\Models\CareRecord;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\ProfessionalSchedule;
use App\Models\Recall;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\AppointmentFlow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Support\Access;
use App\Support\Currency;

class DashboardController extends Controller
{
    private const LOCAL_FORMAT = 'Y-m-d\TH:i';
    private const OPEN = [AppointmentFlow::PENDING, AppointmentFlow::CONFIRMED, AppointmentFlow::CHECKED_IN, AppointmentFlow::IN_PROGRESS];

    public function show(Request $request)
    {
        $user = $request->user();
        $tenant = $user->tenant;
        abort_unless($tenant, 403, 'Esta cuenta no pertenece a una empresa.');
        $tenant->loadMissing(['settings', 'activeSubscription.plan']);

        $timezone = $tenant->zone();
        $own = $this->ownProfessionalId($user);
        $limit = Access::branchLimit($user);
        $now = now($timezone);
        $dayStart = $now->copy()->startOfDay();
        $dayEnd = $dayStart->copy()->addDay();
        $local = fn (?Carbon $date) => $date?->copy()->setTimezone($timezone)->format(self::LOCAL_FORMAT);

        $appointments = fn (): Builder => Appointment::where('appointments.tenant_id', $tenant->id)
            ->when($own, fn ($q) => $q->where('appointments.professional_id', $own))
            ->when($limit, fn ($q, $id) => $q->where('appointments.branch_id', $id));
        $between = fn (Builder $q, Carbon $from, Carbon $to): Builder => $q->where('appointments.starts_at', '>=', $from->toIso8601String())->where('appointments.starts_at', '<', $to->toIso8601String());
        $who = fn (?Appointment $a) => $a ? ($a->dependent?->name ?? ($a->customer ? \App\Support\Person::name($a->customer) : null)) : null;

        $today = $between($appointments(), $dayStart, $dayEnd)->with(['customer', 'dependent', 'service', 'professional', 'branch'])->orderBy('starts_at')->get();
        $active = $today->whereNotIn('status', AppointmentFlow::RELEASED);
        $completed = $today->where('status', AppointmentFlow::COMPLETED);
        $lastWeek = $between($appointments(), $dayStart->copy()->subWeek(), $dayEnd->copy()->subWeek())->whereNotIn('status', AppointmentFlow::RELEASED)->count();

        $scheduled = ProfessionalSchedule::where('professional_schedules.tenant_id', $tenant->id)
            ->where('weekday', $dayStart->dayOfWeek)
            ->when($own, fn ($q) => $q->where('professional_id', $own))
            ->join('branches', 'branches.id', '=', 'professional_schedules.branch_id')
            ->where('branches.status', 'active')
            ->when($limit, fn ($q, $id) => $q->where('professional_schedules.branch_id', $id))
            ->get(['starts_local', 'ends_local'])
            ->sum(fn ($s) => Carbon::parse($s->starts_local)->diffInMinutes(Carbon::parse($s->ends_local)));
        $booked = $active->sum(fn (Appointment $a) => $a->starts_at->diffInMinutes($a->ends_at));

        $upcoming = $today->filter(fn (Appointment $a) => in_array($a->status, self::OPEN, true) && $a->ends_at->gte($now))->take(6)->values();
        $branches = $own ? collect() : $active->groupBy('branch_id')->map(fn ($group) => ['name' => $group->first()->branch?->name, 'count' => $group->count()])->sortByDesc('count')->values();

        $monthAgo = $dayStart->copy()->subDays(30);
        $currency = Currency::of($tenant);
        $value = Currency::ready() ? "coalesce(sum(price) filter (where appointments.currency = '{$currency}'), 0) as value" : 'coalesce(sum(price), 0) as value';
        $byStatus = $between($appointments(), $monthAgo, $now)->select('appointments.status', DB::raw('count(*) as total'), DB::raw($value))->groupBy('status')->get()->keyBy('status');
        $attended = (int) ($byStatus[AppointmentFlow::COMPLETED]->total ?? 0);
        $noShow = (int) ($byStatus[AppointmentFlow::NO_SHOW]->total ?? 0);
        $cancelled = (int) ($byStatus[AppointmentFlow::CANCELLED]->total ?? 0);

        $topServices = $between($appointments(), $monthAgo, $now)->whereNotIn('appointments.status', AppointmentFlow::RELEASED)
            ->join('services', 'services.id', '=', 'appointments.service_id')
            ->select('services.name', DB::raw('count(*) as total'))
            ->groupBy('services.name')->orderByDesc('total')->orderBy('services.name')->limit(3)->get();

        $monthStart = $now->copy()->startOfMonth();
        $newCustomers = Customer::where('tenant_id', $tenant->id)->where('created_at', '>=', $monthStart->toIso8601String())->count();
        $newCustomersBefore = Customer::where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $monthStart->copy()->subMonthNoOverflow()->toIso8601String())
            ->where('created_at', '<', $monthStart->toIso8601String())->count();

        $toConfirm = $between($appointments(), $now, $dayEnd->copy()->addDay())->where('status', AppointmentFlow::PENDING)->count();
        $unclosed = $between($appointments(), $monthAgo, $now)->whereIn('status', self::OPEN)->where('ends_at', '<', $now->copy()->subHour()->toIso8601String())->count();

        $pending = [
            ['key' => 'to_confirm', 'count' => $toConfirm, 'href' => '/appointments'],
            ['key' => 'unclosed', 'count' => $unclosed, 'href' => '/appointments'],
            ['key' => 'blocked', 'count' => $this->blockedAppointments($appointments(), $now, $timezone), 'href' => '/blocks'],
            ['key' => 'recalls', 'count' => Recall::where('tenant_id', $tenant->id)->whereIn('status', ['pending', 'notified'])
                ->where('due_date', '<', $dayStart->toDateString())->when($own, fn ($q) => $q->where('professional_id', $own))->count(), 'href' => '/recalls'],
            ['key' => 'waitlist', 'count' => WaitlistEntry::where('tenant_id', $tenant->id)->where('status', 'waiting')
                ->when($own, fn ($q) => $q->where('professional_id', $own))->count(), 'href' => '/waitlist'],
        ];

        if ($tenant->hasModule('records')) {
            $pending[] = ['key' => 'draft_records', 'count' => CareRecord::where('tenant_id', $tenant->id)->where('status', CareRecord::DRAFT)
                ->when($own, fn ($q) => $q->where('professional_id', $own))->count(), 'href' => '/records'];
        }

        if ($tenant->hasModule('walkin') && $user->allows('walkin.view') && \App\Support\Walkin::ready()) {
            $pending[] = [
                'key'   => 'walkin',
                'count' => \App\Models\WalkinEntry::where('tenant_id', $tenant->id)
                    ->where('status', \App\Models\WalkinEntry::WAITING)
                    ->whereBetween('arrived_at', [$dayStart->copy()->utc(), $dayEnd->copy()->utc()])
                    ->when($limit, fn ($q, $id) => $q->where('branch_id', $id))
                    ->when($own, fn ($q) => $q->where(fn ($sub) => $sub->whereNull('professional_id')->orWhere('professional_id', $own)))
                    ->count(),
                'href'  => '/walkin',
            ];
        }

        $activity = AppointmentStatusHistory::where('tenant_id', $tenant->id)
            ->where('created_at', '<=', $now->toIso8601String())
            ->when($own, fn ($q) => $q->whereHas('appointment', fn ($q) => $q->where('professional_id', $own)))
            ->with(['appointment.customer', 'appointment.dependent', 'appointment.service', 'actor', 'customerActor'])
            ->orderByDesc('created_at')->limit(8)->get();

        return response()->json([
            'scope' => $own ? 'own' : 'company',
            'today' => [
                'total'        => $active->count(),
                'last_week'    => $lastWeek,
                'completed'    => $completed->count(),
                'value'        => round((float) $completed->filter(fn ($a) => ($a->currency ?? $currency) === $currency)->sum('price'), 2),
                'waiting'      => $today->where('status', AppointmentFlow::CHECKED_IN)->count(),
                'in_progress'  => $today->where('status', AppointmentFlow::IN_PROGRESS)->count(),
                'to_confirm'   => $today->where('status', AppointmentFlow::PENDING)->filter(fn (Appointment $a) => $a->starts_at->gte($now))->count(),
                'released'     => $today->whereIn('status', AppointmentFlow::RELEASED)->count(),
                'occupancy'    => $scheduled > 0 ? min(100, (int) round($booked * 100 / $scheduled)) : null,
                'branches'     => $branches,
                'upcoming'     => $upcoming->map(fn (Appointment $a) => [
                    'id'           => $a->id,
                    'starts_at'    => $local($a->starts_at),
                    'who'          => $who($a),
                    'service'      => $a->service?->name,
                    'professional' => $a->professional?->display_name,
                    'branch'       => $a->branch?->name,
                    'status'       => $a->status,
                    'status_name'  => AppointmentFlow::name($tenant, $a->status),
                ]),
            ],
            'month' => [
                'attended'             => $attended,
                'no_show'              => $noShow,
                'cancelled'            => $cancelled,
                'attendance'           => $attended + $noShow > 0 ? (int) round($attended * 100 / ($attended + $noShow)) : null,
                'value'                => round((float) ($byStatus[AppointmentFlow::COMPLETED]->value ?? 0), 2),
                'new_customers'        => $newCustomers,
                'new_customers_before' => $newCustomersBefore,
                'top_services'         => $topServices->map(fn ($row) => ['name' => $row->name, 'count' => (int) $row->total]),
            ],
            'pending'  => $pending,
            'activity' => $activity->map(fn (AppointmentStatusHistory $event) => [
                'at'      => $local($event->created_at),
                'from'    => $event->from_status,
                'to'      => $event->to_status,
                'to_name' => AppointmentFlow::name($tenant, $event->to_status),
                'by'      => $event->actor ? \App\Support\Person::name($event->actor) : null,
                'by_customer' => (bool) $event->actor_customer_id,
                'code'    => $event->appointment?->code,
                'who'     => $who($event->appointment),
                'customer_id' => $event->appointment?->customer_id,
                'service' => $event->appointment?->service?->name,
            ]),
        ]);
    }

    private function blockedAppointments(Builder $query, Carbon $now, string $timezone): int
    {
        return $query->where('appointments.starts_at', '>=', $now->toIso8601String())
            ->whereIn('appointments.status', [AppointmentFlow::PENDING, AppointmentFlow::CONFIRMED])
            ->where(function ($q) use ($timezone) {
                $q->whereExists(fn ($sub) => $sub->from('availability_blocks as b')
                    ->whereColumn('b.tenant_id', 'appointments.tenant_id')
                    ->where(fn ($w) => $w->whereNull('b.professional_id')->orWhereColumn('b.professional_id', 'appointments.professional_id'))
                    ->where(fn ($w) => $w->whereNull('b.branch_id')->orWhereColumn('b.branch_id', 'appointments.branch_id'))
                    ->whereColumn('b.starts_at', '<', 'appointments.ends_at')
                    ->whereColumn('b.ends_at', '>', 'appointments.starts_at'));

                if (Schema::hasTable('holidays')) {
                    $q->orWhereExists(fn ($sub) => $sub->from('holidays as h')
                        ->whereColumn('h.tenant_id', 'appointments.tenant_id')
                        ->where('h.closed', true)
                        ->where(fn ($w) => $w->whereNull('h.branch_id')->orWhereColumn('h.branch_id', 'appointments.branch_id'))
                        ->whereRaw('h.date = (appointments.starts_at AT TIME ZONE ?)::date', [$timezone]));
                }
            })
            ->count();
    }

    private function ownProfessionalId(User $user): ?string
    {
        if ($user->isTenantAdmin() || !$user->roles()->where('slug', 'professional')->exists()) {
            return null;
        }

        return Professional::where('tenant_id', $user->tenant_id)->where('user_id', $user->id)->value('id')
            ?? '00000000-0000-0000-0000-000000000000';
    }
}
