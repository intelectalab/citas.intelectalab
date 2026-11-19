<?php

namespace App\Support;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupportDesk
{
    public const TOPICS = [
        'agenda'  => 'Agenda y citas',
        'sales'   => 'Ventas y caja',
        'billing' => 'Mi plan y pagos',
        'access'  => 'Ingreso y cuentas',
        'data'    => 'Datos e importación',
        'idea'    => 'Una idea o sugerencia',
        'other'   => 'Otro tema',
    ];

    public const PRIORITIES = [
        'low'    => 'Baja',
        'normal' => 'Normal',
        'high'   => 'Alta',
        'urgent' => 'Urgente',
    ];

    public const STATUSES = [
        'open'        => 'Por responder',
        'in_progress' => 'En revisión',
        'waiting'     => 'Esperando a la empresa',
        'resolved'    => 'Resuelto',
    ];

    public const TENANT_STATUSES = [
        'open'        => 'Enviado',
        'in_progress' => 'En revisión',
        'waiting'     => 'Te respondieron',
        'resolved'    => 'Resuelto',
    ];

    public const HOURS_BY_PLAN = ['individual' => 24, 'esencial' => 24, 'profesional' => 8, 'empresarial' => 2];

    public const DEFAULT_HOURS = 24;

    public const ACCESS_HOURS = 72;

    public const NOT_READY = 'Falta actualizar la base de datos para usar Soporte: ejecuta php artisan migrate.';

