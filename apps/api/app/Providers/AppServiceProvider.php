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

        \Laravel\Sanctum\Sanctum::authenticateAccessTokensUsing(fn ($token, bool $valid) => \App\Support\Sessions::check($token, $valid));

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

        RateLimiter::for('register-tenant', fn (Request $request) => array_filter([
            Limit::perMinute(max(1, (int) config('services.signup.per_minute_ip', 5)))
                ->by('signup-ip-minute|' . $request->ip())
                ->response($tooMany('Hiciste varios intentos de registro seguidos. Espera un minuto y vuelve a intentarlo.')),
            Limit::perHour(max(1, (int) config('services.signup.per_hour_ip', 20)))
                ->by('signup-ip-hour|' . $request->ip())
                ->response($tooMany('Se hicieron muchos registros desde esta conexión. Espera una hora o escríbenos para ayudarte.')),
            is_string($request->input('email')) && trim($request->input('email')) !== ''
                ? Limit::perHour(max(1, (int) config('services.signup.per_hour_email', 5)))
                    ->by('signup-email|' . mb_strtolower(trim($request->input('email'))))
                    ->response($tooMany('Hubo muchos intentos de registro con este correo. Espera una hora o escríbenos para ayudarte.'))
                : null,
        ]));
    }
}
