<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS walkin_entries (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                branch_id uuid NOT NULL REFERENCES branches(id) ON DELETE CASCADE,
                customer_id uuid NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
                customer_dependent_id uuid REFERENCES customer_dependents(id) ON DELETE SET NULL,
                service_id uuid NOT NULL REFERENCES services(id) ON DELETE CASCADE,
                professional_id uuid REFERENCES professionals(id) ON DELETE SET NULL,
                appointment_id uuid REFERENCES appointments(id) ON DELETE SET NULL,
                ticket varchar(20) NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'waiting',
                source varchar(20) NOT NULL DEFAULT 'staff',
                notes text,
                waited_minutes integer,
                arrived_at timestamp with time zone NOT NULL DEFAULT now(),
                served_at timestamp with time zone,
                done_at timestamp with time zone,
                left_at timestamp with time zone,
                created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                created_at timestamp with time zone NOT NULL DEFAULT now(),
                updated_at timestamp with time zone NOT NULL DEFAULT now(),
                CONSTRAINT walkin_entries_status_check CHECK (status IN ('waiting', 'serving', 'done', 'left')),
                CONSTRAINT walkin_entries_source_check CHECK (source IN ('staff', 'kiosk'))
            );

            CREATE INDEX IF NOT EXISTS walkin_entries_tenant_branch_status_idx ON walkin_entries (tenant_id, branch_id, status, arrived_at);
            CREATE INDEX IF NOT EXISTS walkin_entries_tenant_arrived_idx ON walkin_entries (tenant_id, arrived_at DESC);
            CREATE INDEX IF NOT EXISTS walkin_entries_tenant_customer_idx ON walkin_entries (tenant_id, customer_id, status);
            CREATE INDEX IF NOT EXISTS walkin_entries_appointment_idx ON walkin_entries (appointment_id);
        SQL);

        if (Access::ready()) {
            $ids = Access::permissionIds();

            foreach (['manager', 'reception', 'professional'] as $slug) {
                $names = array_values(array_intersect(
                    Access::factoryPermissions($slug),
                    array_filter(array_keys($ids), fn ($name) => str_starts_with($name, 'walkin.')),
                ));

                if (!$names) {
                    continue;
                }

                $roles = DB::table('roles')
                    ->where('slug', $slug)
                    ->whereNotNull('permissions_seeded_at')
                    ->pluck('id');

                foreach ($roles as $roleId) {
                    DB::table('role_permissions')->insertOrIgnore(
                        array_map(fn ($name) => ['role_id' => $roleId, 'permission_id' => $ids[$name]], $names),
                    );
                }
            }
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS walkin_entries;');
    }
};
