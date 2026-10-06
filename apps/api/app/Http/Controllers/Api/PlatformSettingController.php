<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\PlatformSetting;
use Illuminate\Http\Request;

/**
 * Configuración de la plataforma. La parte pública (marca y apariencia) la
 * lee cualquier pantalla; verla completa y cambiarla es del administrador SaaS.
 */
class PlatformSettingController extends Controller
{
    public function publicValues()
    {
        return response()->json(PlatformSetting::publicValues());
    }

    public function show()
    {
        return response()->json([
            'values'   => PlatformSetting::values(),
            'defaults' => PlatformSetting::DEFAULTS,
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'product_name'          => 'sometimes|required|string|max:60',
            'support_email'         => 'sometimes|nullable|email|max:190',
            'accent_color'          => ['sometimes', 'required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'usage_warning_percent' => 'sometimes|required|integer|min:1|max:99',
            'usage_danger_percent'  => 'sometimes|required|integer|min:2|max:100',
            'allow_tenant_signup'   => 'sometimes|required|boolean',
            'impersonation_hours'   => 'sometimes|required|integer|min:1|max:24',
        ], [
            'accent_color.regex' => 'El color debe tener el formato #RRGGBB.',
        ]);

        $before = PlatformSetting::values();
        $merged = array_merge($before, $validated);

        if ($merged['usage_warning_percent'] >= $merged['usage_danger_percent']) {
            return response()->json([
                'message' => 'El porcentaje de aviso debe ser menor que el de alerta.',
                'errors'  => ['usage_warning_percent' => ['El porcentaje de aviso debe ser menor que el de alerta.']],
            ], 422);
        }

        $after = PlatformSetting::put($validated);

        AuditEvent::record($request, 'platform.settings_changed', null, array_intersect_key($before, $validated), $validated);

        return response()->json([
            'values'   => $after,
            'defaults' => PlatformSetting::DEFAULTS,
        ]);
    }
}
