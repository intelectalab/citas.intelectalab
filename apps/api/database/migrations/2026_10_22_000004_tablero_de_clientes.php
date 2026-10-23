<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pipelines', 'tenant_id')) {
            DB::statement('ALTER TABLE pipelines ADD COLUMN tenant_id uuid NULL REFERENCES tenants(id) ON DELETE CASCADE');
            DB::statement('CREATE UNIQUE INDEX pipelines_tenant_unique ON pipelines(tenant_id) WHERE tenant_id IS NOT NULL');
        }

        if (!Schema::hasColumn('pipeline_stages', 'system_key')) {
            DB::statement('ALTER TABLE pipeline_stages ADD COLUMN system_key varchar(20) NULL');
        }

        if (!Schema::hasColumn('pipeline_stages', 'description')) {
            DB::statement('ALTER TABLE pipeline_stages ADD COLUMN description varchar(150) NULL');
        }

        DB::statement('UPDATE customers SET pipeline_stage_id = NULL WHERE pipeline_stage_id IN (SELECT s.id FROM pipeline_stages s JOIN pipelines p ON p.id = s.pipeline_id WHERE p.tenant_id IS NULL)');
        DB::statement('DELETE FROM pipelines WHERE tenant_id IS NULL');

        if (!Schema::hasTable('customer_stage_changes')) {
            DB::statement(<<<'SQL'
                CREATE TABLE customer_stage_changes (
                    id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
                    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                    customer_id uuid NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
                    from_stage_id uuid NULL REFERENCES pipeline_stages(id) ON DELETE SET NULL,
                    to_stage_id uuid NULL REFERENCES pipeline_stages(id) ON DELETE SET NULL,
                    from_name varchar(150) NULL,
                    to_name varchar(150) NULL,
                    source varchar(20) NOT NULL DEFAULT 'board',
                    changed_by uuid NULL REFERENCES users(id) ON DELETE SET NULL,
                    created_at timestamptz NOT NULL DEFAULT now()
                )
            SQL);
            DB::statement('CREATE INDEX idx_customer_stage_changes_customer ON customer_stage_changes(customer_id, created_at DESC)');
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS customer_stage_changes');
        DB::statement('ALTER TABLE pipeline_stages DROP COLUMN IF EXISTS description');
        DB::statement('ALTER TABLE pipeline_stages DROP COLUMN IF EXISTS system_key');
        DB::statement('DROP INDEX IF EXISTS pipelines_tenant_unique');
        DB::statement('ALTER TABLE pipelines DROP COLUMN IF EXISTS tenant_id');
    }
};
