<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        \App\Support\Webhooks::listen();

        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\ConnectionEstablished::class, fn ($event) => \App\Support\Rls::apply($event->connection));
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionRolledBack::class, fn ($event) => \App\Support\Rls::apply($event->connection));
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Console\Events\CommandStarting::class, fn () => \App\Support\Rls::commandStarted());
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Console\Events\CommandFinished::class, fn () => \App\Support\Rls::commandFinished());

        $tooMany = fn (string $message) => fn () => response()->json(['message' => $message, 'code' => 'TOO_MANY_REQUESTS'], 429);

        RateLimiter::for('public-booking-read', fn (Request $request) => Limit::perMinute(90)
            ->by('read|' . $request->ip())
            ->response($tooMany('Estás consultando muy seguido. Espera un minuto y vuelve a intentar.')));

        RateLimiter::for('public-booking-write', fn (Request $request) => Limit::perHour(10)
            ->by('write|' . $request->route('slug') . '|' . $request->ip())
            ->response($tooMany('Ya se hicieron varias reservas desde esta conexión. Espera una hora o comunícate con la empresa.')));
    }
}
