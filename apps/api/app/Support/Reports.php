<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class Reports
{
    public const TABS = [
        'sales'        => ['label' => 'Ventas', 'advanced' => false],
        'appointments' => ['label' => 'Citas y ocupación', 'advanced' => false],
        'customers'    => ['label' => 'Clientes', 'advanced' => true],
        'pros'         => ['label' => 'Profesionales', 'advanced' => true],
        'catalog'      => ['label' => 'Servicios y productos', 'advanced' => true],
        'mine'         => ['label' => 'Mis números', 'advanced' => false],
    ];

    public const PERIODS = [
        '7'      => 'Últimos 7 días',
        '30'     => 'Últimos 30 días',
        '90'     => 'Últimos 90 días',
        'month'  => 'Mes en curso',
        'prev'   => 'Mes anterior',
        'custom' => 'Elegir fechas',
    ];

    public const PAID = ['paid', 'partial', 'partially_refunded'];

    public const MAX_DAYS = 366;

    public function __construct(private Tenant $tenant, private ?string $branchId, private Carbon $from, private Carbon $to, private ?string $professionalId = null)
    {
    }

    public static function range(Tenant $tenant, string $period, ?string $from = null, ?string $to = null): array
    {
        $now = now($tenant->zone());
        $today = $now->copy()->startOfDay();

        [$start, $end] = match ($period) {
            '7'      => [$today->copy()->subDays(6), $today->copy()->addDay()],
            '90'     => [$today->copy()->subDays(89), $today->copy()->addDay()],
            'month'  => [$today->copy()->startOfMonth(), $today->copy()->addDay()],
            'prev'   => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->startOfMonth()],
            'custom' => [
                Carbon::createFromFormat('Y-m-d', (string) $from, $tenant->zone())->startOfDay(),
                Carbon::createFromFormat('Y-m-d', (string) $to, $tenant->zone())->startOfDay()->addDay(),
            ],
            default  => [$today->copy()->subDays(29), $today->copy()->addDay()],
        };

        $days = (int) round($start->diffInDays($end));

        return [
            'from'      => $start,
            'to'        => $end,
            'prev_from' => $period === 'prev' || $period === 'month' ? $start->copy()->subMonthNoOverflow() : $start->copy()->subDays($days),
            'prev_to'   => $period === 'month' ? $start->copy()->subMonthNoOverflow()->addDays($days)->min($start->copy()) : $start->copy(),
            'days'      => $days,
        ];
    }

    private function iso(Carbon $date): string
    {
        return $date->copy()->utc()->toIso8601String();
    }

    private function currency(): string
    {
        return Currency::of($this->tenant);
    }

    private function appointments()
    {
        return DB::table('appointments as a')
            ->where('a.tenant_id', $this->tenant->id)
            ->when($this->branchId, fn ($q, $id) => $q->where('a.branch_id', $id))
            ->when($this->professionalId, fn ($q, $id) => $q->where('a.professional_id', $id))
            ->where('a.starts_at', '>=', $this->iso($this->from))
            ->where('a.starts_at', '<', $this->iso($this->to));
    }

    private function payments()
    {
        return DB::table('payments as p')
            ->where('p.tenant_id', $this->tenant->id)
            ->whereIn('p.status', ['confirmed', 'refunded'])
            ->where('p.currency', $this->currency())
            ->when($this->branchId, fn ($q, $id) => $q->where('p.branch_id', $id))
            ->whereRaw('coalesce(p.confirmed_at, p.created_at) >= ?', [$this->iso($this->from)])
            ->whereRaw('coalesce(p.confirmed_at, p.created_at) < ?', [$this->iso($this->to)]);
    }

    private function sales()
    {
        return DB::table('sales as s')
            ->where('s.tenant_id', $this->tenant->id)
            ->whereIn('s.status', self::PAID)
            ->where('s.currency', $this->currency())
            ->when($this->branchId, fn ($q, $id) => $q->where('s.branch_id', $id))
            ->whereRaw('coalesce(s.paid_at, s.created_at) >= ?', [$this->iso($this->from)])
            ->whereRaw('coalesce(s.paid_at, s.created_at) < ?', [$this->iso($this->to)]);
    }

    private function dayKeys(): array
    {
        $keys = [];
        for ($day = $this->from->copy(); $day->lt($this->to); $day->addDay()) {
            $keys[] = $day->toDateString();
        }

        return $keys;
    }

    private function localDay(string $column): string
    {
        $zone = str_replace("'", '', $this->tenant->zone());

        return "to_char({$column} AT TIME ZONE '{$zone}', 'YYYY-MM-DD')";
    }

    public function salesSummary(): array
    {
        $sales = $this->sales()->selectRaw('count(*) AS total, coalesce(sum(s.total), 0) AS amount, coalesce(sum(s.discount), 0) AS discount, coalesce(sum(s.tip), 0) AS tip')->first();
        $income = (float) $this->payments()->sum('p.amount');
        $refunds = (float) DB::table('sale_refunds as r')
            ->join('sales as s', 's.id', '=', 'r.sale_id')
            ->where('s.tenant_id', $this->tenant->id)
            ->where('s.currency', $this->currency())
            ->when($this->branchId, fn ($q, $id) => $q->where('s.branch_id', $id))
            ->where('r.created_at', '>=', $this->iso($this->from))
            ->where('r.created_at', '<', $this->iso($this->to))
            ->sum('r.amount');

        return [
            'income'   => round($income, 2),
            'sales'    => (int) $sales->total,
            'ticket'   => (int) $sales->total > 0 ? round((float) $sales->amount / (int) $sales->total, 2) : 0,
            'discount' => round((float) $sales->discount, 2),
            'tips'     => round((float) $sales->tip, 2),
            'refunds'  => round($refunds, 2),
        ];
    }

    public function salesDetail(): array
    {
        $labels = Sales::METHODS + Sales::OTHER_METHODS;
        $byDay = $this->payments()->selectRaw($this->localDay('coalesce(p.confirmed_at, p.created_at)') . ' AS day, sum(p.amount) AS amount')->groupBy('day')->pluck('amount', 'day');

        return [
            'by_day'    => array_map(fn ($day) => ['day' => $day, 'value' => round((float) ($byDay[$day] ?? 0), 2)], $this->dayKeys()),
            'by_method' => $this->payments()->groupBy('p.method')->selectRaw('p.method, sum(p.amount) AS amount, count(*) FILTER (WHERE p.amount > 0) AS total')
                ->orderByDesc('amount')->get()
                ->map(fn ($row) => ['key' => $row->method, 'label' => $labels[$row->method] ?? $row->method, 'value' => round((float) $row->amount, 2), 'count' => (int) $row->total])->values()->all(),
            'by_branch' => $this->payments()->join('branches as b', 'b.id', '=', 'p.branch_id')->groupBy('b.id', 'b.name')
                ->selectRaw('b.name, sum(p.amount) AS amount')->orderByDesc('amount')->get()
                ->map(fn ($row) => ['label' => $row->name, 'value' => round((float) $row->amount, 2)])->values()->all(),
            'by_type'   => DB::table('sale_items as i')->joinSub($this->sales()->select('s.id'), 'ss', 'ss.id', '=', 'i.sale_id')
                ->groupBy('i.item_type')->selectRaw('i.item_type, sum(i.total) AS amount')->orderByDesc('amount')->get()
                ->map(fn ($row) => ['key' => $row->item_type, 'label' => match ($row->item_type) {
                    'service' => 'Servicios', 'product' => 'Productos', 'package' => 'Paquetes', 'deposit' => 'Adelantos', default => ucfirst($row->item_type),
                }, 'value' => round((float) $row->amount, 2)])->values()->all(),
        ];
    }

    private function scheduledMinutes(?string $professionalId = null): array
    {
        return WorkMinutes::compute($this->tenant, $this->from, $this->to, $this->branchId, $professionalId ?? $this->professionalId);
    }

    public function appointmentsSummary(): array
    {
        $row = $this->appointments()->selectRaw("count(*) AS total,
            count(*) FILTER (WHERE a.status = ?) AS completed,
            count(*) FILTER (WHERE a.status = ?) AS cancelled,
            count(*) FILTER (WHERE a.status = ?) AS no_show,
            count(*) FILTER (WHERE a.source = 'online') AS online,
            coalesce(sum(extract(epoch FROM a.ends_at - a.starts_at) / 60) FILTER (WHERE a.status NOT IN (?, ?)), 0) AS booked",
            [AppointmentFlow::COMPLETED, AppointmentFlow::CANCELLED, AppointmentFlow::NO_SHOW, AppointmentFlow::CANCELLED, AppointmentFlow::NO_SHOW])->first();
        $scheduled = $this->scheduledMinutes()['total'];
        $total = (int) $row->total;

        return [
            'appointments' => $total - (int) $row->cancelled,
            'completed'    => (int) $row->completed,
            'occupancy'    => $scheduled > 0 ? min(100, (int) round((float) $row->booked * 100 / $scheduled)) : null,
            'cancelled'    => $total > 0 ? (int) round((int) $row->cancelled * 100 / $total) : 0,
            'no_show'      => $total > 0 ? (int) round((int) $row->no_show * 100 / $total) : 0,
            'online'       => (int) $row->online,
        ];
    }

    public function appointmentsDetail(bool $advanced): array
    {
        $scheduled = $this->scheduledMinutes();
        $booked = $this->appointments()->whereNotIn('a.status', AppointmentFlow::RELEASED)
            ->selectRaw($this->localDay('a.starts_at') . ' AS day, sum(extract(epoch FROM a.ends_at - a.starts_at) / 60) AS minutes')
            ->groupBy('day')->pluck('minutes', 'day');

        $sources = ['staff' => 'Recepción', 'online' => 'Portal', 'walkin' => 'Sin cita', 'payment_link' => 'Link de pago', 'package' => 'Paquete'];
        $zone = str_replace("'", '', $this->tenant->zone());

        return [
            'occupancy_by_day' => array_map(fn ($day) => [
                'day'   => $day,
                'value' => ($scheduled['by_day'][$day] ?? 0) > 0 ? min(100, (int) round((float) ($booked[$day] ?? 0) * 100 / $scheduled['by_day'][$day])) : null,
            ], $this->dayKeys()),
            'by_source' => $this->appointments()->whereNotIn('a.status', [AppointmentFlow::CANCELLED])->groupBy('a.source')
                ->selectRaw('a.source, count(*) AS total')->orderByDesc('total')->get()
                ->map(fn ($row) => ['key' => $row->source, 'label' => $sources[$row->source] ?? ucfirst((string) $row->source), 'value' => (int) $row->total])->values()->all(),
            'heatmap' => !$advanced ? null : $this->appointments()->whereNotIn('a.status', AppointmentFlow::RELEASED)
                ->selectRaw("extract(isodow FROM a.starts_at AT TIME ZONE '{$zone}')::int AS weekday, extract(hour FROM a.starts_at AT TIME ZONE '{$zone}')::int AS hour, count(*) AS total")
                ->groupBy('weekday', 'hour')->get()
                ->map(fn ($row) => ['weekday' => (int) $row->weekday, 'hour' => (int) $row->hour, 'value' => (int) $row->total])->values()->all(),
        ];
    }

    public function customersSummary(): array
    {
        $attended = $this->appointments()->where('a.status', AppointmentFlow::COMPLETED)->whereNotNull('a.customer_id')->distinct()->pluck('a.customer_id');
        $returning = $attended->isEmpty() ? 0 : DB::table('appointments')
            ->where('tenant_id', $this->tenant->id)->whereIn('customer_id', $attended)
            ->when($this->branchId, fn ($q, $id) => $q->where('branch_id', $id))
            ->where('status', AppointmentFlow::COMPLETED)->where('starts_at', '<', $this->iso($this->from))
            ->distinct()->count('customer_id');

        $last = $this->appointments()->where('a.status', AppointmentFlow::COMPLETED)->whereNotNull('a.customer_id')
            ->groupBy('a.customer_id')->selectRaw('a.customer_id, max(a.starts_at) AS last_at');
        $rebooked = DB::query()->fromSub($last, 'l')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('appointments as n')->whereColumn('n.customer_id', 'l.customer_id')
                ->whereColumn('n.created_at', '>', 'l.last_at')->where('n.status', '!=', AppointmentFlow::CANCELLED))
            ->count();

        $new = $this->branchId
            ? DB::query()->fromSub(DB::table('appointments')->where('tenant_id', $this->tenant->id)->where('branch_id', $this->branchId)
                ->where('status', '!=', AppointmentFlow::CANCELLED)->whereNotNull('customer_id')
                ->groupBy('customer_id')->selectRaw('customer_id, min(starts_at) AS first_at'), 'f')
                ->where('f.first_at', '>=', $this->iso($this->from))->where('f.first_at', '<', $this->iso($this->to))->count()
            : DB::table('customers')->where('tenant_id', $this->tenant->id)->whereNull('deleted_at')
                ->where('created_at', '>=', $this->iso($this->from))->where('created_at', '<', $this->iso($this->to))->count();

        return [
            'attended'  => $attended->count(),
            'new'       => $new,
            'returning' => $returning,
            'rebooked'  => $attended->count() > 0 ? (int) round($rebooked * 100 / $attended->count()) : 0,
        ];
    }

    public function topCustomers(): array
    {
        return $this->sales()->join('customers as c', 'c.id', '=', 's.customer_id')
            ->groupBy('c.id', 'c.first_name', 'c.last_name')
            ->selectRaw('c.id, c.first_name, c.last_name, sum(s.paid) AS spent, count(*) AS sales')
            ->orderByDesc('spent')->limit(10)->get()
            ->map(fn ($row) => [
                'id'     => $row->id,
                'name'   => Person::name($row),
                'spent'  => round((float) $row->spent, 2),
                'sales'  => (int) $row->sales,
                'visits' => DB::table('appointments')->where('customer_id', $row->id)->where('status', AppointmentFlow::COMPLETED)
                    ->when($this->branchId, fn ($q, $id) => $q->where('branch_id', $id))
                    ->where('starts_at', '>=', $this->iso($this->from))->where('starts_at', '<', $this->iso($this->to))->count(),
            ])->values()->all();
    }

    private function collected(string $groupBy): array
    {
        $currency = $this->currency();
        $lines = DB::table('sale_items')->groupBy('sale_id')->selectRaw('sale_id, sum(total) AS lines_total');

        return DB::table('sale_items as i')
            ->join('sales as s', 's.id', '=', 'i.sale_id')
            ->joinSub($lines, 't', 't.sale_id', '=', 'i.sale_id')
            ->leftJoin('appointments as a', 'a.id', '=', 'i.appointment_id')
            ->where('s.tenant_id', $this->tenant->id)
            ->whereIn('s.status', self::PAID)
            ->where('s.currency', $currency)
            ->where('i.item_type', 'service')
            ->when($this->branchId, fn ($q, $id) => $q->where('s.branch_id', $id))
            ->when($this->professionalId, fn ($q, $id) => $q->whereRaw('coalesce(i.professional_id, a.professional_id) = ?', [$id]))
            ->whereRaw('coalesce(s.paid_at, s.created_at) >= ?', [$this->iso($this->from)])
            ->whereRaw('coalesce(s.paid_at, s.created_at) < ?', [$this->iso($this->to)])
            ->groupByRaw($groupBy)
            ->selectRaw("{$groupBy} AS id, coalesce(sum(i.total * s.paid / nullif(t.lines_total, 0)), 0) AS amount")
            ->pluck('amount', 'id')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();
    }

    public function professionals(): array
    {
        $scheduled = $this->scheduledMinutes()['by_pro'];
        $currency = $this->currency();

        $rows = $this->appointments()->join('professionals as pr', 'pr.id', '=', 'a.professional_id')
            ->groupBy('pr.id', 'pr.display_name')
            ->selectRaw("pr.id, pr.display_name,
                count(*) FILTER (WHERE a.status = ?) AS completed,
                count(*) FILTER (WHERE a.status = ?) AS no_show,
                coalesce(sum(a.price) FILTER (WHERE a.status = ? AND coalesce(a.currency, ?) = ?), 0) AS income,
                coalesce(sum(extract(epoch FROM a.ends_at - a.starts_at) / 60) FILTER (WHERE a.status NOT IN (?, ?)), 0) AS booked,
                avg(extract(epoch FROM a.completed_at - a.started_at) / 60) FILTER (WHERE a.status = ? AND a.started_at IS NOT NULL AND a.completed_at > a.started_at) AS minutes",
                [AppointmentFlow::COMPLETED, AppointmentFlow::NO_SHOW, AppointmentFlow::COMPLETED, $currency, $currency, AppointmentFlow::CANCELLED, AppointmentFlow::NO_SHOW, AppointmentFlow::COMPLETED])
            ->get();

        $collected = $this->collected('coalesce(i.professional_id, a.professional_id)');

        return $rows->map(fn ($row) => [
            'id'        => $row->id,
            'name'      => $row->display_name,
            'completed' => (int) $row->completed,
            'income'    => round((float) $row->income, 2),
            'collected' => $collected[$row->id] ?? 0.0,
            'occupancy' => ($scheduled[$row->id] ?? 0) > 0 ? min(100, (int) round((float) $row->booked * 100 / $scheduled[$row->id])) : null,
            'no_show'   => (int) $row->no_show,
            'minutes'   => $row->minutes !== null ? (int) round((float) $row->minutes) : null,
        ])->sortByDesc('completed')->values()->all();
    }

    public function mine(): array
    {
        $row = $this->professionals()[0] ?? null;

        return [
            'completed' => $row['completed'] ?? 0,
            'income'    => $row['income'] ?? 0.0,
            'occupancy' => $row['occupancy'] ?? null,
            'no_show'   => $row['no_show'] ?? 0,
            'minutes'   => $row['minutes'] ?? null,
            'customers' => $this->appointments()->where('a.status', AppointmentFlow::COMPLETED)->whereNotNull('a.customer_id')->distinct()->count('a.customer_id'),
        ];
    }

    public function myCustomers(): array
    {
        $currency = $this->currency();

        return $this->appointments()->join('customers as c', 'c.id', '=', 'a.customer_id')
            ->where('a.status', AppointmentFlow::COMPLETED)
            ->groupBy('c.id', 'c.first_name', 'c.last_name')
            ->selectRaw('c.id, c.first_name, c.last_name, count(*) AS visits, coalesce(sum(a.price) FILTER (WHERE coalesce(a.currency, ?) = ?), 0) AS income, max(a.starts_at) AS last_at', [$currency, $currency])
            ->orderByDesc('visits')->limit(10)->get()
            ->map(fn ($row) => [
                'id'      => $row->id,
                'name'    => Person::name($row),
                'visits'  => (int) $row->visits,
                'income'  => round((float) $row->income, 2),
                'last_at' => Carbon::parse($row->last_at)->toIso8601String(),
            ])->values()->all();
    }

    public function catalog(bool $products): array
    {
        $currency = $this->currency();
        $collectedServices = $this->collected('coalesce(a.service_id, i.reference_id)');

        return [
            'services' => $this->appointments()->join('services as sv', 'sv.id', '=', 'a.service_id')
                ->where('a.status', AppointmentFlow::COMPLETED)
                ->groupBy('sv.id', 'sv.name')
                ->selectRaw('sv.id, sv.name, count(*) AS total, coalesce(sum(a.price) FILTER (WHERE coalesce(a.currency, ?) = ?), 0) AS income', [$currency, $currency])
                ->orderByDesc('total')->get()
                ->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'quantity' => (int) $row->total, 'income' => round((float) $row->income, 2), 'collected' => $collectedServices[$row->id] ?? 0.0])->values()->all(),
            'products' => !$products ? null : DB::table('sale_items as i')->joinSub($this->sales()->select('s.id'), 'ss', 'ss.id', '=', 'i.sale_id')
                ->where('i.item_type', 'product')
                ->groupBy('i.reference_id', 'i.description')
                ->selectRaw('i.reference_id AS id, i.description AS name, sum(i.quantity) AS quantity, sum(i.total) AS income')
                ->orderByDesc('quantity')->get()
                ->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'quantity' => (float) $row->quantity, 'income' => round((float) $row->income, 2)])->values()->all(),
        ];
    }
}
