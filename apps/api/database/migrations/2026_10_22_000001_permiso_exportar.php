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

        if (!isset($ids['data.export'])) {
            return;
        }

        $roles = DB::table('roles')->where('slug', 'manager')->whereNotNull('permissions_seeded_at')->pluck('id');

        foreach ($roles as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $ids['data.export']]);
        }
    }

    public function down(): void
    {
    }
};
