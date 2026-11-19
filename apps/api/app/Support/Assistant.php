<?php

namespace App\Support;

use App\Http\Controllers\Api\ChatBookingController;
use App\Models\Customer;
use App\Models\Tenant;
use App\Support\Assistant\FakeProvider;
use App\Support\Assistant\NoneProvider;
use App\Support\Assistant\Provider;
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
        'none'    => 'Sin proveedor: no respondió',
        'error'   => 'Tuvo un problema',
    ];

    public static ?Provider $provider = null;

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('assistant_logs');
    }

    public static function provider(): Provider
    {
        if (self::$provider) {
            return self::$provider;
        }

        return match (config('services.assistant.provider')) {
            'fake'  => app()->environment('production') ? new NoneProvider() : new FakeProvider(),
            default => new NoneProvider(),
        };
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

        $provider = self::provider();
        $text = (string) $inbound->body;

        if ($word = self::clinicalWord($tenant, $text)) {
            self::handoff($tenant, $conversation, "Consulta de salud (\"{$word}\"): el asistente no responde estos temas.", true);
            self::log($tenant, $conversation, $inbound, $provider->key(), 'blocked', null, "Palabra bloqueada: {$word}");

            return 'blocked';
        }

        try {
            $answer = $provider->answer(self::context($tenant, $conversation, $inbound));
        } catch (\Throwable $e) {
            report($e);
            self::handoff($tenant, $conversation, 'El asistente tuvo un problema al responder.', true);
            self::log($tenant, $conversation, $inbound, $provider->key(), 'error', null, mb_substr($e->getMessage(), 0, 250));

            return 'error';
        }

        $action = (string) ($answer['action'] ?? 'none');

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

        $sent = $text ? WhatsApp::reply($tenant, $conversation, $text, null, true) : null;
        self::log($tenant, $conversation, $inbound, $provider, 'booking', $sent?->body, 'Cita ' . ($data['code'] ?? $data['id'] ?? ''), $sent?->id);

        return 'booking';
    }

    public static function handoff(Tenant $tenant, object $conversation, string $reason, bool $tellCustomer = false): void
    {
        $at = now();
        DB::table('whatsapp_conversations')->where('id', $conversation->id)->update([
            'status' => 'needs_human', 'handoff_reason' => mb_substr($reason, 0, 255), 'handoff_at' => $at, 'updated_at' => $at,
        ]);

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
