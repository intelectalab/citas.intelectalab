<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Access::ready()) {
            return;
        }

        $ids = Access::permissionIds();
        $names = array_values(array_intersect(
            Access::factoryPermissions('manager'),
            array_filter(array_keys($ids), fn ($name) => str_starts_with($name, 'reports.')),
        ));

        foreach (DB::table('roles')->where('slug', 'manager')->whereNotNull('permissions_seeded_at')->pluck('id') as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(
                array_map(fn ($name) => ['role_id' => $roleId, 'permission_id' => $ids[$name]], $names),
            );
        }
    }

    public function down(): void
    {
    }
};
