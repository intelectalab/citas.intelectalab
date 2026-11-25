<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Surveys
{
    public const NOT_READY = 'Falta actualizar la base de datos para usar las encuestas: ejecuta php artisan migrate.';

    public const STATUSES = [
        'scheduled' => 'Por enviar',
        'sent'      => 'Enviada',
        'answered'  => 'Respondida',
        'skipped'   => 'No se envió',
        'expired'   => 'Venció sin respuesta',
    ];

    public const FOLLOW_UPS = [
        'pending'   => 'Por contactar',
        'contacted' => 'Contactado',
        'resolved'  => 'Resuelto',
    ];

    public const DETRACTOR_MAX = 6;

    public const PROMOTER_MIN = 9;

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('surveys');
    }

    public static function enabled(Tenant $tenant): bool
    {
        return self::ready() && (bool) AppointmentFlow::rule($tenant, 'surveys.enabled');
    }

    public static function config(Tenant $tenant): array
    {
        return [
            'enabled'         => (bool) AppointmentFlow::rule($tenant, 'surveys.enabled'),
            'delay_hours'     => max(0, min(48, (int) AppointmentFlow::rule($tenant, 'surveys.delay_hours'))),
            'google_min'      => max(6, min(10, (int) AppointmentFlow::rule($tenant, 'surveys.google_min'))),
            'publish_reviews' => (bool) AppointmentFlow::rule($tenant, 'surveys.publish_reviews'),
            'valid_days'      => max(1, min(30, (int) AppointmentFlow::rule($tenant, 'surveys.valid_days'))),
            'google_url'      => self::googleUrl($tenant),
        ];
    }

    public static function googleUrl(Tenant $tenant): ?string
    {
        $url = trim((string) ($tenant->rules()['surveys.google_url'] ?? ''));

        return $url !== '' && preg_match('#^https://#i', $url) ? $url : null;
    }

    public static function saveConfig(Tenant $tenant, array $values): array
    {
        $settings = $tenant->settings ?? new TenantSetting(['tenant_id' => $tenant->id]);
        $settings->rules = array_merge($settings->rules ?? [], [
            'surveys.enabled'         => (bool) $values['enabled'],
            'surveys.delay_hours'     => (int) $values['delay_hours'],
            'surveys.google_min'      => (int) $values['google_min'],
            'surveys.publish_reviews' => (bool) $values['publish_reviews'],
            'surveys.valid_days'      => (int) $values['valid_days'],
            'surveys.google_url'      => trim((string) ($values['google_url'] ?? '')) ?: null,
        ]);
        $settings->save();
        $tenant->setRelation('settings', $settings);

        return self::config($tenant);
    }

    public static function url(string $token): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . '/encuesta/' . $token;
    }

    public static function appointmentChanged(Tenant $tenant, Appointment $appointment, ?string $from, string $to): void
    {
        try {
            if ($to !== AppointmentFlow::COMPLETED || $from === $to || !$appointment->customer_id || !self::enabled($tenant)) {
                return;
            }

            $config = self::config($tenant);
            $sendAt = now()->addHours($config['delay_hours']);

            DB::table('surveys')->insertOrIgnore([
                'id'              => (string) Str::uuid(),
                'tenant_id'       => $tenant->id,
                'appointment_id'  => $appointment->id,
                'customer_id'     => $appointment->customer_id,
                'professional_id' => $appointment->professional_id,
                'service_id'      => $appointment->service_id,
                'branch_id'       => $appointment->branch_id,
                'token'           => Str::random(40),
                'status'          => 'scheduled',
                'send_at'         => $sendAt,
                'expires_at'      => $sendAt->copy()->addDays($config['valid_days']),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            if ($config['delay_hours'] === 0) {
                self::sendDue($tenant->id);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function consentBlocker(Tenant $tenant, Customer $customer): ?string
    {
        $granted = DB::table('customer_consents')->where('customer_id', $customer->id)
            ->whereIn('channel', ['email', 'whatsapp'])->where('granted', true)->exists();

        if ($granted || WhatsApp::consentAllows($tenant, $customer->id) && WhatsApp::connected($tenant->id) && WhatsApp::channel($tenant) !== 'email') {
            return null;
        }

        return 'El cliente no aceptó recibir mensajes.';
    }

    public static function sendDue(?string $tenantId = null): int
    {
        if (!self::ready()) {
            return 0;
        }

        DB::table('surveys')->when($tenantId, fn ($q, $id) => $q->where('tenant_id', $id))
            ->whereIn('status', ['scheduled', 'sent'])->where('expires_at', '<', now()->toIso8601String())
            ->update(['status' => 'expired', 'updated_at' => now()]);

        $sent = 0;
        $due = DB::table('surveys')->when($tenantId, fn ($q, $id) => $q->where('tenant_id', $id))
            ->where('status', 'scheduled')->where('send_at', '<=', now()->toIso8601String())
            ->orderBy('send_at')->limit(300)->get();
        $tenants = [];

        foreach ($due as $survey) {
            try {
                $tenant = $tenants[$survey->tenant_id] ??= Tenant::with('settings')->find($survey->tenant_id);

                if (!$tenant || !self::enabled($tenant)) {
                    self::skip($survey->id, 'Las encuestas se apagaron antes del envío.');
                    continue;
                }

                $sent += self::deliver($tenant, $survey) ? 1 : 0;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }

    private static function skip(string $id, string $reason): void
    {
        DB::table('surveys')->where('id', $id)->update(['status' => 'skipped', 'skip_reason' => mb_substr($reason, 0, 255), 'updated_at' => now()]);
    }

    private static function deliver(Tenant $tenant, object $survey): bool
    {
        $customer = Customer::where('tenant_id', $tenant->id)->find($survey->customer_id);

        if (!$customer) {
            self::skip($survey->id, 'El cliente ya no existe.');

            return false;
        }

        if ($reason = self::consentBlocker($tenant, $customer)) {
            self::skip($survey->id, $reason);

            return false;
        }

        $message = Messaging::queue($tenant, 'survey', $customer, [
            'Servicio'    => (string) DB::table('services')->where('id', $survey->service_id)->value('name'),
            'Profesional' => (string) DB::table('professionals')->where('id', $survey->professional_id)->value('display_name'),
            'Encuesta'    => self::url($survey->token),
        ], [
            'branch_id'   => $survey->branch_id,
            'entity_type' => 'survey',
            'entity_id'   => $survey->id,
            'dedupe_key'  => "survey:{$survey->id}",
        ]);

        if (!$message) {
            self::skip($survey->id, 'El aviso «Encuesta de satisfacción» está apagado en Mensajes.');

            return false;
        }

        if (($message->status ?? null) === 'not_sent') {
            self::skip($survey->id, (string) ($message->error ?: 'No se pudo enviar.'));

            return false;
        }

        DB::table('surveys')->where('id', $survey->id)->update(['status' => 'sent', 'sent_at' => now(), 'message_id' => $message->id, 'updated_at' => now()]);

        return true;
    }

    public static function findByToken(string $token): ?object
    {
        if (!self::ready() || !preg_match('/^[A-Za-z0-9]{20,64}$/', $token)) {
            return null;
        }

        return Rls::bypass(fn () => DB::table('surveys')->where('token', $token)->first());
    }

    public static function answerable(object $survey): bool
    {
        return in_array($survey->status, ['scheduled', 'sent'], true) && Carbon::parse($survey->expires_at)->isFuture();
    }

    public static function answer(Tenant $tenant, object $survey, int $score, ?string $comment, bool $consent): ?object
    {
        $config = self::config($tenant);
        $comment = trim((string) $comment) ?: null;
        $invite = $score >= $config['google_min'] && $config['google_url'] !== null;
        $detractor = $score <= self::DETRACTOR_MAX;

        $saved = DB::table('surveys')->where('id', $survey->id)->whereIn('status', ['scheduled', 'sent'])->where('expires_at', '>', now())->update([
            'status'          => 'answered',
            'answered_at'     => now(),
            'score'           => $score,
            'comment'         => $comment,
            'publish_consent' => $consent && $comment !== null,
            'google_invited'  => $invite,
            'follow_up'       => $detractor ? 'pending' : null,
            'updated_at'      => now(),
        ]);

        if ($saved === 0) {
            return null;
        }

        $fresh = DB::table('surveys')->where('id', $survey->id)->first();

        if ($detractor) {
            $customer = Customer::find($survey->customer_id);
            $name = $customer ? Person::name($customer) : 'Un cliente';
            $ids = array_unique([...Notifier::staffWith($tenant->id, 'surveys.edit', $survey->branch_id), ...Notifier::adminIds($tenant->id)]);

            foreach ($ids as $id) {
                Notifier::send($tenant->id, $id, 'survey.detractor', "{$name} calificó su atención con {$score} de 10", $comment ? "«{$comment}»" : 'No dejó comentario. Llámalo o escríbele para saber qué pasó.', "/surveys?survey={$survey->id}", 'survey', $survey->id, "survey-detractor:{$survey->id}");
            }
        }

        return $fresh;
    }

    public static function nps(int $promoters, int $detractors, int $answered): ?int
    {
        return $answered > 0 ? (int) round(($promoters - $detractors) * 100 / $answered) : null;
    }

    public static function base(Tenant $tenant, array $filters = [])
    {
        return DB::table('surveys as s')
            ->where('s.tenant_id', $tenant->id)
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('s.branch_id', $id))
            ->when($filters['professional_id'] ?? null, fn ($q, $id) => $q->where('s.professional_id', $id))
            ->when($filters['service_id'] ?? null, fn ($q, $id) => $q->where('s.service_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $at) => $q->where('s.created_at', '>=', $at))
            ->when($filters['to'] ?? null, fn ($q, $at) => $q->where('s.created_at', '<', $at));
    }

    private static function scoreColumns(): string
    {
        return 'count(*) FILTER (WHERE s.score IS NOT NULL) AS answered,
            count(*) FILTER (WHERE s.score >= ' . self::PROMOTER_MIN . ') AS promoters,
            count(*) FILTER (WHERE s.score BETWEEN ' . (self::DETRACTOR_MAX + 1) . ' AND ' . (self::PROMOTER_MIN - 1) . ') AS passives,
            count(*) FILTER (WHERE s.score <= ' . self::DETRACTOR_MAX . ') AS detractors,
            avg(s.score) AS average';
    }

    private static function scoreRow(object $row): array
    {
        $answered = (int) $row->answered;

        return [
            'answered'   => $answered,
            'promoters'  => (int) $row->promoters,
            'passives'   => (int) $row->passives,
            'detractors' => (int) $row->detractors,
            'average'    => $answered ? round((float) $row->average, 1) : null,
            'nps'        => self::nps((int) $row->promoters, (int) $row->detractors, $answered),
        ];
    }

    public static function summary(Tenant $tenant, array $filters = []): array
    {
        $row = self::base($tenant, $filters)->selectRaw(self::scoreColumns() . ",
            count(*) FILTER (WHERE s.status IN ('sent', 'answered', 'expired')) AS delivered,
            count(*) FILTER (WHERE s.status = 'skipped') AS skipped,
            count(*) FILTER (WHERE s.status = 'scheduled') AS scheduled,
            count(*) FILTER (WHERE s.follow_up = 'pending') AS pending,
            count(*) FILTER (WHERE s.google_invited) AS google_invited,
            count(*) FILTER (WHERE s.google_clicked_at IS NOT NULL) AS google_clicked,
            count(*) FILTER (WHERE s.published) AS published")->first();

        $delivered = (int) $row->delivered;

        return self::scoreRow($row) + [
            'delivered'      => $delivered,
            'skipped'        => (int) $row->skipped,
            'scheduled'      => (int) $row->scheduled,
            'response_rate'  => $delivered ? (int) round((int) $row->answered * 100 / $delivered) : null,
            'pending'        => (int) $row->pending,
            'google_invited' => (int) $row->google_invited,
            'google_clicked' => (int) $row->google_clicked,
            'published'      => (int) $row->published,
        ];
    }

    public static function breakdown(Tenant $tenant, array $filters, string $by): array
    {
        [$column, $table, $label] = match ($by) {
            'service' => ['s.service_id', 'services', 'name'],
            'branch'  => ['s.branch_id', 'branches', 'name'],
            default   => ['s.professional_id', 'professionals', 'display_name'],
        };

        return self::base($tenant, $filters)->whereNotNull('s.score')
            ->leftJoin("{$table} as g", 'g.id', '=', $column)
            ->groupBy($column, "g.{$label}")
            ->selectRaw("{$column} AS id, g.{$label} AS name, " . self::scoreColumns())
            ->get()
            ->map(fn ($row) => ['id' => $row->id, 'name' => $row->name ?? 'Sin dato'] + self::scoreRow($row))
            ->sortByDesc('nps')->values()->all();
    }

    public static function present(object $row, Tenant $tenant): array
    {
        $zone = $tenant->zone();
        $at = fn ($value) => $value ? Carbon::parse($value)->setTimezone($zone)->format('Y-m-d\TH:i') : null;

        return [
            'id'              => $row->id,
            'status'          => $row->status,
            'status_label'    => self::STATUSES[$row->status] ?? $row->status,
            'skip_reason'     => $row->skip_reason,
            'score'           => $row->score === null ? null : (int) $row->score,
            'comment'         => $row->comment,
            'publish_consent' => (bool) $row->publish_consent,
            'published'       => (bool) $row->published,
            'hidden'          => (bool) $row->hidden_at,
            'reply'           => $row->reply,
            'google_invited'  => (bool) $row->google_invited,
            'google_clicked'  => (bool) $row->google_clicked_at,
            'follow_up'       => $row->follow_up,
            'follow_up_label' => $row->follow_up ? self::FOLLOW_UPS[$row->follow_up] : null,
            'follow_up_note'  => $row->follow_up_note,
            'follow_up_at'    => $at($row->follow_up_at),
            'follow_up_by'    => $row->follow_up_by_name ?? null,
            'send_at'         => $at($row->send_at),
            'sent_at'         => $at($row->sent_at),
            'answered_at'     => $at($row->answered_at),
            'expires_at'      => $at($row->expires_at),
            'appointment'     => ['id' => $row->appointment_id, 'code' => $row->appointment_code ?? null, 'starts_at' => $at($row->appointment_starts_at ?? null)],
            'customer'        => ['id' => $row->customer_id, 'name' => trim(($row->customer_first ?? '') . ' ' . ($row->customer_last ?? '')) ?: 'Cliente', 'phone' => $row->customer_phone ?? null],
            'professional'    => $row->professional_id ? ['id' => $row->professional_id, 'name' => $row->professional_name ?? null] : null,
            'service'         => $row->service_id ? ['id' => $row->service_id, 'name' => $row->service_name ?? null] : null,
            'branch'          => $row->branch_id ? ['id' => $row->branch_id, 'name' => $row->branch_name ?? null] : null,
        ];
    }

    public static function listQuery(Tenant $tenant, array $filters)
    {
        return self::base($tenant, $filters)
            ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
            ->leftJoin('professionals as p', 'p.id', '=', 's.professional_id')
            ->leftJoin('services as v', 'v.id', '=', 's.service_id')
            ->leftJoin('branches as b', 'b.id', '=', 's.branch_id')
            ->leftJoin('appointments as a', 'a.id', '=', 's.appointment_id')
            ->leftJoin('users as u', 'u.id', '=', 's.follow_up_by')
            ->select('s.*', 'c.first_name as customer_first', 'c.last_name as customer_last', 'c.phone as customer_phone',
                'p.display_name as professional_name', 'v.name as service_name', 'b.name as branch_name',
                'a.code as appointment_code', 'a.starts_at as appointment_starts_at',
                DB::raw("trim(coalesce(u.first_name, '') || ' ' || coalesce(u.last_name, '')) as follow_up_by_name"));
    }

    public static function shortName(?string $first, ?string $last): string
    {
        $first = trim((string) $first);
        $initial = mb_substr(trim((string) $last), 0, 1);

        return trim(($first !== '' ? Str::of($first)->explode(' ')->first() : 'Cliente') . ($initial !== '' ? ' ' . mb_strtoupper($initial) . '.' : ''));
    }

    public static function publicReviews(Tenant $tenant, int $limit = 6): ?array
    {
        if (!self::ready() || !(bool) AppointmentFlow::rule($tenant, 'surveys.publish_reviews')) {
            return null;
        }

        $stats = DB::table('surveys')->where('tenant_id', $tenant->id)->whereNotNull('score')
            ->selectRaw('count(*) AS total, avg(score) AS average')->first();
        $rows = DB::table('surveys as s')->join('customers as c', 'c.id', '=', 's.customer_id')
            ->leftJoin('services as v', 'v.id', '=', 's.service_id')
            ->where('s.tenant_id', $tenant->id)->where('s.published', true)->where('s.publish_consent', true)->whereNull('s.hidden_at')
            ->orderByDesc('s.answered_at')->limit($limit)
            ->get(['s.id', 's.score', 's.comment', 's.reply', 's.answered_at', 'c.first_name', 'c.last_name', 'v.name as service']);

        if ($rows->isEmpty()) {
            return null;
        }

        return [
            'count'   => (int) $stats->total,
            'average' => $stats->total ? round((float) $stats->average / 2, 1) : null,
            'items'   => $rows->map(fn ($row) => [
                'id'      => $row->id,
                'name'    => self::shortName($row->first_name, $row->last_name),
                'stars'   => max(1, (int) round((int) $row->score / 2)),
                'comment' => $row->comment,
                'reply'   => $row->reply,
                'service' => $row->service,
                'date'    => Carbon::parse($row->answered_at)->setTimezone($tenant->zone())->toDateString(),
            ])->all(),
        ];
    }
}
