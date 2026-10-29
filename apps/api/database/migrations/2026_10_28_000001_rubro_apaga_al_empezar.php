<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tenant_settings') || !Schema::hasColumn('tenants', 'business_profile')) {
            return;
        }

        DB::statement("INSERT INTO tenant_settings (tenant_id) SELECT id FROM tenants ON CONFLICT (tenant_id) DO NOTHING");

        DB::statement(<<<'SQL'
            UPDATE tenant_settings AS s SET modules_off = (
                SELECT COALESCE(jsonb_agg(DISTINCT k ORDER BY k), '[]'::jsonb)
                FROM (
                    SELECT jsonb_array_elements_text(COALESCE(s.modules_off, '[]'::jsonb)) AS k
                    UNION
                    SELECT jsonb_array_elements_text(COALESCE(t.business_profile->'modules_off', b.modules_off, '[]'::jsonb))
                ) AS keys
            )
            FROM tenants AS t
            LEFT JOIN business_types AS b ON b.id = t.business_type_id
            WHERE t.id = s.tenant_id
            SQL);

        DB::statement("UPDATE tenants SET business_profile = business_profile - 'modules_off' WHERE jsonb_exists(business_profile, 'modules_off')");
    }

    public function down(): void
    {
        if (!Schema::hasColumn('tenants', 'business_profile')) {
            return;
        }

        DB::statement(<<<'SQL'
            UPDATE tenants AS t SET business_profile = t.business_profile || jsonb_build_object('modules_off', COALESCE(b.modules_off, '[]'::jsonb))
            FROM business_types AS b
            WHERE b.id = t.business_type_id AND t.business_profile IS NOT NULL
            SQL);
    }
};
