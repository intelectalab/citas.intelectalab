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
        $reply = $ids['messages.reply'] ?? null;
        $create = $ids['messages.create'] ?? null;

        if (!$reply || !$create) {
            return;
        }

        $roles = DB::table('role_permissions')->where('permission_id', $create)->pluck('role_id')
            ->merge(DB::table('roles')->whereIn('slug', ['owner', 'admin'])->pluck('id'))
            ->unique();

        foreach ($roles as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $reply]);
        }
    }

    public function down(): void
    {
        DB::table('permissions')->where('name', 'messages.reply')->delete();
    }
};
