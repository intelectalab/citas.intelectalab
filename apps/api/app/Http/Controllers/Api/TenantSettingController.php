<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SaasModule;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Support\AppointmentFlow;
use App\Support\Confirmations;
use App\Support\Sales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Configuración que la empresa maneja por su cuenta. Solo la ve y la cambia
 * su administrador, y solo dentro de lo que le dan su plan y su rubro: puede
 * apagar y volver a encender módulos que ya tiene, no obtener otros.
 */
class TenantSettingController extends Controller
{
    private function tenant(Request $request): Tenant
    {
        $user = $request->user();

        abort_unless($user->isTenantAdmin(), 403, 'Solo el administrador de la empresa puede ver o cambiar esta configuración.');

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
            // Solo se aceptan módulos que la empresa tiene por su plan y su rubro.
            'modules_off.*' => ['string', 'distinct', Rule::in($toggleable)],
            'vocabulary'    => 'sometimes|array',
            'appointment_statuses' => 'sometimes|array',
            // Reglas de reserva que la empresa decide por su cuenta.
            'booking_rules' => 'sometimes|array',
            'booking_rules.confirm_deadline_hours' => 'required_with:booking_rules|integer|min:0|max:168',
            'booking_rules.confirm_on_miss'        => ['required_with:booking_rules', Rule::in(Confirmations::MODES)],
            'booking_rules.confirm_release_hours'  => 'required_with:booking_rules|integer|min:0|max:168',
            'booking_rules.cancel_window_hours'    => 'required_with:booking_rules|integer|min:0|max:168',
            // Qué pasa con una sala cuando un equipo suyo queda fuera de uso.
            'equipment_down' => ['sometimes', Rule::in(['none', 'observe', 'disable'])],
            // Ventas y caja: impuesto, caja abierta, pagos por partes y tolerancia del cierre.
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
            // Qué pasa con quien reserva en línea y no asiste.
            'booking_misses' => 'sometimes|array',
            'booking_misses.action'     => ['required_with:booking_misses', Rule::in(['none', 'note', 'block'])],
            'booking_misses.limit'      => 'required_with:booking_misses|integer|min:1|max:20',
            'booking_misses.block_days' => 'required_with:booking_misses|integer|min:0|max:3650',
        ] + collect(array_keys(Tenant::DEFAULT_VOCABULARY))
            ->mapWithKeys(fn ($key) => ["vocabulary.$key" => 'nullable|string|max:40'])
            ->all()
          + collect(AppointmentFlow::keys())
            ->mapWithKeys(fn ($key) => ["appointment_statuses.$key" => 'nullable|string|max:30'])
            ->all(), [
            'modules_off.*.in' => 'Ese módulo no está disponible para tu plan y rubro.',
            'booking_rules.*.integer' => 'Escribe las horas como un número entero.',
            'booking_rules.*.min'     => 'Las horas no pueden ser negativas.',
            'booking_rules.*.max'     => 'El plazo máximo es de 168 horas (una semana).',
            'sales_rules.tax_rate.*'       => 'Escribe el impuesto como un porcentaje entre 0 y 100.',
            'sales_rules.cash_tolerance.*' => 'Escribe la tolerancia como un monto entre 0 y 9999.',
        ]);

        if (isset($validated['booking_rules'])) {
            $rules = $validated['booking_rules'];

            if ($rules['confirm_on_miss'] === Confirmations::NOTIFY_RELEASE && $rules['confirm_release_hours'] >= $rules['confirm_deadline_hours']) {
                throw ValidationException::withMessages([
                    'booking_rules.confirm_release_hours' => 'La cita debe liberarse más cerca de su hora que el plazo para confirmar: usa un número de horas menor.',
                ]);
            }
        }

        $settings = $tenant->settings ?? new TenantSetting(['tenant_id' => $tenant->id]);

        if (array_key_exists('modules_off', $validated)) {
            // Se conserva lo que estuviera apagado fuera de su alcance actual
            // (por ejemplo, de un plan anterior) para no perderlo al guardar.
            $outOfReach = array_diff($settings->modules_off ?? [], $toggleable);
            $settings->modules_off = array_values(array_unique(array_merge($outOfReach, $validated['modules_off'])));
        }

        if (array_key_exists('vocabulary', $validated)) {
            // Un término vacío vuelve al del rubro.
            $settings->vocabulary = collect($validated['vocabulary'])
                ->only(array_keys(Tenant::DEFAULT_VOCABULARY))
                ->map(fn ($value) => trim((string) $value))
                ->filter()
                ->all();
        }

        // Nombre propio de las fases de la cita. Vacío = el nombre por defecto.
        // En una base sin resetear la columna aún no existe: el resto se guarda igual.
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

        if (isset($validated['booking_misses'])) {
            $settings->rules = array_merge($settings->rules ?? [], [
                'booking.miss_action' => $validated['booking_misses']['action'],
                'booking.miss_limit'  => (int) $validated['booking_misses']['limit'],
                'booking.block_days'  => (int) $validated['booking_misses']['block_days'],
            ]);
        }

        $settings->save();

        return response()->json($this->payload($tenant->fresh(['businessType', 'settings', 'activeSubscription.plan'])));
    }

    private function payload(Tenant $tenant): array
    {
        $plan = $tenant->activeSubscription?->plan;
        $planKeys = $plan?->moduleKeys() ?? [];
        $typeOff = $tenant->businessProfile('modules_off');
        $platformOff = $tenant->modules_off ?? [];
        $active = $tenant->modules();
        $toggleable = $tenant->toggleableModules();

        $modules = SaasModule::orderBy('sort_order')->get()->map(function (SaasModule $module) use ($plan, $planKeys, $typeOff, $platformOff, $active, $toggleable) {
            // Por qué la empresa no puede cambiarlo, si es el caso. El rubro va
            // antes que el plan: lo que su rubro no usa no se le ofrece como
            // mejora de plan, porque cambiar de plan no se lo daría.
            $locked = match (true) {
                !$plan                                  => 'no_subscription',
                $module->is_core                        => 'core',
                in_array($module->key, $typeOff, true)  => 'business_type',
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
                // Términos del rubro: lo que se usa cuando la empresa no define el suyo.
                'defaults'  => array_merge(Tenant::DEFAULT_VOCABULARY, $tenant->businessProfile('vocabulary')),
                'overrides' => (object) ($tenant->settings?->vocabulary ?? []),
            ],
            'dependents' => $tenant->dependents(),
            'appointment_statuses' => AppointmentFlow::forTenant($tenant),
            // Lo que rige hoy: lo que la empresa eligió o, si no eligió, lo de su rubro.
            'booking_rules' => [
                'confirm_deadline_hours' => (int) AppointmentFlow::rule($tenant, 'confirm.deadline_hours'),
                'confirm_on_miss'        => Confirmations::mode($tenant),
                'confirm_release_hours'  => (int) AppointmentFlow::rule($tenant, 'confirm.release_hours'),
                'cancel_window_hours'    => (int) AppointmentFlow::rule($tenant, 'cancel.window_hours'),
                // Sin el paso de confirmación en su rubro, el plazo no aplica.
                'uses_confirmation'      => (bool) AppointmentFlow::rule($tenant, 'agenda.require_confirmation'),
            ],
            // Solo para empresas con el módulo de salas y equipos.
            'resources' => $tenant->hasModule('resources')
                ? ['equipment_down' => in_array(AppointmentFlow::rule($tenant, 'resources.equipment_down'), ['observe', 'disable'], true) ? AppointmentFlow::rule($tenant, 'resources.equipment_down') : 'none']
                : null,
            // Solo para empresas con punto de venta y caja.
            'sales_rules' => $tenant->hasModule('pos') ? Sales::rules($tenant) : null,
            // Solo para empresas con reserva en línea.
            'booking_misses' => $tenant->hasModule('booking') ? \App\Support\OnlineBooking::policy($tenant) : null,
        ];
    }
}
