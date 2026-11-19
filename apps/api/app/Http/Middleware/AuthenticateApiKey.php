<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use App\Support\ApiKeys;
use App\Support\Subscriptions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!ApiKeys::ready()) {
            return $this->fail('La API todavía no está disponible.', 'NOT_READY', 503);
        }

        $plain = $request->bearerToken() ?: $request->header('X-Api-Key');
        $key = ApiKeys::find(is_string($plain) ? trim($plain) : null);

        if (!$key) {
            return $this->fail('Falta la clave de API o no es válida. Envíala en el encabezado Authorization: Bearer <clave>.', 'INVALID_API_KEY', 401);
        }

        if ($key->revoked_at) {
            return $this->fail('Esta clave de API fue revocada y ya no funciona.', 'API_KEY_REVOKED', 401);
        }

        $limiter = 'api-key:' . $key->id;

        if (RateLimiter::tooManyAttempts($limiter, ApiKeys::PER_MINUTE)) {
            return $this->fail('Se hicieron demasiadas solicitudes con esta clave. Espera un minuto y vuelve a intentar.', 'TOO_MANY_REQUESTS', 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($limiter))
                ->header('X-RateLimit-Limit', (string) ApiKeys::PER_MINUTE)
                ->header('X-RateLimit-Remaining', '0');
        }

        RateLimiter::hit($limiter, 60);

        $tenant = Tenant::with(['settings', 'activeSubscription.plan'])->find($key->tenant_id);

        if (!$tenant || !$tenant->isActive()) {
            return $this->fail('La empresa de esta clave no está activa.', 'TENANT_INACTIVE', 403);
        }

        if (!$tenant->hasModule('api')) {
            return $this->fail('El plan de la empresa no incluye la API y los webhooks.', 'MODULE_DISABLED', 403);
        }

        if (!in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && Subscriptions::lockedFor($tenant->id)) {
            return $this->fail(Subscriptions::lockMessage($tenant->id), 'PLAN_LOCKED', 402);
        }

        $actor = $this->actor($key, $tenant);

        if (!$actor) {
            return $this->fail('La empresa no tiene una cuenta de administración activa para trabajar con la API.', 'NO_ACTOR', 403);
        }

        $actor->setRelation('tenant', $tenant);

        DB::table('api_keys')->where('id', $key->id)->update(['last_used_at' => now(), 'last_used_ip' => $request->ip()]);

        $request->attributes->set('api_key', $key);
        $request->attributes->set('api_tenant', $tenant);
        $request->setUserResolver(fn () => $actor);

        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) ApiKeys::PER_MINUTE);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, ApiKeys::PER_MINUTE - RateLimiter::attempts($limiter)));

        return $response;
    }

    private function actor(object $key, Tenant $tenant): ?User
    {
        $active = fn ($query) => $query->where('tenant_id', $tenant->id)->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'));

        if ($key->created_by && ($user = $active(User::query())->find($key->created_by)) && $user->isTenantAdmin()) {
            return $user;
        }

        return $active(User::query())->where('is_owner', true)->orderBy('created_at')->first();
    }

    private function fail(string $message, string $code, int $status): Response
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
