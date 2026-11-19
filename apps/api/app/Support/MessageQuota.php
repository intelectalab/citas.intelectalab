<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MessageQuota
{
    public const SENT = ['sent', 'simulated', 'delivered', 'read'];

    public const LEVELS = [80, 100];

    public const KINDS = [
        'whatsapp'       => ['limit' => 'whatsapp_month', 'label' => 'mensajes de WhatsApp', 'short' => 'WhatsApp'],
        'campaign_email' => ['limit' => 'campaign_emails_month', 'label' => 'correos de campañas', 'short' => 'correos de campañas'],
    ];

    public static function limit(Tenant $tenant, string $kind): ?int
    {
        $value = $tenant->activeSubscription?->plan?->limits[self::KINDS[$kind]['limit']] ?? null;

        return is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
    }

    public static function monthStart(Tenant $tenant): Carbon
    {
        return Carbon::now($tenant->zone())->startOfMonth();
    }

    private static function base(Tenant $tenant, string $kind)
    {
        $query = DB::table('messages')->where('tenant_id', $tenant->id);

        return $kind === 'whatsapp'
            ? $query->where('channel', 'whatsapp')
            : $query->where('channel', 'email')->where('kind', 'campaign');
    }

    public static function used(Tenant $tenant, string $kind): int
    {
        if (!Messaging::ready()) {
            return 0;
        }

        return self::base($tenant, $kind)->whereIn('status', self::SENT)
            ->where('sent_at', '>=', self::monthStart($tenant)->toIso8601String())
            ->count();
    }

    public static function pending(Tenant $tenant, string $kind): int
    {
        if (!Messaging::ready()) {
            return 0;
        }

        return self::base($tenant, $kind)->where('status', 'queued')->count();
    }

    public static function state(Tenant $tenant, string $kind, int $needed = 0): array
    {
        $limit = self::limit($tenant, $kind);
        $used = self::used($tenant, $kind);
        $pending = self::pending($tenant, $kind);
        $remaining = $limit === null ? null : max(0, $limit - $used - $pending);

        return [
            'kind'      => $kind,
            'label'     => self::KINDS[$kind]['label'],
            'limit'     => $limit,
            'used'      => $used,
            'pending'   => $pending,
            'remaining' => $remaining,
            'needed'    => $needed,
            'enough'    => $remaining === null || $needed <= $remaining,
        ];
    }

    public static function reached(Tenant $tenant, string $kind): bool
    {
        $limit = self::limit($tenant, $kind);

        return $limit !== null && self::used($tenant, $kind) >= $limit;
    }

    public static function stopReason(Tenant $tenant, string $kind): string
    {
        return 'No enviado: tu empresa llegó al tope de ' . self::limit($tenant, $kind) . ' ' . self::KINDS[$kind]['label'] . ' de este mes de su plan.';
    }

    public static function shortage(array $state): string
    {
        $label = self::KINDS[$state['kind']]['label'];
        $used = $state['used'] + $state['pending'];

        return $state['remaining'] === 0
            ? "Tu plan ya no tiene {$label} este mes (usaste {$used} de {$state['limit']}), así que la campaña no sale. Podrá salir el próximo mes o si cambias de plan."
            : "Esta campaña necesita {$state['needed']} {$label} y a tu plan le quedan {$state['remaining']} este mes (usaste {$used} de {$state['limit']}), así que no sale. Elige un grupo más chico o cambia de plan.";
    }

    public static function warn(Tenant $tenant, string $kind): void
    {
        $limit = self::limit($tenant, $kind);

        if (!$limit || !Notifier::available()) {
            return;
        }

        $used = self::used($tenant, $kind);
        $percent = (int) floor($used * 100 / $limit);
        $level = collect(self::LEVELS)->filter(fn ($l) => $percent >= $l)->max();

        if (!$level) {
            return;
        }

        $label = self::KINDS[$kind]['label'];
        $title = $level >= 100 ? "Llegaste al tope de {$label} de tu plan" : "Ya usas el {$percent} % de los {$label} de tu plan";
        $body = "Usas {$used} de {$limit} este mes." . ($level >= 100
            ? ($kind === 'whatsapp'
                ? ' Los avisos que iban por WhatsApp saldrán por correo y las campañas por WhatsApp no saldrán hasta el próximo mes o hasta que cambies de plan.'
                : ' Las campañas por correo no saldrán hasta el próximo mes o hasta que cambies de plan.')
            : ' Si necesitas más, considera cambiar de plan.');
        $month = self::monthStart($tenant)->format('Y-m');

        foreach (Notifier::adminIds($tenant->id) as $userId) {
            Notifier::send($tenant->id, $userId, 'plan.limit', $title, $body, '/companies', 'plan', null, "quota:{$kind}:{$level}:{$month}");
        }
    }
}
