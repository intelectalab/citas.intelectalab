<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las rutas del portal son solo para cuentas de cliente con su ficha de
 * cliente vinculada. El personal usa el panel de la empresa.
 */
class EnsureCustomerAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user?->isCustomer() || !$user->customer) {
            return response()->json([
                'message' => 'Esta cuenta no tiene acceso al portal de clientes.'
            ], 403);
        }

        return $next($request);
    }
}
