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
    ];

    public const STATUS_LABELS = [
        'scheduled' => 'Programada',
        'sending'   => 'Enviando',
        'sent'      => 'Enviada',
        'cancelled' => 'Cancelada',
    ];

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

        return collect(self::GROUPS)->map(fn ($group, $key) => [
            'key'   => $key,
            'label' => $group['label'],
            'hint'  => strtr($group['hint'], $replace),
            'needs' => in_array($key, ['service', 'branch'], true) ? $key : null,
        ])->values()->all();
    }

    public static function label(Tenant $tenant, array $segment): string
    {
        $group = self::GROUPS[$segment['group'] ?? 'all']['label'] ?? 'Todos los clientes';

        $label = match ($segment['group'] ?? null) {
            'service' => $group . ': ' . (DB::table('services')->where('tenant_id', $tenant->id)->where('id', $segment['service_id'] ?? null)->value('name') ?? '—'),
            'branch'  => $group . ': ' . (DB::table('branches')->where('tenant_id', $tenant->id)->where('id', $segment['branch_id'] ?? null)->value('name') ?? '—'),
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
            default          => $query,
        };

        if (!empty($segment['only_branch'])) {
            $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('appointments as b')->whereColumn('b.customer_id', 'c.id')
                ->where('b.branch_id', $segment['only_branch'])->where('b.status', '!=', 'cancelled')
                ->where('b.starts_at', '>=', $now->copy()->subMonths(12)->toIso8601String()));
        }

        return $query;
    }

    public static function audience(Tenant $tenant, array $segment, bool $withList = false): array
    {
        $rows = self::customers($tenant, $segment)
            ->leftJoin('customer_consents as cc', fn ($j) => $j->on('cc.customer_id', '=', 'c.id')->where('cc.channel', '=', 'email'))
            ->get(['c.id', 'c.email', 'cc.granted']);

        $result = ['total' => $rows->count(), 'eligible' => 0, 'no_consent' => 0, 'no_email' => 0];
        $groups = ['eligible' => [], 'no_consent' => [], 'no_email' => []];

        foreach ($rows as $row) {
            $reason = match (true) {
                !$row->email || !filter_var(trim($row->email), FILTER_VALIDATE_EMAIL) => 'no_email',
                !$row->granted => 'no_consent',
                default => 'eligible',
            };

            $result[$reason]++;
            $groups[$reason][] = $row->id;
        }

        return $withList ? $result + ['ids' => $groups] : $result;
    }

    public static function dispatch(Tenant $tenant, object $campaign): void
    {
        $claimed = DB::table('marketing_campaigns')->where('id', $campaign->id)->whereIn('status', ['scheduled'])
            ->update(['status' => 'sending', 'updated_at' => now()]);

        if (!$claimed) {
            return;
        }

        $segment = is_string($campaign->segment) ? json_decode($campaign->segment, true) : (array) $campaign->segment;
        $audience = self::audience($tenant, $segment, true);
        $coupon = $campaign->coupon_id ? DB::table('coupons')->where('id', $campaign->coupon_id)->first() : null;
        $user = $campaign->created_by ? User::find($campaign->created_by) : null;
        $base = [
            'Empresa' => Messaging::companyName($tenant),
            'Cupón'   => $coupon->code ?? '',
            'Portal'  => rtrim((string) config('app.frontend_url'), '/') . '/login',
        ];

        $skipped = [];
        foreach (['no_consent' => 'No aceptó recibir promociones por correo.', 'no_email' => 'No tiene un correo registrado.'] as $reason => $text) {
            foreach ($audience['ids'][$reason] as $customerId) {
                $skipped[] = ['id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant->id, 'marketing_campaign_id' => $campaign->id, 'customer_id' => $customerId, 'status' => 'skipped', 'skip_reason' => $text];
            }
        }

        foreach (array_chunk($skipped, 500) as $chunk) {
            DB::table('marketing_campaign_recipients')->insertOrIgnore($chunk);
        }

        Customer::where('tenant_id', $tenant->id)->whereIn('id', $audience['ids']['eligible'])->orderBy('id')
            ->chunk(200, function ($customers) use ($tenant, $campaign, $base, $coupon, $user) {
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

                    DB::table('marketing_campaign_recipients')->insertOrIgnore([
                        'id'                    => $recipientId,
                        'tenant_id'             => $tenant->id,
                        'marketing_campaign_id' => $campaign->id,
                        'customer_id'           => $customer->id,
                        'status'                => $message ? 'pending' : 'failed',
                        'message_id'            => $message->id ?? null,
                        'skip_reason'           => $message ? null : 'No se pudo preparar el correo.',
                    ]);
                }
            });

        DB::table('marketing_campaigns')->where('id', $campaign->id)->update([
            'status'     => 'sent',
            'sent_at'    => now(),
            'stats'      => json_encode(['audience' => $audience['total'], 'eligible' => $audience['eligible'], 'no_consent' => $audience['no_consent'], 'no_email' => $audience['no_email']]),
            'updated_at' => now(),
        ]);
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
        $rows = DB::table('marketing_campaign_recipients as r')
            ->leftJoin('messages as m', 'm.id', '=', 'r.message_id')
            ->where('r.marketing_campaign_id', $campaign->id)
            ->selectRaw("count(*) FILTER (WHERE m.status IN ('sent', 'simulated')) AS sent")
            ->selectRaw("count(*) FILTER (WHERE m.status IN ('queued', 'scheduled')) AS pending")
            ->selectRaw("count(*) FILTER (WHERE m.status IN ('failed', 'not_sent') OR r.status = 'failed') AS failed")
            ->selectRaw("count(*) FILTER (WHERE r.status = 'skipped') AS skipped")
            ->selectRaw("count(*) FILTER (WHERE m.status = 'simulated') AS simulated")
            ->first();

        $booked = ['customers' => 0, 'appointments' => 0];

        if ($campaign->sent_at) {
            $from = Carbon::parse($campaign->sent_at);
            $booked = (array) DB::table('appointments as a')
                ->join('marketing_campaign_recipients as r', fn ($j) => $j->on('r.customer_id', '=', 'a.customer_id')->where('r.marketing_campaign_id', '=', $campaign->id))
                ->join('messages as m', 'm.id', '=', 'r.message_id')
                ->whereIn('m.status', ['sent', 'simulated'])
                ->where('a.created_at', '>=', $from->toIso8601String())
                ->where('a.created_at', '<', $from->copy()->addDays(self::BOOKED_DAYS)->toIso8601String())
                ->where('a.created_at', '<=', now()->toIso8601String())
                ->selectRaw('count(DISTINCT a.customer_id) AS customers, count(*) AS appointments')
                ->first();
        }

        return [
            'sent'      => (int) $rows->sent,
            'pending'   => (int) $rows->pending,
            'failed'    => (int) $rows->failed,
            'skipped'   => (int) $rows->skipped,
            'simulated' => (int) $rows->simulated,
            'booked'    => ['customers' => (int) $booked['customers'], 'appointments' => (int) $booked['appointments']],
        ];
    }
}
