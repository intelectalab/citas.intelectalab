<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Support\Facades\DB;

class LinkPage
{
    public const MAX_LINKS = 6;

    private static function stored(Tenant $tenant): array
    {
        return PublicBooking::pageReady() ? ($tenant->settings?->booking_page ?? []) : [];
    }

    private static function put(Tenant $tenant, string $key, array $value): void
    {
        $settings = $tenant->settings ?? new TenantSetting(['tenant_id' => $tenant->id]);
        $stored = $settings->booking_page ?? [];
        $stored[$key] = $value;
        $settings->setAttribute('booking_page', $stored);
        $settings->save();
        $tenant->setRelation('settings', $settings);
    }

    public static function url(Tenant $tenant): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . '/enlace/' . $tenant->slug;
    }

    public static function get(Tenant $tenant): array
    {
        $stored = self::stored($tenant)['link_page'] ?? [];

        return [
            'enabled'       => (bool) ($stored['enabled'] ?? false),
            'title'         => trim((string) ($stored['title'] ?? '')) ?: null,
            'bio'           => trim((string) ($stored['bio'] ?? '')) ?: null,
            'links'         => array_values(array_filter(array_map(fn ($link) => [
                'label' => trim((string) ($link['label'] ?? '')),
                'url'   => trim((string) ($link['url'] ?? '')),
            ], (array) ($stored['links'] ?? [])), fn ($link) => $link['label'] !== '' && $link['url'] !== '')),
            'show_reviews'  => (bool) ($stored['show_reviews'] ?? true),
            'show_whatsapp' => (bool) ($stored['show_whatsapp'] ?? true),
            'show_location' => (bool) ($stored['show_location'] ?? true),
            'show_promotions' => (bool) ($stored['show_promotions'] ?? true),
            'show_gift_cards' => (bool) ($stored['show_gift_cards'] ?? true),
        ];
    }

    public static function save(Tenant $tenant, array $values): array
    {
        self::put($tenant, 'link_page', [
            'enabled'       => (bool) ($values['enabled'] ?? false),
            'title'         => trim((string) ($values['title'] ?? '')) ?: null,
            'bio'           => trim((string) ($values['bio'] ?? '')) ?: null,
            'links'         => array_slice(array_values(array_filter(array_map(fn ($link) => [
                'label' => trim((string) ($link['label'] ?? '')),
                'url'   => trim((string) ($link['url'] ?? '')),
            ], (array) ($values['links'] ?? [])), fn ($link) => $link['label'] !== '' && $link['url'] !== '')), 0, self::MAX_LINKS),
            'show_reviews'  => (bool) ($values['show_reviews'] ?? true),
            'show_whatsapp' => (bool) ($values['show_whatsapp'] ?? true),
            'show_location' => (bool) ($values['show_location'] ?? true),
            'show_promotions' => (bool) ($values['show_promotions'] ?? true),
            'show_gift_cards' => (bool) ($values['show_gift_cards'] ?? true),
        ]);

        return self::get($tenant);
    }

    public static function tracking(Tenant $tenant): array
    {
        $stored = self::stored($tenant)['tracking'] ?? [];

        return [
            'ga4_id'        => trim((string) ($stored['ga4_id'] ?? '')) ?: null,
            'meta_pixel_id' => trim((string) ($stored['meta_pixel_id'] ?? '')) ?: null,
        ];
    }

    public static function google(Tenant $tenant): array
    {
        $stored = self::stored($tenant)['google'] ?? [];

        return [
            'enabled'      => (bool) ($stored['enabled'] ?? false),
            'enabled_at'   => $stored['enabled_at'] ?? null,
            'booking_url'  => PublicBooking::url($tenant) . '?origen=google',
            'reserve'      => GoogleReserve::status($tenant),
        ];
    }

    public static function saveChannels(Tenant $tenant, array $values): void
    {
        $google = self::stored($tenant)['google'] ?? [];
        $enabled = (bool) ($values['google_enabled'] ?? false);

        self::put($tenant, 'google', [
            'enabled'    => $enabled,
            'enabled_at' => $enabled ? ($google['enabled_at'] ?? now()->toIso8601String()) : null,
        ]);

        self::put($tenant, 'tracking', [
            'ga4_id'        => strtoupper(trim((string) ($values['ga4_id'] ?? ''))) ?: null,
            'meta_pixel_id' => trim((string) ($values['meta_pixel_id'] ?? '')) ?: null,
        ]);
    }

    public static function publicPayload(Tenant $tenant): array
    {
        $config = self::get($tenant);
        $page = PublicBooking::page($tenant);
        $brand = Branding::present($tenant);
        $whatsapp = PublicBooking::digits($page['socials']['whatsapp'] ?? null);

        return [
            'name'        => $tenant->name,
            'code'        => $tenant->code,
            'title'       => $config['title'] ?: $page['title'],
            'bio'         => $config['bio'] ?: $page['description'],
            'brand'       => ['primary' => $brand['primary'], 'accent' => $brand['accent'], 'logo' => $brand['logo_url']],
            'cover_url'   => $page['cover_url'],
            'open'        => PublicBooking::open($tenant),
            'booking_url' => '/reservar/' . $tenant->slug . '?origen=link',
            'whatsapp'    => $config['show_whatsapp'] && $whatsapp !== '' ? $whatsapp : null,
            'socials'     => array_diff_key($page['socials'], ['whatsapp' => true]),
            'links'       => $config['links'],
            'branches'    => $config['show_location'] ? DB::table('branches')->where('tenant_id', $tenant->id)->where('status', 'active')->orderBy('name')
                ->get(['id', 'name', 'address', 'phone'])->map(fn ($b) => (array) $b + ['map_url' => $b->address ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($b->address) : null])->all() : [],
            'reviews'     => $config['show_reviews'] ? Surveys::publicReviews($tenant) : null,
            'tracking'    => self::tracking($tenant),
            'promotions'  => $config['show_promotions'] ? self::promotions($tenant) : [],
            'gift_cards'  => $config['show_gift_cards'] && Loyalty::enabled($tenant) && Loyalty::rules($tenant)['gift_cards_enabled'],
            'phone'       => trim((string) (DB::table('companies')->where('tenant_id', $tenant->id)->orderBy('created_at')->value('phone') ?? '')) ?: null,
            'business_type' => $tenant->businessType?->name,
        ];
    }

    public static function promotions(Tenant $tenant): array
    {
        if (!Packages::onlineReady() || !$tenant->hasModule('packages')) {
            return [];
        }

        $plans = DB::table('package_plans')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->where('status', 'active')
            ->where('sell_online', true)->orderBy('price')->limit(4)->get();
        $items = Packages::planItems($plans->pluck('id')->all());

        return $plans->map(fn ($plan) => collect(Packages::presentPlan($tenant, $plan, $items[$plan->id] ?? collect()))
            ->only(['id', 'name', 'description', 'price', 'currency', 'sessions', 'savings', 'savings_percent', 'validity_days'])->all())->values()->all();
    }

    public static function visitsReady(): bool
    {
        static $ready = null;

        return $ready ??= \Illuminate\Support\Facades\Schema::hasTable('link_page_visits');
    }

    public static function recordVisit(Tenant $tenant, string $kind = 'visits'): void
    {
        if (!self::visitsReady() || !in_array($kind, ['visits', 'bookings'], true)) {
            return;
        }

        DB::statement(
            "INSERT INTO link_page_visits (tenant_id, day, {$kind}) VALUES (?, ?, 1)
             ON CONFLICT (tenant_id, day) DO UPDATE SET {$kind} = link_page_visits.{$kind} + 1",
            [$tenant->id, now($tenant->zone())->toDateString()]
        );
    }

    public static function visits(Tenant $tenant): ?array
    {
        if (!self::visitsReady()) {
            return null;
        }

        $since = now($tenant->zone())->subDays(29)->toDateString();
        $row = DB::table('link_page_visits')->where('tenant_id', $tenant->id)->where('day', '>=', $since)
            ->selectRaw('COALESCE(SUM(visits), 0) AS visits, COALESCE(SUM(bookings), 0) AS bookings')->first();
        $total = (int) DB::table('link_page_visits')->where('tenant_id', $tenant->id)->sum('visits');

        return ['last_30' => (int) ($row->visits ?? 0), 'bookings_30' => (int) ($row->bookings ?? 0), 'total' => $total];
    }
}
