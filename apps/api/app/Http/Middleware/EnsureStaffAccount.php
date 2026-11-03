<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user() instanceof User) {
            return response()->json([
                'message' => 'Esta cuenta no tiene acceso al panel de la empresa.'
            ], 403);
        }

        if ($request->user()->tenant_id && $request->user()->status === 'inactive') {
            return response()->json(['message' => 'Tu cuenta está desactivada. Comunícate con quien administra tu empresa.'], 401);
        }

        $impersonating = str_starts_with((string) $request->user()->currentAccessToken()?->name, 'impersonation:');

        if ($request->user()->must_change_password && !$impersonating && !$request->is('api/account/password')) {
            return response()->json([
                'message' => 'Antes de continuar, crea tu propia contraseña.',
                'code'    => 'PASSWORD_CHANGE_REQUIRED',
            ], 403);
        }

        return $next($request);
    }
}
