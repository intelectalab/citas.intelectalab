<?php

namespace App\Http\Middleware;

use App\Support\Subscriptions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlanPaid
{
    private const OPEN = [
        'api/billing/*', 'api/subscriptions/*', 'api/notifications*', 'api/data-exports', 'api/logout', 'api/impersonation/*',
    ];

    public function handle(Request $request, Closure $next, ?string $area = null): Response
    {
        $tenantId = $request->user()?->tenant_id;

        if (!$tenantId || in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) || ($area !== 'portal' && $request->is(...self::OPEN))) {
            return $next($request);
        }

        if (!Subscriptions::lockedFor($tenantId)) {
            return $next($request);
        }

        return response()->json([
            'message' => $area === 'portal' ? Subscriptions::PORTAL_LOCKED : Subscriptions::lockMessage($tenantId),
            'code'    => 'PLAN_LOCKED',
        ], 402);
    }
}
