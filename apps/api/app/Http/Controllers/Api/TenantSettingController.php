<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SaasModule;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Support\AppointmentFlow;
use App\Support\Audit;
use App\Support\Confirmations;
use App\Support\Inventory;
use App\Support\Loyalty;
use App\Support\OnlineBooking;
use App\Support\Quotes;
use App\Support\Sales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TenantSettingController extends Controller
{
    private const AUDIT_ON_MISS = [
        'notify'         => 'Solo avisar',
        'notify_release' => 'Avisar y luego liberar',
        'release'        => 'Liberar de inmediato',
    ];

    private const AUDIT_MISS_ACTIONS = [
        'none'  => 'No pasa nada',
        'note'  => 'Dejar una nota',
        'block' => 'Nota y dejar de reservar en línea',
    ];

    private const AUDIT_EQUIPMENT_DOWN = [
        'none'    => 'No pasa nada',
        'observe' => 'Mostrarlo como observación',
        'disable' => 'Dejar la sala fuera de uso',
    ];

    private const AUDIT_CANCEL_POLICIES = [
        'unused_quote'  => 'Lo usado al precio del presupuesto',
        'unused_normal' => 'Lo usado a precio normal',
        'none'          => 'No se devuelve dinero',
    ];

    private function tenant(Request $request): Tenant
    {
        $user = $request->user();

        abort_unless($user->allows('settings.view'), 403, 'Tu cuenta no tiene permiso para ver la configuración de la empresa.');

        return $user->tenant->load(['businessType', 'settings', 'activeSubscription.plan']);
    }

    public function show(Request $request)
    {
        return response()->json($this->payload($this->tenant($request)));
    }

    public function update(Request $request)
    {
        $tenant = $this->tenant($request);
        $toggleable = $tenant->toggleableModules();

        $validated = $request->validate([
            'modules_off'   => 'sometimes|array',
            'modules_off.*' => ['string', 'distinct', Rule::in($toggleable)],
            'vocabulary'    => 'sometimes|array',
            'appointment_statuses' => 'sometimes|array',
            'booking_rules' => 'sometimes|array',
            'booking_rules.confirm_deadline_hours' => 'required_with:booking_rules|integer|min:0|max:168',
            'booking_rules.confirm_on_miss'        => ['required_with:booking_rules', Rule::in(Confirmations::MODES)],
            'booking_rules.confirm_release_hours'  => 'required_with:booking_rules|integer|min:0|max:168',
            'booking_rules.cancel_window_hours'    => 'required_with:booking_rules|integer|min:0|max:168',
            'equipment_down' => ['sometimes', Rule::in(['none', 'observe', 'disable'])],
            'sales_rules' => 'sometimes|array',
            'sales_rules.prices_include_tax' => 'required_with:sales_rules|boolean',
            'sales_rules.tax_rate'           => 'required_with:sales_rules|numeric|min:0|max:100',
            'sales_rules.require_open_cash'  => 'required_with:sales_rules|boolean',
            'sales_rules.partial_payments'   => 'required_with:sales_rules|boolean',
            'sales_rules.cash_tolerance'     => 'required_with:sales_rules|numeric|min:0|max:9999',
            'sales_rules.auto_register'      => 'sometimes|boolean',
            'sales_rules.refund_days'        => 'sometimes|integer|min:0|max:365',
            'sales_rules.pay_before_service' => 'sometimes|boolean',
            'sales_rules.arrive_minutes'     => 'sometimes|integer|min:0|max:240',
            'payment_rules' => 'sometimes|array',
            'payment_rules.require_deposit'      => 'required_with:payment_rules|boolean',
            'payment_rules.deposit_minutes'      => 'required_with:payment_rules|integer|min:5|max:1440',
            'payment_rules.deposit_refund_hours' => 'required_with:payment_rules|integer|min:0|max:720',
            'payment_rules.link_hours'           => 'required_with:payment_rules|integer|min:1|max:720',
            'booking_misses' => 'sometimes|array',
            'booking_misses.action'     => ['required_with:booking_misses', Rule::in(['none', 'note', 'block'])],
            'booking_misses.limit'      => 'required_with:booking_misses|integer|min:1|max:20',
            'booking_misses.block_days' => 'required_with:booking_misses|integer|min:0|max:3650',
            'session_idle_minutes' => 'sometimes|integer|min:5|max:1440',
            'help_enabled' => ['sometimes', 'boolean', fn ($attribute, $value, $fail) => $request->user()->isTenantAdmin() ? null : $fail('Solo el administrador de la empresa puede encender o apagar la autoayuda.')],
            'customer_groups' => 'sometimes|array',
            'customer_groups.inactive_days'   => 'required_with:customer_groups|integer|min:15|max:730',
            'customer_groups.frequent_visits' => 'required_with:customer_groups|integer|min:2|max:60',
            'customer_groups.vip_spend'       => 'required_with:customer_groups|numeric|min:1|max:9999999',
            'inventory_rules' => 'sometimes|array',
            'inventory_rules.allow_negative'     => 'required_with:inventory_rules|boolean',
            'inventory_rules.consume_on_service' => 'required_with:inventory_rules|boolean',
            'inventory_rules.transfer_two_step'  => 'required_with:inventory_rules|boolean',
            'inventory_rules.cost_method'        => ['required_with:inventory_rules', Rule::in(array_keys(Inventory::COST_METHODS))],
            'inventory_rules.alert_min_stock'    => 'required_with:inventory_rules|boolean',
            'loyalty_rules' => 'sometimes|array',
            'loyalty_rules.points_enabled'          => 'required_with:loyalty_rules|boolean',
            'loyalty_rules.amount_per_point'        => 'required_with:loyalty_rules|numeric|min:0.1|max:999999',
            'loyalty_rules.point_value'             => 'required_with:loyalty_rules|numeric|min:0.01|max:999999',
            'loyalty_rules.min_redeem_points'       => 'required_with:loyalty_rules|integer|min:1|max:999999',
            'loyalty_rules.points_validity_days'    => 'required_with:loyalty_rules|integer|min:0|max:3650',
            'loyalty_rules.gift_cards_enabled'      => 'required_with:loyalty_rules|boolean',
            'loyalty_rules.gift_card_validity_days' => 'required_with:loyalty_rules|integer|min:0|max:3650',
            'quote_rules' => 'sometimes|array',
            'quote_rules.validity_days'        => 'required_with:quote_rules|integer|min:0|max:3650',
            'quote_rules.allow_installments'   => 'required_with:quote_rules|boolean',
            'quote_rules.auto_create_sale'     => 'required_with:quote_rules|boolean',
            'quote_rules.track_clinical_zones' => 'required_with:quote_rules|boolean',
            'quote_rules.plan_validity_days'   => 'required_with:quote_rules|integer|min:1|max:3650',
            'quote_rules.cancel_policy'        => ['required_with:quote_rules', Rule::in(array_keys(Quotes::CANCEL_POLICIES))],
            'walkin_rules' => 'sometimes|array',
            'walkin_rules.self_checkin' => 'required_with:walkin_rules|boolean',
            'walkin_rules.max_wait'     => 'required_with:walkin_rules|integer|min:10|max:300',
            'session_portal_idle_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440', fn ($attribute, $value, $fail) => $value > 0 && $value < 5 ? $fail('El tiempo sin actividad del portal va de 5 a 1440 minutos (24 horas).') : null],
        ] + collect(array_keys(Tenant::DEFAULT_VOCABULARY))
            ->mapWithKeys(fn ($key) => ["vocabulary.$key" => 'nullable|string|max:40'])
            ->all()
          + collect(Tenant::GENDERED)
            ->mapWithKeys(fn ($key) => ["vocabulary.{$key}_gender" => ['nullable', Rule::in(['m', 'f'])]])
            ->all()
          + collect(AppointmentFlow::keys())
            ->mapWithKeys(fn ($key) => ["appointment_statuses.$key" => 'nullable|string|max:30'])
            ->all(), [
            'modules_off.*.in' => 'Ese módulo no está disponible para tu plan.',
            'vocabulary.client_gender.in' => 'Elige si se dice "el" o "la".',
            'vocabulary.pro_gender.in' => 'Elige si se dice "el" o "la".',
            'vocabulary.dependent_gender.in' => 'Elige si se dice "el" o "la".',
            'booking_rules.*.integer' => 'Escribe las horas como un número entero.',
            'booking_rules.*.min'     => 'Las horas no pueden ser negativas.',
            'booking_rules.*.max'     => 'El plazo máximo es de 168 horas (una semana).',
            'sales_rules.tax_rate.*'       => 'Escribe el impuesto como un porcentaje entre 0 y 100.',
            'sales_rules.cash_tolerance.*' => 'Escribe la tolerancia como un monto entre 0 y 9999.',
            'payment_rules.deposit_minutes.*'      => 'El tiempo para pagar el adelanto va de 5 a 1440 minutos.',
            'payment_rules.deposit_refund_hours.*' => 'Las horas para devolver el adelanto van de 0 a 720.',
            'payment_rules.link_hours.*'           => 'Un link de pago vence entre 1 y 720 horas después.',
            'session_idle_minutes.*'               => 'El tiempo sin actividad va de 5 a 1440 minutos (24 horas).',
            'customer_groups.inactive_days.*'      => 'Los días para considerar inactivo a un cliente van de 15 a 730.',
            'customer_groups.frequent_visits.*'    => 'Las visitas para ser frecuente van de 2 a 60.',
            'customer_groups.vip_spend.*'          => 'Escribe el monto VIP como un número mayor que 0.',
            'session_portal_idle_minutes.integer'  => 'El tiempo sin actividad del portal va de 5 a 1440 minutos (24 horas).',
            'session_portal_idle_minutes.min'      => 'El tiempo sin actividad del portal va de 5 a 1440 minutos (24 horas).',
            'session_portal_idle_minutes.max'      => 'El tiempo sin actividad del portal va de 5 a 1440 minutos (24 horas).',
        ]);

        if (isset($validated['booking_rules'])) {
            $rules = $validated['booking_rules'];

            if ($rules['confirm_on_miss'] === Confirmations::NOTIFY_RELEASE && $rules['confirm_release_hours'] >= $rules['confirm_deadline_hours']) {
                throw ValidationException::withMessages([
                    'booking_rules.confirm_release_hours' => 'La cita debe liberarse más cerca de su hora que el plazo para confirmar: usa un número de horas menor.',
                ]);
            }
        }

        $auditBefore = rescue(fn () => $this->auditSnapshot($tenant), null);
        $settings = $tenant->settings ?? new TenantSetting(['tenant_id' => $tenant->id]);

        if (array_key_exists('modules_off', $validated)) {
            $outOfReach = array_diff($settings->modules_off ?? [], $toggleable);
            $settings->modules_off = array_values(array_unique(array_merge($outOfReach, $validated['modules_off'])));
        }

        if (array_key_exists('vocabulary', $validated)) {
            $before = $tenant->vocabulary();
            $settings->vocabulary = collect($validated['vocabulary'])
                ->only([...array_keys(Tenant::DEFAULT_VOCABULARY), ...array_map(fn ($key) => "{$key}_gender", Tenant::GENDERED)])
                ->map(fn ($value) => trim((string) $value))
                ->filter()
                ->all();
            $after = Tenant::withGenders(
                array_merge(Tenant::DEFAULT_VOCABULARY, array_intersect_key($tenant->businessProfile('vocabulary'), Tenant::DEFAULT_VOCABULARY), array_intersect_key($settings->vocabulary, Tenant::DEFAULT_VOCABULARY)),
                $settings->vocabulary + $tenant->businessProfile('vocabulary'),
            );
            $changedWords = array_keys(array_filter($after, fn ($value, $key) => ($before[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));
            if ($changedWords) {
                \App\Models\AuditEvent::record($request, 'tenant.vocabulary_changed', $tenant, array_intersect_key($before, array_flip($changedWords)), array_intersect_key($after, array_flip($changedWords)));
            }
        }

        if (array_key_exists('appointment_statuses', $validated) && Schema::hasColumn('tenant_settings', 'appointment_statuses')) {
            $settings->appointment_statuses = collect($validated['appointment_statuses'])
                ->only(AppointmentFlow::keys())
                ->map(fn ($value) => trim((string) $value))
                ->filter()
                ->all();
        }

        if (isset($validated['booking_rules'])) {
            $settings->rules = array_merge($settings->rules ?? [], [
                'confirm.deadline_hours' => (int) $validated['booking_rules']['confirm_deadline_hours'],
                'confirm.on_miss'        => $validated['booking_rules']['confirm_on_miss'],
                'confirm.release_hours'  => (int) $validated['booking_rules']['confirm_release_hours'],
                'cancel.window_hours'    => (int) $validated['booking_rules']['cancel_window_hours'],
            ]);
        }

        if (isset($validated['equipment_down'])) {
            $settings->rules = array_merge($settings->rules ?? [], ['resources.equipment_down' => $validated['equipment_down']]);
        }

        if (isset($validated['sales_rules'])) {
            $settings->rules = array_merge($settings->rules ?? [], [
                'pos.prices_include_tax' => (bool) $validated['sales_rules']['prices_include_tax'],
                'pos.tax_rate'           => round((float) $validated['sales_rules']['tax_rate'], 2),
                'pos.require_open_cash'  => (bool) $validated['sales_rules']['require_open_cash'],
                'pos.partial_payments'   => (bool) $validated['sales_rules']['partial_payments'],
                'cash.tolerance'         => round((float) $validated['sales_rules']['cash_tolerance'], 2),
            ] + (array_key_exists('auto_register', $validated['sales_rules']) ? ['cash.auto_register' => (bool) $validated['sales_rules']['auto_register']] : [])
              + (array_key_exists('refund_days', $validated['sales_rules']) ? ['pos.refund_days' => (int) $validated['sales_rules']['refund_days']] : [])
              + (array_key_exists('pay_before_service', $validated['sales_rules']) ? ['pos.pay_before_service' => (bool) $validated['sales_rules']['pay_before_service']] : [])
              + (array_key_exists('arrive_minutes', $validated['sales_rules']) ? ['pos.arrive_minutes' => (int) $validated['sales_rules']['arrive_minutes']] : []));
        }

        if (isset($validated['payment_rules'])) {
            $settings->rules = array_merge($settings->rules ?? [], [
                'booking.require_deposit'      => (bool) $validated['payment_rules']['require_deposit'],
                'booking.deposit_minutes'      => (int) $validated['payment_rules']['deposit_minutes'],
                'booking.deposit_refund_hours' => (int) $validated['payment_rules']['deposit_refund_hours'],
                'payments.link_hours'          => (int) $validated['payment_rules']['link_hours'],
            ]);
        }

        if (isset($validated['booking_misses'])) {
            $settings->rules = array_merge($settings->rules ?? [], [
                'booking.miss_action' => $validated['booking_misses']['action'],
                'booking.miss_limit'  => (int) $validated['booking_misses']['limit'],
                'booking.block_days'  => (int) $validated['booking_misses']['block_days'],
            ]);
        }

        if (array_key_exists('help_enabled', $validated)) {
            $settings->rules = array_merge($settings->rules ?? [], ['help.enabled' => (bool) $validated['help_enabled']]);
        }

        if (isset($validated['session_idle_minutes'])) {
            $settings->rules = array_merge($settings->rules ?? [], ['session.idle_minutes' => (int) $validated['session_idle_minutes']]);
        }

        if (isset($validated['customer_groups'])) {
            $settings->rules = array_merge($settings->rules ?? [], [
                'crm.inactive_days'   => (int) $validated['customer_groups']['inactive_days'],
                'crm.frequent_visits' => (int) $validated['customer_groups']['frequent_visits'],
                'crm.vip_spend'       => round((float) $validated['customer_groups']['vip_spend'], 2),
            ]);
        }

        if (isset($validated['session_portal_idle_minutes'])) {
            $settings->rules = array_merge($settings->rules ?? [], ['session.portal_idle_minutes' => (int) $validated['session_portal_idle_minutes']]);
        }

        if (isset($validated['inventory_rules'])) {
            $settings->rules = array_merge($settings->rules ?? [], [
                'inventory.allow_negative'     => (bool) $validated['inventory_rules']['allow_negative'],
                'inventory.consume_on_service' => (bool) $validated['inventory_rules']['consume_on_service'],
                'inventory.transfer_two_step'  => (bool) $validated['inventory_rules']['transfer_two_step'],
                'inventory.cost_method'        => (string) $validated['inventory_rules']['cost_method'],
                'inventory.alert_min_stock'    => (bool) $validated['inventory_rules']['alert_min_stock'],
            ]);
        }

        if (isset($validated['loyalty_rules'])) {
            $settings->rules = array_merge($settings->rules ?? [], [
                'loyalty.points_enabled'          => (bool) $validated['loyalty_rules']['points_enabled'],
                'loyalty.amount_per_point'        => round((float) $validated['loyalty_rules']['amount_per_point'], 2),
                'loyalty.point_value'             => round((float) $validated['loyalty_rules']['point_value'], 2),
                'loyalty.min_redeem_points'       => (int) $validated['loyalty_rules']['min_redeem_points'],
                'loyalty.points_validity_days'    => (int) $validated['loyalty_rules']['points_validity_days'],
                'loyalty.gift_cards_enabled'      => (bool) $validated['loyalty_rules']['gift_cards_enabled'],
                'loyalty.gift_card_validity_days' => (int) $validated['loyalty_rules']['gift_card_validity_days'],
            ]);
        }

        if (isset($validated['quote_rules'])) {
            $settings->rules = array_merge($settings->rules ?? [], [
                'quotes.validity_days'        => (int) $validated['quote_rules']['validity_days'],
                'quotes.allow_installments'   => (bool) $validated['quote_rules']['allow_installments'],
                'quotes.auto_create_sale'     => (bool) $validated['quote_rules']['auto_create_sale'],
                'quotes.track_clinical_zones' => (bool) $validated['quote_rules']['track_clinical_zones'],
                'quotes.plan_validity_days'   => (int) $validated['quote_rules']['plan_validity_days'],
                'quotes.cancel_policy'        => $validated['quote_rules']['cancel_policy'],
            ]);
        }

        if (isset($validated['walkin_rules'])) {
            $settings->rules = array_merge($settings->rules ?? [], [
                'walkin.self_checkin' => (bool) $validated['walkin_rules']['self_checkin'],
                'walkin.max_wait'     => (int) $validated['walkin_rules']['max_wait'],
            ]);
        }

        $settings->save();

        $fresh = $tenant->fresh(['businessType', 'settings', 'activeSubscription.plan']);
        $auditAfter = $auditBefore === null ? [] : rescue(fn () => $this->auditSnapshot($fresh), []);

        foreach ($auditAfter as $section => $values) {
            [$from, $to] = Audit::changed($auditBefore[$section] ?? [], $values);
            if ($to) {
                Audit::log($request, 'settings.changed', [
                    'tenant'       => $tenant->id,
                    'entity_type'  => 'settings',
                    'entity_label' => $section,
                    'before'       => $from,
                    'after'        => $to,
                ]);
            }
        }

        return response()->json($this->payload($fresh));
    }

    private function payload(Tenant $tenant): array
    {
        $plan = $tenant->activeSubscription?->plan;
        $planKeys = $plan?->moduleKeys() ?? [];
        $platformOff = $tenant->modules_off ?? [];
        $active = $tenant->modules();
        $toggleable = $tenant->toggleableModules();

        $modules = SaasModule::orderBy('sort_order')->get()->map(function (SaasModule $module) use ($plan, $planKeys, $platformOff, $active, $toggleable) {
            $locked = match (true) {
                !$plan                                  => 'no_subscription',
                $module->is_core                        => 'core',
                !in_array($module->key, $planKeys, true) => 'plan',
                in_array($module->key, $platformOff, true) => 'platform',
                default                                 => null,
            };

            return [
                'key'        => $module->key,
                'name'       => $module->name,
                'is_core'    => $module->is_core,
                'enabled'    => in_array($module->key, $active, true),
                'can_toggle' => in_array($module->key, $toggleable, true),
                'locked'     => $locked,
            ];
        });

        return [
            'tenant' => [
                'name'               => $tenant->name,
                'code'               => $tenant->code,
                'business_type_name' => $tenant->businessType?->name,
                'plan_name'          => $plan?->name,
            ],
            'modules' => $modules,
            'vocabulary' => [
                'defaults'  => Tenant::withGenders(array_merge(Tenant::DEFAULT_VOCABULARY, array_intersect_key($tenant->businessProfile('vocabulary'), Tenant::DEFAULT_VOCABULARY)), $tenant->businessProfile('vocabulary')),
                'overrides' => (object) ($tenant->settings?->vocabulary ?? []),
                'current'   => $tenant->vocabulary(),
            ],
            'dependents' => $tenant->dependents(),
            'appointment_statuses' => AppointmentFlow::forTenant($tenant),
            'booking_rules' => [
                'confirm_deadline_hours' => (int) AppointmentFlow::rule($tenant, 'confirm.deadline_hours'),
                'confirm_on_miss'        => Confirmations::mode($tenant),
                'confirm_release_hours'  => (int) AppointmentFlow::rule($tenant, 'confirm.release_hours'),
                'cancel_window_hours'    => (int) AppointmentFlow::rule($tenant, 'cancel.window_hours'),
                'uses_confirmation'      => (bool) AppointmentFlow::rule($tenant, 'agenda.require_confirmation'),
            ],
            'resources' => $tenant->hasModule('resources')
                ? ['equipment_down' => in_array(AppointmentFlow::rule($tenant, 'resources.equipment_down'), ['observe', 'disable'], true) ? AppointmentFlow::rule($tenant, 'resources.equipment_down') : 'none']
                : null,
            'sales_rules' => $tenant->hasModule('pos') ? Sales::rules($tenant) : null,
            'inventory_rules' => Inventory::enabled($tenant) ? Inventory::rules($tenant) : null,
            'loyalty_rules' => Loyalty::enabled($tenant) ? Loyalty::rules($tenant) : null,
            'quote_rules' => Quotes::enabled($tenant) ? Quotes::rules($tenant) : null,
            'walkin_rules' => $tenant->hasModule('walkin') ? \App\Support\Walkin::rules($tenant) : null,
            'session_idle_minutes' => (int) AppointmentFlow::rule($tenant, 'session.idle_minutes'),
            'help_enabled' => (bool) AppointmentFlow::rule($tenant, 'help.enabled'),
            'session_portal_idle_minutes' => (int) AppointmentFlow::rule($tenant, 'session.portal_idle_minutes'),
            'customer_groups' => $tenant->hasModule('marketing') ? [
                'inactive_days'   => (int) AppointmentFlow::rule($tenant, 'crm.inactive_days'),
                'frequent_visits' => (int) AppointmentFlow::rule($tenant, 'crm.frequent_visits'),
                'vip_spend'       => (float) AppointmentFlow::rule($tenant, 'crm.vip_spend'),
            ] : null,
            'booking_misses' => $tenant->hasModule('booking') ? \App\Support\OnlineBooking::policy($tenant) : null,
            'payment_rules' => $tenant->hasModule('pos') && \App\Support\PaymentLinks::ready() ? [
                'require_deposit'      => (bool) AppointmentFlow::rule($tenant, 'booking.require_deposit'),
                'deposit_minutes'      => (int) AppointmentFlow::rule($tenant, 'booking.deposit_minutes'),
                'deposit_refund_hours' => (int) AppointmentFlow::rule($tenant, 'booking.deposit_refund_hours'),
                'link_hours'           => (int) AppointmentFlow::rule($tenant, 'payments.link_hours'),
                'booking'              => $tenant->hasModule('booking'),
            ] : null,
        ];
    }

    private function auditSnapshot(Tenant $tenant): array
    {
        $active = $tenant->modules();
        $sales = Sales::rules($tenant);
        $quotes = Quotes::rules($tenant);
        $inventory = Inventory::rules($tenant);
        $loyalty = Loyalty::rules($tenant);
        $misses = OnlineBooking::policy($tenant);
        $equipment = AppointmentFlow::rule($tenant, 'resources.equipment_down');
        $onMiss = Confirmations::mode($tenant);

        return [
            'Módulos' => SaasModule::orderBy('sort_order')->get(['key', 'name'])
                ->mapWithKeys(fn (SaasModule $module) => [$module->name => in_array($module->key, $active, true)])
                ->all(),
            'Fases de la cita' => collect(AppointmentFlow::STATUSES)
                ->mapWithKeys(fn (array $status, string $key) => [$status['name'] => AppointmentFlow::name($tenant, $key)])
                ->all(),
            'Reglas de reserva' => [
                'confirm_deadline_hours' => (int) AppointmentFlow::rule($tenant, 'confirm.deadline_hours'),
                'confirm_on_miss'        => self::AUDIT_ON_MISS[$onMiss] ?? $onMiss,
                'confirm_release_hours'  => (int) AppointmentFlow::rule($tenant, 'confirm.release_hours'),
                'cancel_window_hours'    => (int) AppointmentFlow::rule($tenant, 'cancel.window_hours'),
            ],
            'Si reserva en línea y no asiste' => [
                'miss_action'     => self::AUDIT_MISS_ACTIONS[$misses['action']] ?? $misses['action'],
                'miss_limit'      => $misses['limit'],
                'miss_block_days' => $misses['block_days'],
            ],
            'Salas y equipos' => [
                'equipment_down' => self::AUDIT_EQUIPMENT_DOWN[$equipment] ?? self::AUDIT_EQUIPMENT_DOWN['none'],
            ],
            'Ventas y caja' => [
                'prices_include_tax' => $sales['prices_include_tax'],
                'tax_rate'           => $sales['tax_rate'],
                'require_open_cash'  => $sales['require_open_cash'],
                'partial_payments'   => $sales['partial_payments'],
                'cash_tolerance'     => $sales['cash_tolerance'],
                'auto_register'      => $sales['auto_register'],
                'refund_days'        => $sales['refund_days'],
                'pay_before_service' => $sales['pay_before_service'],
                'arrive_minutes'     => $sales['arrive_minutes'],
            ],
            'Presupuestos y planes' => [
                'quote_validity_days'  => $quotes['validity_days'],
                'allow_installments'   => $quotes['allow_installments'],
                'auto_create_sale'     => $quotes['auto_create_sale'],
                'track_clinical_zones' => $quotes['track_clinical_zones'],
                'plan_validity_days'   => $quotes['plan_validity_days'],
                'quote_cancel_policy'  => self::AUDIT_CANCEL_POLICIES[$quotes['cancel_policy']] ?? $quotes['cancel_policy'],
            ],
            'Inventario y almacenes' => [
                'allow_negative'     => $inventory['allow_negative'],
                'consume_on_service' => $inventory['consume_on_service'],
                'transfer_two_step'  => $inventory['transfer_two_step'],
                'cost_method'        => Inventory::COST_METHODS[$inventory['cost_method']] ?? $inventory['cost_method'],
                'alert_min_stock'    => $inventory['alert_min_stock'],
            ],
            'Puntos y gift cards' => [
                'points_enabled'          => $loyalty['points_enabled'],
                'amount_per_point'        => $loyalty['amount_per_point'],
                'point_value'             => $loyalty['point_value'],
                'min_redeem_points'       => $loyalty['min_redeem_points'],
                'points_validity_days'    => $loyalty['points_validity_days'],
                'gift_cards_enabled'      => $loyalty['gift_cards_enabled'],
                'gift_card_validity_days' => $loyalty['gift_card_validity_days'],
            ],
            'Adelantos y links de pago' => [
                'require_deposit'      => (bool) AppointmentFlow::rule($tenant, 'booking.require_deposit'),
                'deposit_minutes'      => (int) AppointmentFlow::rule($tenant, 'booking.deposit_minutes'),
                'deposit_refund_hours' => (int) AppointmentFlow::rule($tenant, 'booking.deposit_refund_hours'),
                'link_hours'           => (int) AppointmentFlow::rule($tenant, 'payments.link_hours'),
            ],
            'Grupos de clientes' => [
                'inactive_days'   => (int) AppointmentFlow::rule($tenant, 'crm.inactive_days'),
                'frequent_visits' => (int) AppointmentFlow::rule($tenant, 'crm.frequent_visits'),
                'vip_spend'       => (float) AppointmentFlow::rule($tenant, 'crm.vip_spend'),
            ],
            'Cierre de sesión' => [
                'session_idle_minutes'        => (int) AppointmentFlow::rule($tenant, 'session.idle_minutes'),
                'session_portal_idle_minutes' => (int) AppointmentFlow::rule($tenant, 'session.portal_idle_minutes'),
            ],
            'Autoayuda' => [
                'help_enabled' => (bool) AppointmentFlow::rule($tenant, 'help.enabled'),
            ],
        ];
    }
}
