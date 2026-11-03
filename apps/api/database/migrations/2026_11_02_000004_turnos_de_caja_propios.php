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

        if (!isset($ids['cash.others'])) {
            return;
        }

        foreach (DB::table('roles')->whereIn('slug', ['manager', 'admin', 'owner'])->pluck('id') as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $ids['cash.others']]);
        }
    }

    public function down(): void
    {
    }
};
