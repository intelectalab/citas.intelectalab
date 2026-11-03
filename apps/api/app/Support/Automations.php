<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Automations
{
    public const NOT_READY = 'Falta actualizar la base de datos para usar automatizaciones: ejecuta php artisan migrate.';

    public const BOOKED_DAYS = 7;

    public const LOOKBACK_DAYS = 7;

    public const BATCH = 200;

    public const VARIABLES = [
        'Nombre'  => 'Nombre del cliente',
        'Empresa' => 'Tu empresa',
        'Cupón'   => 'Código del cupón',
        'Portal'  => 'Dirección del portal',
    ];

    public const TRIGGERS = [
        'birthday' => [
            'label'   => 'Cumpleaños',
            'when'    => 'El día de su cumpleaños.',
            'daily'   => true,
            'time'    => '09:00',
            'delay'   => 0,
            'repeat'  => 365,
            'subject' => '¡Feliz cumpleaños, [Nombre]!',
            'body'    => '<p>¡Feliz cumpleaños, [Nombre]! En [Empresa] queremos celebrarlo contigo.</p><p>Te esperamos en tu próxima visita.</p>',
        ],
        'loyal' => [
            'label'   => 'Llegó a fidelizado',
            'when'    => 'Cuando pasa a "Fidelizado" en el tablero de clientes.',
            'daily'   => true,
            'time'    => '09:00',
            'delay'   => 0,
            'repeat'  => 0,
            'subject' => 'Gracias por elegirnos, [Nombre]',
            'body'    => '<p>Hola [Nombre], gracias por elegir [Empresa] siempre. Para nosotros es un gusto atenderte.</p>',
        ],
        'inactive' => [
            'label'   => 'Cliente que dejó de venir',
            'when'    => 'Cuando cumple los días sin venir de "Grupos de clientes" y no tiene una cita próxima.',
            'daily'   => true,
            'time'    => '09:00',
            'delay'   => 0,
            'repeat'  => 90,
            'subject' => '[Nombre], te extrañamos',
            'body'    => '<p>Hola [Nombre], hace tiempo que no te vemos en [Empresa].</p><p>¿Reservamos tu próxima cita? Puedes hacerlo aquí: [Portal]</p>',
        ],
        'first_visit' => [
            'label'   => 'Primera atención',
            'when'    => 'Después de su primera cita atendida.',
            'daily'   => false,
            'time'    => null,
            'delay'   => 1440,
            'repeat'  => 0,
            'subject' => 'Gracias por venir, [Nombre]',
            'body'    => '<p>Hola [Nombre], gracias por visitarnos en [Empresa]. Esperamos que te haya gustado.</p><p>Cuando quieras volver, reserva aquí: [Portal]</p>',
        ],
        'no_show' => [
            'label'   => 'No asistió',
            'when'    => 'Después de marcarse que no asistió a su cita.',
            'daily'   => false,
            'time'    => null,
            'delay'   => 120,
            'repeat'  => 30,
            'subject' => '[Nombre], te esperábamos',
            'body'    => '<p>Hola [Nombre], hoy te esperábamos en [Empresa].</p><p>Si quieres reprogramar tu cita, puedes hacerlo aquí: [Portal]</p>',
        ],
    ];

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Messaging::ready() && Schema::hasColumn('automation_rules', 'enabled_at');
    }

    public static function rules(Tenant $tenant): array
    {
        $existing = DB::table('automation_rules')->where('tenant_id', $tenant->id)->pluck('trigger_event')->all();
        $missing = array_diff(array_keys(self::TRIGGERS), $existing);

        if ($missing) {
            DB::table('automation_rules')->insertOrIgnore(array_map(fn ($key) => [
                'id'            => (string) Str::uuid(),
                'tenant_id'     => $tenant->id,
                'name'          => self::TRIGGERS[$key]['label'],
                'trigger_event' => $key,
                'channel'       => 'email',
                'subject'       => self::TRIGGERS[$key]['subject'],
                'body'          => self::TRIGGERS[$key]['body'],
                'send_time'     => self::TRIGGERS[$key]['time'],
                'delay_minutes' => self::TRIGGERS[$key]['delay'],
                'repeat_days'   => self::TRIGGERS[$key]['repeat'],
                'is_active'     => false,
                'created_at'    => now(),
                'updated_at'    => now(),
            ], array_values($missing)));
        }

        $rows = DB::table('automation_rules')->where('tenant_id', $tenant->id)->whereIn('trigger_event', array_keys(self::TRIGGERS))->get()->keyBy('trigger_event');

        return collect(array_keys(self::TRIGGERS))->mapWithKeys(fn ($key) => [$key => $rows[$key]])->all();
    }

    public static function runDue(): int
    {
        if (!self::ready()) {
            return 0;
        }

        $sent = 0;
        $tenantIds = DB::table('automation_rules')->where('is_active', true)->distinct()->pluck('tenant_id');

        foreach (Tenant::whereIn('id', $tenantIds)->where('status', 'active')->with('settings')->get() as $tenant) {
            try {
                $sent += self::run($tenant);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }

    public static function run(Tenant $tenant, bool $force = false): int
    {
        if (!self::ready() || !$tenant->hasModule('automations') || !Messaging::emailReady($tenant->id)) {
            return 0;
        }

        if (!$force && !Cache::add('automations-run:' . $tenant->id, true, 60)) {
            return 0;
        }

        $sent = 0;

        foreach (self::rules($tenant) as $key => $rule) {
            if (!$rule->is_active || !$rule->enabled_at) {
                continue;
            }

            foreach (self::candidates($tenant, $key, $rule) as $candidate) {
                $sent += self::send($tenant, $rule, $candidate->customer_id, $candidate->appointment_id ?? null) ? 1 : 0;
            }
        }

        return $sent;
    }

    private static function eligible(Tenant $tenant, object $rule, Carbon $now): Builder
    {
        $dayStart = $now->copy()->setTimezone($tenant->zone())->startOfDay();

        return DB::table('customers as c')
            ->where('c.tenant_id', $tenant->id)
            ->whereNull('c.deleted_at')
            ->whereNotNull('c.email')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('customer_consents as cc')
                ->whereColumn('cc.customer_id', 'c.id')->where('cc.channel', 'email')->where('cc.granted', true))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('automation_logs as l')
                ->whereColumn('l.customer_id', 'c.id')->where('l.tenant_id', $tenant->id)->where('l.result', 'sent')
                ->where('l.created_at', '>=', $dayStart->toIso8601String()))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('automation_logs as l')
                ->whereColumn('l.customer_id', 'c.id')->where('l.automation_rule_id', $rule->id)->where('l.result', 'sent')
                ->when((int) $rule->repeat_days > 0, fn ($q) => $q->where('l.created_at', '>=', $now->copy()->subDays((int) $rule->repeat_days)->toIso8601String())));
    }

    private static function candidates(Tenant $tenant, string $key, object $rule)
    {
        $now = now();
        $local = $now->copy()->setTimezone($tenant->zone());
        $enabled = Carbon::parse($rule->enabled_at);
        $query = self::eligible($tenant, $rule, $now);

        if (self::TRIGGERS[$key]['daily'] && $local->format('H:i') < substr((string) ($rule->send_time ?: self::TRIGGERS[$key]['time']), 0, 5)) {
            return collect();
        }

        $delay = $now->copy()->subMinutes((int) $rule->delay_minutes);
        $oldest = $delay->copy()->subDays(self::LOOKBACK_DAYS);

        switch ($key) {
            case 'birthday':
                $query->whereRaw('extract(month from c.birth_date) = ? AND extract(day from c.birth_date) = ?', [$local->month, $local->day])
                    ->select('c.id as customer_id');
                break;

            case 'inactive':
                $days = (int) AppointmentFlow::rule($tenant, 'crm.inactive_days');
                $last = DB::table('appointments')->where('tenant_id', $tenant->id)->where('status', AppointmentFlow::COMPLETED)
                    ->groupBy('customer_id')->selectRaw('customer_id, max(starts_at) AS last_visit');
                $query->joinSub($last, 'v', 'v.customer_id', '=', 'c.id')
                    ->where('v.last_visit', '<=', $now->copy()->subDays($days)->toIso8601String())
                    ->where('v.last_visit', '>', $now->copy()->subDays($days + self::LOOKBACK_DAYS)->toIso8601String())
                    ->where('v.last_visit', '>=', $enabled->copy()->subDays($days)->toIso8601String())
                    ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('appointments as a')->whereColumn('a.customer_id', 'c.id')
                        ->whereIn('a.status', [AppointmentFlow::PENDING, AppointmentFlow::CONFIRMED])->where('a.starts_at', '>', $now->toIso8601String()))
                    ->select('c.id as customer_id');
                break;

            case 'loyal':
                if (!CustomerStages::automatic()) {
                    return collect();
                }
                $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('customer_stage_changes as h')
                    ->join('pipeline_stages as s', 's.id', '=', 'h.to_stage_id')
                    ->whereColumn('h.customer_id', 'c.id')->where('s.system_key', 'loyal')
                    ->where('h.created_at', '>=', $enabled->toIso8601String())
                    ->where('h.created_at', '>', $now->copy()->subDays(self::LOOKBACK_DAYS)->toIso8601String()))
                    ->select('c.id as customer_id');
                break;

            case 'first_visit':
                $query->join('appointments as a', 'a.customer_id', '=', 'c.id')
                    ->where('a.tenant_id', $tenant->id)
                    ->where('a.status', AppointmentFlow::COMPLETED)
                    ->where('a.completed_at', '>=', $enabled->toIso8601String())
                    ->where('a.completed_at', '<=', $delay->toIso8601String())
                    ->where('a.completed_at', '>', $oldest->toIso8601String())
                    ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('appointments as o')->whereColumn('o.customer_id', 'c.id')
                        ->where('o.status', AppointmentFlow::COMPLETED)->whereColumn('o.id', '!=', 'a.id')->whereColumn('o.starts_at', '<=', 'a.starts_at'))
                    ->select('c.id as customer_id', 'a.id as appointment_id');
                break;

            case 'no_show':
                $query->join('appointments as a', 'a.customer_id', '=', 'c.id')
                    ->join('appointment_status_history as h', fn ($j) => $j->on('h.appointment_id', '=', 'a.id')->where('h.to_status', '=', AppointmentFlow::NO_SHOW))
                    ->where('a.tenant_id', $tenant->id)
                    ->where('a.status', AppointmentFlow::NO_SHOW)
                    ->where('h.created_at', '>=', $enabled->toIso8601String())
                    ->where('h.created_at', '<=', $delay->toIso8601String())
                    ->where('h.created_at', '>', $oldest->toIso8601String())
                    ->select('c.id as customer_id', 'a.id as appointment_id');
                break;
        }

        return $query->limit(self::BATCH)->get()->unique('customer_id')->values();
    }

    public static function send(Tenant $tenant, object $rule, string $customerId, ?string $appointmentId = null): bool
    {
        $customer = Customer::where('tenant_id', $tenant->id)->find($customerId);

        if (!$customer) {
            return false;
        }

        $logId = (string) Str::uuid();
        $coupon = $rule->coupon_id ? DB::table('coupons')->where('id', $rule->coupon_id)->first() : null;
        $vars = [
            'Nombre'  => $customer->first_name,
            'Empresa' => Messaging::companyName($tenant),
            'Cupón'   => $coupon->code ?? '',
            'Portal'  => rtrim((string) config('app.frontend_url'), '/') . '/login',
        ];

        $body = RichText::fill((string) $rule->body, $vars);

        if ($coupon && !str_contains((string) $rule->body, '[Cupón]')) {
            $body = RichText::append($body, "Usa el cupón {$coupon->code}" . ($coupon->description ? " ({$coupon->description})" : '') . ' en tu próxima visita.');
        }

        $body = RichText::append($body, "—\nSi no quieres recibir más promociones de {$vars['Empresa']}, entra aquí: " . Campaigns::unsubscribeUrl($logId));

        $message = Messaging::store($tenant, [
            'kind'        => 'automation',
            'customer'    => $customer,
            'subject'     => Messaging::render((string) $rule->subject, $vars),
            'body'        => $body,
            'entity_type' => 'automation',
            'entity_id'   => $rule->id,
            'dedupe_key'  => "automation:{$rule->id}:{$customer->id}:" . ($appointmentId ?? now($tenant->zone())->toDateString()),
            'user'        => null,
        ]);

        if (!$message) {
            return false;
        }

        DB::table('automation_logs')->insert([
            'id'                 => $logId,
            'tenant_id'          => $tenant->id,
            'automation_rule_id' => $rule->id,
            'customer_id'        => $customer->id,
            'appointment_id'     => $appointmentId,
            'message_id'         => $message->id,
            'result'             => $message->status === 'not_sent' ? 'failed' : 'sent',
            'reason'             => $message->status === 'not_sent' ? mb_substr((string) $message->error, 0, 255) : null,
            'created_at'         => now(),
        ]);

        return $message->status !== 'not_sent';
    }

    public static function results(Tenant $tenant): array
    {
        $monthStart = now($tenant->zone())->startOfMonth()->toIso8601String();

        $sent = DB::table('automation_logs as l')
            ->join('messages as m', 'm.id', '=', 'l.message_id')
            ->where('l.tenant_id', $tenant->id)
            ->where('l.created_at', '>=', $monthStart)
            ->whereIn('m.status', ['sent', 'simulated', 'queued'])
            ->groupBy('l.automation_rule_id')
            ->selectRaw('l.automation_rule_id, count(*) AS total')
            ->pluck('total', 'l.automation_rule_id');

        $booked = DB::table('automation_logs as l')
            ->join('messages as m', 'm.id', '=', 'l.message_id')
            ->join('appointments as a', fn ($j) => $j->on('a.customer_id', '=', 'l.customer_id')
                ->whereColumn('a.created_at', '>=', 'l.created_at')
                ->whereRaw("a.created_at < l.created_at + interval '" . self::BOOKED_DAYS . " days'"))
            ->where('l.tenant_id', $tenant->id)
            ->where('l.created_at', '>=', $monthStart)
            ->whereIn('m.status', ['sent', 'simulated'])
            ->groupBy('l.automation_rule_id')
            ->selectRaw('l.automation_rule_id, count(DISTINCT l.customer_id) AS total')
            ->pluck('total', 'l.automation_rule_id');

        $last = DB::table('automation_logs')
            ->where('tenant_id', $tenant->id)
            ->where('result', 'sent')
            ->groupBy('automation_rule_id')
            ->selectRaw('automation_rule_id, max(created_at) AS at')
            ->pluck('at', 'automation_rule_id');

        return collect(self::rules($tenant))->mapWithKeys(fn ($rule) => [$rule->id => [
            'sent_month'   => (int) ($sent[$rule->id] ?? 0),
            'booked_month' => (int) ($booked[$rule->id] ?? 0),
            'last_sent_at' => isset($last[$rule->id]) ? Carbon::parse($last[$rule->id])->setTimezone($tenant->zone())->format('Y-m-d\TH:i') : null,
        ]])->all();
    }
}
