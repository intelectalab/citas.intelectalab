<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    public const TOKEN_PREFIX = 'impersonation:';
    public const HOURS = 2;

    public function start(Request $request, string $tenant)
    {
        $tenant = Tenant::findOrFail($tenant);

        $owner = $tenant->users()
            ->where('status', 'active')
            ->orderByDesc('is_owner')
            ->orderBy('created_at')
            ->first();

        if (!$owner || !$owner->is_owner) {
            return response()->json([
                'message' => 'La empresa no tiene una cuenta de dueño activa para ingresar.',
            ], 422);
        }

        $token = $owner->createToken(
            self::TOKEN_PREFIX . $request->user()->id,
            ['*'],
            now()->addHours((int) (PlatformSetting::value('impersonation_hours') ?: self::HOURS)),
        );

        AuditEvent::record($request, 'impersonation.started', $tenant, null, [
            'as_user_id' => $owner->id,
            'expires_at' => $token->accessToken->expires_at,
        ]);

        return response()->json([
            'access_token' => $token->plainTextToken,
            'token_type'   => 'Bearer',
            'expires_at'   => $token->accessToken->expires_at,
            'tenant'       => ['id' => $tenant->id, 'name' => $tenant->name],
        ]);
    }

    public function stop(Request $request)
    {
        $token = $request->user()->currentAccessToken();

        abort_unless(str_starts_with((string) $token->name, self::TOKEN_PREFIX), 422, 'Esta no es una sesión de soporte.');

        $tenant = $request->user()->tenant;
        $token->delete();

        $platformUser = \App\Models\User::find(substr($token->name, strlen(self::TOKEN_PREFIX)));

        \App\Support\Audit::log($request, 'impersonation.ended', [
            'actor'        => $platformUser,
            'impersonator' => null,
            'tenant'       => $tenant,
            'entity_type'  => 'tenant',
            'entity_id'    => $tenant?->id,
            'entity_label' => $tenant?->name,
        ]);

        return response()->json(['message' => 'Sesión de soporte cerrada.']);
    }
}
