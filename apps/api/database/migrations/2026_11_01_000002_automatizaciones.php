<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'subject'     => 'varchar(200) NULL',
            'body'        => 'text NULL',
            'coupon_id'   => 'uuid NULL REFERENCES coupons(id) ON DELETE SET NULL',
            'send_time'   => 'time NULL',
            'repeat_days' => 'integer NOT NULL DEFAULT 0',
            'enabled_at'  => 'timestamptz NULL',
            'updated_by'  => 'uuid NULL REFERENCES users(id) ON DELETE SET NULL',
        ];

        foreach ($columns as $name => $definition) {
            if (!Schema::hasColumn('automation_rules', $name)) {
                DB::statement("ALTER TABLE automation_rules ADD COLUMN {$name} {$definition}");
            }
        }

        DB::statement("DELETE FROM automation_rules r WHERE trigger_event NOT IN ('birthday', 'inactive', 'first_visit', 'no_show', 'loyal') AND NOT EXISTS (SELECT 1 FROM automation_logs l WHERE l.automation_rule_id = r.id)");

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS automation_rules_tenant_trigger_unique ON automation_rules(tenant_id, trigger_event)');

        if (!Schema::hasColumn('automation_logs', 'message_id')) {
            DB::statement('ALTER TABLE automation_logs ADD COLUMN message_id uuid NULL REFERENCES messages(id) ON DELETE SET NULL');
        }

        DB::statement('CREATE INDEX IF NOT EXISTS automation_logs_rule_customer ON automation_logs(automation_rule_id, customer_id, created_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS automation_logs_tenant_customer ON automation_logs(tenant_id, customer_id, created_at DESC)');

        if (Access::ready()) {
            $ids = Access::permissionIds();
            $names = array_values(array_intersect(
                Access::factoryPermissions('manager'),
                array_filter(array_keys($ids), fn ($name) => str_starts_with($name, 'automations.')),
            ));

            foreach (DB::table('roles')->where('slug', 'manager')->whereNotNull('permissions_seeded_at')->pluck('id') as $roleId) {
                DB::table('role_permissions')->insertOrIgnore(
                    array_map(fn ($name) => ['role_id' => $roleId, 'permission_id' => $ids[$name]], $names),
                );
            }
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS automation_rules_tenant_trigger_unique');
        DB::statement('DROP INDEX IF EXISTS automation_logs_rule_customer');
        DB::statement('DROP INDEX IF EXISTS automation_logs_tenant_customer');
        DB::statement('ALTER TABLE automation_logs DROP COLUMN IF EXISTS message_id');

        foreach (['subject', 'body', 'coupon_id', 'send_time', 'repeat_days', 'enabled_at', 'updated_by'] as $name) {
            DB::statement("ALTER TABLE automation_rules DROP COLUMN IF EXISTS {$name}");
        }
    }
};
