<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El panel de la empresa y la consola son solo para el personal (users). Un
 * cliente inicia sesión con su ficha (customers) y no pasa de aquí.
 */
class EnsureStaffAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user() instanceof User) {
            return response()->json([
                'message' => 'Esta cuenta no tiene acceso al panel de la empresa.'
            ], 403);
        }

        // Una cuenta desactivada deja de entrar aunque tuviera la sesión abierta.
        if ($request->user()->tenant_id && $request->user()->status === 'inactive') {
            return response()->json(['message' => 'Tu cuenta está desactivada. Comunícate con quien administra tu empresa.'], 401);
        }

        return $next($request);
    }
}
