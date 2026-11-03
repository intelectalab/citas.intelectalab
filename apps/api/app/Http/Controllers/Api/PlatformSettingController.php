<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\PlatformSetting;
use App\Support\PlatformLook;
use Illuminate\Http\Request;

class PlatformSettingController extends Controller
{
    public function publicValues()
    {
        return response()->json(PlatformLook::publicPayload());
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
            'mfa_tenant_admins'     => 'sometimes|boolean',
            'mfa_platform'          => 'sometimes|boolean',
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
        PlatformLook::forget();

        AuditEvent::record($request, 'platform.settings_changed', null, array_intersect_key($before, $validated), $validated);

        return response()->json([
            'values'   => $after,
            'defaults' => PlatformSetting::DEFAULTS,
        ]);
    }
}
