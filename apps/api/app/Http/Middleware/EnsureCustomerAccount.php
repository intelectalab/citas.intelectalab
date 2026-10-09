<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las rutas del portal son solo para clientes. El personal usa el panel de la
 * empresa. Mientras el cliente siga con su contraseña inicial (su documento)
 * solo puede ver su resumen y crear la suya.
 */
class EnsureCustomerAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = $request->user();

        if (!$customer instanceof Customer || !$customer->canLogin()) {
            return response()->json([
                'message' => 'Esta cuenta no tiene acceso al portal de clientes.'
            ], 403);
        }

        if (!$customer->tenant?->isActive()) {
            return response()->json([
                'message' => 'El portal de esta empresa no está disponible por ahora.',
                'code'    => 'TENANT_SUSPENDED',
            ], 403);
        }

        if ($customer->must_change_password && !$request->is('api/portal/overview', 'api/portal/password')) {
            return response()->json([
                'message' => 'Antes de continuar, crea tu contraseña.',
                'code'    => 'PASSWORD_CHANGE_REQUIRED',
            ], 403);
        }

        return $next($request);
    }
}
