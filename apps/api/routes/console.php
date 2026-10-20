<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

    $done = \App\Support\Messaging::process(null, 200);
    $this->info("Enviados: {$done['sent']}. Con error: {$done['failed']}. No enviados: {$done['not_sent']}.");
})->purpose('Arma los recordatorios y envía los mensajes pendientes o programados');

Schedule::command('messages:send')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('db:demo', function () {
    if (app()->isProduction()) {
        $this->error('Los datos de muestra no se cargan en producción.');

        return 1;
    }

    if (!$this->confirm('Se borrará la base '.config('database.connections.'.config('database.default').'.database').' y se volverá a crear con los datos de muestra. ¿Continuar?', true)) {
        return 1;
    }

    $this->call('migrate:fresh', ['--force' => true]);
    DB::unprepared(file_get_contents(base_path('infrastructure/docker/postgres/seed_demo.sql')));
    $this->info('Base lista con los datos de muestra.');
})->purpose('Vuelve a crear la base local desde las migraciones y carga los datos de muestra');
