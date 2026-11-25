<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Tenant;
use App\Support\Assistant\NoneProvider;
use App\Support\Assistant\Tools;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AssistantDesk
{
    public const OUTCOMES = [
        'booked'      => 'Cita creada',
        'confirmed'   => 'Confirmó',
        'rescheduled' => 'Reprogramó',
        'cancelled'   => 'Canceló',
        'faq'         => 'Pregunta resuelta',
        'handoff'     => 'Pasó a una persona',
        'open'        => 'En curso',
    ];

    public static function simulate(Tenant $tenant, ?Customer $customer, array $history, string $text): array
    {
        $tenant->loadMissing(['settings', 'activeSubscription.plan']);
        $provider = Assistant::provider($tenant);

        if ($word = Assistant::clinicalWord($tenant, $text)) {
            return [
                'action' => 'blocked',
                'label'  => 'Consulta de salud: pasaría a una persona',
                'reply'  => Assistant::HANDOFF_TEXT,
                'reason' => "El mensaje tiene una palabra de salud («{$word}»): el asistente no la responde y avisa al equipo.",
                'done'   => [],
            ];
        }

        if ($provider instanceof NoneProvider) {
            return [
                'action' => 'none',
                'label'  => 'Sin inteligencia artificial',
                'reply'  => null,
                'reason' => 'Todavía no hay una clave de inteligencia artificial: pégala en Configuración → Mensajes → WhatsApp y asistente, o pide a la plataforma que active la suya.',
                'done'   => [],
            ];
        }

        $conversation = (object) ['id' => null, 'customer_id' => $customer?->id, 'status' => 'open', 'phone' => null, 'contact_name' => null];
        $tools = new Tools($tenant, $customer, $conversation);
        $tools->simulate = true;
        $turns = collect($history)->take(-20)->map(fn ($m) => ['from' => ($m['from'] ?? '') === 'customer' ? 'customer' : 'assistant', 'text' => mb_substr((string) ($m['text'] ?? ''), 0, 2000)])->values()->all();
        $turns[] = ['from' => 'customer', 'text' => $text];

        try {
            $answer = $provider->answer([
                'company'      => Messaging::companyName($tenant),
                'conversation' => null,
                'customer'     => $customer ? ['id' => $customer->id, 'name' => Person::name($customer)] : null,
                'text'         => $text,
                'history'      => $turns,
                'can_book'     => (bool) $customer,
                'tools'        => $tools,
                'simulation'   => true,
            ]);
        } catch (\Throwable $e) {
            IntegrationLog::write($tenant->id, 'ai', $provider->key(), 'answer', false, 'Simulador: ' . $e->getMessage());

            return ['action' => 'error', 'label' => 'Tuvo un problema', 'reply' => null, 'reason' => mb_substr($e->getMessage(), 0, 300), 'done' => []];
        }

        $action = (string) ($answer['action'] ?? 'none');
        $reply = trim((string) ($answer['text'] ?? '')) ?: null;

        if ($reply && Assistant::clinicalWord($tenant, $reply)) {
            return ['action' => 'blocked', 'label' => 'Respuesta con un tema de salud: no se enviaría', 'reply' => Assistant::HANDOFF_TEXT, 'reason' => 'La respuesta del asistente tocaba un tema de salud: en WhatsApp no se manda y pasa a una persona.', 'done' => []];
        }

        $done = collect((array) ($answer['done'] ?? []))->map(fn ($d) => [
            'action' => $d['action'],
            'label'  => ['booking' => 'Reservaría la cita', 'move' => 'Reprogramaría la cita', 'cancel' => 'Cancelaría la cita', 'confirm' => 'Confirmaría la cita'][$d['action']] ?? $d['action'],
        ])->values()->all();

        return [
            'action' => match ($action) {
                'handoff' => 'handoff',
                'book'    => 'booking',
                'reply'   => 'reply',
                default   => 'none',
            },
            'label'  => match ($action) {
                'handoff' => 'Pasaría a una persona',
                'book'    => 'Reservaría la cita',
                'reply'   => 'Respondió',
                default   => 'No respondió',
            },
            'reply'  => $action === 'handoff' ? ($reply ?? Assistant::HANDOFF_TEXT) : $reply,
            'reason' => $answer['reason'] ?? null,
            'done'   => $done,
        ];
    }

    private static function outcome(object $conversation, \Illuminate\Support\Collection $logs): string
    {
        if ($conversation->status === 'needs_human') {
            return 'handoff';
        }

        $actions = $logs->pluck('action');

        if ($actions->contains('booking')) {
            return 'booked';
        }

        foreach (['Reprogramó' => 'rescheduled', 'Canceló' => 'cancelled', 'Confirmó' => 'confirmed'] as $word => $key) {
            if ($logs->where('action', 'acted')->contains(fn ($l) => str_starts_with((string) $l->reason, $word))) {
                return $key;
            }
        }

        if ($actions->intersect(['handoff', 'blocked'])->isNotEmpty()) {
            return 'handoff';
        }

        return $actions->contains('reply') ? 'faq' : 'open';
    }

    public static function board(Tenant $tenant, int $days = 30): array
    {
        $tenant->loadMissing(['settings', 'activeSubscription.plan']);
        $since = now()->subDays($days);
        $logs = Assistant::ready()
            ? DB::table('assistant_logs')->where('tenant_id', $tenant->id)->where('created_at', '>=', $since->toIso8601String())->orderBy('created_at')->get(['conversation_id', 'action', 'reason', 'created_at'])
            : collect();
        $byConversation = $logs->groupBy('conversation_id');
        $conversations = WhatsApp::ready() && $byConversation->isNotEmpty()
            ? DB::table('whatsapp_conversations')->where('tenant_id', $tenant->id)->whereIn('id', $byConversation->keys()->filter()->all())->orderByDesc('last_message_at')->get()
            : collect();
        $customers = DB::table('customers')->where('tenant_id', $tenant->id)->whereIn('id', $conversations->pluck('customer_id')->filter()->unique()->all())->get(['id', 'first_name', 'last_name'])->keyBy('id');
        $first = $conversations->isEmpty() ? collect() : DB::table('whatsapp_messages')->whereIn('id', function ($q) use ($conversations, $since) {
            $q->selectRaw('distinct on (conversation_id) id')->from('whatsapp_messages')->whereIn('conversation_id', $conversations->pluck('id')->all())
                ->where('direction', 'in')->where('created_at', '>=', $since->toIso8601String())->orderBy('conversation_id')->orderByDesc('created_at');
        })->get(['conversation_id', 'body'])->keyBy('conversation_id');

        $rows = $conversations->map(function ($c) use ($byConversation, $customers, $first) {
            $own = $byConversation->get($c->id, collect());
            $outcome = self::outcome($c, $own);

            return [
                'id'            => $c->id,
                'name'          => ($customer = $customers[$c->customer_id] ?? null) ? Person::name($customer) : ($c->contact_name ?: '+' . $c->phone),
                'customer_id'   => $c->customer_id,
                'phone'         => $c->phone,
                'outcome'       => $outcome,
                'outcome_label' => self::OUTCOMES[$outcome],
                'answers'       => $own->where('action', 'reply')->count(),
                'last_text'     => mb_substr((string) ($first[$c->id]->body ?? ''), 0, 140),
                'at'            => Carbon::parse($c->last_message_at ?? $c->created_at)->toIso8601String(),
                'needs_human'   => $c->status === 'needs_human',
            ];
        })->values();

        $count = fn (string $outcome) => $rows->where('outcome', $outcome)->count();
        $provider = Assistant::provider($tenant);

        return [
            'days'          => $days,
            'enabled'       => Assistant::enabled($tenant),
            'connected'     => !($provider instanceof NoneProvider),
            'provider'      => $provider->key(),
            'handoff_minutes' => Assistant::handoffMinutes($tenant),
            'quota'         => Assistant::quota($tenant),
            'kpis'          => [
                ['key' => 'conversations', 'label' => 'Conversaciones', 'value' => $rows->count(), 'hint' => "últimos {$days} días"],
                ['key' => 'booked', 'label' => 'Citas creadas', 'value' => $logs->where('action', 'booking')->count(), 'hint' => 'sin que intervenga recepción'],
                ['key' => 'confirmed', 'label' => 'Confirmaciones', 'value' => $logs->where('action', 'acted')->filter(fn ($l) => str_starts_with((string) $l->reason, 'Confirmó'))->count(), 'hint' => null],
                ['key' => 'rescheduled', 'label' => 'Reprogramaciones', 'value' => $logs->where('action', 'acted')->filter(fn ($l) => str_starts_with((string) $l->reason, 'Reprogramó'))->count(), 'hint' => $logs->where('action', 'acted')->filter(fn ($l) => str_starts_with((string) $l->reason, 'Canceló'))->count() . ' cancelaciones'],
                ['key' => 'faq', 'label' => 'Preguntas resueltas', 'value' => $count('faq'), 'hint' => null],
                ['key' => 'handoff', 'label' => 'Pasadas a una persona', 'value' => $count('handoff'), 'hint' => $logs->where('action', 'blocked')->count() . ' por temas de salud'],
            ],
            'outcomes'      => collect(self::OUTCOMES)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'conversations' => $rows,
            'errors'        => $logs->where('action', 'error')->count(),
        ];
    }
}
