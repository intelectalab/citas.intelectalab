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

        $this->commissions();
    }

    private const COMMISSION_DEMOS = [
        'bella-aura'      => ['percent' => 30, 'category' => ['Uñas', 8], 'service' => ['balayage', 45]],
        'estetica-lumina' => ['percent' => 25, 'category' => null, 'service' => null],
    ];

    private function commissions(): void
    {
        if (!\App\Support\Commissions::ready()) {
            return;
        }

        foreach (self::COMMISSION_DEMOS as $slug => $demo) {
            $tenant = \App\Models\Tenant::where('slug', $slug)->first();

            if (!$tenant || !\App\Support\Commissions::enabled($tenant) || DB::table('commission_rules')->where('tenant_id', $tenant->id)->exists()) {
                continue;
            }

            $now = now();
            $rule = fn (array $values) => DB::table('commission_rules')->insert($values + [
                'id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant->id, 'active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);

            DB::table('professionals')->where('tenant_id', $tenant->id)->update(['commission_percent' => $demo['percent']]);
            $rule(['name' => 'todos los productos', 'kind' => 'product', 'percent' => 10, 'fixed' => 0]);

            if ($demo['category'] && ($categoryId = DB::table('service_categories')->where('tenant_id', $tenant->id)->where('name', $demo['category'][0])->value('id'))) {
                foreach (DB::table('services')->where('tenant_id', $tenant->id)->where('category_id', $categoryId)->get(['id', 'name']) as $service) {
                    $rule(['name' => "{$service->name}, para todos", 'kind' => 'service', 'service_id' => $service->id, 'percent' => 0, 'fixed' => $demo['category'][1]]);
                }
            }

            if ($demo['service'] && ($service = DB::table('services')->where('tenant_id', $tenant->id)->where('name', 'ilike', "%{$demo['service'][0]}%")->first())) {
                $pro = DB::table('professionals')->where('tenant_id', $tenant->id)->orderBy('display_name')->first();
                $rule(['name' => "{$pro->display_name} en {$service->name}", 'kind' => 'service', 'professional_id' => $pro->id, 'service_id' => $service->id, 'percent' => $demo['service'][1], 'fixed' => 0]);
            }

            \App\Support\Commissions::flush();
            $month = now($tenant->zone())->startOfMonth();

            foreach ([$month->copy()->subMonths(2), $month->copy()->subMonth(), $month] as $period) {
                \App\Support\Commissions::recalculate($tenant, $period->format('Y-m'));
            }

            $this->command?->line("  Ejemplos: comisiones de {$tenant->name}");
        }
    }
}
