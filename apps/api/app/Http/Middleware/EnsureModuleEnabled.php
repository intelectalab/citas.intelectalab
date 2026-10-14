<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $tenant = $request->user()?->tenant;

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
