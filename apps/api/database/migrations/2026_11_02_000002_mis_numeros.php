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

        if (!isset($ids['reports.own'])) {
            return;
        }

        foreach (DB::table('roles')->where('slug', 'professional')->whereNotNull('permissions_seeded_at')->pluck('id') as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $ids['reports.own']]);
        }
    }

    public function down(): void
    {
    }
};
