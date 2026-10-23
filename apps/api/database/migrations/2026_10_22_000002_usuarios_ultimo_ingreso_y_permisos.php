<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'last_login_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestampTz('last_login_at')->nullable();
            });
        }

        if (!Access::ready()) {
            return;
        }

        $ids = Access::permissionIds();

        foreach (['manager', 'reception', 'professional'] as $slug) {
            $names = array_values(array_intersect(Access::factoryPermissions($slug), ['walkin.view', 'classes.view']));
            $roles = DB::table('roles')->where('slug', $slug)->whereNotNull('permissions_seeded_at')->pluck('id');

            foreach ($roles as $roleId) {
                foreach ($names as $name) {
                    if (isset($ids[$name])) {
                        DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $ids[$name]]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'last_login_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('last_login_at');
            });
        }
    }
};
