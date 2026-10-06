<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las cuentas del portal de cliente comparten la tabla users con el personal,
 * pero no pueden usar las rutas del backoffice.
 */
class EnsureStaffAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isCustomer()) {
            return response()->json([
                'message' => 'Esta cuenta no tiene acceso al panel de la empresa.'
            ], 403);
        }

        return $next($request);
    }
}
