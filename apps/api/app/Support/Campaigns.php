<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Campaigns
{
    public const NOT_READY = 'Falta actualizar la base de datos para usar campañas: ejecuta php artisan migrate.';

    public const VOLUME_WARNING = 400;

    public const BOOKED_DAYS = 7;

    public const GROUPS = [
        'all'            => ['label' => 'Todos los clientes', 'hint' => 'Todos los clientes registrados.'],
        'new'            => ['label' => 'Clientes nuevos', 'hint' => 'Vinieron una sola vez, en los últimos 45 días.'],
        'frequent'       => ['label' => 'Clientes frecuentes', 'hint' => 'Vinieron [visitas] veces o más en los últimos 90 días.'],
        'vip'            => ['label' => 'Clientes VIP', 'hint' => 'Pagaron [monto] o más en los últimos 12 meses.'],
        'at_risk'        => ['label' => 'En riesgo de no volver', 'hint' => 'No vienen hace [riesgo] días o más y no tienen una cita próxima.'],
        'inactive'       => ['label' => 'Inactivos', 'hint' => 'No vienen hace [inactivo] días o más y no tienen una cita próxima.'],
        'no_visits'      => ['label' => 'Registrados sin visitas', 'hint' => 'Están registrados pero aún no se atienden.'],
        'birthday_month' => ['label' => 'Cumpleaños del mes', 'hint' => 'Cumplen años este mes.'],
        'service'        => ['label' => 'Que se hicieron un servicio', 'hint' => 'Se atendieron alguna vez con el servicio que elijas.'],
        'branch'         => ['label' => 'Que se atendieron en una sede', 'hint' => 'Se atendieron alguna vez en la sede que elijas.'],
        'tag'            => ['label' => 'Con una etiqueta', 'hint' => 'Tienen la etiqueta que elijas.'],
    ];

    public const STATUS_LABELS = [
        'draft'     => 'Borrador',
        'scheduled' => 'Programada',
        'sending'   => 'Enviando',
        'sent'      => 'Enviada',
        'cancelled' => 'Cancelada',
        'blocked'   => 'No salió',
    ];

    public const CHANNELS = [
        'email'    => 'Correo',
        'whatsapp' => 'WhatsApp',
        'both'     => 'Correo y WhatsApp',
    ];

    public const WA_VARIABLES = [
        'Nombre'   => 'Nombre del cliente',
        'Apellido' => 'Apellido del cliente',
        'Empresa'  => 'Tu empresa',
        'Cupón'    => 'Código del cupón',
        'Portal'   => 'Dirección del portal',
        'Baja'     => 'Enlace para dejar de recibir promociones',
    ];

    public const WA_MAX_PARAMS = 10;

    public const EMAIL_REASONS = [
        'no_email'   => 'No tiene un correo registrado.',
        'no_consent' => 'No aceptó recibir promociones por correo.',
    ];

    public const WA_REASONS = [
        'not_connected' => 'Tu empresa aún no conecta WhatsApp.',
        'no_phone'      => 'No tiene un celular registrado.',
        'unsubscribed'  => 'Pidió no recibir más promociones.',
        'no_consent'    => 'No aceptó recibir mensajes por WhatsApp.',
    ];

    public static function whatsappReady(): bool
    {
        static $ready = null;

        return $ready ??= self::ready() && WhatsApp::ready()
            && Schema::hasColumn('marketing_campaigns', 'whatsapp')
            && Schema::hasColumn('marketing_campaign_recipients', 'channel');
    }

    public static function channelsOf(?string $channel): array
    {
        return match ($channel) {
            'whatsapp' => ['whatsapp'],
            'both'     => ['email', 'whatsapp'],
            default    => ['email'],
        };
    }

    public static function quotaKind(string $channel): string
    {
        return $channel === 'whatsapp' ? 'whatsapp' : 'campaign_email';
    }

    public static function quotas(Tenant $tenant, array $audience, string $channel): array
    {
        $result = [];

        foreach (self::channelsOf($channel) as $one) {
            $result[$one] = MessageQuota::state($tenant, self::quotaKind($one), (int) ($audience['channels'][$one]['eligible'] ?? 0));
        }

        return $result;
    }

    public static function shortage(array $quotas): ?string
    {
        foreach ($quotas as $state) {
            if (!$state['enough']) {
                return MessageQuota::shortage($state);
            }
        }

        return null;
    }

    public const VARIABLES = [
        'Nombre'  => 'Nombre del cliente',
        'Empresa' => 'Tu empresa',
        'Cupón'   => 'Código del cupón',
        'Portal'  => 'Dirección del portal',
    ];

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Messaging::ready() && Schema::hasColumn('marketing_campaign_recipients', 'message_id');
    }

    public static function rules(Tenant $tenant): array
    {
        $inactive = (int) AppointmentFlow::rule($tenant, 'crm.inactive_days');

        return [
            'inactive_days'   => $inactive,
            'at_risk_days'    => (int) ceil($inactive * 0.66),
            'frequent_visits' => (int) AppointmentFlow::rule($tenant, 'crm.frequent_visits'),
            'vip_spend'       => (float) AppointmentFlow::rule($tenant, 'crm.vip_spend'),
        ];
    }

    public static function groups(Tenant $tenant): array
    {
        $rules = self::rules($tenant);
        $replace = [
            '[visitas]'  => $rules['frequent_visits'],
            '[monto]'    => Currency::format($rules['vip_spend'], Currency::of($tenant)),
            '[riesgo]'   => $rules['at_risk_days'],
            '[inactivo]' => $rules['inactive_days'],
        ];

        return collect(self::GROUPS)->reject(fn ($group, $key) => $key === 'tag' && !CustomerProfile::tagsReady())->map(fn ($group, $key) => [
            'key'   => $key,
            'label' => $group['label'],
            'hint'  => strtr($group['hint'], $replace),
            'needs' => in_array($key, ['service', 'branch', 'tag'], true) ? $key : null,
        ])->values()->all();
    }

    public static function label(Tenant $tenant, array $segment): string
    {
        $group = self::GROUPS[$segment['group'] ?? 'all']['label'] ?? 'Todos los clientes';

        $label = match ($segment['group'] ?? null) {
            'service' => $group . ': ' . (DB::table('services')->where('tenant_id', $tenant->id)->where('id', $segment['service_id'] ?? null)->value('name') ?? '—'),
            'branch'  => $group . ': ' . (DB::table('branches')->where('tenant_id', $tenant->id)->where('id', $segment['branch_id'] ?? null)->value('name') ?? '—'),
            'tag'     => $group . ': ' . (CustomerProfile::tagsReady() ? DB::table('customer_tags')->where('tenant_id', $tenant->id)->where('id', $segment['tag_id'] ?? null)->value('name') ?? '—' : '—'),
            default   => $group,
        };

        return empty($segment['only_branch']) ? $label : $label . ' · de ' . (DB::table('branches')->where('id', $segment['only_branch'])->value('name') ?? 'su sede');
    }

    public static function customers(Tenant $tenant, array $segment): Builder
    {
        $rules = self::rules($tenant);
        $now = now();
        $group = $segment['group'] ?? 'all';

        $visits = DB::table('appointments')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'completed')
            ->groupBy('customer_id')
            ->selectRaw('customer_id, count(*) AS visits, max(starts_at) AS last_visit, count(*) FILTER (WHERE starts_at >= ?) AS recent', [$now->copy()->subDays(90)->toIso8601String()]);

        $spend = DB::table('sales')
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['paid', 'partial', 'partially_refunded'])
            ->whereRaw('coalesce(paid_at, created_at) >= ?', [$now->copy()->subMonths(12)->toIso8601String()])
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->selectRaw('customer_id, sum(paid) AS spent');

        $query = DB::table('customers as c')
            ->where('c.tenant_id', $tenant->id)
            ->whereNull('c.deleted_at')
            ->leftJoinSub($visits, 'v', 'v.customer_id', '=', 'c.id')
            ->leftJoinSub($spend, 's', 's.customer_id', '=', 'c.id');

        $upcoming = fn ($q) => $q->select(DB::raw(1))->from('appointments as a')
            ->whereColumn('a.customer_id', 'c.id')
            ->whereIn('a.status', ['pending', 'confirmed'])
            ->where('a.starts_at', '>', $now->toIso8601String());

        match ($group) {
            'new'            => $query->where('v.visits', 1)->where('v.last_visit', '>=', $now->copy()->subDays(45)->toIso8601String()),
            'frequent'       => $query->where('v.recent', '>=', max(1, $rules['frequent_visits'])),
            'vip'            => $query->where('s.spent', '>=', max(0.01, $rules['vip_spend'])),
            'at_risk'        => $query->where('v.last_visit', '<=', $now->copy()->subDays($rules['at_risk_days'])->toIso8601String())
                ->where('v.last_visit', '>', $now->copy()->subDays($rules['inactive_days'])->toIso8601String())
                ->whereNotExists($upcoming),
            'inactive'       => $query->where('v.last_visit', '<=', $now->copy()->subDays($rules['inactive_days'])->toIso8601String())->whereNotExists($upcoming),
            'no_visits'      => $query->whereNull('v.visits'),
            'birthday_month' => $query->whereRaw('extract(month from c.birth_date) = ?', [(int) now($tenant->zone())->month]),
            'service'        => $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('appointments as a')->whereColumn('a.customer_id', 'c.id')
                ->where('a.status', 'completed')->where('a.service_id', $segment['service_id'] ?? null)),
            'branch'         => $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('appointments as a')->whereColumn('a.customer_id', 'c.id')
                ->where('a.status', 'completed')->where('a.branch_id', $segment['branch_id'] ?? null)),
            'tag'            => CustomerProfile::tagsReady()
                ? $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('customer_tag_assignments as t')->whereColumn('t.customer_id', 'c.id')->where('t.customer_tag_id', $segment['tag_id'] ?? null))
                : $query->whereRaw('false'),
            default          => $query,
        };

        if (!empty($segment['only_branch'])) {
            $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('appointments as b')->whereColumn('b.customer_id', 'c.id')
                ->where('b.branch_id', $segment['only_branch'])->where('b.status', '!=', 'cancelled')
                ->where('b.starts_at', '>=', $now->copy()->subMonths(12)->toIso8601String()));
        }

        return $query;
    }

    public static function audience(Tenant $tenant, array $segment, bool $withList = false, string $channel = 'email'): array
    {
        $channels = self::channelsOf(self::whatsappReady() ? $channel : 'email');
        $withWhatsApp = in_array('whatsapp', $channels, true);

        $query = self::customers($tenant, $segment)
            ->leftJoin('customer_consents as cc', fn ($j) => $j->on('cc.customer_id', '=', 'c.id')->where('cc.channel', '=', 'email'));

        if ($withWhatsApp) {
            $query->leftJoin('customer_consents as cw', fn ($j) => $j->on('cw.customer_id', '=', 'c.id')->where('cw.channel', '=', 'whatsapp'));
        }

        $rows = $query->get(array_merge(['c.id', 'c.email', 'c.phone', 'cc.granted', 'cc.source'], $withWhatsApp ? ['cw.granted as wa_granted', 'cw.id as wa_id'] : []));

        $connected = $withWhatsApp && WhatsApp::connected($tenant->id);
        $unlessRevoked = AppointmentFlow::rule($tenant, 'whatsapp.consent') === 'unless_revoked';
        $counts = [];
        $ids = [];
        $reach = [];

        foreach ($channels as $one) {
            $keys = array_merge(['eligible'], array_keys($one === 'email' ? self::EMAIL_REASONS : self::WA_REASONS));
            $counts[$one] = array_fill_keys($keys, 0);
            $ids[$one] = array_fill_keys($keys, []);
        }

        foreach ($rows as $row) {
            foreach ($channels as $one) {
                $reason = $one === 'email'
                    ? match (true) {
                        !$row->email || !filter_var(trim($row->email), FILTER_VALIDATE_EMAIL) => 'no_email',
                        !$row->granted => 'no_consent',
                        default => 'eligible',
                    }
                    : match (true) {
                        !$connected => 'not_connected',
                        WhatsApp::phone($row->phone) === '' => 'no_phone',
                        $row->source === 'unsubscribe' && $row->granted !== null && !$row->granted => 'unsubscribed',
                        !($unlessRevoked ? (!$row->wa_id || $row->wa_granted) : (bool) $row->wa_granted) => 'no_consent',
                        default => 'eligible',
                    };

                $counts[$one][$reason]++;
                $ids[$one][$reason][] = $row->id;

                if ($reason === 'eligible') {
                    $reach[$row->id] = true;
                }
            }
        }

        $result = [
            'total'      => $rows->count(),
            'eligible'   => count($reach),
            'no_consent' => $counts['email']['no_consent'] ?? 0,
            'no_email'   => $counts['email']['no_email'] ?? 0,
            'channel'    => count($channels) === 2 ? 'both' : $channels[0],
            'channels'   => $counts,
        ];

        return $withList ? $result + ['ids' => $ids] : $result;
    }

    public static function waConfig(object $campaign): ?array
    {
        $raw = $campaign->whatsapp ?? null;
        $value = is_string($raw) ? json_decode($raw, true) : (array) $raw;

        return !empty($value['template']) ? $value : null;
    }

    public static function dispatch(Tenant $tenant, object $campaign): void
    {
        $claimed = DB::table('marketing_campaigns')->where('id', $campaign->id)->whereIn('status', ['scheduled'])
            ->update(['status' => 'sending', 'updated_at' => now()]);

        if (!$claimed) {
            return;
        }

        $segment = is_string($campaign->segment) ? json_decode($campaign->segment, true) : (array) $campaign->segment;
        $channel = self::whatsappReady() ? ($campaign->channel ?: 'email') : 'email';
        $audience = self::audience($tenant, $segment, true, $channel);
        $shortage = self::shortage(self::quotas($tenant, $audience, $channel));
        $stats = ['audience' => $audience['total'], 'eligible' => $audience['eligible'], 'no_consent' => $audience['no_consent'], 'no_email' => $audience['no_email'], 'channels' => $audience['channels']];

        if ($shortage) {
            DB::table('marketing_campaigns')->where('id', $campaign->id)->update([
                'status'     => 'blocked',
                'stats'      => json_encode($stats + ['blocked' => $shortage]),
                'updated_at' => now(),
            ]);

            if (Notifier::available()) {
                foreach (Notifier::adminIds($tenant->id) as $userId) {
                    Notifier::send($tenant->id, $userId, 'plan.limit', "La campaña «{$campaign->name}» no salió", $shortage, '/campaigns/' . $campaign->id, 'campaign', $campaign->id, "campaign-blocked:{$campaign->id}");
                }
            }

            return;
        }

        $coupon = $campaign->coupon_id ? DB::table('coupons')->where('id', $campaign->coupon_id)->first() : null;
        $user = $campaign->created_by ? User::find($campaign->created_by) : null;
        $base = [
            'Empresa' => Messaging::companyName($tenant),
            'Cupón'   => $coupon->code ?? '',
            'Portal'  => rtrim((string) config('app.frontend_url'), '/') . '/login',
        ];
        $withChannel = fn (array $row, string $one) => self::whatsappReady() ? $row + ['channel' => $one] : $row;

        $skipped = [];
        foreach ($audience['ids'] as $one => $byReason) {
            $texts = $one === 'email' ? self::EMAIL_REASONS : self::WA_REASONS;

            foreach ($texts as $reason => $text) {
                foreach ($byReason[$reason] as $customerId) {
                    $skipped[] = $withChannel(['id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant->id, 'marketing_campaign_id' => $campaign->id, 'customer_id' => $customerId, 'status' => 'skipped', 'skip_reason' => $text], $one);
                }
            }
        }

        foreach (array_chunk($skipped, 500) as $chunk) {
            DB::table('marketing_campaign_recipients')->insertOrIgnore($chunk);
        }

        if (isset($audience['ids']['whatsapp'])) {
            self::dispatchWhatsApp($tenant, $campaign, $audience['ids']['whatsapp']['eligible'], $base, $user);
        }

        Customer::where('tenant_id', $tenant->id)->whereIn('id', $audience['ids']['email']['eligible'] ?? [])->orderBy('id')
            ->chunk(200, function ($customers) use ($tenant, $campaign, $base, $coupon, $user, $withChannel) {
                foreach ($customers as $customer) {
                    $recipientId = (string) \Illuminate\Support\Str::uuid();
                    $vars = $base + ['Nombre' => $customer->first_name];
                    $body = RichText::fill((string) $campaign->body, $vars);

                    if ($coupon && !str_contains((string) $campaign->body, '[Cupón]')) {
                        $body = RichText::append($body, "Usa el cupón {$coupon->code}" . ($coupon->description ? " ({$coupon->description})" : '') . ' en tu próxima visita.');
                    }

                    $body = RichText::append($body, "—\nSi no quieres recibir más promociones de {$base['Empresa']}, entra aquí: " . self::unsubscribeUrl($recipientId));

                    $message = Messaging::store($tenant, [
                        'kind'        => 'campaign',
                        'customer'    => $customer,
                        'subject'     => Messaging::render((string) $campaign->subject, $vars),
                        'body'        => $body,
                        'entity_type' => 'campaign',
                        'entity_id'   => $campaign->id,
                        'dedupe_key'  => "campaign:{$campaign->id}:{$customer->id}",
                        'user'        => $user,
                    ]);

                    DB::table('marketing_campaign_recipients')->insertOrIgnore($withChannel([
                        'id'                    => $recipientId,
                        'tenant_id'             => $tenant->id,
                        'marketing_campaign_id' => $campaign->id,
                        'customer_id'           => $customer->id,
                        'status'                => $message ? 'pending' : 'failed',
                        'message_id'            => $message->id ?? null,
                        'skip_reason'           => $message ? null : 'No se pudo preparar el correo.',
                    ], 'email'));
                }
            });

        DB::table('marketing_campaigns')->where('id', $campaign->id)->update([
            'status'     => 'sent',
            'sent_at'    => now(),
            'stats'      => json_encode($stats),
            'updated_at' => now(),
        ]);
    }

    private static function dispatchWhatsApp(Tenant $tenant, object $campaign, array $customerIds, array $base, ?User $user): void
    {
        $config = self::waConfig($campaign);

        if (!$config || !$customerIds) {
            return;
        }

        $template = ['name' => $config['template'], 'language' => $config['language'] ?? 'es'];
        $params = array_values((array) ($config['params'] ?? []));

        Customer::where('tenant_id', $tenant->id)->whereIn('id', $customerIds)->orderBy('id')
            ->chunk(200, function ($customers) use ($tenant, $campaign, $base, $user, $template, $params) {
                foreach ($customers as $customer) {
                    $recipientId = (string) \Illuminate\Support\Str::uuid();
                    $vars = $base + ['Nombre' => $customer->first_name, 'Apellido' => (string) $customer->last_name, 'Baja' => self::unsubscribeUrl($recipientId)];
                    $values = [];

                    foreach ($params as $i => $key) {
                        $values[(string) ($i + 1)] = (string) ($vars[$key] ?? '');
                    }

                    $message = WhatsApp::store($tenant, [
                        'kind'        => 'campaign',
                        'customer'    => $customer,
                        'subject'     => $campaign->name,
                        'body'        => 'Plantilla de WhatsApp «' . $template['name'] . '» (' . $template['language'] . ')' . ($values ? ': ' . implode(' · ', array_filter($values, fn ($v) => $v !== '')) : ''),
                        'entity_type' => 'campaign',
                        'entity_id'   => $campaign->id,
                        'dedupe_key'  => "campaign:{$campaign->id}:{$customer->id}",
                        'user'        => $user,
                        'vars'        => $values,
                        'template'    => $template,
                        'fallback'    => false,
                    ]);

                    DB::table('marketing_campaign_recipients')->insertOrIgnore([
                        'id'                    => $recipientId,
                        'tenant_id'             => $tenant->id,
                        'marketing_campaign_id' => $campaign->id,
                        'customer_id'           => $customer->id,
                        'channel'               => 'whatsapp',
                        'status'                => $message ? 'pending' : 'failed',
                        'message_id'            => $message->id ?? null,
                        'skip_reason'           => $message ? null : 'No se pudo preparar el WhatsApp.',
                    ]);
                }
            });
    }

    public static function dispatchDue(?string $tenantId = null): int
    {
        if (!self::ready()) {
            return 0;
        }

        if ($tenantId && !Cache::add('campaigns-due:' . $tenantId, 1, 30)) {
            return 0;
        }

        $due = DB::table('marketing_campaigns')
            ->when($tenantId, fn ($q, $id) => $q->where('tenant_id', $id))
            ->where('status', 'scheduled')
            ->where('scheduled_at', '<=', now()->toIso8601String())
            ->orderBy('scheduled_at')
            ->limit(10)
            ->get();

        foreach ($due as $campaign) {
            try {
                $tenant = Tenant::find($campaign->tenant_id);

                if ($tenant && $tenant->hasModule('marketing')) {
                    self::dispatch($tenant, $campaign);
                }
            } catch (\Throwable $e) {
                report($e);
                DB::table('marketing_campaigns')->where('id', $campaign->id)->where('status', 'sending')->update(['status' => 'scheduled', 'updated_at' => now()]);
            }
        }

        return $due->count();
    }

    public static function unsubscribeUrl(string $recipientId): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . '/promociones/baja/' . $recipientId;
    }

    public static function results(object $campaign): array
    {
        $byChannel = self::whatsappReady();
        $query = DB::table('marketing_campaign_recipients as r')
            ->leftJoin('messages as m', 'm.id', '=', 'r.message_id')
            ->where('r.marketing_campaign_id', $campaign->id)
            ->selectRaw("count(*) FILTER (WHERE m.status IN ('sent', 'simulated', 'delivered', 'read')) AS sent")
            ->selectRaw("count(*) FILTER (WHERE m.status IN ('queued', 'scheduled')) AS pending")
            ->selectRaw("count(*) FILTER (WHERE m.status IN ('failed', 'not_sent') OR r.status = 'failed') AS failed")
            ->selectRaw("count(*) FILTER (WHERE r.status = 'skipped') AS skipped")
            ->selectRaw("count(*) FILTER (WHERE m.status = 'simulated') AS simulated")
            ->selectRaw("count(*) FILTER (WHERE m.status IN ('delivered', 'read')) AS delivered")
            ->selectRaw("count(*) FILTER (WHERE m.status = 'read') AS read");

        $rows = $byChannel ? $query->addSelect('r.channel')->groupBy('r.channel')->get()->keyBy('channel') : collect(['email' => $query->first()]);
        $sum = fn (string $key) => (int) $rows->sum(fn ($row) => (int) $row->{$key});
        $channels = $rows->map(fn ($row) => [
            'sent'      => (int) $row->sent,
            'pending'   => (int) $row->pending,
            'failed'    => (int) $row->failed,
            'skipped'   => (int) $row->skipped,
            'simulated' => (int) $row->simulated,
            'delivered' => (int) $row->delivered,
            'read'      => (int) $row->read,
        ])->all();

        $booked = ['customers' => 0, 'appointments' => 0];

        if ($campaign->sent_at) {
            $from = Carbon::parse($campaign->sent_at);
            $booked = (array) DB::table('appointments as a')
                ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('marketing_campaign_recipients as r')
                    ->join('messages as m', 'm.id', '=', 'r.message_id')
                    ->whereColumn('r.customer_id', 'a.customer_id')
                    ->where('r.marketing_campaign_id', $campaign->id)
                    ->whereIn('m.status', self::REACHED))
                ->where('a.created_at', '>=', $from->toIso8601String())
                ->where('a.created_at', '<', $from->copy()->addDays(self::BOOKED_DAYS)->toIso8601String())
                ->where('a.created_at', '<=', now()->toIso8601String())
                ->selectRaw('count(DISTINCT a.customer_id) AS customers, count(*) AS appointments')
                ->first();
        }

        $revenue = 0.0;

        if ($campaign->sent_at) {
            $from = Carbon::parse($campaign->sent_at);
            $revenue = (float) DB::table('sales as s')
                ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('marketing_campaign_recipients as r')
                    ->join('messages as m', 'm.id', '=', 'r.message_id')
                    ->whereColumn('r.customer_id', 's.customer_id')
                    ->where('r.marketing_campaign_id', $campaign->id)
                    ->whereIn('m.status', self::REACHED))
                ->where('s.tenant_id', $campaign->tenant_id)
                ->whereIn('s.status', ['paid', 'partial', 'partially_refunded'])
                ->whereRaw('coalesce(s.paid_at, s.created_at) >= ?', [$from->toIso8601String()])
                ->whereRaw('coalesce(s.paid_at, s.created_at) < ?', [$from->copy()->addDays(self::BOOKED_DAYS)->toIso8601String()])
                ->sum('s.paid');
        }

        return [
            'delivered'  => $sum('delivered'),
            'read'       => $sum('read'),
            'revenue'    => round($revenue, 2),
            'sent'       => $sum('sent'),
            'pending'    => $sum('pending'),
            'failed'     => $sum('failed'),
            'skipped'    => $sum('skipped'),
            'simulated'  => $sum('simulated'),
            'by_channel' => $channels,
            'booked'     => ['customers' => (int) $booked['customers'], 'appointments' => (int) $booked['appointments']],
        ];
    }

    public const REACHED = ['sent', 'simulated', 'delivered', 'read'];
}
