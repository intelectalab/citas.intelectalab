<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Support\WhatsApp\CloudApiProvider;
use App\Support\WhatsApp\Provider;
use App\Support\WhatsApp\SendFailed;
use App\Support\WhatsApp\SimulatedProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WhatsApp
{
    public const NOT_READY = 'Falta actualizar la base de datos para usar WhatsApp: ejecuta php artisan migrate.';

    public const WINDOW_HOURS = 24;

    public const CHANNELS = ['email', 'whatsapp', 'both'];

    public const CONSENT_MODES = ['required', 'unless_revoked'];

    public const EMAIL_ONLY_KINDS = ['payment_link'];

    public const STATUS_ORDER = ['queued' => 0, 'simulated' => 1, 'sent' => 1, 'delivered' => 2, 'read' => 3];

    public const STATUS_LABELS = [
        'received'  => 'Recibido',
        'queued'    => 'Por enviar',
        'sent'      => 'Enviado',
        'simulated' => 'Simulado',
        'delivered' => 'Entregado',
        'read'      => 'Leído',
        'failed'    => 'Falló',
    ];

    public const CONVERSATION_LABELS = [
        'open'        => 'Abierta',
        'needs_human' => 'Necesita una persona',
        'closed'      => 'Cerrada',
    ];

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('whatsapp_conversations') && Schema::hasTable('whatsapp_messages') && Schema::hasColumn('messages', 'meta');
    }

    public static function integration(string $tenantId): ?object
    {
        return DB::table('tenant_integrations')->where('tenant_id', $tenantId)->where('kind', 'whatsapp')->first();
    }

    public static function config(string $tenantId): ?array
    {
        $row = self::integration($tenantId);

        if (!$row) {
            return null;
        }

        $settings = is_string($row->settings) ? (json_decode($row->settings, true) ?: []) : (array) $row->settings;
        $secret = [];

        if ($row->credentials) {
            try {
                $secret = json_decode(Crypt::decryptString($row->credentials), true) ?: [];
            } catch (\Throwable $e) {
                $secret = [];
            }
        }

        return $settings + [
            'provider'     => $row->provider,
            'token'        => $secret['token'] ?? null,
            'app_secret'   => $secret['app_secret'] ?? null,
            'verify_token' => $secret['verify_token'] ?? null,
            'updated_at'   => $row->updated_at,
        ];
    }

    public static function connected(string $tenantId): bool
    {
        if (!self::ready()) {
            return false;
        }

        $config = self::config($tenantId);

        return $config && ($config['provider'] === 'simulated' || (!empty($config['token']) && !empty($config['phone_number_id'])));
    }

    public static function simulated(array $config): bool
    {
        return $config['provider'] === 'simulated' || (bool) config('services.whatsapp.simulate');
    }

    public static function provider(array $config): Provider
    {
        return self::simulated($config) ? new SimulatedProvider() : new CloudApiProvider();
    }

    public static function channel(Tenant $tenant): string
    {
        $value = (string) AppointmentFlow::rule($tenant, 'whatsapp.channel');

        return in_array($value, self::CHANNELS, true) ? $value : 'email';
    }

    public static function phone(?string $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        if (strlen($digits) === 9 && str_starts_with($digits, '9')) {
            $digits = '51' . $digits;
        }

        return strlen($digits) >= 8 ? $digits : '';
    }

    public static function consent(string $customerId): ?object
    {
        return DB::table('customer_consents')->where('customer_id', $customerId)->where('channel', 'whatsapp')->first();
    }

    public static function consentAllows(Tenant $tenant, ?string $customerId): bool
    {
        if (!$customerId) {
            return false;
        }

        $row = self::consent($customerId);

        if (AppointmentFlow::rule($tenant, 'whatsapp.consent') === 'unless_revoked') {
            return !$row || (bool) $row->granted;
        }

        return $row && (bool) $row->granted;
    }

    public static function revoked(?string $customerId): bool
    {
        $row = $customerId ? self::consent($customerId) : null;

        return $row && !$row->granted && $row->revoked_at;
    }

    public static function windowOpen(?object $conversation): bool
    {
        return $conversation && $conversation->last_inbound_at && Carbon::parse($conversation->last_inbound_at)->gt(now()->subHours(self::WINDOW_HOURS));
    }

    public static function template(array $config, string $kind): ?array
    {
        $template = $config['templates'][$kind] ?? null;

        return $template && !empty($template['name']) ? ['name' => $template['name'], 'language' => $template['language'] ?? 'es'] : null;
    }

    public static function conversationByPhone(string $tenantId, string $phone): ?object
    {
        return DB::table('whatsapp_conversations')->where('tenant_id', $tenantId)->where('phone', $phone)->first();
    }

    public static function conversationFor(Tenant $tenant, string $phone, ?string $customerId = null, ?string $name = null): object
    {
        $current = self::conversationByPhone($tenant->id, $phone);

        if ($current) {
            $changes = array_filter([
                'customer_id'  => !$current->customer_id && $customerId ? $customerId : null,
                'contact_name' => $name && $name !== $current->contact_name ? mb_substr($name, 0, 190) : null,
            ]);

            if ($changes) {
                DB::table('whatsapp_conversations')->where('id', $current->id)->update($changes + ['updated_at' => now()]);

                return DB::table('whatsapp_conversations')->where('id', $current->id)->first();
            }

            return $current;
        }

        if (!$customerId) {
            $customerId = PublicBooking::findCustomer($tenant, ['phone' => $phone])?->id;
        }

        $id = (string) Str::uuid();
        DB::table('whatsapp_conversations')->insertOrIgnore([
            'id'           => $id,
            'tenant_id'    => $tenant->id,
            'customer_id'  => $customerId,
            'phone'        => $phone,
            'contact_name' => $name ? mb_substr($name, 0, 190) : null,
            'status'       => 'open',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return self::conversationByPhone($tenant->id, $phone);
    }

    public static function blocker(Tenant $tenant, ?Customer $customer, string $kind): ?string
    {
        if (!self::ready() || in_array($kind, self::EMAIL_ONLY_KINDS, true)) {
            return 'Este aviso solo sale por correo.';
        }

        if (!self::connected($tenant->id)) {
            return 'Tu empresa aún no conecta WhatsApp.';
        }

        if (!$customer) {
            return 'No hay cliente.';
        }

        $phone = self::phone($customer->phone);

        if ($phone === '') {
            return 'El cliente no tiene un celular registrado.';
        }

        if (!self::consentAllows($tenant, $customer->id)) {
            return 'El cliente no aceptó recibir mensajes por WhatsApp.';
        }

        $config = self::config($tenant->id);

        if (!self::windowOpen(self::conversationByPhone($tenant->id, $phone)) && !self::template($config, $kind)) {
            return 'Pasaron más de 24 horas desde que el cliente escribió y este aviso no tiene una plantilla aprobada de WhatsApp.';
        }

        return null;
    }

    public static function store(Tenant $tenant, array $data): ?object
    {
        $customer = $data['customer'];
        $phone = self::phone($customer?->phone);
        $scheduled = $data['scheduled_at'] ?? null;
        $dedupe = $data['dedupe_key'] ? 'wa:' . $data['dedupe_key'] : null;

        if ($dedupe && DB::table('messages')->where('tenant_id', $tenant->id)->where('dedupe_key', $dedupe)->exists()) {
            return null;
        }

        $status = $scheduled && Carbon::parse($scheduled)->isFuture() ? 'scheduled' : 'queued';
        $id = (string) Str::uuid();

        DB::table('messages')->insertOrIgnore([
            'id'           => $id,
            'tenant_id'    => $tenant->id,
            'customer_id'  => $customer?->id,
            'branch_id'    => $data['branch_id'] ?? null,
            'channel'      => 'whatsapp',
            'kind'         => $data['kind'],
            'status'       => $status,
            'to_address'   => $phone ?: null,
            'to_name'      => $customer ? Person::name($customer) : null,
            'subject'      => mb_substr((string) $data['subject'], 0, 255),
            'body'         => $data['body'],
            'scheduled_at' => $scheduled ? Carbon::parse($scheduled)->utc() : null,
            'entity_type'  => $data['entity_type'] ?? null,
            'entity_id'    => $data['entity_id'] ?? null,
            'dedupe_key'   => $dedupe,
            'meta'         => json_encode(['vars' => $data['vars'] ?? [], 'fallback' => (bool) ($data['fallback'] ?? false), 'email_dedupe' => $data['dedupe_key'] ?? null] + (empty($data['template']) ? [] : ['template' => $data['template']])),
            'created_by'   => $data['user']?->id,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        if ($status === 'queued') {
            Messaging::kick($tenant->id);
        }

        return DB::table('messages')->where('id', $id)->first();
    }

    private static function meta(object $message): array
    {
        return is_string($message->meta ?? null) ? (json_decode($message->meta, true) ?: []) : (array) ($message->meta ?? []);
    }

    public static function deliver(object $message): string
    {
        $tenant = Tenant::find($message->tenant_id);
        $config = self::config($message->tenant_id);
        $customer = $message->customer_id ? Customer::find($message->customer_id) : null;
        $phone = self::phone($customer?->phone ?? $message->to_address);

        $stop = match (true) {
            !$tenant || !$config || !self::connected($message->tenant_id) => 'No enviado: tu empresa aún no conecta WhatsApp (Configuración → Mensajes → WhatsApp).',
            $phone === ''                                                => 'No enviado: el cliente no tiene un celular registrado.',
            $customer && !self::consentAllows($tenant, $customer->id)    => 'No enviado: el cliente no aceptó recibir mensajes por WhatsApp.',
            ($capped = MessageQuota::reached($tenant, 'whatsapp'))      => MessageQuota::stopReason($tenant, 'whatsapp'),
            default                                                      => null,
        };

        if ($stop) {
            if (!empty($capped)) {
                MessageQuota::warn($tenant, 'whatsapp');
            }

            DB::table('messages')->where('id', $message->id)->update(['status' => 'not_sent', 'error' => $stop, 'updated_at' => now()]);
            self::fallback($message, $stop);

            return 'not_sent';
        }

        $conversation = self::conversationFor($tenant, $phone, $customer?->id);
        $provider = self::provider($config);
        $template = self::meta($message)['template'] ?? null;

        if (!$template && !self::windowOpen($conversation)) {
            $template = self::template($config, $message->kind);

            if (!$template) {
                $error = 'No enviado: pasaron más de 24 horas desde que el cliente escribió y este aviso no tiene una plantilla aprobada de WhatsApp.';
                DB::table('messages')->where('id', $message->id)->update(['status' => 'not_sent', 'error' => $error, 'updated_at' => now()]);
                self::fallback($message, $error);

                return 'not_sent';
            }
        }

        $text = RichText::toText($message->body);

        try {
            $result = $template
                ? $provider->sendTemplate($config, $phone, $template['name'], $template['language'], self::templateParams($message))
                : $provider->sendText($config, $phone, $text);
        } catch (\Throwable $e) {
            $attempts = (int) $message->attempts + 1;
            $final = ($e instanceof SendFailed && $e->final) || $attempts >= Messaging::MAX_ATTEMPTS;
            $error = mb_substr($e instanceof SendFailed ? $e->getMessage() : 'WhatsApp respondió con un error: ' . mb_substr($e->getMessage(), 0, 300), 0, 500);

            DB::table('messages')->where('id', $message->id)->update([
                'status'       => $final ? 'failed' : 'queued',
                'scheduled_at' => $final ? $message->scheduled_at : now()->addMinutes(5 * $attempts),
                'error'        => $error,
                'updated_at'   => now(),
            ]);

            if ($final) {
                self::fallback($message, $error);
            }

            return 'failed';
        }

        $status = !empty($result['simulated']) ? 'simulated' : 'sent';

        DB::table('messages')->where('id', $message->id)->update([
            'status' => $status, 'provider' => $provider->key(), 'provider_message_id' => $result['id'], 'to_address' => $phone,
            'sent_at' => now(), 'error' => null, 'updated_at' => now(),
        ]);

        self::record($conversation, [
            'direction'           => 'out',
            'type'                => $template ? 'template' : 'text',
            'body'                => $text,
            'template'            => $template['name'] ?? null,
            'status'              => $status,
            'provider_message_id' => $result['id'],
            'message_id'          => $message->id,
            'sent_by'             => $message->created_by,
            'sent_at'             => now(),
        ]);

        MessageQuota::warn($tenant, 'whatsapp');

        return 'sent';
    }

    public static function templateParams(object $message): array
    {
        $vars = self::meta($message)['vars'] ?? [];
        $order = Messaging::KINDS[$message->kind]['vars'] ?? array_keys($vars);

        return array_map(fn ($key) => (string) ($vars[$key] ?? ''), $order);
    }

    public static function fallback(object $message, string $reason): ?object
    {
        $meta = self::meta($message);

        if (empty($meta['fallback']) || !Messaging::ready()) {
            return null;
        }

        $tenant = Tenant::find($message->tenant_id);
        $customer = $message->customer_id ? Customer::find($message->customer_id) : null;

        if (!$tenant || !$customer) {
            return null;
        }

        DB::table('messages')->where('id', $message->id)->update(['meta' => json_encode(['fallback' => false] + $meta), 'error' => mb_substr($reason . ' Se envió por correo.', 0, 500)]);

        return Messaging::store($tenant, [
            'kind'        => $message->kind,
            'customer'    => $customer,
            'subject'     => $message->subject,
            'body'        => $message->body,
            'branch_id'   => $message->branch_id,
            'entity_type' => $message->entity_type,
            'entity_id'   => $message->entity_id,
            'dedupe_key'  => $meta['email_dedupe'] ?? null,
            'user'        => $message->created_by ? User::find($message->created_by) : null,
        ]);
    }

    public static function record(object $conversation, array $data): object
    {
        $id = (string) Str::uuid();

        DB::table('whatsapp_messages')->insert($data + [
            'id'              => $id,
            'tenant_id'       => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        DB::table('whatsapp_conversations')->where('id', $conversation->id)->update(['last_message_at' => now(), 'updated_at' => now()]);

        return DB::table('whatsapp_messages')->where('id', $id)->first();
    }

    public static function reply(Tenant $tenant, object $conversation, string $text, ?User $user, bool $assistant = false): object
    {
        $text = trim($text);
        $config = self::config($tenant->id);

        if (!self::connected($tenant->id)) {
            throw ValidationException::withMessages(['body' => 'Tu empresa aún no conecta WhatsApp: hazlo en Configuración → Mensajes → WhatsApp.']);
        }

        if ($text === '') {
            throw ValidationException::withMessages(['body' => 'Escribe el mensaje.']);
        }

        if (!self::windowOpen($conversation)) {
            throw ValidationException::withMessages(['body' => 'Pasaron más de 24 horas desde el último mensaje del cliente. WhatsApp solo deja responder con una plantilla aprobada: el próximo aviso automático saldrá con su plantilla.']);
        }

        if (self::revoked($conversation->customer_id)) {
            throw ValidationException::withMessages(['body' => 'El cliente pidió no recibir mensajes por WhatsApp.']);
        }

        $provider = self::provider($config);

        try {
            $result = $provider->sendText($config, $conversation->phone, $text);
            $status = !empty($result['simulated']) ? 'simulated' : 'sent';
            $error = null;
        } catch (\Throwable $e) {
            $result = ['id' => null];
            $status = 'failed';
            $error = mb_substr($e instanceof SendFailed ? $e->getMessage() : 'WhatsApp respondió con un error: ' . mb_substr($e->getMessage(), 0, 300), 0, 500);
        }

        if (!$assistant) {
            DB::table('whatsapp_conversations')->where('id', $conversation->id)->update(['unread' => 0]);
        }

        return self::record($conversation, [
            'direction'           => 'out',
            'type'                => 'text',
            'body'                => $text,
            'status'              => $status,
            'provider_message_id' => $result['id'],
            'sent_by'             => $user?->id,
            'by_assistant'        => $assistant,
            'error'               => $error,
            'sent_at'             => $status === 'failed' ? null : now(),
        ]);
    }

    public static function signatureValid(array $config, string $raw, ?string $header): bool
    {
        $secret = (string) ($config['app_secret'] ?? '');

        if ($secret === '') {
            return self::simulated($config);
        }

        return is_string($header) && hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $header);
    }

    public static function receive(Tenant $tenant, array $payload): array
    {
        $done = ['messages' => 0, 'statuses' => 0];

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                $names = collect((array) ($value['contacts'] ?? []))->mapWithKeys(fn ($c) => [(string) ($c['wa_id'] ?? '') => $c['profile']['name'] ?? null])->all();

                foreach ((array) ($value['messages'] ?? []) as $message) {
                    $from = self::phone((string) ($message['from'] ?? ''));

                    if ($from === '' || empty($message['id'])) {
                        continue;
                    }

                    if (self::inbound($tenant, $from, $names[$message['from']] ?? null, (string) $message['id'], $message)) {
                        $done['messages']++;
                    }
                }

                foreach ((array) ($value['statuses'] ?? []) as $status) {
                    if (!empty($status['id']) && self::updateStatus($tenant, (string) $status['id'], (string) ($status['status'] ?? ''), $status['errors'][0] ?? null, $status['timestamp'] ?? null)) {
                        $done['statuses']++;
                    }
                }
            }
        }

        return $done;
    }

    public static function inbound(Tenant $tenant, string $phone, ?string $name, string $providerId, array $message): ?object
    {
        if (DB::table('whatsapp_messages')->where('tenant_id', $tenant->id)->where('provider_message_id', $providerId)->exists()) {
            return null;
        }

        $type = (string) ($message['type'] ?? 'text');
        $body = match ($type) {
            'text'        => (string) ($message['text']['body'] ?? ''),
            'button'      => (string) ($message['button']['text'] ?? ''),
            'interactive' => (string) ($message['interactive']['button_reply']['title'] ?? $message['interactive']['list_reply']['title'] ?? ''),
            'image'       => '[Imagen] ' . ($message['image']['caption'] ?? ''),
            'audio'       => '[Audio]',
            'video'       => '[Video] ' . ($message['video']['caption'] ?? ''),
            'document'    => '[Documento] ' . ($message['document']['filename'] ?? ''),
            'location'    => '[Ubicación]',
            'sticker'     => '[Sticker]',
            default       => '[Mensaje que el sistema aún no muestra]',
        };

        $at = isset($message['timestamp']) && is_numeric($message['timestamp']) ? Carbon::createFromTimestamp((int) $message['timestamp']) : now();
        $conversation = self::conversationFor($tenant, $phone, null, $name);
        $row = self::record($conversation, [
            'direction'           => 'in',
            'type'                => in_array($type, ['text', 'button', 'interactive'], true) ? 'text' : 'other',
            'body'                => trim($body),
            'status'              => 'received',
            'provider_message_id' => $providerId,
            'sent_at'             => $at,
        ]);

        DB::table('whatsapp_conversations')->where('id', $conversation->id)->update([
            'last_inbound_at' => $at,
            'unread'          => DB::raw('unread + 1'),
            'status'          => $conversation->status === 'closed' ? 'open' : $conversation->status,
        ]);

        try {
            Assistant::handle($tenant, DB::table('whatsapp_conversations')->where('id', $conversation->id)->first(), $row);
        } catch (\Throwable $e) {
            report($e);
        }

        return $row;
    }

    public static function updateStatus(Tenant $tenant, string $providerId, string $status, ?array $error = null, $timestamp = null): bool
    {
        if (!in_array($status, ['sent', 'delivered', 'read', 'failed'], true)) {
            return false;
        }

        $at = is_numeric($timestamp) ? Carbon::createFromTimestamp((int) $timestamp) : now();
        $reason = $status === 'failed' ? mb_substr('WhatsApp no pudo entregar el mensaje' . (!empty($error['title']) ? ': ' . $error['title'] : '.'), 0, 500) : null;
        $changed = false;

        foreach (['whatsapp_messages', 'messages'] as $table) {
            $query = DB::table($table)->where('tenant_id', $tenant->id)->where('provider_message_id', $providerId);
            $row = $query->first();

            if (!$row) {
                continue;
            }

            if ($status !== 'failed' && (self::STATUS_ORDER[$row->status] ?? -1) >= self::STATUS_ORDER[$status]) {
                continue;
            }

            if ($row->status === 'failed' || $row->status === 'read') {
                continue;
            }

            DB::table($table)->where('id', $row->id)->update(array_filter([
                'status'       => $status,
                'delivered_at' => in_array($status, ['delivered', 'read'], true) && !$row->delivered_at ? $at : null,
                'read_at'      => $status === 'read' ? $at : null,
                'error'        => $reason,
            ]) + ['updated_at' => now()]);
            $changed = true;

            if ($table === 'messages' && $status === 'failed') {
                self::fallback($row, $reason);
            }
        }

        return $changed;
    }

    public static function presentConversation(object $row, ?object $customer = null, ?object $last = null): array
    {
        $iso = fn ($value) => $value ? Carbon::parse($value)->toIso8601String() : null;

        return [
            'id'              => $row->id,
            'phone'           => $row->phone,
            'contact_name'    => $row->contact_name,
            'customer'        => $customer ? ['id' => $customer->id, 'name' => Person::name($customer)] : null,
            'status'          => $row->status,
            'status_label'    => self::CONVERSATION_LABELS[$row->status] ?? $row->status,
            'handoff_reason'  => $row->handoff_reason,
            'unread'          => (int) $row->unread,
            'window_open'     => self::windowOpen($row),
            'window_ends_at'  => $row->last_inbound_at ? Carbon::parse($row->last_inbound_at)->addHours(self::WINDOW_HOURS)->toIso8601String() : null,
            'last_inbound_at' => $iso($row->last_inbound_at),
            'last_message_at' => $iso($row->last_message_at ?? $row->created_at),
            'last'            => $last ? ['body' => mb_substr((string) $last->body, 0, 140), 'direction' => $last->direction, 'status' => $last->status] : null,
        ];
    }

    public static function presentMessage(object $row, ?string $by = null): array
    {
        $iso = fn ($value) => $value ? Carbon::parse($value)->toIso8601String() : null;

        return [
            'id'           => $row->id,
            'direction'    => $row->direction,
            'type'         => $row->type,
            'body'         => $row->body,
            'template'     => $row->template,
            'status'       => $row->status,
            'status_label' => self::STATUS_LABELS[$row->status] ?? $row->status,
            'by'           => $row->by_assistant ? 'Asistente' : $by,
            'by_assistant' => (bool) $row->by_assistant,
            'error'        => $row->error,
            'at'           => $iso($row->sent_at ?? $row->created_at),
            'delivered_at' => $iso($row->delivered_at),
            'read_at'      => $iso($row->read_at),
        ];
    }
}
