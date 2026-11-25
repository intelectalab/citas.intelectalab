<?php

namespace App\Support;

use App\Models\Tenant;

class AppointmentFlow
{
    public const PENDING = 'pending';
    public const CONFIRMED = 'confirmed';
    public const CHECKED_IN = 'checked_in';
    public const IN_PROGRESS = 'in_progress';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';
    public const NO_SHOW = 'no_show';

    public const STATUSES = [
        self::PENDING     => ['name' => 'Pendiente',   'tone' => 'warn'],
        self::CONFIRMED   => ['name' => 'Confirmada',  'tone' => 'info'],
        self::CHECKED_IN  => ['name' => 'Llegó',       'tone' => 'accent'],
        self::IN_PROGRESS => ['name' => 'En atención', 'tone' => 'accent'],
        self::COMPLETED   => ['name' => 'Atendida',    'tone' => 'ok'],
        self::CANCELLED   => ['name' => 'Cancelada',   'tone' => 'muted'],
        self::NO_SHOW     => ['name' => 'No asistió',  'tone' => 'bad'],
    ];

    public const TRANSITIONS = [
        self::PENDING     => [self::CONFIRMED, self::CHECKED_IN, self::CANCELLED, self::NO_SHOW],
        self::CONFIRMED   => [self::CHECKED_IN, self::IN_PROGRESS, self::CANCELLED, self::NO_SHOW, self::PENDING],
        self::CHECKED_IN  => [self::IN_PROGRESS, self::COMPLETED, self::CONFIRMED],
        self::IN_PROGRESS => [self::COMPLETED],
        self::COMPLETED   => [],
        self::CANCELLED   => [],
        self::NO_SHOW     => [],
    ];

    public const RELEASED = [self::CANCELLED, self::NO_SHOW];

    public const RESCHEDULABLE = [self::PENDING, self::CONFIRMED];

    public const RULE_DEFAULTS = [
        'agenda.require_confirmation' => true,
        'agenda.checkin_step'         => true,
        'agenda.respect_buffers'      => true,
        'agenda.client_overlap'       => true,
        'agenda.allow_override'       => true,
        'agenda.slot_interval'        => 15,
        'cancel.require_reason'       => true,
        'cancel.window_hours'         => 12,
        'noshow.grace_minutes'        => 15,
        'penalties.enabled'           => false,
        'noshow.fee_percent'          => 0,
        'cancel.late_fee_percent'     => 0,
        'records.require_on_complete' => false,
        'records.lock_hours'          => 0,
        'records.private_to_author'   => false,
        'recalls.notify_days'         => 3,
        'booking.enabled'             => true,
        'booking.min_lead_minutes'    => 60,
        'booking.max_days_ahead'      => 45,
        'booking.choose_professional' => true,
        'booking.auto_confirm'        => false,
        'booking.require_deposit'     => true,
        'cancel.client_allowed'       => true,
        'reschedule.max_times'        => 2,
        'confirm.deadline_hours'      => 24,
        'confirm.on_miss'             => 'notify',
        'confirm.release_hours'       => 6,
        'resources.equipment_down'    => 'none',
        'pos.prices_include_tax'      => true,
        'pos.tax_rate'                => 18,
        'pos.require_open_cash'       => true,
        'pos.partial_payments'        => false,
        'cash.tolerance'              => 0,
        'cash.review_over'            => 0,
        'pos.refund_approval_over'    => 0,
        'pos.refund_days'             => 30,
        'cash.auto_register'          => true,
        'invoice.default_type'        => 'boleta',
        'pos.pay_before_service'      => true,
        'pos.arrive_minutes'          => 15,
        'pos.tips_enabled'            => false,
        'pos.max_discount'            => 0,
        'booking.miss_deposit_percent' => 50,
        'booking.miss_action'         => 'note',
        'booking.miss_limit'          => 2,
        'booking.block_days'          => 30,
        'payments.link_hours'         => 24,
        'booking.deposit_minutes'     => 30,
        'booking.deposit_refund_hours' => 24,
        'booking.deposit_on_cancel'   => 'credit',
        'session.idle_minutes'        => 30,
        'session.portal_idle_minutes' => 0,
        'session.idle_by_role'        => [],
        'campaigns.manager_scope'     => 'branch',
        'booking.count_late_cancel'   => false,
        'help.enabled'                => true,
        'crm.inactive_days'           => 60,
        'crm.frequent_visits'         => 4,
        'crm.vip_spend'               => 1500,
        'inventory.allow_negative'    => false,
        'inventory.consume_on_service' => true,
        'inventory.transfer_two_step' => true,
        'inventory.cost_method'       => 'last',
        'inventory.alert_min_stock'   => true,
        'commission.base'             => 'net',
        'commission.on_products'      => true,
        'commission.on_package_use'   => true,
        'commission.auto_approve'     => false,
        'loyalty.points_enabled'      => true,
        'loyalty.amount_per_point'    => 10,
        'loyalty.point_value'         => 0.5,
        'loyalty.min_redeem_points'   => 20,
        'loyalty.points_validity_days' => 365,
        'loyalty.gift_cards_enabled'  => true,
        'loyalty.gift_card_validity_days' => 365,
        'quotes.validity_days'        => 30,
        'quotes.allow_installments'   => true,
        'quotes.auto_create_sale'     => true,
        'quotes.track_clinical_zones' => true,
        'quotes.plan_validity_days'   => 365,
        'quotes.cancel_policy'        => 'unused_quote',
        'quotes.portal_accept'        => true,
        'walkin.self_checkin'         => true,
        'walkin.max_wait'             => 90,
        'classes.waitlist'            => true,
        'classes.auto_promote'        => true,
        'classes.portal_leave_hours'  => 2,
        'waitlist.enabled'            => true,
        'waitlist.notify_mode'        => 'all',
        'waitlist.reply_hours'        => 2,
        'membership.grace_days'       => 5,
        'surveys.enabled'             => false,
        'surveys.delay_hours'         => 2,
        'surveys.google_min'          => 9,
        'surveys.publish_reviews'     => true,
        'surveys.valid_days'          => 7,
        'whatsapp.channel'            => 'email',
        'whatsapp.consent'            => 'required',
        'assistant.enabled'           => false,
        'assistant.can_book'          => true,
        'assistant.handoff_minutes'   => 10,
        'assistant.blocked_words'     => 'diagnóstico, diagnostico, receta, medicamento, medicina, pastilla, dosis, síntoma, sintoma, dolor, sangrado, infección, infeccion, fiebre, alergia, embarazo, resultado de examen',
    ];

