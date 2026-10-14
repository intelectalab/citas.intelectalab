<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
