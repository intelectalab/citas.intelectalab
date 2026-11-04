<?php

namespace App\Support;

use App\Models\Status;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PlatformReports
{
    public const TABS = [
        'income'  => 'Ingresos',
        'tenants' => 'Empresas',
        'usage'   => 'Uso',
    ];

    public const ZONE = 'America/Lima';

    public const NEAR_LIMIT = 80;

    public const IDLE_DAYS = 14;

    public const LIMITS = [
        'appointments_month' => 'Citas del mes',
        'users'              => 'Usuarios',
        'professionals'      => 'Profesionales',
        'branches'           => 'Sedes',
    ];

    public const STATUSES = [
        'active'    => 'Activa',
        'trial'     => 'En prueba',
        'grace'     => 'En días de gracia',
        'locked'    => 'Solo lectura',
        'none'      => 'Sin plan',
        'canceled'  => 'Cancelada',
        'suspended' => 'Suspendida',
    ];

    private ?Collection $tenants = null;

    private ?string $main = null;

    public function __construct(private ?Carbon $from = null, private ?Carbon $to = null, private ?Carbon $prevFrom = null, private ?Carbon $prevTo = null)
    {
    }

    private function iso(Carbon $date): string
    {
        return $date->copy()->utc()->toIso8601String();
    }

    public function tenants(): Collection
    {
        return $this->tenants ??= Tenant::query()->whereNull('deleted_at')->with('businessType')->orderBy('name')->get()->map(function (Tenant $tenant) {
            $subscription = Subscriptions::latest($tenant->id);
            $state = Subscriptions::state($tenant->id);
            $plan = $subscription?->plan;
            $cycle = $subscription?->billing_cycle ?? 'monthly';
            $price = Subscriptions::price($plan, $cycle);

            $status = match (true) {
                $tenant->status !== 'active'                         => 'suspended',
                $subscription?->status === 'canceled'                => 'canceled',
                in_array($state['state'], ['trial', 'card_needed'], true) => 'trial',
                default                                              => $state['state'],
            };

            return (object) [
                'id'            => $tenant->id,
                'name'          => $tenant->name,
                'plan_id'       => $plan?->id,
                'plan'          => $plan?->name,
                'currency'      => substr($plan?->currency ?? Currency::DEFAULT, 0, 3),
                'cycle'         => $cycle,
                'price'         => $price,
                'monthly'       => $cycle === 'yearly' ? round($price / 12, 2) : $price,
                'free'          => (bool) ($state['free'] ?? false) || $price <= 0,
                'status'        => $status,
                'business_type' => $tenant->businessType?->name,
                'created_at'    => $tenant->created_at,
                'limits'        => is_array($plan?->limits) ? $plan->limits : [],
            ];
        });
    }

    public function currency(): string
    {
        return $this->main ??= (string) ($this->tenants()->whereNotNull('plan_id')->countBy('currency')->sortDesc()->keys()->first() ?? Currency::DEFAULT);
    }

    private function money(Collection $byCurrency): array
    {
        $main = $this->currency();

        return [
            'amount' => round((float) ($byCurrency[$main] ?? 0), 2),
            'others' => $byCurrency->except($main)->filter(fn ($v) => abs((float) $v) > 0)
                ->map(fn ($v, $code) => ['currency' => $code, 'amount' => round((float) $v, 2)])->values()->all(),
        ];
    }

    private function recurring(): Collection
    {
        return $this->tenants()->filter(fn ($t) => in_array($t->status, ['active', 'grace'], true) && !$t->free);
    }

    private function owing(): Collection
    {
        return $this->tenants()->filter(fn ($t) => in_array($t->status, ['grace', 'locked'], true) && !$t->free && $t->plan_id);
    }

    private function review(): array
    {
        if (!PlanTransfers::ready()) {
            return ['count' => 0, 'amount' => 0.0];
        }

        $rows = DB::table('plan_payments')->where('kind', 'transfer')->where('status', 'review')->get(['amount', 'currency']);

        return ['count' => $rows->count(), 'amount' => round((float) $rows->where('currency', $this->currency())->sum('amount'), 2)];
    }

    public function summary(): array
    {
        $mrr = $this->money($this->recurring()->groupBy('currency')->map->sum('monthly'));
        $owing = $this->owing();

        return [
            'currency' => $this->currency(),
            'mrr'      => $mrr,
            'yearly'   => ['amount' => round($mrr['amount'] * 12, 2), 'others' => array_map(fn ($o) => ['currency' => $o['currency'], 'amount' => round($o['amount'] * 12, 2)], $mrr['others'])],
            'active'   => $this->tenants()->where('status', 'active')->count(),
            'total'    => $this->tenants()->count(),
            'trial'    => $this->tenants()->where('status', 'trial')->count(),
            'owed'     => $this->money($owing->groupBy('currency')->map->sum('price')) + ['count' => $owing->count()],
            'review'   => $this->review(),
        ];
    }

    private function paidStatusId(): ?string
    {
        static $id = false;

        if ($id === false) {
            $id = Status::whereHas('module', fn ($q) => $q->where('name', 'payments'))->whereNull('tenant_id')->where('slug', 'paid')->value('id');
        }

        return $id;
    }

    private function invoices(Carbon $from, Carbon $to)
    {
        return DB::table('subscription_invoices as i')
            ->join('tenants as t', 't.id', '=', 'i.tenant_id')
            ->whereNull('t.deleted_at')
            ->where('i.status_id', $this->paidStatusId())
            ->whereNotNull('i.paid_at')
            ->where('i.paid_at', '>=', $this->iso($from))
            ->where('i.paid_at', '<', $this->iso($to));
    }

    private function collected(Carbon $from, Carbon $to): array
    {
        $rows = $this->invoices($from, $to)->get(['i.amount', 'i.currency']);

        return ['money' => $this->money($rows->groupBy('currency')->map->sum('amount')), 'count' => $rows->count()];
    }

    private function failed(Carbon $from, Carbon $to): array
    {
        if (!Subscriptions::ready()) {
            return ['count' => 0, 'amount' => 0.0];
        }

        $rows = DB::table('plan_payments')->where('status', 'failed')
            ->where('created_at', '>=', $this->iso($from))->where('created_at', '<', $this->iso($to))
            ->get(['amount', 'currency']);

        return ['count' => $rows->count(), 'amount' => round((float) $rows->where('currency', $this->currency())->sum('amount'), 2)];
    }

    private static function methodLabel(object $row): string
    {
        if (!$row->pp_kind) {
            return 'Registrado a mano';
        }

        $label = match ($row->pp_kind) {
            'transfer'        => $row->pp_method ?: 'Transferencia',
            'card', 'charge'  => 'Tarjeta (cobro automático)',
            'link'            => 'Pago en línea',
            default           => 'Otro',
        };

        return $row->pp_provider === 'simulated' ? "{$label} (simulación)" : $label;
    }

    public function income(): array
    {
        $main = $this->currency();
        $now = $this->collected($this->from, $this->to);
        $prev = $this->collected($this->prevFrom, $this->prevTo);
        $failed = $this->failed($this->from, $this->to);
        $failedPrev = $this->failed($this->prevFrom, $this->prevTo);
        $summary = $this->summary();
        $ready = Subscriptions::ready();
        $transfers = PlanTransfers::ready();

        $rows = $this->invoices($this->from, $this->to)
            ->where('i.currency', $main)
            ->join('subscriptions as s', 's.id', '=', 'i.subscription_id')
            ->when($ready, fn ($q) => $q->leftJoin('plan_payments as pp', 'pp.invoice_id', '=', 'i.id'))
            ->get(array_merge(
                ['i.id', 'i.amount', 'i.paid_at', 's.plan_id as sub_plan'],
                $ready ? ['pp.kind as pp_kind', 'pp.provider as pp_provider', 'pp.plan_id as pp_plan'] : [DB::raw('null as pp_kind'), DB::raw('null as pp_provider'), DB::raw('null as pp_plan')],
                $transfers ? ['pp.method_label as pp_method'] : [DB::raw('null as pp_method')],
            ))
            ->unique('id');

        $byDay = $rows->groupBy(fn ($r) => Carbon::parse($r->paid_at)->setTimezone(self::ZONE)->format('Y-m-d'))->map->sum('amount');
        $days = [];
        for ($day = $this->from->copy(); $day->lt($this->to); $day->addDay()) {
            $key = $day->format('Y-m-d');
            $days[] = ['day' => $key, 'value' => round((float) ($byDay[$key] ?? 0), 2)];
        }

        $plans = DB::table('saas_plans')->pluck('name', 'id');

        return [
            'summary' => [
                'mrr'       => $summary['mrr'],
                'yearly'    => $summary['yearly'],
                'collected' => $now['money'],
                'payments'  => $now['count'],
                'failed'    => $failed,
                'owed'      => $summary['owed'],
                'review'    => $summary['review'],
            ],
            'previous' => [
                'collected' => $prev['money']['amount'],
                'payments'  => $prev['count'],
                'failed'    => $failedPrev['count'],
            ],
            'by_day'    => $days,
            'by_method' => $rows->groupBy(fn ($r) => self::methodLabel($r))
                ->map(fn ($g, $label) => ['key' => $label, 'label' => $label, 'value' => round((float) $g->sum('amount'), 2), 'count' => $g->count()])
                ->sortByDesc('value')->values()->all(),
            'by_plan'   => $rows->groupBy(fn ($r) => $r->pp_plan ?? $r->sub_plan)
                ->map(fn ($g, $id) => ['key' => (string) $id, 'label' => $plans[$id] ?? 'Plan borrado', 'value' => round((float) $g->sum('amount'), 2), 'count' => $g->count()])
                ->sortByDesc('value')->values()->all(),
            'recurring_by_plan' => $this->recurring()->where('currency', $main)->groupBy('plan')
                ->map(fn ($g, $name) => ['key' => (string) $name, 'label' => (string) $name, 'value' => round((float) $g->sum('monthly'), 2), 'count' => $g->count()])
                ->sortByDesc('value')->values()->all(),
            'owing' => $this->owing()->sortBy('name')->map(fn ($t) => [
                'id' => $t->id, 'name' => $t->name, 'plan' => $t->plan, 'status' => $t->status,
                'status_label' => self::STATUSES[$t->status], 'amount' => $t->price, 'currency' => $t->currency,
            ])->values()->all(),
        ];
    }

    private function conversions(Carbon $from, Carbon $to): array
    {
        $converted = $this->invoices($from, $to)->where('i.billing_reason', 'subscription_create')->distinct()->count('i.tenant_id');
        $ended = DB::table('subscriptions as s')->join('tenants as t', 't.id', '=', 's.tenant_id')->whereNull('t.deleted_at')
            ->whereIn('s.status', ['trialing', 'expired'])
            ->whereNotNull('s.trial_ends_at')
            ->where('s.trial_ends_at', '>=', $this->iso($from))
            ->where('s.trial_ends_at', '<', $this->iso($to->copy()->min(now())))
            ->distinct()->count('s.tenant_id');

        return [
            'converted' => $converted,
            'ended'     => $ended,
            'rate'      => $converted + $ended > 0 ? (int) round($converted * 100 / ($converted + $ended)) : null,
        ];
    }

    private function created(Carbon $from, Carbon $to): int
    {
        return DB::table('tenants')->whereNull('deleted_at')->where('created_at', '>=', $this->iso($from))->where('created_at', '<', $this->iso($to))->count();
    }

    private function canceled(Carbon $from, Carbon $to): int
    {
        return DB::table('subscriptions as s')->join('tenants as t', 't.id', '=', 's.tenant_id')->whereNull('t.deleted_at')
            ->whereNotNull('s.canceled_at')->where('s.canceled_at', '>=', $this->iso($from))->where('s.canceled_at', '<', $this->iso($to))
            ->distinct()->count('s.tenant_id');
    }

    public function tenantsTab(): array
    {
        $all = $this->tenants();
        $counts = $all->countBy('status');
        $conversion = $this->conversions($this->from, $this->to);
        $conversionPrev = $this->conversions($this->prevFrom, $this->prevTo);

        $last = $this->to->copy()->subDay()->startOfMonth();
        $first = $last->copy()->subMonths(11);
        $created = DB::table('tenants')->whereNull('deleted_at')->where('created_at', '>=', $this->iso($first))->get(['created_at'])
            ->countBy(fn ($r) => Carbon::parse($r->created_at)->setTimezone(self::ZONE)->format('Y-m'));
        $months = [];
        for ($month = $first->copy(); $month->lte($last); $month->addMonth()) {
            $key = $month->format('Y-m');
            $months[] = ['day' => $month->format('Y-m-01'), 'value' => (int) ($created[$key] ?? 0)];
        }

        $current = $all->whereNotIn('status', ['canceled', 'suspended']);

        return [
            'summary' => [
                'active'     => (int) ($counts['active'] ?? 0),
                'trial'      => (int) ($counts['trial'] ?? 0),
                'grace'      => (int) ($counts['grace'] ?? 0),
                'locked'     => (int) (($counts['locked'] ?? 0) + ($counts['none'] ?? 0)),
                'canceled'   => (int) ($counts['canceled'] ?? 0),
                'suspended'  => (int) ($counts['suspended'] ?? 0),
                'total'      => $all->count(),
                'new'        => $this->created($this->from, $this->to),
                'conversion' => $conversion['rate'],
                'converted'  => $conversion['converted'],
                'ended'      => $conversion['ended'],
                'lost'       => $this->canceled($this->from, $this->to),
            ],
            'previous' => [
                'new'        => $this->created($this->prevFrom, $this->prevTo),
                'conversion' => $conversionPrev['rate'],
                'lost'       => $this->canceled($this->prevFrom, $this->prevTo),
            ],
            'by_month'         => $months,
            'by_status'        => collect(self::STATUSES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'value' => (int) ($counts[$key] ?? 0)])->filter(fn ($s) => $s['value'] > 0)->values()->all(),
            'by_plan'          => $current->groupBy(fn ($t) => $t->plan ?? 'Sin plan')->map(fn ($g, $name) => ['key' => (string) $name, 'label' => (string) $name, 'value' => $g->count()])->sortByDesc('value')->values()->all(),
            'by_business_type' => $current->groupBy(fn ($t) => $t->business_type ?? 'Sin rubro')->map(fn ($g, $name) => ['key' => (string) $name, 'label' => (string) $name, 'value' => $g->count()])->sortByDesc('value')->values()->all(),
        ];
    }

    private function appointmentCounts(Carbon $from, Carbon $to): Collection
    {
        return DB::table('appointments')
            ->where('starts_at', '>=', $this->iso($from))->where('starts_at', '<', $this->iso($to))
            ->when(Schema::hasColumn('appointments', 'status'), fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->groupBy('tenant_id')->selectRaw('tenant_id, count(*) as total')->pluck('total', 'tenant_id');
    }

    public function usage(): array
    {
        $tenants = $this->tenants();
        $now = $this->appointmentCounts($this->from, $this->to);
        $prev = $this->appointmentCounts($this->prevFrom, $this->prevTo);
        $monthStart = now(self::ZONE)->startOfMonth();
        $month = $this->appointmentCounts($monthStart, $monthStart->copy()->addMonth());
        $users = DB::table('users')->whereNotNull('tenant_id')->where('is_owner', false)->where('status', 'active')->groupBy('tenant_id')->selectRaw('tenant_id, count(*) as total')->pluck('total', 'tenant_id');
        $branches = DB::table('branches')->where('status', 'active')->groupBy('tenant_id')->selectRaw('tenant_id, count(*) as total')->pluck('total', 'tenant_id');
        $professionals = DB::table('professionals')
            ->where(fn ($q) => $q->whereNull('status_id')->orWhereNotIn('status_id', DB::table('statuses')->where('slug', 'inactive')->select('id')))
            ->groupBy('tenant_id')->selectRaw('tenant_id, count(*) as total')->pluck('total', 'tenant_id');
        $lastLogin = Schema::hasColumn('users', 'last_login_at')
            ? DB::table('users')->whereNotNull('tenant_id')->groupBy('tenant_id')->selectRaw('tenant_id, max(last_login_at) as last_at')->pluck('last_at', 'tenant_id')
            : collect();
        $idleSince = now()->subDays(self::IDLE_DAYS);

        $rows = $tenants->map(function ($t) use ($now, $month, $users, $branches, $professionals, $lastLogin, $idleSince) {
            $used = [
                'appointments_month' => (int) ($month[$t->id] ?? 0),
                'users'              => (int) ($users[$t->id] ?? 0),
                'professionals'      => (int) ($professionals[$t->id] ?? 0),
                'branches'           => (int) ($branches[$t->id] ?? 0),
            ];
            $limits = collect(self::LIMITS)->map(function ($label, $key) use ($t, $used) {
                $limit = $t->limits[$key] ?? null;
                $limit = is_numeric($limit) && (int) $limit > 0 ? (int) $limit : null;

                return [
                    'key'     => $key,
                    'label'   => $label,
                    'used'    => $used[$key],
                    'limit'   => $limit,
                    'percent' => $limit ? (int) floor($used[$key] * 100 / $limit) : null,
                ];
            })->values();
            $top = $limits->whereNotNull('percent')->sortByDesc('percent')->first();
            $login = $lastLogin[$t->id] ?? null;
            $open = !in_array($t->status, ['canceled', 'suspended'], true);

            return [
                'id'            => $t->id,
                'name'          => $t->name,
                'plan'          => $t->plan,
                'status'        => $t->status,
                'status_label'  => self::STATUSES[$t->status],
                'appointments'  => (int) ($now[$t->id] ?? 0),
                'limits'        => $limits->all(),
                'top'           => $top,
                'last_login_at' => $login ? Carbon::parse($login)->toIso8601String() : null,
                'idle'          => $open && (!$login || Carbon::parse($login)->lt($idleSince)),
                'near'          => $open && $top && $top['percent'] >= self::NEAR_LIMIT,
            ];
        })->values();

        $open = $rows->whereNotIn('status', ['canceled', 'suspended']);

        return [
            'summary' => [
                'appointments' => (int) $now->sum(),
                'average'      => $open->count() ? (int) round($open->sum('appointments') / $open->count()) : 0,
                'near'         => $rows->where('near', true)->count(),
                'idle'         => $rows->where('idle', true)->count(),
            ],
            'previous' => [
                'appointments' => (int) $prev->sum(),
            ],
            'near_limit'  => self::NEAR_LIMIT,
            'idle_days'   => self::IDLE_DAYS,
            'login_ready' => Schema::hasColumn('users', 'last_login_at'),
            'rows'        => $rows->sortByDesc('appointments')->values()->all(),
        ];
    }
}
