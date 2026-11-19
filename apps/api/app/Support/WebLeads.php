<?php

namespace App\Support;

use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class WebLeads
{
    public const NOT_READY = 'Falta actualizar la base de datos para ver el embudo del sitio. Pide que se ejecute la actualización (php artisan migrate).';

    public const STAGES = [
        'new'       => 'Nuevo',
        'contacted' => 'Contactado',
        'trial'     => 'En prueba',
        'paid'      => 'Pagó',
        'lost'      => 'Perdido',
    ];

    public const SOURCES = [
        'registro' => 'Registro en la web',
        'contacto' => 'Formulario «Te llamamos»',
        'manual'   => 'Agregado a mano',
        'chatbot'  => 'Chat del sitio',
    ];

    public const PAGES = ['home', 'pricing', 'business', 'blog', 'post', 'legal', 'signup'];

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('web_visits') && Schema::hasTable('web_lead_events');
    }

    public static function cleanUtm(mixed $utm): array
    {
        $out = [];

        foreach (['source', 'medium', 'campaign', 'term', 'content'] as $key) {
            $value = is_array($utm) ? ($utm[$key] ?? $utm["utm_{$key}"] ?? null) : null;

            if (is_string($value) && trim($value) !== '') {
                $out[$key] = mb_substr(trim(strip_tags($value)), 0, 80);
            }
        }

        return $out;
    }

    public static function sourceOf(?string $utmSource, ?string $referrer, ?string $host = null): string
    {
        if ($utmSource) {
            return mb_substr(Str::slug($utmSource), 0, 40) ?: 'directo';
        }

        $domain = $referrer ? parse_url($referrer, PHP_URL_HOST) : null;

        if (!$domain || ($host && str_ends_with($domain, $host))) {
            return 'directo';
        }

        $domain = preg_replace('/^(www\.|m\.|l\.|lm\.)/', '', strtolower($domain));

        foreach (['google' => 'google', 'bing' => 'bing', 'facebook' => 'facebook', 'instagram' => 'instagram', 'tiktok' => 'tiktok', 'youtube' => 'youtube', 'linkedin' => 'linkedin', 'whatsapp' => 'whatsapp', 't.co' => 'x', 'twitter' => 'x'] as $needle => $name) {
            if (str_contains($domain, $needle)) {
                return $name;
            }
        }

        return mb_substr($domain, 0, 40);
    }

    public static function recordVisit(string $page, string $source, ?string $businessType = null): void
    {
        if (!self::ready() || !in_array($page, self::PAGES, true)) {
            return;
        }

        DB::statement(
            'INSERT INTO web_visits (day, page, source, business_type, visits) VALUES (?, ?, ?, ?, 1)
             ON CONFLICT (day, page, source, business_type) DO UPDATE SET visits = web_visits.visits + 1',
            [Carbon::now('America/Lima')->toDateString(), $page, mb_substr($source, 0, 40), mb_substr((string) $businessType, 0, 50)]
        );
    }

    public static function create(array $values, ?object $user = null): string
    {
        return Rls::bypass(function () use ($values, $user) {
            $id = (string) Str::uuid();
            DB::table('web_leads')->insert([
                'id'            => $id,
                'tenant_id'     => $values['tenant_id'] ?? null,
                'name'          => mb_substr($values['name'], 0, 150),
                'email'         => isset($values['email']) ? mb_strtolower(trim($values['email'])) : null,
                'phone'         => $values['phone'] ?? null,
                'business'      => $values['business'] ?? null,
                'business_type' => $values['business_type'] ?? null,
                'professionals' => $values['professionals'] ?? null,
                'source'        => $values['source'],
                'utm'           => json_encode(self::cleanUtm($values['utm'] ?? [])),
                'stage'         => $values['stage'] ?? 'new',
                'coupon'        => $values['coupon'] ?? null,
                'trial_at'      => $values['trial_at'] ?? null,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
            self::event($id, 'stage', null, $values['stage'] ?? 'new', $values['note'] ?? null, $user);

            return $id;
        });
    }

    public static function event(string $leadId, string $kind, ?string $from, ?string $to, ?string $body, ?object $user = null): void
    {
        DB::table('web_lead_events')->insert([
            'id'         => (string) Str::uuid(),
            'lead_id'    => $leadId,
            'kind'       => $kind,
            'from_stage' => $from,
            'to_stage'   => $to,
            'body'       => $body,
            'user_id'    => $user?->id,
            'user_name'  => $user ? Person::name($user) : null,
            'created_at' => now(),
        ]);
    }

    public static function fromSignup(Tenant $tenant, array $values): void
    {
        if (!self::ready()) {
            return;
        }

        try {
            Rls::bypass(function () use ($tenant, $values) {
                $email = mb_strtolower(trim($values['email']));
                $existing = DB::table('web_leads')->whereNull('tenant_id')->where('email', $email)->whereIn('stage', ['new', 'contacted', 'lost'])->orderByDesc('created_at')->first();
                $businessType = $tenant->business_type_id ? DB::table('business_types')->where('id', $tenant->business_type_id)->value('slug') : null;

                if ($existing) {
                    DB::table('web_leads')->where('id', $existing->id)->update([
                        'tenant_id'     => $tenant->id,
                        'stage'         => 'trial',
                        'trial_at'      => now(),
                        'business'      => $existing->business ?: $tenant->name,
                        'business_type' => $existing->business_type ?: $businessType,
                        'coupon'        => $values['coupon'] ?? $existing->coupon,
                        'updated_at'    => now(),
                    ]);
                    self::event($existing->id, 'stage', $existing->stage, 'trial', 'Se registró en la web y empezó su prueba.');

                    return;
                }

                self::create([
                    'tenant_id'     => $tenant->id,
                    'name'          => $values['name'],
                    'email'         => $email,
                    'phone'         => $values['phone'] ?? null,
                    'business'      => $tenant->name,
                    'business_type' => $businessType,
                    'source'        => 'registro',
                    'utm'           => $values['utm'] ?? [],
                    'stage'         => 'trial',
                    'coupon'        => $values['coupon'] ?? null,
                    'trial_at'      => now(),
                    'note'          => 'Se registró en la web y empezó su prueba.',
                ]);
            });
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function onPaid(Subscription $subscription): void
    {
        if (!self::ready()) {
            return;
        }

        try {
            Rls::bypass(function () use ($subscription) {
                $leads = DB::table('web_leads')->where('tenant_id', $subscription->tenant_id)->whereNull('paid_at')->get(['id', 'stage']);

                foreach ($leads as $lead) {
                    DB::table('web_leads')->where('id', $lead->id)->update(['stage' => 'paid', 'paid_at' => now(), 'updated_at' => now()]);
                    self::event($lead->id, 'stage', $lead->stage, 'paid', 'Pagó su primer periodo.');
                }
            });
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function range(?string $from, ?string $to): array
    {
        $zone = 'America/Lima';
        $end = $to ? Carbon::parse($to, $zone)->endOfDay() : Carbon::now($zone)->endOfDay();
        $start = $from ? Carbon::parse($from, $zone)->startOfDay() : $end->copy()->subDays(29)->startOfDay();

        return [$start, $end];
    }

    public static function funnel(?string $from, ?string $to): array
    {
        [$start, $end] = self::range($from, $to);
        $leads = DB::table('web_leads')->whereBetween('created_at', [$start->copy()->utc(), $end->copy()->utc()])->get(['source', 'business_type', 'trial_at', 'paid_at', 'stage']);
        $visits = DB::table('web_visits')->whereBetween('day', [$start->toDateString(), $end->toDateString()]);
        $visitTotal = (int) (clone $visits)->sum('visits');
        $names = DB::table('business_types')->pluck('name', 'slug');

        $rate = fn (int $part, int $whole) => $whole > 0 ? round($part * 100 / $whole, 1) : null;
        $group = function (string $key, callable $label) use ($leads, $rate) {
            return $leads->groupBy(fn ($lead) => $lead->{$key} ?: '')->map(function ($rows, $value) use ($label, $rate) {
                $count = $rows->count();
                $trials = $rows->whereNotNull('trial_at')->count();
                $paid = $rows->whereNotNull('paid_at')->count();

                return ['key' => $value, 'label' => $label($value), 'leads' => $count, 'trials' => $trials, 'paid' => $paid, 'trial_rate' => $rate($trials, $count), 'paid_rate' => $rate($paid, $count)];
            })->sortByDesc('leads')->values();
        };

        $leadCount = $leads->count();
        $trialCount = $leads->whereNotNull('trial_at')->count();
        $paidCount = $leads->whereNotNull('paid_at')->count();

        return [
            'from'   => $start->toDateString(),
            'to'     => $end->toDateString(),
            'steps'  => [
                ['key' => 'visits', 'label' => 'Visitas', 'value' => $visitTotal, 'rate' => null],
                ['key' => 'leads', 'label' => 'Interesados', 'value' => $leadCount, 'rate' => $rate($leadCount, $visitTotal)],
                ['key' => 'trials', 'label' => 'Pruebas', 'value' => $trialCount, 'rate' => $rate($trialCount, $leadCount)],
                ['key' => 'paid', 'label' => 'Pagaron', 'value' => $paidCount, 'rate' => $rate($paidCount, $trialCount)],
            ],
            'lost'   => $leads->where('stage', 'lost')->count(),
            'by_source' => $group('source', fn ($value) => self::SOURCES[$value] ?? ($value ?: 'Sin fuente')),
            'by_business_type' => $group('business_type', fn ($value) => $names[$value] ?? 'Sin rubro'),
            'visits_by_source' => (clone $visits)->selectRaw('source, sum(visits) as visits')->groupBy('source')->orderByDesc('visits')->limit(12)->get()->map(fn ($row) => ['source' => $row->source, 'visits' => (int) $row->visits])->values(),
            'visits_by_page' => (clone $visits)->selectRaw('page, sum(visits) as visits')->groupBy('page')->orderByDesc('visits')->get()->map(fn ($row) => ['page' => $row->page, 'visits' => (int) $row->visits])->values(),
        ];
    }

    public static function present(object $lead, array $names = []): array
    {
        $utm = is_string($lead->utm) ? json_decode($lead->utm, true) : (array) $lead->utm;

        return [
            'id'                  => $lead->id,
            'name'                => $lead->name,
            'email'               => $lead->email,
            'phone'               => $lead->phone,
            'business'            => $lead->business,
            'business_type'       => $lead->business_type,
            'business_type_name'  => $lead->business_type ? ($names[$lead->business_type] ?? $lead->business_type) : null,
            'professionals'       => $lead->professionals,
            'source'              => $lead->source,
            'source_label'        => self::SOURCES[$lead->source] ?? $lead->source,
            'utm'                 => $utm ?: [],
            'stage'               => $lead->stage,
            'stage_label'         => self::STAGES[$lead->stage] ?? $lead->stage,
            'coupon'              => $lead->coupon,
            'lost_reason'         => $lead->lost_reason,
            'tenant_id'           => $lead->tenant_id,
            'tenant_name'         => $lead->tenant_name ?? null,
            'trial_at'            => $lead->trial_at,
            'paid_at'             => $lead->paid_at,
            'created_at'          => $lead->created_at,
        ];
    }
}
