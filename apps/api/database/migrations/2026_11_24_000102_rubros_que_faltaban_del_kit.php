<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function rubros(): array
    {
        return json_decode((string) file_get_contents(database_path('data/rubros-del-kit.json')), true) ?: [];
    }

    public function up(): void
    {
        if (!Schema::hasTable('business_types')) {
            return;
        }

        foreach ($this->rubros() as $rubro) {
            if (DB::table('business_types')->where('slug', $rubro['slug'])->exists()) {
                continue;
            }

            DB::table('business_types')->insert([
                'slug'          => $rubro['slug'],
                'name'          => $rubro['name'],
                'short_name'    => $rubro['short_name'],
                'description'   => $rubro['description'],
                'icon'          => $rubro['icon'],
                'color'         => $rubro['color'],
                'vocabulary'    => json_encode($rubro['vocabulary'], JSON_UNESCAPED_UNICODE),
                'modules_off'   => json_encode($rubro['modules_off']),
                'default_rules' => json_encode((object) $rubro['default_rules'], JSON_UNESCAPED_UNICODE),
                'dependents'    => json_encode((object) $rubro['dependents']),
                'template'      => json_encode($rubro['template'], JSON_UNESCAPED_UNICODE),
                'sort_order'    => $rubro['sort_order'],
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('business_types')) {
            return;
        }

        $slugs = array_column($this->rubros(), 'slug');

        DB::table('business_types')
            ->whereIn('slug', $slugs)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('tenants')->whereColumn('tenants.business_type_id', 'business_types.id'))
            ->delete();
    }
};
