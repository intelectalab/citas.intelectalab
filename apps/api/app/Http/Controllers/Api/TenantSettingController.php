<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SaasModule;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Support\AppointmentFlow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

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
        ] + collect(array_keys(Tenant::DEFAULT_VOCABULARY))
            ->mapWithKeys(fn ($key) => ["vocabulary.$key" => 'nullable|string|max:40'])
            ->all()
          + collect(AppointmentFlow::keys())
            ->mapWithKeys(fn ($key) => ["appointment_statuses.$key" => 'nullable|string|max:30'])
            ->all(), [
            'modules_off.*.in' => 'Ese módulo no está disponible para tu plan y rubro.',
        ]);

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
        ];
    }
}
