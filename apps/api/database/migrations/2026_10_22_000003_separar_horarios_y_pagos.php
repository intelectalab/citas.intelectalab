<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const COPIES = [
        'professionals.view' => 'schedules.view',
        'professionals.edit' => 'schedules.edit',
        'sales.view'         => 'payments.view',
    ];

    public function up(): void
    {
        if (!Access::ready()) {
            return;
        }

        $ids = Access::permissionIds();

        foreach (self::COPIES as $from => $to) {
            if (!isset($ids[$from], $ids[$to])) {
                continue;
            }

            $roles = DB::table('role_permissions')->where('permission_id', $ids[$from])->pluck('role_id');

            foreach ($roles as $roleId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $ids[$to]]);
            }

            $overrides = DB::table('user_permissions')->where('permission_id', $ids[$from])->get();

            foreach ($overrides as $override) {
                $exists = DB::table('user_permissions')->where('user_id', $override->user_id)->where('permission_id', $ids[$to])->exists();

                if (!$exists) {
                    DB::table('user_permissions')->insert([
                        'tenant_id'     => $override->tenant_id,
                        'user_id'       => $override->user_id,
                        'permission_id' => $ids[$to],
                        'granted'       => $override->granted,
                        'created_by'    => $override->created_by ?? null,
                        'created_at'    => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
    }
};
