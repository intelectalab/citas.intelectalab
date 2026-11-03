<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('subscriptions:expire', function () {
    $limit = now()->subDays(\App\Support\Subscriptions::graceDays());
    $withCard = \App\Support\Subscriptions::ready();
    $trials = DB::table('subscriptions')
        ->where('status', 'trialing')
        ->where(function ($q) use ($limit, $withCard) {
            $ended = fn ($when) => fn ($c) => $c->where('ends_at', '<=', $when)->orWhere(fn ($n) => $n->whereNull('ends_at')->where('trial_ends_at', '<=', $when));
            $withCard
                ? $q->where(fn ($c) => $c->where(fn ($p) => $p->whereNull('card_status')->orWhere('card_status', '!=', 'authorized'))->where($ended(now())))
                    ->orWhere(fn ($c) => $c->where('card_status', 'authorized')->where($ended($limit)))
                : $q->where($ended(now()));
        })
        ->update(['status' => 'expired', 'updated_at' => now()]);

    $pastDue = DB::table('subscriptions')
        ->where('status', 'active')
        ->where('ends_at', '<=', $limit)
        ->update(['status' => 'past_due', 'updated_at' => now()]);

    $this->info("Pruebas vencidas: {$trials}. Suscripciones con pago vencido: {$pastDue}.");
})->purpose('Marca como vencidas las suscripciones cuya fecha de fin ya pasó');

Schedule::command('subscriptions:expire')->hourly();

Artisan::command('billing:sync', function () {
    $links = \App\Support\PaymentLinks::checkPending();
    $plans = \App\Support\PlanBilling::syncAll();
    $this->info("Links de pago revisados con la pasarela: {$links}. Planes revisados: {$plans}.");
})->purpose('Consulta a Mercado Pago los pagos en línea y los cobros de planes que no hayan avisado');

Schedule::command('billing:sync')->everyFifteenMinutes()->withoutOverlapping();

Artisan::command('messages:send', function () {
    if (!\App\Support\Messaging::ready()) {
        $this->warn(\App\Support\Messaging::NOT_READY);

        return;
    }

    foreach (\App\Models\Tenant::where('status', 'active')->with('settings')->get() as $tenant) {
        \App\Support\Messaging::generate($tenant, true);
    }

    $campaigns = \App\Support\Campaigns::dispatchDue();
    $automations = \App\Support\Automations::runDue();
    $done = \App\Support\Messaging::process(null, 200);
    $this->info("Campañas que salieron: {$campaigns}. Automatizaciones: {$automations}. Enviados: {$done['sent']}. Con error: {$done['failed']}. No enviados: {$done['not_sent']}.");
})->purpose('Arma los recordatorios y envía los mensajes pendientes o programados');

Schedule::command('messages:send')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('appointments:confirmations', function () {
    $count = 0;
    foreach (\App\Models\Tenant::where('status', 'active')->with('settings')->get() as $tenant) {
        \App\Support\Confirmations::process($tenant);
        \App\Support\Notifier::upcomingAbsences($tenant);
        $count++;
    }

    $this->info("Empresas revisadas: {$count}.");
})->purpose('Revisa las citas sin confirmar (avisa o libera según la regla) y las ausencias próximas');

Schedule::command('appointments:confirmations')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('customers:stages', function () {
    if (!\App\Support\CustomerStages::automatic()) {
        $this->warn('Falta actualizar la base de datos: ejecuta php artisan migrate.');

        return;
    }

    $moved = 0;
    foreach (\App\Models\Tenant::where('status', 'active')->with('settings')->get() as $tenant) {
        $moved += \App\Support\CustomerStages::refresh($tenant);
    }

    $this->info("Clientes que cambiaron de columna: {$moved}.");
})->purpose('Ubica a cada cliente en la columna del tablero que corresponde a su historia');

Schedule::command('customers:stages')->dailyAt('03:10')->withoutOverlapping();

Artisan::command('notifications:due', function () {
    $count = 0;
    foreach (\App\Models\Tenant::where('status', 'active')->with(['settings', 'activeSubscription.plan'])->get() as $tenant) {
        \App\Support\DueNotices::run($tenant, true);
        $count++;
    }

    $this->info("Empresas revisadas: {$count}.");
})->purpose('Avisa controles por vencer, fichas en borrador, tope del plan y suscripción por vencer');

Schedule::command('notifications:due')->hourlyAt(7)->withoutOverlapping();

Artisan::command('db:demo {--agregar : Solo suma los ejemplos nuevos, sin borrar nada} {--force : No pregunta antes de borrar}', function () {
    if (app()->isProduction()) {
        $this->error('Los datos de muestra no se cargan en producción.');

        return 1;
    }

    if ($this->option('agregar')) {
        $this->call('migrate', ['--force' => true]);
        $this->call('db:seed', ['--class' => \Database\Seeders\DemoExtrasSeeder::class, '--force' => true]);
        $this->info('Listo: se sumaron los ejemplos nuevos. No se borró nada de lo que ya tenías.');

        return 0;
    }

    $database = config('database.connections.' . config('database.default') . '.database');
    $this->newLine();
    $this->warn("Esto BORRA toda la base \"{$database}\" y la vuelve a crear desde cero.");
    $this->line('Se pierde todo lo que hayas creado o cambiado a mano: empresas, clientes, citas, ventas, cajas, mensajes y configuración.');
    $this->line('Queda solo con los datos de muestra.');
    $this->newLine();
    $this->line('Si solo quieres los ejemplos nuevos sin borrar nada, cancela y usa: php artisan db:demo --agregar');
    $this->newLine();

    if (!$this->option('force') && !$this->confirm('¿Borrar todo y volver a crear la base?', false)) {
        $this->info('No se borró nada.');

        return 1;
    }

    $this->call('migrate:fresh', ['--force' => true, '--seed' => true]);
    $this->info('Base lista con los datos de muestra.');
})->purpose('Vuelve a crear la base local con los datos de muestra (migrate:fresh --seed, borra todo), o con --agregar solo suma los ejemplos nuevos');

Artisan::command('seguridad:sin-codigo', function () {
    \App\Models\PlatformSetting::put(['mfa_platform' => false]);
    \App\Support\PlatformLook::forget();
    $users = \App\Models\User::whereNull('tenant_id')->get()->filter(fn ($user) => $user->isPlatformUser());
    $unlocked = 0;

    if (\App\Support\AccountSecurity::ready()) {
        foreach ($users as $user) {
            if ($user->locked_until || $user->failed_logins || $user->mfa_mode === 'required') {
                $user->forceFill(['failed_logins' => 0, 'locked_until' => null, 'mfa_mode' => $user->mfa_mode === 'required' ? null : $user->mfa_mode])->saveQuietly();
                $unlocked++;
            }
        }
    }

    \App\Support\Audit::log(request(), 'platform.codes_off', [
        'before' => [],
        'after'  => ['mfa_platform' => false],
    ]);

    $this->info("Listo: las cuentas de la plataforma ya entran sin código. Cuentas desbloqueadas o sin código obligatorio: {$unlocked}.");
    $this->line('Las empresas quedan como estaban. Vuelve a encender el código en la consola cuando el correo funcione.');
})->purpose('Emergencia: apaga el código al entrar de las cuentas de la plataforma (superadmin y soporte) y las desbloquea');
