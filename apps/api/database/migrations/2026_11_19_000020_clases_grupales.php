<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS class_sessions (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                branch_id uuid NOT NULL REFERENCES branches(id) ON DELETE CASCADE,
                service_id uuid NOT NULL REFERENCES services(id) ON DELETE CASCADE,
                professional_id uuid NOT NULL REFERENCES professionals(id) ON DELETE CASCADE,
                room_id uuid REFERENCES rooms(id) ON DELETE SET NULL,
                series_id uuid,
                title varchar(150) NOT NULL,
                starts_at timestamp with time zone NOT NULL,
                ends_at timestamp with time zone NOT NULL,
                capacity integer NOT NULL,
                online_booking boolean NOT NULL DEFAULT true,
                status varchar(20) NOT NULL DEFAULT 'scheduled',
                notes text,
                cancel_reason varchar(255),
                cancelled_at timestamp with time zone,
                cancelled_by uuid REFERENCES users(id) ON DELETE SET NULL,
                created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                created_at timestamp with time zone NOT NULL DEFAULT now(),
                updated_at timestamp with time zone NOT NULL DEFAULT now(),
                CONSTRAINT class_sessions_time_check CHECK (ends_at > starts_at),
                CONSTRAINT class_sessions_capacity_check CHECK (capacity >= 1),
                CONSTRAINT class_sessions_status_check CHECK (status IN ('scheduled', 'cancelled'))
            );

            CREATE INDEX IF NOT EXISTS class_sessions_tenant_start_idx ON class_sessions (tenant_id, starts_at);
            CREATE INDEX IF NOT EXISTS class_sessions_professional_idx ON class_sessions (professional_id, starts_at);
            CREATE INDEX IF NOT EXISTS class_sessions_room_idx ON class_sessions (room_id, starts_at);
            CREATE INDEX IF NOT EXISTS class_sessions_series_idx ON class_sessions (series_id);

            CREATE TABLE IF NOT EXISTS class_enrollments (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                class_session_id uuid NOT NULL REFERENCES class_sessions(id) ON DELETE CASCADE,
                customer_id uuid NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
                status varchar(20) NOT NULL DEFAULT 'enrolled',
                source varchar(20) NOT NULL DEFAULT 'staff',
                sale_id uuid REFERENCES sales(id) ON DELETE SET NULL,
                customer_package_id uuid REFERENCES customer_packages(id) ON DELETE SET NULL,
                package_usage_id uuid,
                paid_with varchar(20),
                notes varchar(255),
                attended_at timestamp with time zone,
                cancelled_at timestamp with time zone,
                promoted_at timestamp with time zone,
                created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                created_at timestamp with time zone NOT NULL DEFAULT now(),
                updated_at timestamp with time zone NOT NULL DEFAULT now(),
                CONSTRAINT class_enrollments_status_check CHECK (status IN ('enrolled', 'waitlist', 'attended', 'no_show', 'cancelled')),
                CONSTRAINT class_enrollments_source_check CHECK (source IN ('staff', 'portal', 'online')),
                CONSTRAINT class_enrollments_paid_check CHECK (paid_with IS NULL OR paid_with IN ('sale', 'package', 'membership'))
            );

            CREATE UNIQUE INDEX IF NOT EXISTS class_enrollments_once_idx ON class_enrollments (class_session_id, customer_id) WHERE status <> 'cancelled';
            CREATE INDEX IF NOT EXISTS class_enrollments_tenant_customer_idx ON class_enrollments (tenant_id, customer_id);
            CREATE INDEX IF NOT EXISTS class_enrollments_session_status_idx ON class_enrollments (class_session_id, status, created_at);
            CREATE INDEX IF NOT EXISTS class_enrollments_sale_idx ON class_enrollments (sale_id);
        SQL);

        if (!Access::ready()) {
            return;
        }

        $ids = Access::permissionIds();
        $grants = [
            'classes.view'   => ['classes.view', 'agenda.view'],
            'classes.create' => ['agenda.create'],
            'classes.edit'   => ['agenda.create'],
            'classes.cancel' => ['agenda.cancel'],
        ];

        foreach ($grants as $name => $sources) {
            if (empty($ids[$name])) {
                continue;
            }

            $sourceIds = array_values(array_filter(array_map(fn ($source) => $ids[$source] ?? null, $sources)));

            $roles = DB::table('role_permissions')
                ->whereIn('permission_id', $sourceIds)
                ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
                ->where(fn ($q) => $q->whereNotIn('roles.slug', ['cashier'])->orWhereNull('roles.slug'))
                ->when($name !== 'classes.view', fn ($q) => $q->whereIn('role_permissions.role_id', DB::table('role_permissions')->where('permission_id', $ids['classes.view'])->select('role_id')))
                ->pluck('role_permissions.role_id')
                ->merge(DB::table('roles')->whereIn('slug', ['owner', 'admin'])->pluck('id'))
                ->unique();

            foreach ($roles as $roleId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $ids[$name]]);
            }
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS class_enrollments; DROP TABLE IF EXISTS class_sessions;');
        DB::table('permissions')->whereIn('name', ['classes.create', 'classes.edit', 'classes.cancel'])->delete();
    }
};
