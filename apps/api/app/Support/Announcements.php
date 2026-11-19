<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Announcements
{
    public const KINDS = [
        'news'        => 'Novedad',
        'maintenance' => 'Mantenimiento',
        'important'   => 'Importante',
    ];

    public const AUDIENCES = [
        'all'            => 'Todas las empresas',
        'plans'          => 'Solo algunos planes',
        'business_types' => 'Solo algunos rubros',
    ];

    public const NOT_READY = 'Falta actualizar la base de datos para publicar anuncios: ejecuta php artisan migrate.';

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('saas_announcements') && Schema::hasTable('saas_announcement_dismissals');
    }

    private static function list($value): array
    {
        $value = is_string($value) ? json_decode($value, true) : $value;

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    public static function reaches(object $row, ?string $planId, ?string $businessTypeId): bool
    {
        return match ($row->audience) {
            'plans'          => $planId !== null && in_array($planId, self::list($row->plan_ids), true),
            'business_types' => $businessTypeId !== null && in_array($businessTypeId, self::list($row->business_type_ids), true),
            default          => true,
        };
    }

    public static function live()
    {
        return DB::table('saas_announcements')->where('starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public static function forUser(User $user, bool $includeDismissed = false): array
    {
        if (!self::ready() || !$user->tenant_id) {
            return [];
        }

        $tenant = $user->tenant;
        $planId = Subscriptions::latest($user->tenant_id)?->plan_id;
        $dismissed = DB::table('saas_announcement_dismissals')->where('user_id', $user->id)->pluck('announcement_id')->all();

        return self::live()->orderByRaw("case kind when 'maintenance' then 0 when 'important' then 1 else 2 end")->orderByDesc('starts_at')->get()
            ->filter(fn ($row) => self::reaches($row, $planId, $tenant?->business_type_id))
            ->filter(fn ($row) => $includeDismissed || !in_array($row->id, $dismissed, true))
            ->map(fn ($row) => [
                'id'         => $row->id,
                'title'      => $row->title,
                'body'       => $row->body,
                'kind'       => $row->kind,
                'kind_label' => self::KINDS[$row->kind] ?? $row->kind,
                'starts_at'  => Carbon::parse($row->starts_at)->toIso8601String(),
                'ends_at'    => $row->ends_at ? Carbon::parse($row->ends_at)->toIso8601String() : null,
                'dismissed'  => in_array($row->id, $dismissed, true),
            ])->values()->all();
    }

    public static function deliver(User $user): void
    {
        try {
            foreach (self::forUser($user) as $item) {
                Notifier::send($user->tenant_id, $user->id, 'announcement', mb_substr("{$item['kind_label']}: {$item['title']}", 0, 190), mb_substr(RichText::toText($item['body']), 0, 300), '/home', 'announcement', $item['id'], "announcement:{$item['id']}");
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function dismiss(User $user, string $id): void
    {
        DB::table('saas_announcement_dismissals')->insertOrIgnore(['announcement_id' => $id, 'user_id' => $user->id, 'created_at' => now()]);
        DB::table('user_notifications')->where('user_id', $user->id)->where('entity_type', 'announcement')->where('entity_id', $id)->whereNull('read_at')->update(['read_at' => now()]);
    }

    public static function reachCount(object $row): int
    {
        $tenants = DB::table('tenants')->whereNull('deleted_at')->where('status', 'active')->get(['id', 'business_type_id']);
        $plans = DB::table('subscriptions')->whereIn('tenant_id', $tenants->pluck('id'))->orderBy('starts_at')->get(['tenant_id', 'plan_id'])->pluck('plan_id', 'tenant_id');

        return $tenants->filter(fn ($t) => self::reaches($row, $plans[$t->id] ?? null, $t->business_type_id))->count();
    }

    public static function present(object $row): array
    {
        $now = now();
        $start = Carbon::parse($row->starts_at);
        $end = $row->ends_at ? Carbon::parse($row->ends_at) : null;

        return [
            'id'                => $row->id,
            'title'             => $row->title,
            'body'              => $row->body,
            'kind'              => $row->kind,
            'kind_label'        => self::KINDS[$row->kind] ?? $row->kind,
            'audience'          => $row->audience,
            'audience_label'    => self::AUDIENCES[$row->audience] ?? $row->audience,
            'plan_ids'          => self::list($row->plan_ids),
            'business_type_ids' => self::list($row->business_type_ids),
            'starts_at'         => $start->toIso8601String(),
            'ends_at'           => $end?->toIso8601String(),
            'state'             => $start->gt($now) ? 'scheduled' : ($end && $end->lte($now) ? 'ended' : 'live'),
            'reach'             => self::reachCount($row),
            'dismissals'        => DB::table('saas_announcement_dismissals')->where('announcement_id', $row->id)->count(),
        ];
    }

    public static function save(?string $id, array $data, User $user): object
    {
        $zone = PlatformReports::ZONE;
        $values = [
            'title'             => trim($data['title']),
            'body'              => RichText::normalize($data['body']),
            'kind'              => $data['kind'],
            'audience'          => $data['audience'],
            'plan_ids'          => json_encode($data['audience'] === 'plans' ? array_values($data['plan_ids'] ?? []) : []),
            'business_type_ids' => json_encode($data['audience'] === 'business_types' ? array_values($data['business_type_ids'] ?? []) : []),
            'starts_at'         => Carbon::parse($data['starts_on'], $zone)->startOfDay()->utc(),
            'ends_at'           => !empty($data['ends_on']) ? Carbon::parse($data['ends_on'], $zone)->addDay()->startOfDay()->utc() : null,
            'updated_at'        => now(),
        ];

        if ($id) {
            DB::table('saas_announcements')->where('id', $id)->update($values);
        } else {
            $id = (string) Str::uuid();
            DB::table('saas_announcements')->insert($values + ['id' => $id, 'created_by' => $user->id, 'created_at' => now()]);
        }

        return DB::table('saas_announcements')->where('id', $id)->first();
    }
}
