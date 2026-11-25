<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomerInsights
{
    public const SEGMENTS = ['new', 'frequent', 'vip', 'at_risk', 'inactive', 'no_visits', 'birthday_month', 'debt'];

    public const ORIGINS = [
        'staff'   => 'Recepción',
        'online'  => 'Reserva en línea',
        'walkin'  => 'Sin cita',
        'kiosk'   => 'Kiosko',
        'api'     => 'Integración',
        'chatbot' => 'Chat',
        'portal'  => 'Portal',
        'import'  => 'Importación',
    ];

    public const CALL_OUTCOMES = [
        'answered'  => 'contestó',
        'no_answer' => 'no contestó',
        'voicemail' => 'se dejó mensaje de voz',
        'callback'  => 'pidió que le devuelvan la llamada',
    ];

    public static function callsReady(): bool
    {
        static $ready = null;

        if ($ready !== null) {
            return $ready;
        }

        $definition = DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'messages_channel_check'");

        return $ready = !$definition || str_contains((string) $definition->def, "'call'");
    }

    public static function segmentKeys(User $user): array
    {
        return array_values(array_filter(self::SEGMENTS, fn ($key) => $key !== 'debt' || ($user->allows('sales.view') && Schema::hasTable('sales'))));
    }

    public static function segmentIds(Tenant $tenant, string $key): Builder
    {
        if ($key === 'debt') {
            return DB::table('sales')
                ->where('tenant_id', $tenant->id)
                ->whereIn('status', ['pending', 'partial'])
                ->where('balance', '>', 0)
                ->whereNotNull('customer_id')
                ->select('customer_id');
        }

        return Campaigns::customers($tenant, ['group' => $key])->select('c.id');
    }

    public static function segmentCounts(Tenant $tenant, User $user): array
    {
        $visible = Customer::visibleTo($user)->select('customers.id');
        $hints = collect(Campaigns::groups($tenant))->keyBy('key');
        $rows = [];

        foreach (self::segmentKeys($user) as $key) {
            $rows[] = [
                'key'   => $key,
                'count' => (int) Customer::visibleTo($user)->whereIn('customers.id', self::segmentIds($tenant, $key))->count(),
                'hint'  => $key === 'debt' ? 'Tienen ventas con pagos por completar.' : ($hints[$key]['hint'] ?? null),
            ];
        }

        return ['total' => (int) $visible->count(), 'segments' => $rows];
    }

    public static function forList(Tenant $tenant, User $user, array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $since = now()->subMonths(12)->toIso8601String();
        $out = array_fill_keys($ids, ['visits' => 0, 'spend_12m' => null, 'points' => null, 'segments' => [], 'origin' => null, 'origin_label' => null]);

        DB::table('appointments')
            ->where('tenant_id', $tenant->id)
            ->whereIn('customer_id', $ids)
            ->where('status', 'completed')
            ->groupBy('customer_id')
            ->selectRaw('customer_id, count(*) AS visits')
            ->get()
            ->each(function ($row) use (&$out) {
                $out[$row->customer_id]['visits'] = (int) $row->visits;
            });

        foreach (self::origins($tenant, $ids) as $id => $origin) {
            $out[$id]['origin'] = $origin;
            $out[$id]['origin_label'] = self::ORIGINS[$origin] ?? null;
        }

        if ($user->allows('sales.view') && Schema::hasTable('sales')) {
            foreach ($ids as $id) {
                $out[$id]['spend_12m'] = 0.0;
            }

            DB::table('sales')
                ->where('tenant_id', $tenant->id)
                ->whereIn('customer_id', $ids)
                ->whereIn('status', ['paid', 'partial', 'partially_refunded'])
                ->whereRaw('coalesce(paid_at, created_at) >= ?', [$since])
                ->groupBy('customer_id')
                ->selectRaw('customer_id, sum(paid) AS spent')
                ->get()
                ->each(function ($row) use (&$out) {
                    $out[$row->customer_id]['spend_12m'] = round((float) $row->spent, 2);
                });
        }

        if (Loyalty::enabled($tenant)) {
            foreach ($ids as $id) {
                $out[$id]['points'] = 0;
            }

            DB::table('loyalty_transactions')
                ->where('tenant_id', $tenant->id)
                ->whereIn('customer_id', $ids)
                ->groupBy('customer_id')
                ->selectRaw('customer_id, sum(points) AS points')
                ->get()
                ->each(function ($row) use (&$out) {
                    $out[$row->customer_id]['points'] = max(0, (int) $row->points);
                });
        }

        foreach (self::segmentKeys($user) as $key) {
            $found = $key === 'debt'
                ? self::segmentIds($tenant, $key)->whereIn('customer_id', $ids)->distinct()->pluck('customer_id')
                : Campaigns::customers($tenant, ['group' => $key])->whereIn('c.id', $ids)->pluck('c.id');

            foreach ($found as $id) {
                $out[$id]['segments'][] = $key;
            }
        }

        return $out;
    }

    public static function origins(Tenant $tenant, array $ids): array
    {
        $found = [];

        if (Schema::hasTable('customer_consent_events')) {
            DB::table('customer_consent_events')
                ->where('tenant_id', $tenant->id)
                ->whereIn('customer_id', $ids)
                ->where('source', 'import')
                ->distinct()
                ->pluck('customer_id')
                ->each(function ($id) use (&$found) {
                    $found[$id] = 'import';
                });
        }

        DB::table('appointments')
            ->where('tenant_id', $tenant->id)
            ->whereIn('customer_id', $ids)
            ->selectRaw('DISTINCT ON (customer_id) customer_id, source')
            ->orderBy('customer_id')
            ->orderBy('created_at')
            ->get()
            ->each(function ($row) use (&$found) {
                $found[$row->customer_id] ??= $row->source;
            });

        return $found;
    }

    public static function preferences(Tenant $tenant, Customer $customer): array
    {
        $favorite = fn (string $column, string $table, string $label) => DB::table('appointments as a')
            ->join("{$table} as t", 't.id', '=', "a.{$column}")
            ->where('a.tenant_id', $tenant->id)
            ->where('a.customer_id', $customer->id)
            ->where('a.status', 'completed')
            ->groupBy("a.{$column}", "t.{$label}")
            ->orderByRaw('count(*) desc')
            ->selectRaw("t.{$label} AS name, count(*) AS total")
            ->first();

        $service = $favorite('service_id', 'services', 'name');
        $professional = $favorite('professional_id', 'professionals', 'display_name');
        $origin = self::origins($tenant, [$customer->id])[$customer->id] ?? null;

        return [
            'favorite_service'      => $service ? ['name' => $service->name, 'times' => (int) $service->total] : null,
            'usual_professional'    => $professional ? ['name' => $professional->name, 'times' => (int) $professional->total] : null,
            'preferred_professional' => $customer->preferred_professional_id ? DB::table('professionals')->where('id', $customer->preferred_professional_id)->value('display_name') : null,
            'home_branch'           => $customer->home_branch_id ? DB::table('branches')->where('id', $customer->home_branch_id)->value('name') : null,
            'district'              => $customer->district,
            'birthday'              => $customer->birth_date?->format('m-d'),
            'origin'                => $origin ? (self::ORIGINS[$origin] ?? null) : null,
        ];
    }

    public static function money(Tenant $tenant, Customer $customer, array $base): array
    {
        $months = max(1, (int) ceil(Carbon::parse($customer->created_at)->diffInDays(now()) / 30));
        $paidSales = !Schema::hasTable('sales') ? 0 : DB::table('sales')
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['paid', 'partial', 'partially_refunded'])
            ->count();

        return $base + [
            'per_month'  => round($base['spent'] / $months, 2),
            'months'     => $months,
            'avg_ticket' => $paidSales > 0 ? round($base['spent'] / $paidSales, 2) : 0.0,
        ];
    }

    public static function recalls(Tenant $tenant, Customer $customer): array
    {
        if (!Schema::hasTable('recalls')) {
            return [];
        }

        return DB::table('recalls as r')
            ->leftJoin('customer_dependents as d', 'd.id', '=', 'r.customer_dependent_id')
            ->leftJoin('services as s', 's.id', '=', 'r.service_id')
            ->where('r.tenant_id', $tenant->id)
            ->where('r.customer_id', $customer->id)
            ->whereIn('r.status', ['pending', 'notified'])
            ->orderBy('r.due_date')
            ->limit(10)
            ->get(['r.id', 'r.label', 'r.due_date', 'r.status', 'r.service_id', 'r.customer_dependent_id', 'd.name as dependent', 's.name as service'])
            ->map(fn ($r) => [
                'id'           => $r->id,
                'label'        => $r->label,
                'due_date'     => Carbon::parse($r->due_date)->toDateString(),
                'overdue'      => Carbon::parse($r->due_date)->lt(now($tenant->zone())->startOfDay()),
                'status'       => $r->status,
                'service_id'   => $r->service_id,
                'service'      => $r->service,
                'dependent_id' => $r->customer_dependent_id,
                'dependent'    => $r->dependent,
            ])
            ->all();
    }

    public static function memberships(Tenant $tenant, Customer $customer): array
    {
        Memberships::expireDue($tenant);

        $rows = DB::table('memberships')
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->orderByRaw("CASE WHEN status IN ('active', 'grace', 'paused') THEN 0 ELSE 1 END")
            ->orderByDesc('ends_at')
            ->limit(20)
            ->get();

        return collect(Memberships::presentMany($tenant, $rows))
            ->map(fn (array $m) => collect($m)->only(['id', 'code', 'name', 'status', 'status_label', 'price', 'period_label', 'starts_at', 'ends_at', 'days_left', 'benefits_text', 'uses', 'grace_until'])->all())
            ->values()
            ->all();
    }

    public static function quotes(Tenant $tenant, Customer $customer, User $user): array
    {
        $branchLimit = Access::branchLimit($user);

        return DB::table('quotes as q')
            ->leftJoin('sales as s', 's.id', '=', 'q.sale_id')
            ->leftJoin('customer_dependents as d', 'd.id', '=', 'q.customer_dependent_id')
            ->where('q.tenant_id', $tenant->id)
            ->where('q.customer_id', $customer->id)
            ->when($branchLimit, fn ($query, $id) => $query->where(fn ($sub) => $sub->where('q.branch_id', $id)->orWhereNull('q.branch_id')))
            ->orderByDesc('q.created_at')
            ->limit(50)
            ->get(['q.id', 'q.code', 'q.title', 'q.status', 'q.total', 'q.valid_until', 'q.created_at', 's.balance as sale_balance', 'd.name as dependent'])
            ->map(fn ($q) => [
                'id'           => $q->id,
                'code'         => $q->code,
                'title'        => $q->title,
                'status'       => $q->status,
                'status_label' => Quotes::STATUSES[$q->status] ?? $q->status,
                'total'        => round((float) $q->total, 2),
                'balance'      => round((float) ($q->sale_balance ?? 0), 2),
                'dependent'    => $q->dependent,
                'valid_until'  => $q->valid_until ? Carbon::parse($q->valid_until)->toDateString() : null,
                'created_at'   => Carbon::parse($q->created_at)->setTimezone($tenant->zone())->format('Y-m-d\TH:i:s'),
            ])
            ->all();
    }

    public static function loyalty(Tenant $tenant, Customer $customer, User $user): array
    {
        $rules = Loyalty::rules($tenant);
        $balance = Loyalty::customerPoints($tenant->id, $customer->id);
        $local = fn ($value) => $value ? Carbon::parse($value)->setTimezone($tenant->zone())->format('Y-m-d\TH:i:s') : null;

        $movements = $rules['points_enabled'] ? DB::table('loyalty_transactions as lt')
            ->leftJoin('sales as s', 's.id', '=', 'lt.sale_id')
            ->where('lt.tenant_id', $tenant->id)
            ->where('lt.customer_id', $customer->id)
            ->orderByDesc('lt.created_at')
            ->limit(100)
            ->get(['lt.id', 'lt.type', 'lt.points', 'lt.balance_after', 'lt.reason', 'lt.created_at', 's.code as sale_code'])
            ->map(fn ($m) => [
                'id'            => $m->id,
                'type'          => $m->type,
                'type_label'    => Loyalty::POINT_TYPES[$m->type] ?? $m->type,
                'points'        => (int) $m->points,
                'balance_after' => (int) $m->balance_after,
                'reason'        => $m->reason,
                'sale_code'     => $m->sale_code,
                'created_at'    => $local($m->created_at),
            ])->values()->all() : [];

        $giftCards = [];

        if ($rules['gift_cards_enabled']) {
            Loyalty::expireDue($tenant->id);
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
                    'status_label'   => Loyalty::GIFT_CARD_STATUSES[$gc->status] ?? $gc->status,
                    'recipient_name' => $gc->recipient_name,
                    'bought'         => $gc->purchaser_customer_id === $customer->id && $gc->recipient_customer_id !== $customer->id,
                    'expires_at'     => $gc->expires_at ? Carbon::parse($gc->expires_at)->toDateString() : null,
                ])->values()->all();
        }

        return [
            'points_enabled'     => (bool) $rules['points_enabled'],
            'gift_cards_enabled' => (bool) $rules['gift_cards_enabled'],
            'balance'            => $balance,
            'value'              => round($balance * $rules['point_value'], 2),
            'rules'              => array_intersect_key($rules, array_flip(['points_enabled', 'amount_per_point', 'point_value', 'min_redeem_points', 'points_validity_days', 'gift_cards_enabled', 'gift_card_validity_days'])),
            'movements'          => $movements,
            'gift_cards'         => $giftCards,
            'can_adjust'         => $rules['points_enabled'] && ($user->allows('loyalty.create') || $user->allows('loyalty.edit')),
        ];
    }

    public static function dependentStats(Tenant $tenant, Customer $customer, array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $zone = $tenant->zone();
        $out = array_fill_keys($ids, ['appointments' => 0, 'last_visit' => null, 'next_appointment' => null, 'next_control' => null]);

        DB::table('appointments')
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->whereIn('customer_dependent_id', $ids)
            ->groupBy('customer_dependent_id')
            ->selectRaw("customer_dependent_id, count(*) FILTER (WHERE status NOT IN ('cancelled')) AS total, max(starts_at) FILTER (WHERE status = 'completed') AS last_visit, min(starts_at) FILTER (WHERE status IN ('pending', 'confirmed') AND starts_at > now()) AS next_visit")
            ->get()
            ->each(function ($row) use (&$out, $zone) {
                $out[$row->customer_dependent_id]['appointments'] = (int) $row->total;
                $out[$row->customer_dependent_id]['last_visit'] = $row->last_visit ? Carbon::parse($row->last_visit)->setTimezone($zone)->format('Y-m-d\TH:i:s') : null;
                $out[$row->customer_dependent_id]['next_appointment'] = $row->next_visit ? Carbon::parse($row->next_visit)->setTimezone($zone)->format('Y-m-d\TH:i:s') : null;
            });

        if (Schema::hasTable('recalls')) {
            DB::table('recalls')
                ->where('tenant_id', $tenant->id)
                ->where('customer_id', $customer->id)
                ->whereIn('customer_dependent_id', $ids)
                ->whereIn('status', ['pending', 'notified'])
                ->orderBy('due_date')
                ->get(['customer_dependent_id', 'label', 'due_date'])
                ->each(function ($row) use (&$out) {
                    $out[$row->customer_dependent_id]['next_control'] ??= ['label' => $row->label, 'due_date' => Carbon::parse($row->due_date)->toDateString()];
                });
        }

        return $out;
    }

    public static function messages(Tenant $tenant, Customer $customer): array
    {
        if (!Messaging::ready()) {
            return [];
        }

        return DB::table('messages')
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get(['id', 'channel', 'kind', 'subject', 'body', 'status', 'created_at'])
            ->map(fn ($m) => [
                'id'         => $m->id,
                'channel'    => $m->channel,
                'kind'       => $m->kind,
                'status'     => $m->status,
                'status_label' => Messaging::STATUS_LABELS[$m->status] ?? $m->status,
                'summary'    => mb_strimwidth(trim(strip_tags((string) ($m->subject ?: $m->body))), 0, 90, '…'),
                'created_at' => Carbon::parse($m->created_at)->setTimezone($tenant->zone())->format('Y-m-d\TH:i:s'),
            ])
            ->all();
    }
}
