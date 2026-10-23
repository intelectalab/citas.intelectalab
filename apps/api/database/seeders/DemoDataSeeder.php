<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoDataSeeder extends Seeder
{
    public const DEMO_TENANT = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('Los datos de muestra no se cargan en producción.');

            return;
        }

        if (DB::table('tenants')->where('id', self::DEMO_TENANT)->exists()) {
            $this->command?->info('Los datos de muestra base ya estaban: solo se suman los ejemplos nuevos.');
        } else {
            DB::unprepared(file_get_contents(database_path('seeders/sql/demo.sql')));
        }

        $this->call(DemoExtrasSeeder::class);
    }
}