    public const SETTINGS_DEFAULTS = ['consent_required' => false];

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('saas_support_tickets') && Schema::hasTable('saas_support_messages');
    }

    public static function settings(): array
    {
        $stored = PlatformSetting::value('support_desk');
        $stored = is_array($stored) ? $stored : [];

        return ['consent_required' => (bool) ($stored['consent_required'] ?? self::SETTINGS_DEFAULTS['consent_required'])];
    }

    public static function saveSettings(array $values): array
    {
        PlatformSetting::put(['support_desk' => ['consent_required' => (bool) ($values['consent_required'] ?? false)]]);

        return self::settings();
    }

    public static function responseHours(string $tenantId, string $priority): int
    {
        $plan = Subscriptions::latest($tenantId)?->plan;
        $support = is_array($plan?->support) ? $plan->support : [];
        $hours = is_numeric($support['response_hours'] ?? null) && (int) $support['response_hours'] > 0
            ? (int) $support['response_hours']
            : (self::HOURS_BY_PLAN[$plan?->slug ?? ''] ?? self::DEFAULT_HOURS);

        return $priority === 'urgent' ? max(1, (int) ceil($hours / 4)) : $hours;
    }

    private static function nextCode(): string
    {
        $number = (int) DB::table('saas_support_tickets')->count() + 1;

        do {
            $code = 'SOP-' . str_pad((string) $number, 5, '0', STR_PAD_LEFT);
            $number++;
        } while (DB::table('saas_support_tickets')->where('code', $code)->exists());

        return $code;
    }

    private static function storeFile(string $tenantId, string $ticketId, ?UploadedFile $file): array
    {
        if (!$file) {
            return [null, null];
        }

        $name = Str::uuid() . '.' . strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'png');
        $path = Storage::disk('local')->putFileAs("support/{$tenantId}/{$ticketId}", $file, $name);

        return [$path, mb_substr($file->getClientOriginalName() ?: $name, 0, 150)];
    }

    public static function create(User $user, array $data, ?UploadedFile $file): object
    {
        abort_unless(self::ready(), 503, self::NOT_READY);

        $priority = ($data['priority'] ?? 'normal') === 'urgent' ? 'urgent' : 'normal';
        $ticketId = (string) Str::uuid();
        [$path, $original] = self::storeFile($user->tenant_id, $ticketId, $file);

        DB::transaction(function () use ($user, $data, $priority, $ticketId, $path, $original) {
            DB::table('saas_support_tickets')->insert([
                'id'              => $ticketId,
                'tenant_id'       => $user->tenant_id,
                'code'            => self::nextCode(),
                'subject'         => mb_substr(trim($data['subject']), 0, 150),
                'topic'           => isset(self::TOPICS[$data['topic'] ?? '']) ? $data['topic'] : 'other',
                'priority'        => $priority,
                'status'          => 'open',
                'created_by'      => $user->id,
                'access_until'    => !empty($data['allow_access']) ? now()->addHours(self::ACCESS_HOURS) : null,
                'due_at'          => now()->addHours(self::responseHours($user->tenant_id, $priority)),
                'last_message_at' => now(),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            DB::table('saas_support_messages')->insert([
                'id'              => (string) Str::uuid(),
                'ticket_id'       => $ticketId,
                'tenant_id'       => $user->tenant_id,
                'author_id'       => $user->id,
                'side'            => 'tenant',
                'body'            => trim($data['body']),
                'attachment_path' => $path,
                'attachment_name' => $original,
                'created_at'      => now(),
            ]);
        });

        $ticket = self::find($ticketId);
        $tenant = Tenant::find($user->tenant_id);
        $title = "Pedido de ayuda {$ticket->code} de {$tenant?->name}";
        $body = "{$ticket->subject} · " . self::PRIORITIES[$priority] . ' · Responder hasta el ' . self::when($ticket->due_at) . '.';

        foreach (self::platformIds() as $userId) {
            self::notifyPlatform($userId, 'support.new', $title, $body, $ticket->id, "support-new:{$ticket->id}");
        }

        if ($support = PlatformSetting::value('support_email')) {
            PlatformMail::trySend($support, $title, '<p>Llegó un pedido de ayuda de <strong>' . e($tenant?->name) . '</strong>.</p><p><strong>' . e($ticket->subject) . '</strong></p><p>' . nl2br(e(trim($data['body']))) . '</p><p>Respóndelo desde la consola, en Soporte, hasta el <strong>' . self::when($ticket->due_at) . '</strong>.</p>');
        }

        return $ticket;
    }

    public static function find(string $id, ?string $tenantId = null): ?object
    {
        if (!self::ready()) {
            return null;
        }

        return DB::table('saas_support_tickets')->where('id', $id)->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->first();
    }

    public static function reply(object $ticket, User $user, string $side, string $body, ?UploadedFile $file, ?string $status = null): object
    {
        [$path, $original] = self::storeFile($ticket->tenant_id, $ticket->id, $file);
        $now = now();

        DB::transaction(function () use ($ticket, $user, $side, $body, $path, $original, $status, $now) {
            DB::table('saas_support_messages')->insert([
                'id'              => (string) Str::uuid(),
                'ticket_id'       => $ticket->id,
                'tenant_id'       => $ticket->tenant_id,
                'author_id'       => $user->id,
                'side'            => $side,
                'body'            => trim($body),
                'attachment_path' => $path,
                'attachment_name' => $original,
                'created_at'      => $now,
            ]);

            $update = ['last_message_at' => $now, 'updated_at' => $now];

            if ($side === 'platform') {
                $update['status'] = $status && isset(self::STATUSES[$status]) ? $status : 'waiting';
                $update['first_response_at'] = $ticket->first_response_at ?? $now;
                $update['assigned_to'] = $ticket->assigned_to ?? $user->id;
                $update['resolved_at'] = $update['status'] === 'resolved' ? $now : null;
            } elseif (in_array($ticket->status, ['waiting', 'resolved'], true)) {
                $update['status'] = 'open';
                $update['resolved_at'] = null;
                $update['due_at'] = $now->copy()->addHours(self::responseHours($ticket->tenant_id, $ticket->priority));
            }

            DB::table('saas_support_tickets')->where('id', $ticket->id)->update($update);
        });

        $fresh = self::find($ticket->id);
        $tenant = Tenant::find($ticket->tenant_id);

        if ($side === 'platform') {
            $title = "Soporte respondió tu pedido {$ticket->code}";
            $recipients = array_unique(array_filter(array_merge([$ticket->created_by], Notifier::adminIds($ticket->tenant_id))));

            foreach ($recipients as $userId) {
                if ($userId !== $user->id) {
                    Notifier::send($ticket->tenant_id, $userId, 'support.reply', $title, mb_substr(trim($body), 0, 300), "/support/{$ticket->id}", 'support_ticket', $ticket->id, "support-reply:{$ticket->id}:{$now->timestamp}");
                }
            }

            $email = $ticket->created_by ? User::where('id', $ticket->created_by)->value('email') : null;

            if ($email) {
                PlatformMail::trySend($email, $title, '<p>Respondimos tu pedido de ayuda <strong>' . e($ticket->subject) . '</strong>:</p><p>' . nl2br(e(trim($body))) . '</p><p>Puedes ver la conversación y responder en tu panel, en Soporte.</p>', $tenant);
            }
        } else {
            $title = "{$tenant?->name} respondió en {$ticket->code}";
            $recipients = $ticket->assigned_to ? [$ticket->assigned_to] : self::platformIds();

            foreach ($recipients as $userId) {
                self::notifyPlatform($userId, 'support.reply', $title, mb_substr(trim($body), 0, 300), $ticket->id, "support-reply:{$ticket->id}:{$now->timestamp}");
            }
        }

        return $fresh;
    }

    public static function platformIds(): array
    {
        return Rls::bypass(fn () => User::whereNull('tenant_id')->where('status', 'active')->pluck('id')->all());
    }

    private static function notifyPlatform(string $userId, string $type, string $title, string $body, string $id, string $key): void
    {
        try {
            Rls::bypass(fn () => DB::table('user_notifications')->insertOrIgnore([
                'id'          => (string) Str::uuid(),
                'tenant_id'   => null,
                'user_id'     => $userId,
                'type'        => $type,
                'title'       => mb_substr($title, 0, 190),
                'body'        => mb_substr($body, 0, 500),
                'link'        => "/admin/support/{$id}",
                'entity_type' => 'support_ticket',
                'entity_id'   => $id,
                'dedupe_key'  => $key,
                'created_at'  => now(),
            ]));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function when($value): string
    {
        return $value ? Carbon::parse($value)->setTimezone(PlatformReports::ZONE)->locale('es')->isoFormat('D [de] MMMM, h:mm a') : '';
    }

    public static function overdue(object $ticket): bool
    {
        return in_array($ticket->status, ['open', 'in_progress'], true) && $ticket->due_at && Carbon::parse($ticket->due_at)->isPast();
    }

    public static function accessOpen(object $ticket): bool
    {
        return $ticket->access_until && Carbon::parse($ticket->access_until)->isFuture() && $ticket->status !== 'resolved';
    }

    public static function present(object $ticket, bool $platform, array $names = [], array $tenants = []): array
    {
        $iso = fn ($v) => $v ? Carbon::parse($v)->toIso8601String() : null;

        return [
            'id'                => $ticket->id,
            'code'              => $ticket->code,
            'subject'           => $ticket->subject,
            'topic'             => $ticket->topic,
            'topic_label'       => self::TOPICS[$ticket->topic] ?? self::TOPICS['other'],
            'priority'          => $ticket->priority,
            'priority_label'    => self::PRIORITIES[$ticket->priority] ?? $ticket->priority,
            'status'            => $ticket->status,
            'status_label'      => ($platform ? self::STATUSES : self::TENANT_STATUSES)[$ticket->status] ?? $ticket->status,
            'overdue'           => self::overdue($ticket),
            'due_at'            => $iso($ticket->due_at),
            'created_at'        => $iso($ticket->created_at),
            'last_message_at'   => $iso($ticket->last_message_at),
            'first_response_at' => $iso($ticket->first_response_at),
            'access_until'      => self::accessOpen($ticket) ? $iso($ticket->access_until) : null,
            'created_by'        => $ticket->created_by ? ($names[$ticket->created_by] ?? null) : null,
            'assigned_to'       => $platform ? $ticket->assigned_to : null,
            'assigned_name'     => $ticket->assigned_to ? ($names[$ticket->assigned_to] ?? null) : null,
            'tenant'            => $platform ? ['id' => $ticket->tenant_id, 'name' => $tenants[$ticket->tenant_id] ?? 'Empresa'] : null,
        ];
    }

    public static function names(iterable $ids): array
    {
        $ids = collect($ids)->filter()->unique()->values();

        return $ids->isEmpty() ? [] : User::whereIn('id', $ids)->get(['id', 'first_name', 'last_name', 'email'])
            ->mapWithKeys(fn ($u) => [$u->id => trim("{$u->first_name} {$u->last_name}") ?: $u->email])->all();
    }

    public static function presentMany($tickets, bool $platform): array
    {
        $names = self::names($tickets->flatMap(fn ($t) => [$t->created_by, $t->assigned_to]));
        $tenants = $platform ? Tenant::whereIn('id', $tickets->pluck('tenant_id')->unique())->pluck('name', 'id')->all() : [];

        return $tickets->map(fn ($t) => self::present($t, $platform, $names, $tenants))->values()->all();
    }

    public static function messages(object $ticket, bool $platform): array
    {
        $rows = DB::table('saas_support_messages')->where('ticket_id', $ticket->id)->orderBy('created_at')->get();
        $names = self::names($rows->pluck('author_id'));
        $base = $platform ? "/admin/support/{$ticket->id}" : "/support/tickets/{$ticket->id}";

        return $rows->map(fn ($m) => [
            'id'         => $m->id,
            'side'       => $m->side,
            'author'     => $m->side === 'platform' && !$platform ? 'Soporte de ' . PlatformMail::productName() : ($names[$m->author_id] ?? ($m->side === 'platform' ? 'Soporte' : 'Empresa')),
            'body'       => $m->body,
            'created_at' => Carbon::parse($m->created_at)->toIso8601String(),
            'attachment' => $m->attachment_path ? ['name' => $m->attachment_name, 'url' => "{$base}/files/{$m->id}"] : null,
        ])->all();
    }

    public static function file(object $ticket, string $messageId)
    {
        $path = DB::table('saas_support_messages')->where('id', $messageId)->where('ticket_id', $ticket->id)->value('attachment_path');
        abort_unless($path && Storage::disk('local')->exists($path), 404, 'No encontramos la captura.');

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-store']);
    }

    public static function assertConsent(Tenant $tenant, ?string $ticketId): ?object
    {
        $ticket = $ticketId && self::ready() ? self::find($ticketId, $tenant->id) : null;

        if ($ticketId && !$ticket) {
            throw ValidationException::withMessages(['ticket_id' => 'Ese pedido de ayuda no es de esta empresa.']);
        }

        if (!self::settings()['consent_required']) {
            return $ticket;
        }

        $open = self::ready() ? DB::table('saas_support_tickets')->where('tenant_id', $tenant->id)->where('status', '!=', 'resolved')
            ->whereNotNull('access_until')->where('access_until', '>', now())
            ->when($ticket, fn ($q) => $q->where('id', $ticket->id))->orderByDesc('access_until')->first() : null;

        if (!$open) {
            throw ValidationException::withMessages(['reason' => 'La empresa no autorizó que soporte entre a su cuenta. Pídele que lo autorice en su pedido de ayuda (dura ' . self::ACCESS_HOURS . ' horas).']);
        }

        return $ticket ?? $open;
    }
}
