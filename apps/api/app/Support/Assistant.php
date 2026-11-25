<?php

namespace App\Support;

use App\Http\Controllers\Api\ChatBookingController;
use App\Models\Customer;
use App\Models\Tenant;
use App\Support\Assistant\FakeProvider;
use App\Support\Assistant\NoneProvider;
use App\Support\Assistant\Provider;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Assistant
{
    public const HANDOFF_TEXT = 'Gracias por escribirnos. Una persona de nuestro equipo te responderá en breve.';

    public const ACTION_LABELS = [
        'reply'   => 'Respondió',
        'handoff' => 'Pasó a una persona',
        'blocked' => 'Consulta de salud: pasó a una persona',
        'booking' => 'Reservó una cita',
        'acted'   => 'Cambió o confirmó una cita',
        'none'    => 'Sin proveedor: no respondió',
        'error'   => 'Tuvo un problema',
    ];

    public static ?Provider $provider = null;

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('assistant_logs');
    }

    public const AI_PROVIDERS = [
        'anthropic' => ['label' => 'Claude (Anthropic)', 'class' => \App\Support\Assistant\AnthropicProvider::class, 'model' => 'services.assistant.anthropic_model'],
        'openai'    => ['label' => 'OpenAI', 'class' => \App\Support\Assistant\OpenAiProvider::class, 'model' => 'services.assistant.openai_model'],
    ];

    public const QUOTA_LIMIT = 'assistant_month';

    public static function decode(?object $row): ?array
    {
        if (!$row || !isset(self::AI_PROVIDERS[$row->provider])) {
            return null;
        }

        $settings = is_string($row->settings) ? (json_decode($row->settings, true) ?: []) : (array) $row->settings;

        try {
            $secret = $row->credentials ? (json_decode(Crypt::decryptString($row->credentials), true) ?: []) : [];
        } catch (\Throwable $e) {
            $secret = [];
        }

        return empty($secret['api_key']) ? null : $settings + ['provider' => $row->provider, 'api_key' => $secret['api_key'], 'updated_at' => $row->updated_at];
    }

    public static function tenantConfig(string $tenantId): ?array
    {
        return self::decode(Schema::hasTable('tenant_integrations') ? DB::table('tenant_integrations')->where('tenant_id', $tenantId)->where('kind', 'ai')->first() : null);
    }

    public static function platformConfig(): ?array
    {
        return self::decode(Schema::hasTable('platform_integrations') ? DB::table('platform_integrations')->where('kind', 'ai')->first() : null);
    }

    public static function configFor(?Tenant $tenant): ?array
    {
        $own = $tenant ? self::tenantConfig($tenant->id) : null;

        return $own ? $own + ['source' => 'company'] : (($platform = self::platformConfig()) ? $platform + ['source' => 'platform'] : null);
    }

    public static function build(array $config): Provider
    {
        $def = self::AI_PROVIDERS[$config['provider']];
        $model = trim((string) ($config['model'] ?? '')) ?: (string) config($def['model']);

        return new $def['class']((string) $config['api_key'], $model);
    }

    public static function provider(?Tenant $tenant = null): Provider
    {
        if (self::$provider) {
            return self::$provider;
        }

        if ($config = self::configFor($tenant)) {
            return self::build($config);
        }

        return match (config('services.assistant.provider')) {
            'fake'  => app()->environment('production') ? new NoneProvider() : new FakeProvider(),
            default => new NoneProvider(),
        };
    }

    public static function quota(Tenant $tenant): array
    {
        $value = $tenant->activeSubscription?->plan?->limits[self::QUOTA_LIMIT] ?? null;
        $limit = is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
        $used = self::ready() ? DB::table('assistant_logs')->where('tenant_id', $tenant->id)
            ->where('action', 'reply')->whereIn('provider', array_keys(self::AI_PROVIDERS))
            ->where('created_at', '>=', MessageQuota::monthStart($tenant)->toIso8601String())->count() : 0;

        return ['limit' => $limit, 'used' => $used, 'reached' => $limit !== null && $used >= $limit];
    }

    public static function enabled(Tenant $tenant): bool
    {
        return (bool) AppointmentFlow::rule($tenant, 'assistant.enabled');
    }

    public static function plain(string $text): string
    {
        return str_replace(['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'u', 'n'], mb_strtolower($text));
    }

    public static function blockedWords(Tenant $tenant): array
    {
        $raw = (string) AppointmentFlow::rule($tenant, 'assistant.blocked_words');

        return collect(preg_split('/[,\n;]+/', $raw) ?: [])->map(fn ($w) => trim(self::plain($w)))->filter(fn ($w) => mb_strlen($w) >= 3)->unique()->values()->all();
    }

    public static function clinicalWord(Tenant $tenant, string $text): ?string
    {
        $plain = self::plain($text);

        foreach (self::blockedWords($tenant) as $word) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '/u', $plain)) {
                return $word;
            }
        }

        return null;
    }

    public static function handle(Tenant $tenant, object $conversation, object $inbound): ?string
    {
        if (!self::enabled($tenant) || !self::ready() || $conversation->status === 'needs_human' || $inbound->direction !== 'in') {
            return null;
        }

        $tenant->loadMissing('activeSubscription.plan');
        $provider = self::provider($tenant);
        $text = (string) $inbound->body;

        if ($word = self::clinicalWord($tenant, $text)) {
            self::handoff($tenant, $conversation, "Consulta de salud (\"{$word}\"): el asistente no responde estos temas.", true);
            self::log($tenant, $conversation, $inbound, $provider->key(), 'blocked', null, "Palabra bloqueada: {$word}");

            return 'blocked';
        }

        if (isset(self::AI_PROVIDERS[$provider->key()]) && self::quota($tenant)['reached']) {
            self::handoff($tenant, $conversation, 'El asistente llegó al tope de respuestas del mes de tu plan: responde una persona.', true);
            self::log($tenant, $conversation, $inbound, $provider->key(), 'handoff', null, 'Tope del plan alcanzado');

            return 'handoff';
        }

        $started = self::episode($conversation, $inbound, self::handoffMinutes($tenant));

        if ($started && $started->lt(now()->subMinutes(self::handoffMinutes($tenant)))) {
            $reason = 'Pasaron más de ' . self::handoffMinutes($tenant) . ' minutos sin resolver la conversación: sigue una persona.';
            self::handoff($tenant, $conversation, $reason, true);
            self::log($tenant, $conversation, $inbound, $provider->key(), 'handoff', null, $reason);

            return 'handoff';
        }

        $customer = $conversation->customer_id ? Customer::where('tenant_id', $tenant->id)->find($conversation->customer_id) : null;
        $tools = new \App\Support\Assistant\Tools($tenant, $customer, $conversation);

        try {
            $answer = $provider->answer(self::context($tenant, $conversation, $inbound) + ['tools' => $tools]);
        } catch (\Throwable $e) {
            report($e);
            self::handoff($tenant, $conversation, 'El asistente tuvo un problema al responder.', true);
            self::log($tenant, $conversation, $inbound, $provider->key(), 'error', null, mb_substr($e->getMessage(), 0, 250));
            IntegrationLog::write($tenant->id, 'ai', $provider->key(), 'answer', false, $e->getMessage());

            return 'error';
        }

        if (!empty($answer['done'])) {
            self::resolved($conversation);
        }

        foreach ((array) ($answer['done'] ?? []) as $done) {
            self::log($tenant, $conversation, $inbound, $provider->key(), $done['action'] === 'booking' || !WhatsApp::mediaReady() ? 'booking' : 'acted', null, mb_substr((['booking' => 'Reservó la cita ', 'move' => 'Reprogramó la cita ', 'cancel' => 'Canceló la cita ', 'confirm' => 'Confirmó la cita '][$done['action']] ?? '') . ($done['code'] ?? ''), 0, 255));
        }

        $action = (string) ($answer['action'] ?? 'none');

        if ($action === 'handoff' && !empty($answer['text']) && WhatsApp::windowOpen($conversation) && !self::clinicalWord($tenant, (string) $answer['text'])) {
            WhatsApp::reply($tenant, $conversation, (string) $answer['text'], null, true);
            self::handoff($tenant, $conversation, (string) ($answer['reason'] ?? 'El asistente pidió ayuda de una persona.'), false);
            self::log($tenant, $conversation, $inbound, $provider->key(), 'handoff', $answer['text'], $answer['reason'] ?? null);

            return 'handoff';
        }

        if ($action === 'reply' && trim((string) ($answer['text'] ?? '')) !== '') {
            if (self::clinicalWord($tenant, (string) $answer['text'])) {
                self::handoff($tenant, $conversation, 'La respuesta del asistente tocaba un tema de salud: no se envió.', true);
                self::log($tenant, $conversation, $inbound, $provider->key(), 'blocked', $answer['text'], 'Respuesta con palabra bloqueada, no enviada');

                return 'blocked';
            }

            $sent = WhatsApp::reply($tenant, $conversation, (string) $answer['text'], null, true);
            self::log($tenant, $conversation, $inbound, $provider->key(), 'reply', $sent->body, $answer['reason'] ?? null, $sent->id);

            return 'reply';
        }

        if ($action === 'handoff') {
            $reason = (string) ($answer['reason'] ?? 'El cliente pidió hablar con una persona.');
            self::handoff($tenant, $conversation, $reason, true);
            self::log($tenant, $conversation, $inbound, $provider->key(), 'handoff', null, $reason);

            return 'handoff';
        }

        if ($action === 'book') {
            return self::book($tenant, $conversation, $inbound, $provider->key(), (array) ($answer['booking'] ?? []), $answer['text'] ?? null);
        }

        self::log($tenant, $conversation, $inbound, $provider->key(), 'none', null, $answer['reason'] ?? null);

        return 'none';
    }

    public static function context(Tenant $tenant, object $conversation, object $inbound): array
    {
        $customer = $conversation->customer_id ? Customer::find($conversation->customer_id) : null;

        return [
            'company'      => Messaging::companyName($tenant),
            'conversation' => $conversation->id,
            'customer'     => $customer ? ['id' => $customer->id, 'name' => Person::name($customer)] : null,
            'text'         => (string) $inbound->body,
            'history'      => DB::table('whatsapp_messages')->where('conversation_id', $conversation->id)->orderByDesc('created_at')->limit(10)->get(['direction', 'body', 'by_assistant'])
                ->reverse()->map(fn ($m) => ['from' => $m->direction === 'in' ? 'customer' : ($m->by_assistant ? 'assistant' : 'staff'), 'text' => $m->body])->values()->all(),
            'can_book'     => (bool) $customer,
        ];
    }

    public static function book(Tenant $tenant, object $conversation, object $inbound, string $provider, array $booking, ?string $text = null): string
    {
        $customer = $conversation->customer_id ? Customer::where('tenant_id', $tenant->id)->find($conversation->customer_id) : null;

        if (!$customer) {
            self::handoff($tenant, $conversation, 'Quiere reservar, pero su número no está unido a un cliente.', true);
            self::log($tenant, $conversation, $inbound, $provider, 'handoff', null, 'Reserva sin cliente unido');

            return 'handoff';
        }

        try {
            $response = app(ChatBookingController::class)->bookFromChat($tenant, $customer, $booking);
            $data = $response->getData(true);

            if ($response->getStatusCode() >= 400) {
                throw ValidationException::withMessages(['booking' => $data['message'] ?? 'No se pudo reservar.']);
            }
        } catch (\Throwable $e) {
            $reason = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : 'No se pudo reservar.';
            self::handoff($tenant, $conversation, 'No se pudo reservar por chat: ' . $reason, true);
            self::log($tenant, $conversation, $inbound, $provider, 'handoff', null, mb_substr('Reserva fallida: ' . $reason, 0, 255));

            return 'handoff';
        }

        self::resolved($conversation);
        $sent = $text ? WhatsApp::reply($tenant, $conversation, $text, null, true) : null;
        self::log($tenant, $conversation, $inbound, $provider, 'booking', $sent?->body, 'Cita ' . ($data['code'] ?? $data['id'] ?? ''), $sent?->id);

        return 'booking';
    }

    public static function handoff(Tenant $tenant, object $conversation, string $reason, bool $tellCustomer = false): void
    {
        $at = now();
        DB::table('whatsapp_conversations')->where('id', $conversation->id)->update([
            'status' => 'needs_human', 'handoff_reason' => mb_substr($reason, 0, 255), 'handoff_at' => $at, 'updated_at' => $at,
        ] + (self::episodesReady() ? ['handoff_summary' => self::summary($conversation), 'assistant_started_at' => null] : []));

        $name = $conversation->contact_name ?: $conversation->phone;

        if ($conversation->customer_id && ($customer = Customer::find($conversation->customer_id))) {
            $name = Person::name($customer);
        }

        foreach (Notifier::staffWith($tenant->id, 'messages.reply', null) as $userId) {
            Notifier::send($tenant->id, $userId, 'whatsapp_handoff', "{$name} necesita una persona en WhatsApp", $reason, "/messages/whatsapp/{$conversation->id}", 'whatsapp_conversation', $conversation->id, "wa-handoff:{$conversation->id}:{$at->timestamp}");
        }

        if ($tellCustomer && WhatsApp::windowOpen($conversation)) {
            try {
                WhatsApp::reply($tenant, $conversation, self::HANDOFF_TEXT, null, true);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    public static function log(Tenant $tenant, object $conversation, ?object $inbound, string $provider, string $action, ?string $reply, ?string $reason, ?string $replyId = null): void
    {
        DB::table('assistant_logs')->insert([
            'id'              => (string) Str::uuid(),
            'tenant_id'       => $tenant->id,
            'conversation_id' => $conversation->id,
            'inbound_id'      => $inbound?->id,
            'reply_id'        => $replyId,
            'provider'        => $provider,
            'action'          => $action,
            'question'        => $inbound?->body,
            'reply'           => $reply,
            'reason'          => $reason ? mb_substr($reason, 0, 255) : null,
            'created_at'      => now(),
        ]);
    }

    private static ?bool $episodes = null;

    public static function episodesReady(): bool
    {
        return self::$episodes ??= Schema::hasColumn('whatsapp_conversations', 'assistant_started_at');
    }

    public static function handoffMinutes(Tenant $tenant): int
    {
        $value = AppointmentFlow::rule($tenant, 'assistant.handoff_minutes');

        return is_numeric($value) ? max(1, min(120, (int) $value)) : 10;
    }

    public static function episode(object $conversation, object $inbound, int $minutes): ?\Illuminate\Support\Carbon
    {
        if (!self::episodesReady()) {
            return null;
        }

        $previous = DB::table('whatsapp_messages')->where('conversation_id', $conversation->id)->where('id', '!=', $inbound->id)->orderByDesc('created_at')->value('created_at');
        $gap = max(30, $minutes);
        $started = $conversation->assistant_started_at ?? null;

        if (!$started || !$previous || \Illuminate\Support\Carbon::parse($previous)->lt(now()->subMinutes($gap))) {
            DB::table('whatsapp_conversations')->where('id', $conversation->id)->update(['assistant_started_at' => now()]);

            return now();
        }

        return \Illuminate\Support\Carbon::parse($started);
    }

    public static function resolved(object $conversation): void
    {
        if (self::episodesReady()) {
            DB::table('whatsapp_conversations')->where('id', $conversation->id)->update(['assistant_started_at' => null]);
        }
    }

    public static function summary(object $conversation): string
    {
        $messages = DB::table('whatsapp_messages')->where('conversation_id', $conversation->id)->orderByDesc('created_at')->limit(8)->get(['direction', 'body', 'by_assistant'])->reverse();
        $said = $messages->where('direction', 'in')->pluck('body')->map(fn ($b) => '«' . mb_substr(trim((string) $b), 0, 120) . '»')->filter(fn ($b) => $b !== '«»')->take(-4)->implode(' ');
        $answered = $messages->where('direction', 'out')->pluck('body')->last();
        $text = 'El cliente escribió: ' . ($said ?: '(sin texto)') . '.';

        if ($answered) {
            $text .= ' Lo último que se le respondió: «' . mb_substr(trim((string) $answered), 0, 160) . '».';
        }

        return mb_substr($text, 0, 1000);
    }

    public static function sweep(): int
    {
        if (!self::ready() || !self::episodesReady() || !WhatsApp::ready()) {
            return 0;
        }

        $done = 0;
        $rows = Rls::bypass(fn () => DB::table('whatsapp_conversations as c')
            ->where('c.status', 'open')
            ->whereNotNull('c.last_inbound_at')
            ->where('c.last_inbound_at', '<', now()->subMinute())
            ->where('c.last_inbound_at', '>', now()->subDay())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('whatsapp_messages as m')->whereColumn('m.conversation_id', 'c.id')->where('m.direction', 'out')->whereColumn('m.created_at', '>=', 'c.last_inbound_at'))
            ->limit(500)->get(['c.*']));

        foreach ($rows->groupBy('tenant_id') as $tenantId => $conversations) {
            $tenant = Tenant::with('settings')->find($tenantId);

            if (!$tenant || !self::enabled($tenant)) {
                continue;
            }

            $minutes = self::handoffMinutes($tenant);

            foreach ($conversations as $conversation) {
                if (\Illuminate\Support\Carbon::parse($conversation->last_inbound_at)->gt(now()->subMinutes($minutes))) {
                    continue;
                }

                Rls::asTenant($tenant->id, function () use ($tenant, $conversation, $minutes) {
                    $reason = "Nadie respondió al cliente en {$minutes} minutos: sigue una persona.";
                    self::handoff($tenant, $conversation, $reason, true);
                    self::log($tenant, $conversation, null, 'system', 'handoff', null, $reason);
                });
                $done++;
            }
        }

        return $done;
    }

    public static function stats(Tenant $tenant, int $days = 30): array
    {
        if (!self::ready()) {
            return [];
        }

        $counts = DB::table('assistant_logs')->where('tenant_id', $tenant->id)->where('created_at', '>=', now()->subDays($days)->toIso8601String())
            ->groupBy('action')->selectRaw('action, count(*) as total')->pluck('total', 'action');

        return collect(self::ACTION_LABELS)->map(fn ($label, $action) => ['action' => $action, 'label' => $label, 'total' => (int) ($counts[$action] ?? 0)])->values()->all();
    }
}
