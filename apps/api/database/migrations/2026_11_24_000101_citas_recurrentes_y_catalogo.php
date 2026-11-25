<?php

use App\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('appointment_series')) {
            Schema::create('appointment_series', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->uuid('customer_id');
                $table->uuid('service_id');
                $table->uuid('professional_id');
                $table->uuid('branch_id');
                $table->string('frequency', 20);
                $table->integer('every')->default(1);
                $table->date('first_date');
                $table->string('time_local', 5);
                $table->date('until_date')->nullable();
                $table->integer('occurrences')->nullable();
                $table->integer('created_count')->default(0);
                $table->jsonb('skipped')->default('[]');
                $table->uuid('created_by')->nullable();
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
                $table->index(['tenant_id', 'customer_id']);
            });
            DB::statement("ALTER TABLE appointment_series ADD CONSTRAINT appointment_series_frequency_check CHECK (frequency IN ('weekly', 'days', 'monthly'))");
            DB::statement('ALTER TABLE appointment_series ADD CONSTRAINT appointment_series_every_check CHECK (every BETWEEN 1 AND 90)');
            DB::statement('ALTER TABLE appointment_series ADD CONSTRAINT appointment_series_end_check CHECK (until_date IS NOT NULL OR occurrences IS NOT NULL)');
            Rls::protect('appointment_series');
        }

        Schema::table('appointments', function (Blueprint $table) {
            if (!Schema::hasColumn('appointments', 'series_id')) {
                $table->uuid('series_id')->nullable();
                $table->integer('series_number')->nullable();
                $table->foreign('series_id')->references('id')->on('appointment_series')->nullOnDelete();
                $table->index('series_id');
            }
        });

        if (!Schema::hasTable('service_branch_prices')) {
            Schema::create('service_branch_prices', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->uuid('service_id');
                $table->uuid('branch_id');
                $table->decimal('price', 14, 2);
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('service_id')->references('id')->on('services')->cascadeOnDelete();
                $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
                $table->unique(['service_id', 'branch_id']);
            });
            DB::statement('ALTER TABLE service_branch_prices ADD CONSTRAINT service_branch_prices_price_check CHECK (price >= 0)');
            Rls::protect('service_branch_prices');
        }

        if (!Schema::hasTable('sample_records')) {
            Schema::create('sample_records', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->string('table_name', 60);
                $table->uuid('record_id');
                $table->integer('position')->default(0);
                $table->timestampTz('created_at')->useCurrent();
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->index(['tenant_id', 'table_name']);
            });
            Rls::protect('sample_records');
        }

        Schema::table('tenant_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('tenant_settings', 'onboarding_hidden_at')) {
                $table->timestampTz('onboarding_hidden_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            if (Schema::hasColumn('tenant_settings', 'onboarding_hidden_at')) {
                $table->dropColumn('onboarding_hidden_at');
            }
        });

        Schema::dropIfExists('sample_records');
        Schema::dropIfExists('service_branch_prices');

        Schema::table('appointments', function (Blueprint $table) {
            if (Schema::hasColumn('appointments', 'series_id')) {
                $table->dropForeign(['series_id']);
                $table->dropColumn(['series_id', 'series_number']);
            }
        });

        Schema::dropIfExists('appointment_series');
    }
};
