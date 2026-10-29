<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Access::ready()) {
            $ids = Access::permissionIds();

            foreach (['manager', 'reception', 'professional', 'cashier'] as $slug) {
                $names = array_values(array_intersect(
                    Access::factoryPermissions($slug),
                    array_filter(array_keys($ids), fn ($name) => str_starts_with($name, 'quotes.')),
                ));

                if (!$names) {
                    continue;
                }

                $roles = DB::table('roles')
                    ->where('slug', $slug)
                    ->whereNotNull('permissions_seeded_at')
                    ->pluck('id');

                foreach ($roles as $roleId) {
                    DB::table('role_permissions')->insertOrIgnore(
                        array_map(fn ($name) => ['role_id' => $roleId, 'permission_id' => $ids[$name]], $names),
                    );
                }
            }
        }
    }

    public function down(): void
    {
    }
};
