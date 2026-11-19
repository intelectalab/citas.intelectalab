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
        $editId = $ids['agenda.edit'] ?? null;

        if (!$editId) {
            return;
        }

        $newIds = array_values(array_filter(array_map(fn (string $step) => $ids["agenda.{$step}"] ?? null, Access::APPOINTMENT_STEPS)));

        foreach (DB::table('role_permissions')->where('permission_id', $editId)->pluck('role_id') as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(array_map(fn (string $id) => ['role_id' => $roleId, 'permission_id' => $id], $newIds));
        }

        foreach (DB::table('user_permissions')->where('permission_id', $editId)->get() as $override) {
            $row = (array) $override;

            foreach ($newIds as $id) {
                if (!DB::table('user_permissions')->where('user_id', $row['user_id'])->where('permission_id', $id)->exists()) {
                    DB::table('user_permissions')->insert(['permission_id' => $id] + $row);
                }
            }
        }
    }

    public function down(): void
    {
    }
};
