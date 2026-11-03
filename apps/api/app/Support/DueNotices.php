<?php

namespace App\Support;

use App\Models\Professional;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DueNotices
{
    public const RECALL_DAYS = 3;
    public const DRAFT_HOURS = 24;
    public const PLAN_LEVELS = [80, 100];
    public const SUBSCRIPTION_DAYS = [7, 3, 1];

    public static function run(Tenant $tenant, bool $force = false): void
    {
        if (!$force && !Cache::add("due-notices:{$tenant->id}", 1, 3600)) {
            return;
        }

        foreach (['recalls', 'drafts', 'planLimits', 'subscription'] as $step) {
            try {
                self::$step($tenant);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    private static function userOf(?string $professionalId): ?string
    {
        return $professionalId ? Professional::where('id', $professionalId)->value('user_id') : null;
    }

    private static function plural(int $count, string $one, string $many): string
    {
        return $count === 1 ? "1 {$one}" : "{$count} {$many}";
    }

    public static function recalls(Tenant $tenant): void
    {
        if (!Schema::hasTable('recalls')) {
            return;
        }

        $today = Carbon::now($tenant->zone())->startOfDay();
        $rows = DB::table('recalls as r')
            ->leftJoin('appointments as a', 'a.id', '=', 'r.source_appointment_id')
            ->where('r.tenant_id', $tenant->id)
            ->whereIn('r.status', ['pending', 'notified'])
            ->where('r.due_date', '<=', $today->copy()->addDays(self::RECALL_DAYS)->toDateString())
            ->where('r.due_date', '>=', $today->copy()->subDays(60)->toDateString())
            ->get(['r.id', 'r.due_date', 'r.professional_id', 'a.branch_id']);

        $byUser = [];

        foreach ($rows as $row) {
            $late = (string) $row->due_date < $today->toDateString();
            $users = array_filter([self::userOf($row->professional_id), ...Notifier::staffWith($tenant->id, 'recalls.edit', $row->branch_id)]);

            foreach (array_unique($users) as $userId) {
                $byUser[$userId] ??= ['late' => 0, 'soon' => 0];
                $byUser[$userId][$late ? 'late' : 'soon']++;
            }
        }

        foreach ($byUser as $userId => $count) {
            $parts = array_filter([
                $count['late'] ? self::plural($count['late'], 'control vencido', 'controles vencidos') : null,
                $count['soon'] ? self::plural($count['soon'], 'control que vence', 'controles que vencen') . ' en los próximos ' . self::RECALL_DAYS . ' días' : null,
            ]);

            Notifier::send($tenant->id, $userId, 'recall.due', 'Hay controles por atender', 'Hay ' . implode(' y ', $parts) . '. Llama al cliente para agendarle su cita.', '/recalls', 'recall', null, "recalls:{$today->toDateString()}");
        }
    }

    public static function drafts(Tenant $tenant): void
    {
        if (!Schema::hasTable('care_records')) {
            return;
        }

        $rows = DB::table('care_records as r')
            ->join('appointments as a', 'a.id', '=', 'r.appointment_id')
            ->where('r.tenant_id', $tenant->id)
            ->where('r.status', 'draft')
            ->where('a.status', AppointmentFlow::COMPLETED)
            ->where('a.completed_at', '<', now()->subHours(self::DRAFT_HOURS))
            ->where('a.completed_at', '>', now()->subDays(60))
            ->get(['r.professional_id', 'r.created_by']);

        $today = Carbon::now($tenant->zone())->toDateString();

        foreach ($rows->groupBy(fn ($r) => self::userOf($r->professional_id) ?? $r->created_by) as $userId => $records) {
            if (!$userId) {
                continue;
            }

            Notifier::send($tenant->id, $userId, 'record.draft', 'Fichas sin terminar', 'Tienes ' . self::plural($records->count(), 'ficha', 'fichas') . ' en borrador de citas ya atendidas. Complétalas y fírmalas para que queden en la historia.', '/records', 'care_record', null, "drafts:{$today}");
        }
    }

    public static function planLimits(Tenant $tenant): void
    {
        $limits = $tenant->activeSubscription?->plan?->limits ?? [];
        $usage = $tenant->usage();
        $labels = ['appointments_month' => 'citas de este mes', 'users' => 'cuentas de usuario', 'professionals' => 'profesionales', 'branches' => 'sedes'];
        $month = Carbon::now($tenant->zone())->format('Y-m');

        foreach ($labels as $key => $label) {
            $limit = $limits[$key] ?? null;

            if (!is_numeric($limit) || (int) $limit <= 0) {
                continue;
            }

            $used = (int) ($usage[$key] ?? 0);
            $percent = (int) floor($used * 100 / (int) $limit);
            $level = collect(self::PLAN_LEVELS)->filter(fn ($l) => $percent >= $l)->max();

            if (!$level) {
                continue;
            }

            $title = $level >= 100 ? "Llegaste al tope de {$label} de tu plan" : "Ya usas el {$percent} % de las {$label} de tu plan";
            $body = "Usas {$used} de {$limit}." . ($level >= 100
                ? ($key === 'appointments_month' ? ' No se pueden agendar más citas este mes hasta que cambies de plan.' : ' Para agregar más, cambia de plan.')
                : ' Si necesitas más, considera cambiar de plan.');
            $period = $key === 'appointments_month' ? $month : "tope-{$limit}";

            foreach (Notifier::adminIds($tenant->id) as $userId) {
                Notifier::send($tenant->id, $userId, 'plan.limit', $title, $body, '/companies', 'plan', null, "plan:{$key}:{$level}:{$period}");
            }
        }
    }

    public static function subscription(Tenant $tenant): void
    {
        $subscription = $tenant->activeSubscription;

        if (!$subscription) {
            return;
        }

        $zone = $tenant->zone();
        $today = Carbon::now($zone)->startOfDay();
        $trial = $subscription->status === 'trialing';
        $end = $trial ? $subscription->trial_ends_at : $subscription->ends_at;

        if (!$end) {
            return;
        }

        $endDay = Carbon::parse($end)->setTimezone($zone)->startOfDay();
        $days = (int) $today->diffInDays($endDay, false);
        $bucket = collect(self::SUBSCRIPTION_DAYS)->filter(fn ($d) => $days <= $d)->min();

        $state = Subscriptions::state($tenant);

        if ($days < 0) {
            if ($state['state'] !== 'grace') {
                return;
            }

            $graceDay = Carbon::parse($state['grace_ends_at'])->setTimezone($zone);
            $title = 'Tu plan venció';
            $body = 'Venció el ' . $endDay->locale('es')->isoFormat('D [de] MMMM') . '. Págalo antes del ' . $graceDay->locale('es')->isoFormat('D [de] MMMM') . ' para que tu empresa siga usando el sistema.';

            foreach (Notifier::adminIds($tenant->id) as $userId) {
                Notifier::send($tenant->id, $userId, 'subscription.grace', $title, $body, '/companies?tab=plan', 'subscription', $subscription->id, "subscription-grace:{$subscription->id}:{$endDay->toDateString()}:{$today->toDateString()}");
            }

            return;
        }

        if ($bucket === null) {
            return;
        }

        $when = $days === 0 ? 'hoy' : ($days === 1 ? 'mañana' : "en {$days} días");
        $card = Subscriptions::ready() && $subscription->card_status === 'authorized';
        $price = Subscriptions::price($subscription->pending_plan_id ? \App\Models\SaaSPlan::find($subscription->pending_plan_id) : $subscription->plan, $subscription->pending_billing_cycle ?? $subscription->billing_cycle);
        $title = $trial ? "Tu periodo de prueba termina {$when}" : "Tu suscripción vence {$when}";
        $body = ($trial ? 'La prueba' : 'La suscripción') . ' termina el ' . $endDay->locale('es')->isoFormat('D [de] MMMM') . '. '
            . ($card
                ? 'Ese día se cobrará ' . Currency::format($price, $subscription->plan?->currency) . ' a tu tarjeta' . ($subscription->card_last4 ? " terminada en {$subscription->card_last4}" : '') . '.'
                : 'Págalo en Mi empresa → Tu plan para que tu empresa siga usando el sistema.');

        foreach (Notifier::adminIds($tenant->id) as $userId) {
            Notifier::send($tenant->id, $userId, 'subscription.ending', $title, $body, '/companies?tab=plan', 'subscription', $subscription->id, "subscription:{$subscription->id}:{$endDay->toDateString()}:{$bucket}");
        }
    }

    public static function closure(Tenant $tenant, Collection $appointments, string $what, string $link, ?string $actorId, string $key, ?string $whatOwn = null): void
    {
        try {
            if ($appointments->isEmpty()) {
                return;
            }

            $sent = [];

            foreach ($appointments->groupBy('professional_id') as $professionalId => $list) {
                $userId = self::userOf($professionalId);

                if ($userId && $userId !== $actorId) {
                    Notifier::send($tenant->id, $userId, 'block.affected', ($whatOwn ?? $what) . ' cae sobre ' . self::plural($list->count(), 'cita tuya', 'citas tuyas'), 'Hay que reprogramarlas o avisar a los clientes. Las ves en Agenda.', $link, 'appointment', null, "closure:{$key}:{$userId}");
                    $sent[$userId] = true;
                }
            }

            foreach ($appointments->groupBy('branch_id') as $branchId => $list) {
                foreach (Notifier::staffWith($tenant->id, 'agenda.edit', $branchId) as $userId) {
                    if ($userId === $actorId || isset($sent[$userId])) {
                        continue;
                    }

                    Notifier::send($tenant->id, $userId, 'block.affected', "{$what} deja " . self::plural($list->count(), 'cita', 'citas') . ' por reprogramar', 'Avisa a los clientes y cámbiales la fecha. Las ves en Agenda.', $link, 'appointment', null, "closure:{$key}:{$userId}:{$branchId}");
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
