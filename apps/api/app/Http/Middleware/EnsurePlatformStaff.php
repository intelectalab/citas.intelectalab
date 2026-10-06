<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rutas de la plataforma (consola del SaaS). Uso:
 *  - ->middleware('saas')        administrador SaaS o soporte SaaS;
 *  - ->middleware('saas:admin')  solo administrador SaaS.
 * El personal de una empresa nunca pasa, sea cual sea su rol.
 */
class EnsurePlatformStaff
{
    public function handle(Request $request, Closure $next, ?string $level = null): Response
    {
        $user = $request->user();

        $allowed = $level === 'admin'
            ? $user?->isSaasAdmin()
            : $user?->isPlatformUser();

        if (!$allowed) {
            return response()->json([
                'message' => 'Esta acción es solo para el equipo de la plataforma.',
            ], 403);
        }

        return $next($request);
    }
}
