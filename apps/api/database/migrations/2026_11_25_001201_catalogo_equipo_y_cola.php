<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_categories', function (Blueprint $table) {
            if (!Schema::hasColumn('service_categories', 'color')) {
                $table->string('color', 7)->nullable();
            }
        });

        Schema::table('services', function (Blueprint $table) {
            if (!Schema::hasColumn('services', 'description')) {
                $table->text('description')->nullable();
            }

            if (!Schema::hasColumn('services', 'requires_record')) {
                $table->boolean('requires_record')->default(false);
            }
        });

        Schema::table('rooms', function (Blueprint $table) {
            if (!Schema::hasColumn('rooms', 'capacity')) {
                $table->integer('capacity')->default(1);
            }

            if (!Schema::hasColumn('rooms', 'maintenance_reason')) {
                $table->string('maintenance_reason', 255)->nullable();
                $table->timestampTz('maintenance_since')->nullable();
            }
        });

        if (!DB::table('pg_constraint')->where('conname', 'rooms_capacity_check')->exists()) {
            DB::statement('ALTER TABLE rooms ADD CONSTRAINT rooms_capacity_check CHECK (capacity BETWEEN 1 AND 500)');
        }

        $duplicated = DB::table('services')
            ->whereNotNull('code')
            ->whereRaw("trim(code) <> ''")
            ->groupBy('tenant_id', DB::raw('lower(trim(code))'))
            ->havingRaw('count(*) > 1')
            ->select('tenant_id')
            ->exists();

        if (!$duplicated) {
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS services_tenant_code_unique ON services (tenant_id, lower(trim(code))) WHERE code IS NOT NULL AND trim(code) <> ''");
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS services_tenant_code_unique');
        DB::statement('ALTER TABLE rooms DROP CONSTRAINT IF EXISTS rooms_capacity_check');

        Schema::table('rooms', function (Blueprint $table) {
            foreach (['capacity', 'maintenance_reason', 'maintenance_since'] as $column) {
                if (Schema::hasColumn('rooms', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('services', function (Blueprint $table) {
            foreach (['description', 'requires_record'] as $column) {
                if (Schema::hasColumn('services', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('service_categories', function (Blueprint $table) {
            if (Schema::hasColumn('service_categories', 'color')) {
                $table->dropColumn('color');
            }
        });
    }
};
