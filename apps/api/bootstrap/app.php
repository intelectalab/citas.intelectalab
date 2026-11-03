<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'staff' => \App\Http\Middleware\EnsureStaffAccount::class,
            'customer' => \App\Http\Middleware\EnsureCustomerAccount::class,
            'module' => \App\Http\Middleware\EnsureModuleEnabled::class,
            'saas' => \App\Http\Middleware\EnsurePlatformStaff::class,
            'perm' => \App\Http\Middleware\EnsurePermission::class,
            'plan' => \App\Http\Middleware\EnsurePlanPaid::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'appointments_no_overlap')) {
                return response()->json(['message' => 'Ese horario se acaba de ocupar: otra persona agendó a ese profesional a la misma hora. Elige otro horario.', 'code' => 'SLOT_TAKEN'], 409);
            }

            return null;
        });
        $exceptions->respond(fn ($response) => \App\Support\ErrorMessages::apply($response));
    })->create();
