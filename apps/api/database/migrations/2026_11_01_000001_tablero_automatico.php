<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('customers', 'stage_manual_at')) {
            DB::statement('ALTER TABLE customers ADD COLUMN stage_manual_at timestamptz NULL');
        }

        if (Schema::hasTable('customer_stage_changes')) {
            DB::statement(<<<'SQL'
                UPDATE customers c
                SET stage_manual_at = last.created_at
                FROM (
                    SELECT DISTINCT ON (customer_id) customer_id, created_at, source
                    FROM customer_stage_changes
                    ORDER BY customer_id, created_at DESC
                ) last
                WHERE last.customer_id = c.id
                  AND last.source IN ('board', 'form', 'detail')
                  AND c.pipeline_stage_id IS NOT NULL
                  AND c.stage_manual_at IS NULL
            SQL);
        }

        if (Schema::hasColumn('pipeline_stages', 'system_key')) {
            DB::statement(<<<'SQL'
                UPDATE customers c
                SET stage_manual_at = now()
                FROM pipeline_stages s
                WHERE s.id = c.pipeline_stage_id
                  AND c.stage_manual_at IS NULL
                  AND (s.system_key IS NULL OR s.system_key NOT IN ('lead', 'booked', 'active', 'loyal', 'recover'))
            SQL);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE customers DROP COLUMN IF EXISTS stage_manual_at');
    }
};
