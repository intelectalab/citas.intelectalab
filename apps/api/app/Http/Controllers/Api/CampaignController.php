<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Access;
use App\Support\AppointmentFlow;
use App\Support\Audit;
use App\Support\Campaigns;
use App\Support\Coupons;
use App\Support\Currency;
use App\Support\Messaging;
use App\Support\MessageQuota;
use App\Support\Person;
use App\Support\WhatsApp;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CampaignController extends Controller
{
    private const LOCAL_FORMAT = 'Y-m-d\TH:i';

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 403);
        abort_unless(Campaigns::ready(), 503, Campaigns::NOT_READY);

        return $tenant->loadMissing('settings');
    }

    private function emailState(Tenant $tenant): array
    {
        $config = Messaging::emailConfig($tenant->id);

        return match (true) {
            !$config || !Messaging::emailReady($tenant->id) => ['state' => 'none', 'label' => 'Tu empresa aún no configura su correo: las campañas no se pueden enviar.'],
            $config['provider'] === 'simulated' => ['state' => 'simulated', 'label' => 'Tu correo está en "Probar sin enviar": las campañas se arman pero no salen.'],
            default => ['state' => 'ok', 'label' => 'Las campañas salen desde ' . ($config['from_address'] ?? 'el correo de tu empresa') . '.'],
        };
    }

    private function whatsappState(Tenant $tenant): array
    {
        $config = Campaigns::whatsappReady() ? WhatsApp::config($tenant->id) : null;

        return match (true) {
            !Campaigns::whatsappReady() => ['state' => 'not_ready', 'label' => 'Falta actualizar la base de datos para enviar campañas por WhatsApp: ejecuta php artisan migrate.'],
            !WhatsApp::connected($tenant->id) => ['state' => 'none', 'label' => 'Tu empresa aún no conecta WhatsApp (Configuración → Mensajes → WhatsApp y asistente): las campañas por WhatsApp no se pueden enviar.'],
            WhatsApp::simulated($config) => ['state' => 'simulated', 'label' => 'WhatsApp está en "Probar sin enviar": las campañas por WhatsApp se arman pero no salen.'],
            default => ['state' => 'ok', 'label' => 'Las campañas por WhatsApp salen con una plantilla aprobada por Meta.'],
        };
    }

    private function quotaStates(Tenant $tenant): array
    {
        return [
            'email'    => MessageQuota::state($tenant, 'campaign_email'),
            'whatsapp' => MessageQuota::state($tenant, 'whatsapp'),
        ];
    }

    private function iso($value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
    }

    private function branchScope(Tenant $tenant, $user): ?array
    {
        $limit = Access::branchLimit($user);

        if (!$limit) {
            return null;
        }

        return [
            'branch_id'   => $limit,
            'branch_name' => DB::table('branches')->where('id', $limit)->value('name'),
            'can_choose'  => AppointmentFlow::rule($tenant, 'campaigns.manager_scope') === 'choose',
        ];
    }

    private function segment(Tenant $tenant, array $validated, $user): array
    {
        $scope = $this->branchScope($tenant, $user);
        $only = $scope && (!$scope['can_choose'] || ($validated['only_branch'] ?? true)) ? $scope['branch_id'] : null;

        return array_filter([
            'group'       => $validated['group'],
            'service_id'  => $validated['group'] === 'service' ? $validated['service_id'] : null,
            'branch_id'   => $validated['group'] === 'branch' ? $validated['branch_id'] : null,
            'tag_id'      => $validated['group'] === 'tag' ? ($validated['tag_id'] ?? null) : null,
            'only_branch' => $only,
        ]);
    }

    private function segmentRules(Tenant $tenant): array
    {
        return [
            'group'      => ['required', Rule::in(array_keys(Campaigns::GROUPS))],
            'service_id' => ['required_if:group,service', 'nullable', 'uuid', Rule::exists('services', 'id')->where('tenant_id', $tenant->id)],
            'branch_id'  => ['required_if:group,branch', 'nullable', 'uuid', Rule::exists('branches', 'id')->where('tenant_id', $tenant->id)],
            'only_branch' => 'nullable|boolean',
        ] + (\App\Support\CustomerProfile::tagsReady() ? [
            'tag_id' => ['required_if:group,tag', 'nullable', 'uuid', Rule::exists('customer_tags', 'id')->where('tenant_id', $tenant->id)],
        ] : []);
    }

    private const SEGMENT_MESSAGES = [
        'group.required'         => 'Elige a qué grupo de clientes va la campaña.',
        'service_id.required_if' => 'Elige el servicio.',
        'branch_id.required_if'  => 'Elige la sede.',
        'tag_id.required_if'     => 'Elige la etiqueta.',
    ];

    private function present(Tenant $tenant, object $campaign, bool $withResults = true): array
    {
        $stats = is_string($campaign->stats) ? json_decode($campaign->stats, true) : (array) $campaign->stats;
        $segment = is_string($campaign->segment) ? json_decode($campaign->segment, true) : (array) $campaign->segment;
        $coupon = $campaign->coupon_id ? DB::table('coupons')->where('id', $campaign->coupon_id)->first(['id', 'code', 'description']) : null;

        return [
            'id'           => $campaign->id,
            'name'         => $campaign->name,
            'status'       => $campaign->status,
            'status_label' => Campaigns::STATUS_LABELS[$campaign->status] ?? $campaign->status,
            'segment'      => $segment,
            'channel'      => $campaign->channel ?: 'email',
            'channel_label' => Campaigns::CHANNELS[$campaign->channel] ?? 'Correo',
            'whatsapp'     => Campaigns::whatsappReady() ? Campaigns::waConfig($campaign) : null,
            'blocked'      => $stats['blocked'] ?? null,
            'audience'     => $campaign->audience_label ?: Campaigns::label($tenant, $segment),
            'subject'      => (string) $campaign->subject,
            'body'         => (string) $campaign->body,
            'coupon'       => $coupon ? ['id' => $coupon->id, 'code' => $coupon->code, 'description' => $coupon->description] : null,
            'scheduled_at' => $this->iso($campaign->scheduled_at),
            'sent_at'      => $this->iso($campaign->sent_at),
            'created_at'   => $this->iso($campaign->created_at),
            'by'           => ($author = $campaign->created_by ? DB::table('users')->where('id', $campaign->created_by)->first() : null) ? Person::name($author) : null,
            'stats'        => $stats,
            'results'      => $withResults && in_array($campaign->status, ['sending', 'sent'], true) ? Campaigns::results($campaign) : null,
            'editable'     => in_array($campaign->status, ['scheduled', 'draft'], true),
        ];
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);
        Campaigns::dispatchDue($tenant->id);

        $campaigns = DB::table('marketing_campaigns')->where('tenant_id', $tenant->id)->orderByDesc('created_at')->get();

        return response()->json([
            'campaigns' => $campaigns->map(fn ($c) => $this->present($tenant, $c))->values(),
            'email'     => $this->emailState($tenant),
            'whatsapp'  => $this->whatsappState($tenant),
            'quota'     => $this->quotaStates($tenant),
        ]);
    }

    public function options(Request $request)
    {
        $tenant = $this->tenant($request);
        $currency = Currency::of($tenant);

        $coupons = DB::table('coupons')->where('tenant_id', $tenant->id)->orderBy('code')->get()
            ->filter(fn ($c) => in_array(Coupons::state($c), ['valid', 'scheduled'], true))
            ->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'description' => $c->description, 'benefit' => Coupons::summary($c, 0, $currency)['benefit']])
            ->values();

        return response()->json([
            'groups'    => Campaigns::groups($tenant),
            'services'  => DB::table('services')->where('tenant_id', $tenant->id)->orderBy('name')->get(['id', 'name']),
            'branches'  => DB::table('branches')->where('tenant_id', $tenant->id)->orderBy('name')->get(['id', 'name']),
            'tags'      => \App\Support\CustomerProfile::tags($tenant->id),
            'coupons'   => $coupons,
            'variables' => collect(Campaigns::VARIABLES)->map(fn ($label, $key) => ['token' => "[{$key}]", 'label' => $label])->values(),
            'volume_warning' => Campaigns::VOLUME_WARNING,
            'email'     => $this->emailState($tenant),
            'whatsapp'  => $this->whatsappState($tenant),
            'channels'  => collect(Campaigns::CHANNELS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'wa_variables' => collect(Campaigns::WA_VARIABLES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'wa_max_params' => Campaigns::WA_MAX_PARAMS,
            'quota'     => $this->quotaStates($tenant),
            'branch_scope' => $this->branchScope($tenant, $request->user()),
        ]);
    }

    private function channelRule(): array
    {
        return ['channel' => ['nullable', Rule::in(Campaigns::whatsappReady() ? array_keys(Campaigns::CHANNELS) : ['email'])]];
    }

    public function audience(Request $request)
    {
        $tenant = $this->tenant($request);
        $validated = $request->validate($this->segmentRules($tenant) + $this->channelRule(), self::SEGMENT_MESSAGES + self::CHANNEL_MESSAGES);
        $channel = $validated['channel'] ?? 'email';
        $audience = Campaigns::audience($tenant, $this->segment($tenant, $validated, $request->user()), true, $channel);
        $firstId = collect($audience['ids'] ?? [])->flatMap(fn ($byReason) => $byReason['eligible'] ?? [])->first();
        $sample = $firstId ? DB::table('customers')->where('tenant_id', $tenant->id)->where('id', $firstId)->first(['first_name', 'last_name']) : null;
        unset($audience['ids']);

        return response()->json($audience + [
            'quota'  => Campaigns::quotas($tenant, $audience, $audience['channel']),
            'sample' => $sample ? ['first_name' => $sample->first_name, 'name' => Person::name($sample)] : null,
        ]);
    }

    public function segments(Request $request)
    {
        $tenant = $this->tenant($request);
        $only = Access::branchLimit($request->user());

        return response()->json([
            'segments' => collect(Campaigns::groups($tenant))
                ->filter(fn ($g) => !$g['needs'])
                ->map(fn ($g) => $g + ['count' => Campaigns::customers($tenant, array_filter(['group' => $g['key'], 'only_branch' => $only]))->count()])
                ->values(),
            'branch' => $only ? DB::table('branches')->where('id', $only)->value('name') : null,
        ]);
    }

    private const CHANNEL_MESSAGES = [
        'channel.in'           => 'Elige si la campaña sale por correo, por WhatsApp o por los dos. Si WhatsApp no aparece, falta actualizar la base de datos.',
        'wa_template.required' => 'Escribe el nombre de la plantilla aprobada en Meta.',
        'wa_template.regex'    => 'El nombre de la plantilla va como en Meta: minúsculas, números y guion bajo (ej. promo_noviembre).',
        'wa_language.regex'    => 'El idioma va como en Meta (ej. es, es_PE, en_US).',
        'wa_params.max'        => 'Una plantilla admite hasta 10 datos en esta pantalla.',
        'wa_params.*.in'       => 'Uno de los datos de la plantilla no es válido: elígelo de la lista.',
    ];

    private function input(Request $request, Tenant $tenant): array
    {
        $channel = Campaigns::whatsappReady() && in_array($request->input('channel'), array_keys(Campaigns::CHANNELS), true) ? $request->input('channel') : 'email';
        $channels = Campaigns::channelsOf($channel);
        $withEmail = in_array('email', $channels, true);
        $withWhatsApp = in_array('whatsapp', $channels, true);
        $draft = $request->input('send') === 'draft';

        $validated = $request->validate($this->segmentRules($tenant) + $this->channelRule() + [
            'name'         => 'required|string|max:150',
            'subject'      => [$withEmail && !$draft ? 'required' : 'nullable', 'string', 'max:200'],
            'body'         => [$withEmail && !$draft ? 'required' : 'nullable', 'string', 'max:20000'],
            'coupon_id'    => ['nullable', 'uuid', Rule::exists('coupons', 'id')->where('tenant_id', $tenant->id)],
            'send'         => ['required', Rule::in(['now', 'scheduled', 'draft'])],
            'scheduled_at' => 'required_if:send,scheduled|nullable|date_format:' . self::LOCAL_FORMAT,
        ] + ($withWhatsApp ? [
            'wa_template' => [$draft ? 'nullable' : 'required', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'wa_language' => ['nullable', 'string', 'max:15', 'regex:/^[A-Za-z]{2,3}(_[A-Za-z]{2,4})?$/'],
            'wa_params'   => ['nullable', 'array', 'max:' . Campaigns::WA_MAX_PARAMS],
            'wa_params.*' => ['required', Rule::in(array_keys(Campaigns::WA_VARIABLES))],
        ] : []), self::SEGMENT_MESSAGES + self::CHANNEL_MESSAGES + [
            'name.required'            => 'Ponle un nombre a la campaña. Solo lo ve tu equipo.',
            'subject.required'         => 'Escribe el asunto del correo.',
            'body.required'            => 'Escribe el mensaje.',
            'scheduled_at.required_if' => 'Elige el día y la hora del envío.',
        ]);

        $params = $withWhatsApp ? array_values($validated['wa_params'] ?? []) : [];
        $subject = (string) ($validated['subject'] ?? '');
        $body = (string) ($validated['body'] ?? '');

        if (!$draft && empty($validated['coupon_id']) && (($withEmail && (str_contains($body, '[Cupón]') || str_contains($subject, '[Cupón]'))) || in_array('Cupón', $params, true))) {
            throw ValidationException::withMessages(['coupon_id' => 'El mensaje usa el dato [Cupón], pero no elegiste un cupón. Elige uno o quita ese dato del mensaje.']);
        }

        $at = null;

        if ($draft) {
            $segment = $this->segment($tenant, $validated, $request->user());
            $at = !empty($validated['scheduled_at']) ? Carbon::createFromFormat(self::LOCAL_FORMAT, $validated['scheduled_at'], $tenant->zone()) : null;

            return [
                'name'           => trim($validated['name']),
                'segment'        => json_encode($segment),
                'audience_label' => mb_substr(Campaigns::label($tenant, $segment), 0, 200),
                'subject'        => $withEmail ? ($subject ?: null) : null,
                'body'           => $withEmail && $body !== '' ? \App\Support\RichText::normalize($body) : null,
                'coupon_id'      => $validated['coupon_id'] ?? null,
                'scheduled_at'   => $at?->copy()->utc(),
                'channel'        => $channel,
            ] + (Campaigns::whatsappReady() ? [
                'whatsapp' => $withWhatsApp && !empty($validated['wa_template']) ? json_encode([
                    'template' => $validated['wa_template'],
                    'language' => $validated['wa_language'] ?? null ?: 'es',
                    'params'   => $params,
                ]) : null,
            ] : []);
        }

        if ($validated['send'] === 'scheduled') {
            $at = Carbon::createFromFormat(self::LOCAL_FORMAT, $validated['scheduled_at'], $tenant->zone());

            if ($at->lt(now()->addMinute())) {
                throw ValidationException::withMessages(['scheduled_at' => 'Esa hora ya pasó. Elige una hora futura o envíala ahora.']);
            }

            if ($at->gt(now()->addYear())) {
                throw ValidationException::withMessages(['scheduled_at' => 'Se puede programar hasta con un año de anticipación.']);
            }
        }

        if ($withEmail && !Messaging::emailReady($tenant->id)) {
            throw ValidationException::withMessages(['send' => 'Antes de enviar campañas por correo, configura el correo de tu empresa en Configuración → Correo de tu empresa.']);
        }

        if ($withWhatsApp && !WhatsApp::connected($tenant->id)) {
            throw ValidationException::withMessages(['channel' => 'Antes de enviar campañas por WhatsApp, conecta WhatsApp en Configuración → Mensajes → WhatsApp y asistente.']);
        }

        $segment = $this->segment($tenant, $validated, $request->user());
        $audience = Campaigns::audience($tenant, $segment, false, $channel);

        if ($audience['eligible'] === 0) {
            throw ValidationException::withMessages(['group' => $audience['total'] === 0
                ? 'Este grupo no tiene clientes por ahora. Elige otro grupo.'
                : match ($channel) {
                    'whatsapp' => 'Ningún cliente de este grupo puede recibir la campaña por WhatsApp (falta el celular o su permiso, o pidió no recibir promociones), así que no hay a quién enviarla.',
                    'both'     => 'Ningún cliente de este grupo puede recibir la campaña ni por correo ni por WhatsApp, así que no hay a quién enviarla.',
                    default    => 'Ningún cliente de este grupo aceptó recibir promociones por correo, así que no hay a quién enviarla.',
                }]);
        }

        $sameMonth = !$at || $at->copy()->setTimezone($tenant->zone())->format('Y-m') === now($tenant->zone())->format('Y-m');
        $shortage = $sameMonth ? Campaigns::shortage(Campaigns::quotas($tenant, $audience, $channel)) : null;

        if ($shortage) {
            throw ValidationException::withMessages(['send' => $shortage]);
        }

        return [
            'name'           => trim($validated['name']),
            'segment'        => json_encode($segment),
            'audience_label' => mb_substr(Campaigns::label($tenant, $segment), 0, 200),
            'subject'        => $withEmail ? $subject : null,
            'body'           => $withEmail ? \App\Support\RichText::normalize($body) : null,
            'coupon_id'      => $validated['coupon_id'] ?? null,
            'scheduled_at'   => ($at ?? now())->copy()->utc(),
            'channel'        => $channel,
        ] + (Campaigns::whatsappReady() ? [
            'whatsapp' => $withWhatsApp ? json_encode([
                'template' => $validated['wa_template'],
                'language' => $validated['wa_language'] ?? null ?: 'es',
                'params'   => $params,
            ]) : null,
        ] : []);
    }

    public function store(Request $request)
    {
        $tenant = $this->tenant($request);
        $data = $this->input($request, $tenant);
        $id = (string) Str::uuid();

        DB::table('marketing_campaigns')->insert($data + [
            'id'         => $id,
            'tenant_id'  => $tenant->id,
            'status'     => $request->input('send') === 'draft' ? 'draft' : 'scheduled',
            'stats'      => '{}',
            'created_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $created = DB::table('marketing_campaigns')->where('id', $id)->first();
        $this->audit($request, 'campaign.created', $created, [], array_filter($this->auditable($created), fn ($value) => $value !== null));

        if ($request->input('send') === 'now') {
            Campaigns::dispatch($tenant, $created);
        }

        if ($created->status !== 'draft') {
            $this->auditDelivery($request, $created);
        }

        return response()->json($this->present($tenant, DB::table('marketing_campaigns')->where('id', $id)->first()), 201);
    }

    private function auditable(object $campaign): array
    {
        $coupon = $campaign->coupon_id ? DB::table('coupons')->where('id', $campaign->coupon_id)->value('code') : null;

        $wa = Campaigns::whatsappReady() ? Campaigns::waConfig($campaign) : null;

        return [
            'name'     => $campaign->name,
            'channel'  => Campaigns::CHANNELS[$campaign->channel] ?? null,
            'template' => $wa ? $wa['template'] . ' (' . ($wa['language'] ?? 'es') . ')' : null,
            'audience' => $campaign->audience_label,
            'subject'  => $campaign->subject,
            'body'     => mb_strimwidth(trim(preg_replace('/\s+/', ' ', strip_tags((string) $campaign->body))), 0, 160, '…'),
            'coupon'   => $coupon,
        ];
    }

    private function audit(Request $request, string $action, object $campaign, array $before, array $after): void
    {
        Audit::log($request, $action, [
            'tenant'       => $request->user()->tenant_id,
            'entity_type'  => 'campaign',
            'entity_id'    => $campaign->id,
            'entity_label' => $campaign->name,
            'before'       => $before ?: null,
            'after'        => $after ?: null,
        ]);
    }

    private function auditDelivery(Request $request, object $campaign, ?object $previous = null): void
    {
        $fresh = DB::table('marketing_campaigns')->where('id', $campaign->id)->first();

        if ($fresh->status === 'blocked') {
            $stats = is_string($fresh->stats) ? json_decode($fresh->stats, true) : (array) $fresh->stats;
            $this->audit($request, 'campaign.blocked', $fresh, [], array_filter(['reason' => $stats['blocked'] ?? null]));

            return;
        }

        if ($request->input('send') === 'now') {
            $stats = is_string($fresh->stats) ? json_decode($fresh->stats, true) : (array) $fresh->stats;
            $this->audit($request, 'campaign.sent', $fresh, [], array_filter([
                'audience'   => $fresh->audience_label,
                'recipients' => (int) ($stats['eligible'] ?? 0),
            ], fn ($value) => $value !== null));

            return;
        }

        $at = Carbon::parse($fresh->scheduled_at)->toIso8601String();
        $before = $previous?->scheduled_at ? Carbon::parse($previous->scheduled_at)->toIso8601String() : null;

        if ($before !== $at) {
            $this->audit($request, 'campaign.scheduled', $fresh, array_filter(['scheduled_at' => $before]), ['scheduled_at' => $at]);
        }
    }

    private function find(Tenant $tenant, string $id): object
    {
        abort_unless(Str::isUuid($id), 404, 'No encontramos esa campaña.');

        $campaign =DB::table('marketing_campaigns')->where('tenant_id', $tenant->id)->where('id', $id)->first();
        abort_unless($campaign, 404, 'No encontramos esa campaña.');

        return $campaign;
    }

    public function show(Request $request, string $campaign)
    {
        $tenant = $this->tenant($request);
        Campaigns::dispatchDue($tenant->id);
        $row = $this->find($tenant, $campaign);

        $recipients = DB::table('marketing_campaign_recipients as r')
            ->join('customers as c', 'c.id', '=', 'r.customer_id')
            ->leftJoin('messages as m', 'm.id', '=', 'r.message_id')
            ->where('r.marketing_campaign_id', $row->id)
            ->orderBy('c.first_name')
            ->get(array_merge(['r.id', 'r.status as r_status', 'r.skip_reason', 'c.id as customer_id', 'c.first_name', 'c.last_name', 'c.email', 'c.phone', 'm.status as m_status', 'm.error', 'm.sent_at'], Campaigns::whatsappReady() ? ['r.channel'] : []));

        $booked = $row->sent_at ? DB::table('appointments as a')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('marketing_campaign_recipients as r')
                ->join('messages as m', 'm.id', '=', 'r.message_id')
                ->whereColumn('r.customer_id', 'a.customer_id')
                ->where('r.marketing_campaign_id', $row->id)
                ->whereIn('m.status', Campaigns::REACHED))
            ->join('customers as c', 'c.id', '=', 'a.customer_id')
            ->leftJoin('services as s', 's.id', '=', 'a.service_id')
            ->where('a.created_at', '>=', Carbon::parse($row->sent_at)->toIso8601String())
            ->where('a.created_at', '<', Carbon::parse($row->sent_at)->addDays(Campaigns::BOOKED_DAYS)->toIso8601String())
            ->where('a.created_at', '<=', now()->toIso8601String())
            ->orderBy('a.created_at')
            ->get(['a.id', 'a.code', 'a.starts_at', 'a.created_at', 'c.id as customer_id', 'c.first_name', 'c.last_name', 's.name as service'])
            ->map(fn ($a) => [
                'id'         => $a->id,
                'code'       => $a->code,
                'customer'   => ['id' => $a->customer_id, 'name' => trim($a->first_name . ' ' . $a->last_name)],
                'service'    => $a->service,
                'starts_at'  => $this->iso($a->starts_at),
                'created_at' => $this->iso($a->created_at),
            ]) : collect();

        return response()->json($this->present($tenant, $row) + [
            'recipients' => $recipients->map(function ($r) {
                $state = match (true) {
                    $r->r_status === 'skipped' => 'skipped',
                    in_array($r->m_status, ['sent', 'simulated', 'delivered', 'read'], true) => $r->m_status,
                    in_array($r->m_status, ['queued', 'scheduled'], true) => 'pending',
                    default => 'failed',
                };
                $channel = $r->channel ?? 'email';

                return [
                    'id'       => $r->id,
                    'customer' => ['id' => $r->customer_id, 'name' => trim($r->first_name . ' ' . $r->last_name)],
                    'channel'  => $channel,
                    'email'    => $r->email,
                    'to'       => $channel === 'whatsapp' ? ($r->phone ?: null) : $r->email,
                    'state'    => $state,
                    'label'    => ['skipped' => 'No se envió', 'sent' => 'Enviado', 'simulated' => 'Simulado', 'delivered' => 'Entregado', 'read' => 'Leído', 'pending' => 'Por enviar', 'failed' => 'Falló'][$state],
                    'reason'   => $r->skip_reason ?: $r->error,
                    'sent_at'  => $this->iso($r->sent_at),
                ];
            })->values(),
            'booked_list' => $booked->values(),
            'booked_days' => Campaigns::BOOKED_DAYS,
            'email'       => $this->emailState($tenant),
            'whatsapp_state' => $this->whatsappState($tenant),
        ]);
    }

    public function update(Request $request, string $campaign)
    {
        $tenant = $this->tenant($request);
        $row = $this->find($tenant, $campaign);
        abort_unless(in_array($row->status, ['scheduled', 'draft'], true), 422, 'Solo se puede cambiar una campaña programada o en borrador que aún no sale.');

        $data = $this->input($request, $tenant);
        $status = $request->input('send') === 'draft' ? 'draft' : 'scheduled';
        DB::table('marketing_campaigns')->where('id', $row->id)->where('status', $row->status)->update($data + ['status' => $status, 'updated_at' => now()]);
        $fresh = DB::table('marketing_campaigns')->where('id', $row->id)->first();
        [$before, $after] = Audit::changed($this->auditable($row), $this->auditable($fresh));

        if ($after) {
            $this->audit($request, 'campaign.updated', $fresh, $before, $after);
        }

        if ($request->input('send') === 'now') {
            Campaigns::dispatch($tenant, $fresh);
        }

        if ($status !== 'draft') {
            $this->auditDelivery($request, $fresh, $row);
        }

        return response()->json($this->present($tenant, DB::table('marketing_campaigns')->where('id', $row->id)->first()));
    }

    public function cancel(Request $request, string $campaign)
    {
        $tenant = $this->tenant($request);
        $row = $this->find($tenant, $campaign);
        abort_unless(in_array($row->status, ['scheduled', 'draft'], true), 422, 'Solo se puede cancelar una campaña programada o en borrador que aún no sale.');

        DB::table('marketing_campaigns')->where('id', $row->id)->where('status', $row->status)->update(['status' => 'cancelled', 'updated_at' => now()]);
        Audit::log($request, 'campaign.cancelled', [
            'tenant'       => $tenant->id,
            'entity_type'  => 'campaign',
            'entity_id'    => $row->id,
            'entity_label' => $row->name,
            'before'       => array_filter(['scheduled_at' => $row->scheduled_at ? Carbon::parse($row->scheduled_at)->toIso8601String() : null]),
        ]);

        return response()->json($this->present($tenant, DB::table('marketing_campaigns')->where('id', $row->id)->first()));
    }

    private function recipientForUnsubscribe(string $recipient): ?object
    {
        if (!Str::isUuid($recipient) || !Campaigns::ready()) {
            return null;
        }

        $row = DB::table('marketing_campaign_recipients as r')
            ->join('customers as c', 'c.id', '=', 'r.customer_id')
            ->join('tenants as t', 't.id', '=', 'r.tenant_id')
            ->where('r.id', $recipient)
            ->first(array_merge(['r.id', 'r.tenant_id', 'r.customer_id', 'c.first_name'], Campaigns::whatsappReady() ? ['r.channel'] : []));

        if ($row || !\App\Support\Automations::ready()) {
            return $row;
        }

        return DB::table('automation_logs as l')
            ->join('customers as c', 'c.id', '=', 'l.customer_id')
            ->where('l.id', $recipient)
            ->first(['l.id', 'l.tenant_id', 'l.customer_id', 'c.first_name']);
    }

    public function unsubscribeShow(string $recipient)
    {
        $row = $this->recipientForUnsubscribe($recipient);
        abort_unless($row, 404, 'Este enlace no es válido o ya no existe.');

        $consent = DB::table('customer_consents')->where('customer_id', $row->customer_id)->where('channel', 'email')->first();

        return response()->json([
            'company'      => Messaging::companyName(Tenant::find($row->tenant_id)),
            'name'         => $row->first_name,
            'unsubscribed' => ($row->channel ?? 'email') === 'whatsapp'
                ? $consent && !$consent->granted && $consent->source === 'unsubscribe'
                : !$consent || !$consent->granted,
        ]);
    }

    public function unsubscribe(string $recipient)
    {
        $row = $this->recipientForUnsubscribe($recipient);
        abort_unless($row, 404, 'Este enlace no es válido o ya no existe.');

        MessageController::saveConsents($row->tenant_id, $row->customer_id, ['email' => false], 'unsubscribe');
        DB::table('customer_consents')->where('customer_id', $row->customer_id)->where('channel', 'email')->where('granted', false)
            ->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', 'unsubscribe'))
            ->update(['source' => 'unsubscribe', 'revoked_at' => now(), 'updated_at' => now()]);

        return response()->json([
            'company'      => Messaging::companyName(Tenant::find($row->tenant_id)),
            'name'         => $row->first_name,
            'unsubscribed' => true,
        ]);
    }
}
