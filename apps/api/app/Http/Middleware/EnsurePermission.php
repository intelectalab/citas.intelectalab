<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea la ruta si a quien inició sesión su rol no le permite la acción.
 * Uso: ->middleware('perm:customers.delete'). Con varios separados por "|"
 * basta con tener uno: ->middleware('perm:sales.view|sales.create').
 * Ocultar el botón en pantalla no basta: la API también debe rechazarlo.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();

        // El equipo de la plataforma no pertenece a una empresa y no tiene rol en ella.
        if (!$user instanceof User || !$user->tenant_id) {
            return $next($request);
        }

        $options = explode('|', $permissions);

        foreach ($options as $permission) {
            if ($user->allows($permission)) {
                return $next($request);
            }
        }

        return response()->json([
            'message'    => Access::denial($options[0], $user->tenant),
            'code'       => 'PERMISSION_DENIED',
            'permission' => $options[0],
        ], 403);
    }
}
