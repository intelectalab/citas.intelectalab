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
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
