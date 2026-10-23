<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoExtrasSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('Los datos de muestra no se cargan en producción.');

            return;
        }

        if (!DB::table('tenants')->where('id', DemoDataSeeder::DEMO_TENANT)->exists()) {
            $this->command?->error('Tu base no tiene los datos de muestra base. Cárgalos primero con: php artisan db:seed');

            return;
        }

        $files = glob(database_path('seeders/sql/demo/*.sql')) ?: [];
        sort($files);
        DB::select("select set_config('app.frontend_url', ?, false)", [rtrim((string) config('app.frontend_url'), '/')]);

        foreach ($files as $file) {
            DB::unprepared(file_get_contents($file));
            $this->command?->line('  Ejemplos: ' . pathinfo($file, PATHINFO_FILENAME));
        }
    }
}
