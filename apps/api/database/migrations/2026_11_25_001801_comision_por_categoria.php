<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NONE = '00000000-0000-0000-0000-000000000000';

    public function up(): void
    {
        if (!Schema::hasTable('commission_rules')) {
            return;
        }

        if (!Schema::hasColumn('commission_rules', 'category_id')) {
            Schema::table('commission_rules', function ($table) {
                $table->uuid('category_id')->nullable();
                $table->foreign('category_id')->references('id')->on('service_categories')->cascadeOnDelete();
            });
        }

        $none = self::NONE;
        DB::statement('DROP INDEX IF EXISTS commission_rules_one_per_case');
        DB::statement("CREATE UNIQUE INDEX commission_rules_one_per_case ON commission_rules (tenant_id, kind, coalesce(professional_id, '{$none}'), coalesce(service_id, '{$none}'), coalesce(product_id, '{$none}'), coalesce(category_id, '{$none}'))");
        DB::statement('ALTER TABLE commission_rules DROP CONSTRAINT IF EXISTS commission_rules_shape_check');
        DB::statement("ALTER TABLE commission_rules ADD CONSTRAINT commission_rules_shape_check CHECK ((kind = 'service' AND service_id IS NOT NULL AND product_id IS NULL AND category_id IS NULL) OR (kind = 'service' AND category_id IS NOT NULL AND service_id IS NULL AND professional_id IS NULL AND product_id IS NULL) OR (kind = 'product' AND professional_id IS NULL AND service_id IS NULL AND category_id IS NULL))");
    }

    public function down(): void
    {
        if (!Schema::hasTable('commission_rules')) {
            return;
        }

        $none = self::NONE;
        DB::table('commission_rules')->whereNotNull('category_id')->delete();
        DB::statement('ALTER TABLE commission_rules DROP CONSTRAINT IF EXISTS commission_rules_shape_check');
        DB::statement("ALTER TABLE commission_rules ADD CONSTRAINT commission_rules_shape_check CHECK ((kind = 'service' AND service_id IS NOT NULL AND product_id IS NULL AND category_id IS NULL) OR (kind = 'product' AND professional_id IS NULL AND service_id IS NULL AND category_id IS NULL))");
        DB::statement('DROP INDEX IF EXISTS commission_rules_one_per_case');
        DB::statement("CREATE UNIQUE INDEX commission_rules_one_per_case ON commission_rules (tenant_id, kind, coalesce(professional_id, '{$none}'), coalesce(service_id, '{$none}'), coalesce(product_id, '{$none}'))");
    }
};
