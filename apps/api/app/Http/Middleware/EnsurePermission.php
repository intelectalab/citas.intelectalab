<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();

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
