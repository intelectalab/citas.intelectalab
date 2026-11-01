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

        $total = count(Access::all());

        foreach (['manager', 'reception', 'professional'] as $slug) {
            $roles = DB::table('roles')
                ->where('slug', $slug)
                ->whereNotNull('permissions_seeded_at')
                ->pluck('id');

            foreach ($roles as $roleId) {
                if (count(Access::rolePermissions($roleId)) >= $total - 5) {
                    Access::setRolePermissions($roleId, Access::factoryPermissions($slug));
                }
            }
        }
    }

    public function down(): void
    {
    }
};
