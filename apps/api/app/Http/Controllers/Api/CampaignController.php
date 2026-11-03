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
use App\Support\Person;
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
        ];
    }

    private const SEGMENT_MESSAGES = [
        'group.required'         => 'Elige a qué grupo de clientes va la campaña.',
        'service_id.required_if' => 'Elige el servicio.',
        'branch_id.required_if'  => 'Elige la sede.',
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
            'audience'     => $campaign->audience_label ?: Campaigns::label($tenant, $segment),
            'subject'      => $campaign->subject,
            'body'         => $campaign->body,
            'coupon'       => $coupon ? ['id' => $coupon->id, 'code' => $coupon->code, 'description' => $coupon->description] : null,
            'scheduled_at' => $this->iso($campaign->scheduled_at),
            'sent_at'      => $this->iso($campaign->sent_at),
            'created_at'   => $this->iso($campaign->created_at),
            'by'           => ($author = $campaign->created_by ? DB::table('users')->where('id', $campaign->created_by)->first() : null) ? Person::name($author) : null,
            'stats'        => $stats,
            'results'      => $withResults && in_array($campaign->status, ['sending', 'sent'], true) ? Campaigns::results($campaign) : null,
            'editable'     => $campaign->status === 'scheduled',
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
            'coupons'   => $coupons,
            'variables' => collect(Campaigns::VARIABLES)->map(fn ($label, $key) => ['token' => "[{$key}]", 'label' => $label])->values(),
            'volume_warning' => Campaigns::VOLUME_WARNING,
            'email'     => $this->emailState($tenant),
            'branch_scope' => $this->branchScope($tenant, $request->user()),
        ]);
    }

    public function audience(Request $request)
    {
        $tenant = $this->tenant($request);
        $validated = $request->validate($this->segmentRules($tenant), self::SEGMENT_MESSAGES);

        return response()->json(Campaigns::audience($tenant, $this->segment($tenant, $validated, $request->user())));
    }

    private function input(Request $request, Tenant $tenant): array
    {
        $validated = $request->validate($this->segmentRules($tenant) + [
            'name'         => 'required|string|max:150',
            'subject'      => 'required|string|max:200',
            'body'         => 'required|string|max:20000',
            'coupon_id'    => ['nullable', 'uuid', Rule::exists('coupons', 'id')->where('tenant_id', $tenant->id)],
            'send'         => ['required', Rule::in(['now', 'scheduled'])],
            'scheduled_at' => 'required_if:send,scheduled|nullable|date_format:' . self::LOCAL_FORMAT,
        ], self::SEGMENT_MESSAGES + [
            'name.required'            => 'Ponle un nombre a la campaña. Solo lo ve tu equipo.',
            'subject.required'         => 'Escribe el asunto del correo.',
            'body.required'            => 'Escribe el mensaje.',
            'scheduled_at.required_if' => 'Elige el día y la hora del envío.',
        ]);

        if (empty($validated['coupon_id']) && (str_contains($validated['body'], '[Cupón]') || str_contains($validated['subject'], '[Cupón]'))) {
            throw ValidationException::withMessages(['coupon_id' => 'El mensaje usa el dato [Cupón], pero no elegiste un cupón. Elige uno o quita ese dato del mensaje.']);
        }

        $at = null;

        if ($validated['send'] === 'scheduled') {
            $at = Carbon::createFromFormat(self::LOCAL_FORMAT, $validated['scheduled_at'], $tenant->zone());

            if ($at->lt(now()->addMinute())) {
                throw ValidationException::withMessages(['scheduled_at' => 'Esa hora ya pasó. Elige una hora futura o envíala ahora.']);
            }

            if ($at->gt(now()->addYear())) {
                throw ValidationException::withMessages(['scheduled_at' => 'Se puede programar hasta con un año de anticipación.']);
            }
        }

        if (!Messaging::emailReady($tenant->id)) {
            throw ValidationException::withMessages(['send' => 'Antes de enviar campañas, configura el correo de tu empresa en Configuración → Correo de tu empresa.']);
        }

        $segment = $this->segment($tenant, $validated, $request->user());
        $audience = Campaigns::audience($tenant, $segment);

        if ($audience['eligible'] === 0) {
            throw ValidationException::withMessages(['group' => $audience['total'] === 0
                ? 'Este grupo no tiene clientes por ahora. Elige otro grupo.'
                : 'Ningún cliente de este grupo aceptó recibir promociones por correo, así que no hay a quién enviarla.']);
        }

        return [
            'name'           => trim($validated['name']),
            'segment'        => json_encode($segment),
            'audience_label' => mb_substr(Campaigns::label($tenant, $segment), 0, 200),
            'subject'        => $validated['subject'],
            'body'           => \App\Support\RichText::normalize($validated['body']),
            'coupon_id'      => $validated['coupon_id'] ?? null,
            'scheduled_at'   => ($at ?? now())->copy()->utc(),
        ];
    }

    public function store(Request $request)
    {
        $tenant = $this->tenant($request);
        $data = $this->input($request, $tenant);
        $id = (string) Str::uuid();

        DB::table('marketing_campaigns')->insert($data + [
            'id'         => $id,
            'tenant_id'  => $tenant->id,
            'channel'    => 'email',
            'status'     => 'scheduled',
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

        $this->auditDelivery($request, $created);

        return response()->json($this->present($tenant, DB::table('marketing_campaigns')->where('id', $id)->first()), 201);
    }

    private function auditable(object $campaign): array
    {
        $coupon = $campaign->coupon_id ? DB::table('coupons')->where('id', $campaign->coupon_id)->value('code') : null;

        return [
            'name'     => $campaign->name,
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
            ->get(['r.id', 'r.status as r_status', 'r.skip_reason', 'c.id as customer_id', 'c.first_name', 'c.last_name', 'c.email', 'm.status as m_status', 'm.error', 'm.sent_at']);

        $booked = $row->sent_at ? DB::table('appointments as a')
            ->join('marketing_campaign_recipients as r', fn ($j) => $j->on('r.customer_id', '=', 'a.customer_id')->where('r.marketing_campaign_id', '=', $row->id))
            ->join('messages as m', 'm.id', '=', 'r.message_id')
            ->join('customers as c', 'c.id', '=', 'a.customer_id')
            ->leftJoin('services as s', 's.id', '=', 'a.service_id')
            ->whereIn('m.status', ['sent', 'simulated'])
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
                    in_array($r->m_status, ['sent', 'simulated'], true) => $r->m_status,
                    in_array($r->m_status, ['queued', 'scheduled'], true) => 'pending',
                    default => 'failed',
                };

                return [
                    'id'       => $r->id,
                    'customer' => ['id' => $r->customer_id, 'name' => trim($r->first_name . ' ' . $r->last_name)],
                    'email'    => $r->email,
                    'state'    => $state,
                    'label'    => ['skipped' => 'No se envió', 'sent' => 'Enviado', 'simulated' => 'Simulado', 'pending' => 'Por enviar', 'failed' => 'Falló'][$state],
                    'reason'   => $r->skip_reason ?: $r->error,
                    'sent_at'  => $this->iso($r->sent_at),
                ];
            })->values(),
            'booked_list' => $booked->values(),
            'booked_days' => Campaigns::BOOKED_DAYS,
            'email'       => $this->emailState($tenant),
        ]);
    }

    public function update(Request $request, string $campaign)
    {
        $tenant = $this->tenant($request);
        $row = $this->find($tenant, $campaign);
        abort_unless($row->status === 'scheduled', 422, 'Solo se puede cambiar una campaña programada que aún no sale.');

        $data = $this->input($request, $tenant);
        DB::table('marketing_campaigns')->where('id', $row->id)->where('status', 'scheduled')->update($data + ['updated_at' => now()]);
        $fresh = DB::table('marketing_campaigns')->where('id', $row->id)->first();
        [$before, $after] = Audit::changed($this->auditable($row), $this->auditable($fresh));

        if ($after) {
            $this->audit($request, 'campaign.updated', $fresh, $before, $after);
        }

        if ($request->input('send') === 'now') {
            Campaigns::dispatch($tenant, $fresh);
        }

        $this->auditDelivery($request, $fresh, $row);

        return response()->json($this->present($tenant, DB::table('marketing_campaigns')->where('id', $row->id)->first()));
    }

    public function cancel(Request $request, string $campaign)
    {
        $tenant = $this->tenant($request);
        $row = $this->find($tenant, $campaign);
        abort_unless($row->status === 'scheduled', 422, 'Solo se puede cancelar una campaña programada que aún no sale.');

        DB::table('marketing_campaigns')->where('id', $row->id)->where('status', 'scheduled')->update(['status' => 'cancelled', 'updated_at' => now()]);
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
            ->first(['r.id', 'r.tenant_id', 'r.customer_id', 'c.first_name']);

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
            'unsubscribed' => !$consent || !$consent->granted,
        ]);
    }

    public function unsubscribe(string $recipient)
    {
        $row = $this->recipientForUnsubscribe($recipient);
        abort_unless($row, 404, 'Este enlace no es válido o ya no existe.');

        MessageController::saveConsents($row->tenant_id, $row->customer_id, ['email' => false], 'unsubscribe');

        return response()->json([
            'company'      => Messaging::companyName(Tenant::find($row->tenant_id)),
            'name'         => $row->first_name,
            'unsubscribed' => true,
        ]);
    }
}
