<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Assistant;
use App\Support\Audit;
use App\Support\Messaging;
use App\Support\Person;
use App\Support\WhatsApp;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WhatsAppController extends Controller
{
    private const RULE_LABELS = [
        'email'          => 'Solo correo',
        'whatsapp'       => 'WhatsApp (si no se puede, correo)',
        'both'           => 'WhatsApp y correo',
        'required'       => 'Solo a quien aceptó WhatsApp',
        'unless_revoked' => 'A todos, salvo quien lo rechazó',
    ];

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 403);
        abort_unless(WhatsApp::ready(), 503, WhatsApp::NOT_READY);

        return $tenant->loadMissing('settings');
    }

    private function canView(Request $request): void
    {
        abort_unless($request->user()->allows('settings.view'), 403, 'Tu cuenta no tiene permiso para ver la configuración de la empresa.');
    }

    private function canEdit(Request $request): void
    {
        abort_unless($request->user()->allows('settings.edit'), 403, 'Tu cuenta no tiene permiso para cambiar la configuración de la empresa.');
    }

    private static function mask(?string $value): ?string
    {
        return $value ? str_repeat('•', 8) . mb_substr($value, -4) : null;
    }

    public static function status(Tenant $tenant): array
    {
        if (!WhatsApp::ready()) {
            return ['state' => 'not_ready', 'label' => WhatsApp::NOT_READY, 'channel' => 'email'];
        }

        $config = WhatsApp::config($tenant->id);
        $channel = WhatsApp::channel($tenant);

        if (!$config) {
            return ['state' => 'none', 'label' => 'Sin conectar: los avisos salen solo por correo', 'channel' => $channel];
        }

        if (WhatsApp::simulated($config)) {
            return ['state' => 'simulated', 'label' => 'Probar sin enviar: los mensajes de WhatsApp se arman y se guardan, pero no salen', 'channel' => $channel];
        }

        $state = match (true) {
            !empty($config['test_ok']) => 'ok',
            isset($config['test_ok']) && $config['test_ok'] === false => 'error',
            default => 'untested',
        };

        return [
            'state'   => $state,
            'label'   => match ($state) {
                'ok'    => 'Conectado: los mensajes salen desde ' . ($config['display_phone'] ?? 'tu número de WhatsApp'),
                'error' => 'La última prueba falló: revisa los datos',
                default => 'Guardado, falta probar la conexión',
            },
            'channel' => $channel,
        ];
    }

    private function payload(Request $request, Tenant $tenant): array
    {
        $config = WhatsApp::config($tenant->id);
        $iso = fn ($value) => $value ? Carbon::parse($value)->toIso8601String() : null;
        $edit = $request->user()->allows('settings.edit');
        $provider = Assistant::provider();

        return [
            'status'          => self::status($tenant),
            'simulate_forced' => (bool) config('services.whatsapp.simulate'),
            'webhook_url'     => rtrim((string) config('app.url'), '/') . '/api/webhooks/whatsapp/' . $tenant->id,
            'config'          => $config ? [
                'provider'            => $config['provider'] === 'cloud' ? 'cloud' : 'simulated',
                'phone_number_id'     => $config['phone_number_id'] ?? '',
                'business_account_id' => $config['business_account_id'] ?? '',
                'display_phone'       => $config['display_phone'] ?? '',
                'token_saved'         => self::mask($config['token'] ?? null),
                'app_secret_saved'    => self::mask($config['app_secret'] ?? null),
                'verify_token'        => $edit ? ($config['verify_token'] ?? null) : self::mask($config['verify_token'] ?? null),
                'templates'           => (object) ($config['templates'] ?? []),
                'tested_at'           => $iso($config['tested_at'] ?? null),
                'test_message'        => $config['test_message'] ?? null,
                'saved_at'            => $iso($config['updated_at'] ?? null),
            ] : null,
            'notices'         => collect(Messaging::KINDS)->except(WhatsApp::EMAIL_ONLY_KINDS)
                ->map(fn ($def, $kind) => ['kind' => $kind, 'label' => $def['label'], 'vars' => collect($def['vars'])->map(fn ($v, $i) => ['position' => $i + 1, 'token' => "[{$v}]", 'label' => Messaging::VARIABLES[$v] ?? $v])->values()])->values(),
            'rules'           => self::rules($tenant),
            'assistant'       => [
                'provider'  => $provider->key(),
                'connected' => $provider->key() !== 'none',
                'stats'     => Assistant::stats($tenant),
            ],
            'waiting'         => DB::table('whatsapp_conversations')->where('tenant_id', $tenant->id)->where('status', 'needs_human')->count(),
        ];
    }

    public static function rules(Tenant $tenant): array
    {
        return [
            'channel'           => WhatsApp::channel($tenant),
            'consent'           => \App\Support\AppointmentFlow::rule($tenant, 'whatsapp.consent') === 'unless_revoked' ? 'unless_revoked' : 'required',
            'assistant_enabled' => Assistant::enabled($tenant),
            'blocked_words'     => (string) \App\Support\AppointmentFlow::rule($tenant, 'assistant.blocked_words'),
        ];
    }

    public function settings(Request $request)
    {
        $this->canView($request);

        return response()->json($this->payload($request, $this->tenant($request)));
    }

    public function save(Request $request)
    {
        $this->canEdit($request);
        $tenant = $this->tenant($request);
        $old = WhatsApp::config($tenant->id) ?? [];
        $kinds = array_keys(array_diff_key(Messaging::KINDS, array_flip(WhatsApp::EMAIL_ONLY_KINDS)));

        $validated = $request->validate([
            'provider'                => ['required', Rule::in(['simulated', 'cloud'])],
            'phone_number_id'         => 'required_if:provider,cloud|nullable|string|max:60|regex:/^\d+$/',
            'business_account_id'     => 'nullable|string|max:60|regex:/^\d+$/',
            'display_phone'           => 'nullable|string|max:30',
            'token'                   => 'nullable|string|max:1000',
            'app_secret'              => 'nullable|string|max:255',
            'verify_token'            => 'nullable|string|min:8|max:120|regex:/^[A-Za-z0-9._-]+$/',
            'templates'               => 'nullable|array',
            'templates.*.name'        => 'nullable|string|max:120|regex:/^[a-z0-9_]+$/',
            'templates.*.language'    => 'nullable|string|max:10|regex:/^[a-zA-Z_]+$/',
        ], [
            'phone_number_id.required_if' => 'Escribe el identificador del número de teléfono (Phone number ID) que te da Meta.',
            'phone_number_id.regex'       => 'El identificador del número va solo con números.',
            'business_account_id.regex'   => 'El identificador de la cuenta va solo con números.',
            'verify_token.min'            => 'La clave de verificación debe tener al menos 8 caracteres.',
            'verify_token.regex'          => 'La clave de verificación va sin espacios: letras, números, punto, guion o guion bajo.',
            'templates.*.name.regex'      => 'El nombre de la plantilla va como en Meta: minúsculas, números y guion bajo.',
        ]);

        $sameCloud = ($old['provider'] ?? null) === 'cloud';
        $token = trim((string) ($validated['token'] ?? '')) ?: ($sameCloud ? ($old['token'] ?? null) : null);
        $appSecret = trim((string) ($validated['app_secret'] ?? '')) ?: ($old['app_secret'] ?? null);
        $verify = trim((string) ($validated['verify_token'] ?? '')) ?: ($old['verify_token'] ?? Str::random(32));

        if ($validated['provider'] === 'cloud') {
            if (!$token) {
                throw ValidationException::withMessages(['token' => 'Pega el token de acceso que te da Meta.']);
            }

            if (!$appSecret) {
                throw ValidationException::withMessages(['app_secret' => 'Pega la clave secreta de la aplicación (App Secret): con ella se comprueba que los avisos vienen de Meta.']);
            }
        }

        $templates = collect($validated['templates'] ?? [])->only($kinds)
            ->map(fn ($t) => ['name' => trim((string) ($t['name'] ?? '')), 'language' => trim((string) ($t['language'] ?? '')) ?: 'es'])
            ->filter(fn ($t) => $t['name'] !== '')->all();

        $settings = $validated['provider'] === 'cloud'
            ? [
                'phone_number_id'     => trim($validated['phone_number_id']),
                'business_account_id' => trim((string) ($validated['business_account_id'] ?? '')) ?: null,
                'display_phone'       => trim((string) ($validated['display_phone'] ?? '')) ?: null,
                'templates'           => $templates,
            ]
            : ['templates' => $templates];

        $values = [
            'provider'    => $validated['provider'],
            'mode'        => $validated['provider'] === 'cloud' ? 'production' : 'simulated',
            'status'      => 'active',
            'settings'    => json_encode($settings),
            'credentials' => Crypt::encryptString(json_encode(array_filter([
                'token'        => $validated['provider'] === 'cloud' ? $token : null,
                'app_secret'   => $appSecret,
                'verify_token' => $verify,
            ]))),
        ];

        $current = WhatsApp::integration($tenant->id);

        if ($current) {
            DB::table('tenant_integrations')->where('id', $current->id)->update($values + ['updated_at' => now()]);
        } else {
            DB::table('tenant_integrations')->insert($values + ['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'kind' => 'whatsapp', 'created_at' => now(), 'updated_at' => now()]);
        }

        $after = $this->audit(['provider' => $validated['provider']] + $settings);

        if (trim((string) ($validated['token'] ?? '')) !== '') {
            $after['whatsapp_token'] = 'Cambiado';
        }

        if (trim((string) ($validated['app_secret'] ?? '')) !== '') {
            $after['whatsapp_app_secret'] = 'Cambiada';
        }

        [$from, $to] = Audit::changed($this->audit($old), $after);

        if ($to) {
            Audit::log($request, 'settings.changed', ['tenant' => $tenant->id, 'entity_type' => 'settings', 'entity_label' => 'WhatsApp', 'before' => $from, 'after' => $to]);
        }

        return response()->json($this->payload($request, $tenant));
    }

    private function audit(array $config): array
    {
        if (!$config) {
            return ['whatsapp_provider' => 'Sin conectar'];
        }

        return array_filter([
            'whatsapp_provider'  => ($config['provider'] ?? null) === 'cloud' ? 'WhatsApp de Meta' : 'Probar sin enviar',
            'whatsapp_number_id' => $config['phone_number_id'] ?? null,
            'whatsapp_account'   => $config['business_account_id'] ?? null,
            'whatsapp_phone'     => $config['display_phone'] ?? null,
            'whatsapp_templates' => !empty($config['templates']) ? collect($config['templates'])->map(fn ($t, $k) => (Messaging::KINDS[$k]['label'] ?? $k) . ': ' . $t['name'])->implode(', ') : null,
        ]);
    }

    public function remove(Request $request)
    {
        $this->canEdit($request);
        $tenant = $this->tenant($request);
        $old = WhatsApp::config($tenant->id);
        DB::table('tenant_integrations')->where('tenant_id', $tenant->id)->where('kind', 'whatsapp')->delete();

        if ($old) {
            Audit::log($request, 'settings.changed', [
                'tenant' => $tenant->id, 'entity_type' => 'settings', 'entity_label' => 'WhatsApp',
                'before' => ['whatsapp_provider' => $this->audit($old)['whatsapp_provider']], 'after' => ['whatsapp_provider' => 'Sin conectar'],
            ]);
        }

        return response()->json($this->payload($request, $tenant));
    }

    public function test(Request $request)
    {
        $this->canEdit($request);
        $tenant = $this->tenant($request);
        $to = $request->validate(['to' => 'nullable|string|max:30|regex:/^[0-9+\s()-]+$/'], ['to.regex' => 'Escribe el celular solo con números.'])['to'] ?? null;
        $config = WhatsApp::config($tenant->id);

        if (!$config) {
            throw ValidationException::withMessages(['to' => 'Primero guarda los datos de WhatsApp.']);
        }

        $provider = WhatsApp::provider($config);
        $result = $provider->check($config);

        if ($result['ok'] && $to) {
            $phone = WhatsApp::phone($to);

            if ($phone === '') {
                throw ValidationException::withMessages(['to' => 'Ese celular no es válido.']);
            }

            try {
                $provider->sendTemplate($config, $phone, 'hello_world', 'en_US', []);
                $result['message'] .= " Se envió la plantilla de prueba de Meta (hello_world) a {$phone}.";
            } catch (\Throwable $e) {
                $result = ['ok' => false, 'message' => $e instanceof \App\Support\WhatsApp\SendFailed ? $e->getMessage() : 'WhatsApp respondió con un error al enviar la prueba.'];
            }
        }

        if (!WhatsApp::simulated($config)) {
            $row = WhatsApp::integration($tenant->id);
            $settings = is_string($row->settings) ? (json_decode($row->settings, true) ?: []) : (array) $row->settings;
            $settings['test_ok'] = $result['ok'];
            $settings['tested_at'] = now()->toIso8601String();
            $settings['test_message'] = $result['message'];

            if (!empty($result['display']) && empty($settings['display_phone'])) {
                $settings['display_phone'] = $result['display'];
            }

            DB::table('tenant_integrations')->where('id', $row->id)->update(['settings' => json_encode($settings)]);
        }

        return response()->json(['ok' => $result['ok'], 'message' => $result['message']] + $this->payload($request, $tenant));
    }

    public function saveRules(Request $request)
    {
        $this->canEdit($request);
        $tenant = $this->tenant($request);

        $validated = $request->validate([
            'channel'           => ['required', Rule::in(WhatsApp::CHANNELS)],
            'consent'           => ['required', Rule::in(WhatsApp::CONSENT_MODES)],
            'assistant_enabled' => 'required|boolean',
            'blocked_words'     => 'nullable|string|max:3000',
        ]);

        $before = self::rules($tenant);
        $settings = $tenant->settings;
        abort_unless($settings, 422, 'Tu empresa aún no tiene su configuración creada.');
        $settings->rules = array_merge($settings->rules ?? [], [
            'whatsapp.channel'        => $validated['channel'],
            'whatsapp.consent'        => $validated['consent'],
            'assistant.enabled'       => (bool) $validated['assistant_enabled'],
            'assistant.blocked_words' => trim((string) ($validated['blocked_words'] ?? '')),
        ]);
        $settings->save();

        $fresh = $tenant->fresh('settings');
        $label = fn (array $r) => [
            'whatsapp_channel'  => self::RULE_LABELS[$r['channel']],
            'whatsapp_consent'  => self::RULE_LABELS[$r['consent']],
            'assistant_enabled' => $r['assistant_enabled'] ? 'Encendido' : 'Apagado',
            'blocked_words'     => $r['blocked_words'],
        ];
        [$from, $to] = Audit::changed($label($before), $label(self::rules($fresh)));

        if ($to) {
            Audit::log($request, 'settings.changed', ['tenant' => $tenant->id, 'entity_type' => 'settings', 'entity_label' => 'WhatsApp y asistente', 'before' => $from, 'after' => $to]);
        }

        return response()->json($this->payload($request, $fresh));
    }

    private function find(Tenant $tenant, string $id): object
    {
        $row = DB::table('whatsapp_conversations')->where('tenant_id', $tenant->id)->where('id', $id)->first();
        abort_unless($row, 404);

        return $row;
    }

    public function conversations(Request $request)
    {
        $tenant = $this->tenant($request);
        $rows = DB::table('whatsapp_conversations')->where('tenant_id', $tenant->id)->orderByDesc('last_message_at')->limit(500)->get();
        $customers = DB::table('customers')->where('tenant_id', $tenant->id)->whereIn('id', $rows->pluck('customer_id')->filter()->unique()->all())->get(['id', 'first_name', 'last_name'])->keyBy('id');
        $last = DB::table('whatsapp_messages')->whereIn('id', function ($q) use ($rows) {
            $q->selectRaw('distinct on (conversation_id) id')->from('whatsapp_messages')->whereIn('conversation_id', $rows->pluck('id')->all())->orderBy('conversation_id')->orderByDesc('created_at');
        })->get()->keyBy('conversation_id');

        return response()->json([
            'conversations' => $rows->map(fn ($c) => WhatsApp::presentConversation($c, $customers[$c->customer_id] ?? null, $last[$c->id] ?? null))->values(),
            'status'        => self::status($tenant),
            'can'           => ['reply' => $request->user()->allows('messages.reply'), 'settings' => $request->user()->allows('settings.edit')],
        ]);
    }

    private function thread(Request $request, Tenant $tenant, object $conversation): array
    {
        $customer = $conversation->customer_id ? DB::table('customers')->where('tenant_id', $tenant->id)->where('id', $conversation->customer_id)->first(['id', 'first_name', 'last_name', 'phone']) : null;
        $messages = DB::table('whatsapp_messages as w')->leftJoin('users as u', 'u.id', '=', 'w.sent_by')
            ->where('w.tenant_id', $tenant->id)->where('w.conversation_id', $conversation->id)
            ->orderBy('w.created_at')->limit(500)->get(['w.*', 'u.first_name as by_first', 'u.last_name as by_last']);
        $logs = Assistant::ready() ? DB::table('assistant_logs')->where('tenant_id', $tenant->id)->where('conversation_id', $conversation->id)->orderByDesc('created_at')->limit(50)->get() : collect();

        return [
            'conversation' => WhatsApp::presentConversation($conversation, $customer),
            'messages'     => $messages->map(fn ($m) => WhatsApp::presentMessage($m, trim("{$m->by_first} {$m->by_last}") ?: null))->values(),
            'assistant'    => $logs->map(fn ($l) => [
                'id'     => $l->id,
                'action' => $l->action,
                'label'  => Assistant::ACTION_LABELS[$l->action] ?? $l->action,
                'reason' => $l->reason,
                'at'     => Carbon::parse($l->created_at)->toIso8601String(),
            ])->values(),
            'consent'      => $conversation->customer_id ? (WhatsApp::revoked($conversation->customer_id) ? 'revoked' : (WhatsApp::consentAllows($tenant, $conversation->customer_id) ? 'ok' : 'missing')) : 'unknown',
            'status'       => self::status($tenant),
            'can'          => ['reply' => $request->user()->allows('messages.reply')],
        ];
    }

    public function conversation(Request $request, string $conversation)
    {
        $tenant = $this->tenant($request);
        $row = $this->find($tenant, $conversation);

        if ($row->unread > 0) {
            DB::table('whatsapp_conversations')->where('id', $row->id)->update(['unread' => 0]);
            $row->unread = 0;
        }

        return response()->json($this->thread($request, $tenant, $row));
    }

    public function reply(Request $request, string $conversation)
    {
        $tenant = $this->tenant($request);
        $row = $this->find($tenant, $conversation);
        $body = $request->validate(['body' => 'required|string|max:4000'], ['body.required' => 'Escribe el mensaje.'])['body'];
        $sent = WhatsApp::reply($tenant, $row, $body, $request->user());
        $customer = $row->customer_id ? DB::table('customers')->where('id', $row->customer_id)->first(['first_name', 'last_name']) : null;

        Audit::log($request, 'whatsapp.replied', [
            'tenant'       => $tenant->id,
            'entity_type'  => 'whatsapp_conversation',
            'entity_id'    => $row->id,
            'entity_label' => $customer ? Person::name($customer) : $row->phone,
            'after'        => ['status' => WhatsApp::STATUS_LABELS[$sent->status] ?? $sent->status],
        ]);

        $payload = $this->thread($request, $tenant, DB::table('whatsapp_conversations')->where('id', $row->id)->first());

        if ($sent->status === 'failed') {
            return response()->json(['message' => $sent->error ?? 'No se pudo enviar.'] + $payload, 422);
        }

        return response()->json($payload);
    }

    public function resolve(Request $request, string $conversation)
    {
        $tenant = $this->tenant($request);
        $row = $this->find($tenant, $conversation);

        if ($row->status !== 'needs_human') {
            throw ValidationException::withMessages(['conversation' => 'Esta conversación no estaba esperando a una persona.']);
        }

        DB::table('whatsapp_conversations')->where('id', $row->id)->update(['status' => 'open', 'handoff_reason' => null, 'handoff_at' => null, 'unread' => 0, 'updated_at' => now()]);

        Audit::log($request, 'whatsapp.handled', [
            'tenant'       => $tenant->id,
            'entity_type'  => 'whatsapp_conversation',
            'entity_id'    => $row->id,
            'entity_label' => $row->contact_name ?: $row->phone,
            'before'       => ['status' => WhatsApp::CONVERSATION_LABELS['needs_human']],
            'after'        => ['status' => WhatsApp::CONVERSATION_LABELS['open']],
        ]);

        return response()->json($this->thread($request, $tenant, DB::table('whatsapp_conversations')->where('id', $row->id)->first()));
    }
}