    public static function keys(): array
    {
        return array_keys(self::STATUSES);
    }

    public static function rule(Tenant $tenant, string $key): mixed
    {
        return $tenant->rules()[$key] ?? self::RULE_DEFAULTS[$key] ?? null;
    }

    public static function recordRequired(Tenant $tenant, ?object $appointment): bool
    {
        return (bool) self::rule($tenant, 'records.require_on_complete')
            || (CatalogExtras::serviceFields() && (bool) ($appointment?->service?->requires_record ?? false));
    }

    public static function next(Tenant $tenant, string $status): array
    {
        $next = self::TRANSITIONS[$status] ?? [];

        if (!self::rule($tenant, 'agenda.checkin_step') && in_array($status, [self::PENDING, self::CONFIRMED], true)) {
            $next[] = self::COMPLETED;
        }

        return $next;
    }

    public static function permission(string $from, string $to): string
    {
        return match ($to) {
            self::PENDING     => 'agenda.confirm',
            self::CONFIRMED   => $from === self::CHECKED_IN ? 'agenda.checkin' : 'agenda.confirm',
            self::CHECKED_IN  => 'agenda.checkin',
            self::IN_PROGRESS => 'agenda.start',
            self::COMPLETED   => 'agenda.complete',
            self::NO_SHOW     => 'agenda.noshow',
            self::CANCELLED   => 'agenda.cancel',
            default           => 'agenda.edit',
        };
    }

    public static function allowedNext(Tenant $tenant, string $status, mixed $user): array
    {
        $next = self::next($tenant, $status);

        if (!$user instanceof \App\Models\User) {
            return $next;
        }

        return array_values(array_filter($next, fn (string $to) => $user->allows(self::permission($status, $to))));
    }

    public static function forTenant(Tenant $tenant): array
    {
        $names = $tenant->settings?->appointment_statuses ?? [];

        return collect(self::STATUSES)->map(fn (array $status, string $key) => [
            'key'          => $key,
            'name'         => $names[$key] ?? $status['name'],
            'default_name' => $status['name'],
            'tone'         => $status['tone'],
        ])->values()->all();
    }

    public static function name(Tenant $tenant, string $status): string
    {
        return $tenant->settings?->appointment_statuses[$status] ?? self::STATUSES[$status]['name'] ?? $status;
    }
}
