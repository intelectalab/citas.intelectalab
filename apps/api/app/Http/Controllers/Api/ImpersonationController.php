<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * Entrar al panel de una empresa para darle soporte. El administrador SaaS o
 * el soporte SaaS reciben una sesión temporal como el dueño de la empresa.
 * Cada ingreso queda en la bitácora y la sesión caduca sola.
 */
class ImpersonationController extends Controller
{
    public const TOKEN_PREFIX = 'impersonation:';
    public const HOURS = 2;

    public function start(Request $request, string $tenant)
    {
        $tenant = Tenant::findOrFail($tenant);

        $owner = $tenant->users()
            ->where('account_type', 'staff')
            ->where('status', 'active')
            ->orderByDesc('is_owner')
            ->orderBy('created_at')
            ->first();

        if (!$owner || !$owner->is_owner) {
            return response()->json([
                'message' => 'La empresa no tiene una cuenta de dueño activa para ingresar.',
            ], 422);
        }

        // El nombre del token guarda quién entró: así /api/user puede avisarlo en pantalla.
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

    /**
     * Cierra la sesión de soporte. Solo aplica a una sesión abierta por la plataforma.
     */
    public function stop(Request $request)
    {
        $token = $request->user()->currentAccessToken();

        abort_unless(str_starts_with((string) $token->name, self::TOKEN_PREFIX), 422, 'Esta no es una sesión de soporte.');

        $tenant = $request->user()->tenant;
        $token->delete();

        AuditEvent::create([
            'tenant_id'     => $tenant?->id,
            'actor_user_id' => substr($token->name, strlen(self::TOKEN_PREFIX)),
            'action'        => 'impersonation.ended',
            'entity_type'   => 'tenant',
            'entity_id'     => $tenant?->id,
            'ip_address'    => $request->ip(),
        ]);

        return response()->json(['message' => 'Sesión de soporte cerrada.']);
    }
}
