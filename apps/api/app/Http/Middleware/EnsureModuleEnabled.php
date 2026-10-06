<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea la ruta si el tenant del usuario no tiene activo el módulo indicado
 * (por plan, rubro o configuración propia). Uso: ->middleware('module:inventory').
 * Ocultar el módulo en el menú no basta: la API también debe rechazarlo.
 */
class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $tenant = $request->user()?->tenant;

        // El admin SaaS no pertenece a un tenant y no está sujeto a planes.
        if ($tenant && !$tenant->hasModule($module)) {
            return response()->json([
                'message' => 'Este módulo no está disponible para tu empresa.',
                'code'    => 'MODULE_DISABLED',
                'module'  => $module,
            ], 403);
        }

        return $next($request);
    }
}
