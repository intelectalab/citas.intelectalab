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
