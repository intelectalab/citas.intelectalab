<?php

use App\Support\Access;
use App\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS surveys (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                appointment_id uuid NOT NULL REFERENCES appointments(id) ON DELETE CASCADE,
                customer_id uuid NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
                professional_id uuid REFERENCES professionals(id) ON DELETE SET NULL,
                service_id uuid REFERENCES services(id) ON DELETE SET NULL,
                branch_id uuid REFERENCES branches(id) ON DELETE SET NULL,
                token varchar(64) NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'scheduled',
                skip_reason varchar(255),
                send_at timestamp with time zone NOT NULL,
                sent_at timestamp with time zone,
                expires_at timestamp with time zone NOT NULL,
                message_id uuid,
                answered_at timestamp with time zone,
                score smallint,
                comment text,
                publish_consent boolean NOT NULL DEFAULT false,
                published boolean NOT NULL DEFAULT false,
                published_at timestamp with time zone,
                published_by uuid REFERENCES users(id) ON DELETE SET NULL,
                hidden_at timestamp with time zone,
                hidden_by uuid REFERENCES users(id) ON DELETE SET NULL,
                reply text,
                replied_at timestamp with time zone,
                replied_by uuid REFERENCES users(id) ON DELETE SET NULL,
                google_invited boolean NOT NULL DEFAULT false,
                google_clicked_at timestamp with time zone,
                follow_up varchar(20),
                follow_up_note varchar(1000),
                follow_up_by uuid REFERENCES users(id) ON DELETE SET NULL,
                follow_up_at timestamp with time zone,
                created_at timestamp with time zone NOT NULL DEFAULT now(),
                updated_at timestamp with time zone NOT NULL DEFAULT now(),
                CONSTRAINT surveys_score_check CHECK (score IS NULL OR score BETWEEN 0 AND 10),
                CONSTRAINT surveys_status_check CHECK (status IN ('scheduled', 'sent', 'answered', 'skipped', 'expired')),
                CONSTRAINT surveys_follow_up_check CHECK (follow_up IS NULL OR follow_up IN ('pending', 'contacted', 'resolved')),
                CONSTRAINT surveys_publish_check CHECK (NOT published OR publish_consent)
            );

            CREATE UNIQUE INDEX IF NOT EXISTS surveys_appointment_idx ON surveys (appointment_id);
            CREATE UNIQUE INDEX IF NOT EXISTS surveys_token_idx ON surveys (token);
            CREATE INDEX IF NOT EXISTS surveys_tenant_status_idx ON surveys (tenant_id, status, send_at);
            CREATE INDEX IF NOT EXISTS surveys_tenant_answered_idx ON surveys (tenant_id, answered_at);
            CREATE INDEX IF NOT EXISTS surveys_published_idx ON surveys (tenant_id, published) WHERE published;
            CREATE INDEX IF NOT EXISTS surveys_customer_idx ON surveys (customer_id);
            CREATE INDEX IF NOT EXISTS surveys_professional_idx ON surveys (professional_id);
        SQL);

        Rls::protect('surveys');

        if (!Schema::hasColumn('appointments', 'channel')) {
            DB::statement('ALTER TABLE appointments ADD COLUMN channel varchar(30)');
            DB::statement('CREATE INDEX IF NOT EXISTS appointments_tenant_channel_idx ON appointments (tenant_id, channel) WHERE channel IS NOT NULL');
        }

        if (Schema::hasTable('payment_links') && !Schema::hasColumn('payment_links', 'channel')) {
            DB::statement('ALTER TABLE payment_links ADD COLUMN channel varchar(30)');
        }

        if (!Access::ready()) {
            return;
        }

        $ids = Access::permissionIds();
        $grants = [
            'surveys.view' => ['campaigns.view', 'customers.edit'],
            'surveys.edit' => ['campaigns.edit'],
        ];

        foreach ($grants as $name => $sources) {
            if (empty($ids[$name])) {
                continue;
            }

            $sourceIds = array_values(array_filter(array_map(fn ($source) => $ids[$source] ?? null, $sources)));
            $roles = DB::table('role_permissions')->whereIn('permission_id', $sourceIds)
                ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
                ->where(fn ($q) => $q->whereNotIn('roles.slug', ['cashier', 'professional'])->orWhereNull('roles.slug'))
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
        DB::unprepared('DROP TABLE IF EXISTS surveys;');
        DB::statement('ALTER TABLE appointments DROP COLUMN IF EXISTS channel');
        DB::statement('ALTER TABLE payment_links DROP COLUMN IF EXISTS channel');
        DB::table('permissions')->whereIn('name', ['surveys.view', 'surveys.edit'])->delete();
    }
};
