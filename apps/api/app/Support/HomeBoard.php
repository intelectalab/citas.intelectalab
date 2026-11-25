<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Professional;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HomeBoard
{
    public const SOURCES = [
        'staff'   => 'Recepción',
        'online'  => 'En línea',
        'portal'  => 'Portal',
        'walkin'  => 'Sin cita',
        'kiosk'   => 'Kiosko',
        'api'     => 'API',
        'chatbot' => 'Asistente',
        'whatsapp' => 'WhatsApp',
    ];

    public const ADVANCE = [
        AppointmentFlow::PENDING     => AppointmentFlow::CONFIRMED,
        AppointmentFlow::CHECKED_IN  => AppointmentFlow::IN_PROGRESS,
        AppointmentFlow::IN_PROGRESS => AppointmentFlow::COMPLETED,
    ];

    public static function source(?string $source): ?string
    {
        return $source ? (self::SOURCES[$source] ?? ucfirst($source)) : null;
    }

    public static function advance(Tenant $tenant, Appointment $appointment, User $user): ?string
    {
        $to = $appointment->status === AppointmentFlow::CONFIRMED
            ? (AppointmentFlow::rule($tenant, 'agenda.checkin_step') ? AppointmentFlow::CHECKED_IN : AppointmentFlow::COMPLETED)
            : (self::ADVANCE[$appointment->status] ?? null);

        return $to && in_array($to, AppointmentFlow::allowedNext($tenant, $appointment->status, $user), true) ? $to : null;
    }

    private static function iso(Carbon $date): string
    {
        return $date->copy()->utc()->toIso8601String();
    }

    private static function chargeable(Tenant $tenant)
    {
        return DB::table('sale_items as i')->join('sales as s', 's.id', '=', 'i.sale_id')
            ->where('s.tenant_id', $tenant->id)->where('s.status', '!=', 'void')->whereNotNull('i.appointment_id')->select('i.appointment_id');
    }

    public static function attention(Tenant $tenant, User $user, ?string $own, ?string $limit, Carbon $now): array
    {
        $items = [];
        $dayStart = $now->copy()->startOfDay();
        $weekAgo = $dayStart->copy()->subDays(7);
        $add = function (string $key, int $count, string $href, array $extra = []) use (&$items) {
            $items[] = ['key' => $key, 'count' => $count, 'href' => $href] + $extra;
        };
        $branchOf = fn ($q, string $column) => $q->when($limit, fn ($w, $id) => $w->where($column, $id));

        if ($tenant->hasModule('records') && $user->allows('records.edit')) {
            $services = DB::table('record_template_services as rs')
                ->join('record_templates as rt', 'rt.id', '=', 'rs.record_template_id')
                ->where('rs.tenant_id', $tenant->id)->where('rt.status', 'active')->select('rs.service_id');

            $add('missing_records', Appointment::where('appointments.tenant_id', $tenant->id)
                ->where('status', AppointmentFlow::COMPLETED)
                ->where('starts_at', '>=', self::iso($weekAgo))
                ->whereIn('service_id', $services)
                ->whereNotExists(fn ($q) => $q->from('care_records as cr')->whereColumn('cr.appointment_id', 'appointments.id'))
                ->when($own, fn ($q) => $q->where('professional_id', $own))
                ->when($limit, fn ($q, $id) => $q->where('branch_id', $id))
                ->count(), '/records');
        }

        if (Quotes::enabled($tenant) && $user->allows('quotes.edit')) {
            $add('quotes_sent', $branchOf(DB::table('quotes')->where('tenant_id', $tenant->id)->where('status', 'sent'), 'branch_id')
                ->when($own, fn ($q) => $q->where('professional_id', $own))->count(), '/quotes');
        }

        if (Sales::ready() && $tenant->hasModule('pos') && $user->allows('sales.create')) {
            $add('uncharged', Appointment::where('tenant_id', $tenant->id)
                ->where('status', AppointmentFlow::COMPLETED)
                ->where('price', '>', 0)
                ->where('starts_at', '>=', self::iso($weekAgo))
                ->when(Packages::ready(), fn ($q) => $q->whereNull('customer_package_id'))
                ->whereNotIn('id', self::chargeable($tenant))
                ->when($own, fn ($q) => $q->where('professional_id', $own))
                ->when($limit, fn ($q, $id) => $q->where('branch_id', $id))
                ->count(), '/pos');
        }

        if (Sales::ready() && $tenant->hasModule('pos') && $user->allows('cash.view') && Schema::hasTable('cash_sessions')) {
            $branches = DB::table('branches')->where('tenant_id', $tenant->id)->where('status', 'active')
                ->when($limit, fn ($q, $id) => $q->where('id', $id))->orderBy('name')->get(['id', 'name']);
            $open = DB::table('cash_sessions')->where('tenant_id', $tenant->id)->where('status', 'open')
                ->whereIn('branch_id', $branches->pluck('id'))->get(['branch_id', 'opened_at'])->groupBy('branch_id');
            $closed = $branches->filter(fn ($b) => !isset($open[$b->id]))->pluck('name')->values()->all();
            $stale = $branches->filter(fn ($b) => isset($open[$b->id]) && $open[$b->id]->contains(fn ($s) => Carbon::parse($s->opened_at)->lt($dayStart)))->pluck('name')->values()->all();

            $add('cash_closed', count($closed), '/cash', ['names' => array_slice($closed, 0, 3)]);
            $add('cash_stale', count($stale), '/cash', ['names' => array_slice($stale, 0, 3)]);

            if (Cash::reviewReady() && $user->allows('cash.approve')) {
                $add('cash_review', $branchOf(DB::table('cash_sessions')->where('tenant_id', $tenant->id)->where('review_status', 'pending'), 'branch_id')->count(), '/cash');
            }
        }

        if ($tenant->hasModule('invoicing') && ($user->allows('payments.view') || $user->allows('sales.view')) && Schema::hasTable('invoices')) {
            $retries = Schema::hasColumn('invoices', 'send_attempts');
            $add('invoices_error', $branchOf(DB::table('invoices')->where('tenant_id', $tenant->id)->where('status', 'issued'), 'branch_id')
                ->where(fn ($q) => $q->where('sunat_status', 'rejected')
                    ->when($retries, fn ($w) => $w->orWhere(fn ($g) => $g->where('sunat_status', 'pending')->where('send_attempts', '>', 0)->whereNull('next_attempt_at'))))
                ->count(), '/invoices');
        }

        if ($tenant->hasModule('pos') && $user->allows('payments.view') && PaymentLinks::ready()) {
            $add('payment_links', $branchOf(DB::table('payment_links')->where('tenant_id', $tenant->id)->where('status', 'pending'), 'branch_id')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', self::iso($now)))->count(), '/payments');
        }

        if ($user->allows('payments.review') && Schema::hasTable('payment_vouchers')) {
            $add('transfers_review', $branchOf(DB::table('payment_vouchers')->where('tenant_id', $tenant->id)->where('status', 'review'), 'branch_id')->count(), '/payments/vouchers');
        }

        if (Commissions::enabled($tenant) && $user->allows('commissions.approve')) {
            $add('commissions_pending', $branchOf(DB::table('commission_entries')->where('tenant_id', $tenant->id)->where('status', 'calculated'), 'branch_id')
                ->when($own, fn ($q) => $q->where('professional_id', $own))->count(), '/commissions');
        }

        if (Memberships::ready() && $tenant->hasModule('memberships') && $user->allows('memberships.view')) {
            $add('memberships_ending', DB::table('memberships')->where('tenant_id', $tenant->id)->whereIn('status', Memberships::ALIVE)
                ->where('ends_at', '>=', $dayStart->toDateString())->where('ends_at', '<=', $dayStart->copy()->addDays(5)->toDateString())->count(), '/memberships');
        }

        if (Refunds::requestsReady() && $tenant->hasModule('pos') && $user->allows('sales.approve')) {
            $add('refunds_pending', $branchOf(DB::table('refund_requests')->where('tenant_id', $tenant->id)->where('status', 'pending'), 'branch_id')->count(), '/sales');
        }

        if ($user->allows('messages.view') && Schema::hasTable('messages')) {
            $add('messages_failed', DB::table('messages')->where('tenant_id', $tenant->id)->where('status', 'failed')
                ->where('created_at', '>=', self::iso($dayStart->copy()->subDays(2)))
                ->when($limit, fn ($q, $id) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $id)))->count(), '/messages');
        }

        return $items;
    }

    public static function revenue(Tenant $tenant, ?string $limit, Carbon $dayStart): array
    {
        $from = $dayStart->copy()->subDays(13);
        $to = $dayStart->copy()->addDay();
        $zone = str_replace("'", '', $tenant->zone());
        $currency = Currency::of($tenant);
        $day = "to_char(coalesce(s.paid_at, s.created_at) AT TIME ZONE '{$zone}', 'YYYY-MM-DD')";

        $rows = DB::table('sale_items as i')->join('sales as s', 's.id', '=', 'i.sale_id')
            ->where('s.tenant_id', $tenant->id)
            ->whereIn('s.status', Reports::PAID)
            ->when(Currency::ready(), fn ($q) => $q->where('s.currency', $currency))
            ->when($limit, fn ($q, $id) => $q->where('s.branch_id', $id))
            ->whereRaw('coalesce(s.paid_at, s.created_at) >= ?', [self::iso($from)])
            ->whereRaw('coalesce(s.paid_at, s.created_at) < ?', [self::iso($to)])
            ->selectRaw("{$day} AS day, sum(i.total) FILTER (WHERE i.item_type = 'service') AS services, sum(i.total) FILTER (WHERE i.item_type <> 'service') AS others")
            ->groupBy('day')->get()->keyBy('day');

        $points = [];
        for ($cursor = $from->copy(); $cursor->lt($to); $cursor->addDay()) {
            $key = $cursor->toDateString();
            $points[] = [
                'day'      => $key,
                'services' => round((float) ($rows[$key]->services ?? 0), 2),
                'others'   => round((float) ($rows[$key]->others ?? 0), 2),
            ];
        }

        $month = (new Reports($tenant, $limit, $dayStart->copy()->subDays(29), $to))->salesSummary();

        return ['days' => $points, 'ticket' => $month['ticket'], 'sales' => $month['sales']];
    }

    public static function lowStock(Tenant $tenant, ?string $limit): array
    {
        return DB::table('stock_balances as sb')
            ->join('products as p', 'p.id', '=', 'sb.product_id')
            ->join('warehouses as w', 'w.id', '=', 'sb.warehouse_id')
            ->where('sb.tenant_id', $tenant->id)
            ->where('p.track_stock', true)->where('p.status', 'active')->whereNull('p.deleted_at')
            ->where('p.min_stock', '>', 0)->whereColumn('sb.quantity', '<=', 'p.min_stock')
            ->when($limit, fn ($q, $id) => $q->where('w.branch_id', $id))
            ->orderByRaw('sb.quantity - p.min_stock')->orderBy('p.name')
            ->limit(6)
            ->get(['p.id', 'p.name', 'w.name as warehouse', 'sb.quantity', 'p.min_stock'])
            ->map(fn ($row) => [
                'id'        => $row->id,
                'name'      => $row->name,
                'warehouse' => $row->warehouse,
                'quantity'  => (float) $row->quantity,
                'min'       => (float) $row->min_stock,
            ])->all();
    }

    public static function marketing(Tenant $tenant, User $user): ?array
    {
        $campaigns = $tenant->hasModule('marketing') && $user->allows('campaigns.view') && Schema::hasTable('marketing_campaigns');
        $automations = $tenant->hasModule('automations') && $user->allows('automations.view') && Schema::hasTable('automation_rules');

        if (!$campaigns && !$automations) {
            return null;
        }

        $since = now()->subDays(30);

        return [
            'campaigns' => !$campaigns ? null : DB::table('marketing_campaigns')->where('tenant_id', $tenant->id)
                ->orderByDesc('created_at')->limit(4)->get()
                ->map(function ($c) {
                    $stats = is_string($c->stats) ? (json_decode($c->stats, true) ?: []) : (array) $c->stats;

                    return [
                        'id'           => $c->id,
                        'name'         => $c->name,
                        'channel'      => Campaigns::CHANNELS[$c->channel ?: 'email'] ?? 'Correo',
                        'status'       => $c->status,
                        'status_label' => Campaigns::STATUS_LABELS[$c->status] ?? $c->status,
                        'recipients'   => (int) ($stats['eligible'] ?? $stats['audience'] ?? 0),
                    ];
                })->values()->all(),
            'automations' => !$automations ? null : DB::table('automation_rules as r')
                ->where('r.tenant_id', $tenant->id)->where('r.is_active', true)
                ->leftJoin('automation_logs as l', fn ($j) => $j->on('l.automation_rule_id', '=', 'r.id')->where('l.result', 'sent')->where('l.created_at', '>=', $since))
                ->groupBy('r.id', 'r.name')->orderByDesc(DB::raw('count(l.id)'))->orderBy('r.name')->limit(6)
                ->get(['r.id', 'r.name', DB::raw('count(l.id) as sent')])
                ->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'sent' => (int) $row->sent])->values()->all(),
        ];
    }

    public static function myDay(Tenant $tenant, User $user, string $own, ?string $limit, Carbon $now): ?array
    {
        $professional = Professional::where('tenant_id', $tenant->id)->where('id', $own)->first();

        if (!$professional) {
            return null;
        }

        $zone = $tenant->zone();
        $dayStart = $now->copy()->startOfDay();
        $local = fn (?Carbon $date) => $date?->copy()->setTimezone($zone)->format('Y-m-d\TH:i');
        $records = $tenant->hasModule('records') ? DB::table('record_template_services as rs')
            ->join('record_templates as rt', 'rt.id', '=', 'rs.record_template_id')
            ->where('rs.tenant_id', $tenant->id)->where('rt.status', 'active')->pluck('rs.service_id')->all() : [];

        $appointments = Appointment::where('tenant_id', $tenant->id)->where('professional_id', $own)
            ->where('starts_at', '>=', self::iso($dayStart))->where('starts_at', '<', self::iso($dayStart->copy()->addDay()))
            ->where('status', '!=', AppointmentFlow::CANCELLED)
            ->with(['customer', 'dependent', 'service', 'branch', 'record'])
            ->orderBy('starts_at')->get();

        $list = $appointments->map(function (Appointment $a) use ($tenant, $user, $local, $records) {
            $to = self::advance($tenant, $a, $user);

            return [
                'id'          => $a->id,
                'code'        => $a->code,
                'starts_at'   => $local($a->starts_at),
                'ends_at'     => $local($a->ends_at),
                'who'         => $a->dependent?->name ?? ($a->customer ? Person::name($a->customer) : null),
                'customer_id' => $a->customer_id,
                'service'     => $a->service?->name,
                'branch'      => $a->branch?->name,
                'status'      => $a->status,
                'status_name' => AppointmentFlow::name($tenant, $a->status),
                'source'      => self::source($a->source),
                'alert'       => $a->dependent?->alert ?: ($a->customer?->alert ?: null),
                'notes'       => $a->notes ?: null,
                'advance'     => $to ? ['to' => $to, 'label' => match ($to) {
                    AppointmentFlow::CONFIRMED   => 'Confirmar',
                    AppointmentFlow::CHECKED_IN  => 'Llegó',
                    AppointmentFlow::IN_PROGRESS => 'Iniciar',
                    default                      => 'Completar',
                }] : null,
                'record'      => $a->record !== null || in_array($a->service_id, $records, true),
                'record_done' => $a->record !== null && $a->record->status !== \App\Models\CareRecord::DRAFT,
            ];
        })->values()->all();

        $classes = [];
        if (ClassSessions::enabled($tenant) && Schema::hasTable('class_sessions')) {
            $sessions = DB::table('class_sessions')->where('tenant_id', $tenant->id)->where('professional_id', $own)->where('status', 'scheduled')
                ->where('starts_at', '>=', self::iso($dayStart))->where('starts_at', '<', self::iso($dayStart->copy()->addDay()))
                ->orderBy('starts_at')->get(['id', 'title', 'starts_at', 'ends_at', 'capacity']);
            $enrolled = DB::table('class_enrollments')->whereIn('class_session_id', $sessions->pluck('id'))->whereIn('status', ['enrolled', 'attended', 'no_show'])
                ->groupBy('class_session_id')->selectRaw('class_session_id, count(*) as total')->pluck('total', 'class_session_id');
            $classes = $sessions->map(fn ($s) => [
                'id'        => $s->id,
                'title'     => $s->title,
                'starts_at' => $local(Carbon::parse($s->starts_at)),
                'ends_at'   => $local(Carbon::parse($s->ends_at)),
                'enrolled'  => (int) ($enrolled[$s->id] ?? 0),
                'capacity'  => (int) $s->capacity,
            ])->values()->all();
        }

        $range = Reports::range($tenant, 'month');
        $production = (new Reports($tenant, null, $range['from'], $range['to']))->production($own);
        $goal = Schema::hasColumn('professionals', 'goal_month') && $professional->goal_month !== null ? round((float) $professional->goal_month, 2) : null;

        $from = $dayStart->copy()->addDay();
        $counts = Appointment::where('tenant_id', $tenant->id)->where('professional_id', $own)
            ->whereNotIn('status', AppointmentFlow::RELEASED)
            ->where('starts_at', '>=', self::iso($from))->where('starts_at', '<', self::iso($from->copy()->addDays(6)))
            ->get(['starts_at'])->groupBy(fn (Appointment $a) => $a->starts_at->copy()->setTimezone($zone)->toDateString())->map->count();

        $next = [];
        for ($i = 0; $i < 6; $i++) {
            $key = $from->copy()->addDays($i)->toDateString();
            $next[] = ['day' => $key, 'count' => (int) ($counts[$key] ?? 0)];
        }

        return [
            'professional' => ['id' => $professional->id, 'name' => $professional->display_name],
            'appointments' => $list,
            'classes'      => $classes,
            'production'   => ['value' => $production, 'goal' => $goal, 'progress' => $goal ? min(100, (int) round($production * 100 / $goal)) : null],
            'next_days'    => $next,
            'can_block'    => $user->allows('blocks.create'),
        ];
    }
}
