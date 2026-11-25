<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomerProfile
{
    public const NOT_READY = 'Falta actualizar la base de datos para esta parte: ejecuta php artisan migrate.';

    public const SEGMENT_GROUPS = ['new', 'frequent', 'vip', 'at_risk', 'inactive', 'no_visits', 'birthday_month'];

    public const TAG_COLORS = ['#022340', '#3F768C', '#84D0D9', '#0F766E', '#B45309', '#BE123C', '#7C3AED', '#475569'];

    public static function fieldsReady(): bool
    {
        return Schema::hasColumn('customers', 'alert');
    }

    public static function notesReady(): bool
    {
        return Schema::hasTable('customer_notes');
    }

    public static function consentEventsReady(): bool
    {
        return Schema::hasTable('customer_consent_events');
    }

    public static function tagsReady(): bool
    {
        return Schema::hasTable('customer_tags') && Schema::hasTable('customer_tag_assignments');
    }

    public static function money(Tenant $tenant, string $customerId): array
    {
        if (!Schema::hasTable('sales')) {
            return ['spent' => 0.0, 'balance' => 0.0, 'purchases' => 0];
        }

        $row = DB::table('sales')
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $customerId)
            ->selectRaw("coalesce(sum(paid) FILTER (WHERE status IN ('paid', 'partial', 'partially_refunded')), 0) AS spent")
            ->selectRaw("coalesce(sum(balance) FILTER (WHERE status IN ('pending', 'partial')), 0) AS balance")
            ->selectRaw("count(*) FILTER (WHERE status NOT IN ('void')) AS purchases")
            ->first();

        return ['spent' => round((float) $row->spent, 2), 'balance' => round((float) $row->balance, 2), 'purchases' => (int) $row->purchases];
    }

    public static function segments(Tenant $tenant, string $customerId): array
    {
        $groups = collect(Campaigns::groups($tenant))->keyBy('key');
        $found = [];

        foreach (self::SEGMENT_GROUPS as $key) {
            if (Campaigns::customers($tenant, ['group' => $key])->where('c.id', $customerId)->exists()) {
                $found[] = ['key' => $key, 'label' => $groups[$key]['label'] ?? $key, 'hint' => $groups[$key]['hint'] ?? null];
            }
        }

        return $found;
    }

    public static function tags(string $tenantId): array
    {
        if (!self::tagsReady()) {
            return [];
        }

        $counts = DB::table('customer_tag_assignments as a')
            ->join('customers as c', 'c.id', '=', 'a.customer_id')
            ->whereNull('c.deleted_at')
            ->where('a.tenant_id', $tenantId)
            ->groupBy('a.customer_tag_id')
            ->selectRaw('a.customer_tag_id, count(*) as total')
            ->pluck('total', 'customer_tag_id');

        return DB::table('customer_tags')->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name', 'color'])
            ->map(fn ($tag) => ['id' => $tag->id, 'name' => $tag->name, 'color' => $tag->color, 'customers' => (int) ($counts[$tag->id] ?? 0)])
            ->all();
    }

    public static function tagsOf(string $customerId): array
    {
        if (!self::tagsReady()) {
            return [];
        }

        return DB::table('customer_tag_assignments as a')
            ->join('customer_tags as t', 't.id', '=', 'a.customer_tag_id')
            ->where('a.customer_id', $customerId)
            ->orderBy('t.name')
            ->get(['t.id', 't.name', 't.color'])
            ->map(fn ($tag) => (array) $tag)
            ->all();
    }

    public static function tagsFor(array $customerIds): array
    {
        if (!$customerIds || !self::tagsReady()) {
            return [];
        }

        return DB::table('customer_tag_assignments as a')
            ->join('customer_tags as t', 't.id', '=', 'a.customer_tag_id')
            ->whereIn('a.customer_id', $customerIds)
            ->orderBy('t.name')
            ->get(['a.customer_id', 't.id', 't.name', 't.color'])
            ->groupBy('customer_id')
            ->map(fn ($rows) => $rows->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color])->values()->all())
            ->all();
    }

    public static function purchases(Tenant $tenant, string $customerId): array
    {
        if (!Schema::hasTable('sales')) {
            return [];
        }

        $zone = $tenant->zone();

        $sales = DB::table('sales as s')
            ->leftJoin('branches as b', 'b.id', '=', 's.branch_id')
            ->where('s.tenant_id', $tenant->id)
            ->where('s.customer_id', $customerId)
            ->orderByDesc('s.created_at')
            ->limit(300)
            ->get(['s.id', 's.code', 's.status', 's.total', 's.paid', 's.balance', 's.created_at', 's.paid_at', 'b.name as branch']);

        $items = DB::table('sale_items')->whereIn('sale_id', $sales->pluck('id'))->orderBy('position')->get(['sale_id', 'description'])->groupBy('sale_id');

        return $sales
            ->map(fn ($s) => [
                'id'       => $s->id,
                'code'     => $s->code,
                'status'   => $s->status,
                'status_label' => Sales::STATUS_LABELS[$s->status] ?? $s->status,
                'total'    => (float) $s->total,
                'paid'     => (float) $s->paid,
                'balance'  => (float) $s->balance,
                'branch'   => $s->branch,
                'date'     => Carbon::parse($s->paid_at ?? $s->created_at)->setTimezone($zone)->format('Y-m-d\TH:i:s'),
                'items'    => ($items[$s->id] ?? collect())->pluck('description')->filter()->values()->all(),
            ])
            ->values()
            ->all();
    }

    public static function notes(Tenant $tenant, string $customerId, User $user): array
    {
        if (!self::notesReady()) {
            return [];
        }

        $canPrivate = $user->allows('customers.notes');
        $zone = $tenant->zone();

        return DB::table('customer_notes as n')
            ->leftJoin('users as u', 'u.id', '=', 'n.author_id')
            ->where('n.tenant_id', $tenant->id)
            ->where('n.customer_id', $customerId)
            ->whereNull('n.deleted_at')
            ->when(!$canPrivate, fn ($q) => $q->where('n.is_private', false))
            ->orderByDesc('n.created_at')
            ->get(['n.id', 'n.body', 'n.is_private', 'n.author_id', 'n.created_at', 'u.first_name', 'u.last_name', 'u.email'])
            ->map(fn ($n) => [
                'id'         => $n->id,
                'body'       => $n->body,
                'is_private' => (bool) $n->is_private,
                'author'     => trim(($n->first_name ?? '') . ' ' . ($n->last_name ?? '')) ?: ($n->email ?? 'Alguien del equipo'),
                'mine'       => $n->author_id === $user->id,
                'created_at' => Carbon::parse($n->created_at)->setTimezone($zone)->format('Y-m-d\TH:i:s'),
            ])
            ->all();
    }

    public static function consentHistory(Tenant $tenant, string $customerId): array
    {
        if (!self::consentEventsReady()) {
            return [];
        }

        $zone = $tenant->zone();

        return DB::table('customer_consent_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->where('e.tenant_id', $tenant->id)
            ->where('e.customer_id', $customerId)
            ->orderByDesc('e.created_at')
            ->limit(200)
            ->get(['e.id', 'e.channel', 'e.granted', 'e.source', 'e.ip_address', 'e.actor_customer_id', 'e.created_at', 'u.first_name', 'u.last_name', 'u.email'])
            ->map(fn ($e) => [
                'id'      => $e->id,
                'channel' => $e->channel,
                'granted' => (bool) $e->granted,
                'source'  => $e->source,
                'by'      => $e->first_name || $e->last_name ? trim("{$e->first_name} {$e->last_name}") : ($e->email ?? ($e->actor_customer_id ? 'La misma persona, desde su portal' : self::SOURCE_LABELS[$e->source] ?? 'El sistema')),
                'ip'      => $e->ip_address,
                'at'      => Carbon::parse($e->created_at)->setTimezone($zone)->format('Y-m-d\TH:i:s'),
            ])
            ->all();
    }

    private const SOURCE_LABELS = [
        'unsubscribe'    => 'La misma persona, desde el enlace del correo',
        'public_booking' => 'La misma persona, al reservar en línea',
        'portal'         => 'La misma persona, desde su portal',
        'import'         => 'Importación de clientes',
    ];

    public static function recordConsent(string $tenantId, string $customerId, string $channel, bool $granted, string $source): void
    {
        if (!self::consentEventsReady()) {
            return;
        }

        $request = request();
        $actor = $request?->user();

        DB::table('customer_consent_events')->insert([
            'tenant_id'         => $tenantId,
            'customer_id'       => $customerId,
            'channel'           => $channel,
            'granted'           => $granted,
            'source'            => $source,
            'ip_address'        => $request?->ip(),
            'user_id'           => $actor instanceof User ? $actor->id : null,
            'actor_customer_id' => $actor instanceof \App\Models\Customer ? $actor->id : null,
            'created_at'        => now(),
        ]);
    }
}
