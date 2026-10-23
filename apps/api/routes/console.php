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
    $trials = DB::table('subscriptions')
        ->where('status', 'trialing')
        ->where(fn ($q) => $q->where('ends_at', '<=', now())->orWhere(fn ($q) => $q->whereNull('ends_at')->where('trial_ends_at', '<=', now())))
        ->update(['status' => 'expired', 'updated_at' => now()]);

    $pastDue = DB::table('subscriptions')
        ->where('status', 'active')
        ->where('ends_at', '<=', now())
        ->update(['status' => 'past_due', 'updated_at' => now()]);

    $this->info("Pruebas vencidas: {$trials}. Suscripciones con pago vencido: {$pastDue}.");
})->purpose('Marca como vencidas las suscripciones cuya fecha de fin ya pasó');

Schedule::command('subscriptions:expire')->hourly();

Artisan::command('messages:send', function () {
    if (!\App\Support\Messaging::ready()) {
        $this->warn(\App\Support\Messaging::NOT_READY);

        return;
    }

    foreach (\App\Models\Tenant::where('status', 'active')->with('settings')->get() as $tenant) {
        \App\Support\Messaging::generate($tenant, true);
    }

    $campaigns = \App\Support\Campaigns::dispatchDue();
    $done = \App\Support\Messaging::process(null, 200);
    $this->info("Campañas que salieron: {$campaigns}. Enviados: {$done['sent']}. Con error: {$done['failed']}. No enviados: {$done['not_sent']}.");
})->purpose('Arma los recordatorios y envía los mensajes pendientes o programados');

Schedule::command('messages:send')->everyFiveMinutes()->withoutOverlapping();

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
