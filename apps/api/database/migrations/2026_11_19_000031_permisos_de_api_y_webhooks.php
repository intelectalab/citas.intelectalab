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
        $edit = $ids['settings.edit'] ?? null;
        $new = array_filter([$ids['integrations.view'] ?? null, $ids['integrations.edit'] ?? null]);

        if (!$edit || !$new) {
            return;
        }

        $roles = DB::table('role_permissions')->where('permission_id', $edit)->pluck('role_id')
            ->merge(DB::table('roles')->whereIn('slug', ['owner', 'admin'])->pluck('id'))
            ->unique();

        foreach ($roles as $roleId) {
            foreach ($new as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', ['integrations.view', 'integrations.edit'])->delete();
    }
};
