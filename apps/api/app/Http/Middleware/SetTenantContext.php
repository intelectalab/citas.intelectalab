<?php

namespace App\Http\Middleware;

use App\Support\ApiKeys;
use App\Support\AppointmentLinks;
use App\Support\Rls;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    public const SYSTEM_ROUTES = [
        'api/login',
        'api/login/verify',
        'api/login/resend',
        'api/password/forgot',
        'api/password/reset',
        'api/password/reset/{token}',
        'api/register-customer',
        'api/register-tenant',
        'api/complaints-book',
    ];

    public const SYSTEM_PREFIXES = [
        'api/webhooks/payments/',
        'api/plan-payments/',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        Rls::platform();
        $uri = (string) $request->route()?->uri();

        if ($this->isSystemRoute($uri)) {
            return $next($request);
        }

        try {
            $resolved = $this->publicTenant($request, $uri);
        } catch (\Throwable) {
            $resolved = null;
        }

        if ($resolved !== false) {
            Rls::tenant($resolved);

            return $next($request);
        }

        Rls::forUser($request->user('sanctum'));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        Rls::clear();
    }

    private function isSystemRoute(string $uri): bool
    {
        if (in_array($uri, self::SYSTEM_ROUTES, true)) {
            return true;
        }

        foreach (self::SYSTEM_PREFIXES as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function publicTenant(Request $request, string $uri): string|null|false
    {
        $param = fn (string $name) => (string) $request->route($name);
        $tenantBy = fn (string $column, string $value) => $value === '' ? null : DB::table('tenants')->where($column, $value)->value('id');
        $tenantOf = fn (string $table, string $column, ?string $value) => $value === null || $value === '' ? null : DB::table($table)->where($column, $value)->value('tenant_id');

        return match (true) {
            str_starts_with($uri, 'api/public/booking/{slug}') => $tenantBy('slug', $param('slug')),
            str_starts_with($uri, 'api/kiosk/{code}'),
            str_starts_with($uri, 'api/complaints-book/{code}'),
            str_starts_with($uri, 'api/signup-info/{code}') => $tenantBy('code', $param('code')),
            str_starts_with($uri, 'api/branding/{tenant}'),
            str_starts_with($uri, 'api/webhooks/whatsapp/{tenant}') => Str::isUuid($param('tenant')) ? $param('tenant') : null,
            str_starts_with($uri, 'api/pay/{token}') => $tenantOf('payment_links', 'token', $param('token')),
            str_starts_with($uri, 'api/public/appointment/{token}') => $tenantOf('appointments', 'id', AppointmentLinks::idFrom($param('token'))),
            str_starts_with($uri, 'api/calendar/{token}') => $tenantOf('calendar_feeds', 'token', $param('token')),
            str_starts_with($uri, 'api/promotions/unsubscribe/{recipient}') => Str::isUuid($param('recipient')) ? $tenantOf('marketing_campaign_recipients', 'id', $param('recipient')) : null,
            str_starts_with($uri, 'api/v1/') => $this->apiKeyTenant($request),
            default => false,
        };
    }

    private function apiKeyTenant(Request $request): ?string
    {
        $plain = $request->bearerToken() ?: $request->header('X-Api-Key');

        return ApiKeys::find(is_string($plain) ? trim($plain) : null)?->tenant_id;
    }
}
