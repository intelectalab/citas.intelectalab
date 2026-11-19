<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cash_sessions') && !Schema::hasColumn('cash_sessions', 'review_status')) {
            Schema::table('cash_sessions', function (Blueprint $table) {
                $table->string('review_status', 20)->nullable();
                $table->uuid('reviewed_by')->nullable();
                $table->timestampTz('reviewed_at')->nullable();
                $table->string('review_notes', 500)->nullable();
            });
            DB::statement("ALTER TABLE cash_sessions ADD CONSTRAINT cash_sessions_review_status_check CHECK (review_status IS NULL OR review_status IN ('pending', 'approved', 'observed'))");
        }

        if (Schema::hasTable('sales') && !Schema::hasTable('refund_requests')) {
            Schema::create('refund_requests', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->uuid('sale_id');
                $table->uuid('branch_id')->nullable();
                $table->decimal('amount', 14, 2);
                $table->string('currency', 3)->default('PEN');
                $table->string('method', 20);
                $table->uuid('cash_session_id')->nullable();
                $table->string('reason', 255);
                $table->string('status', 20)->default('pending');
                $table->uuid('requested_by');
                $table->uuid('reviewed_by')->nullable();
                $table->timestampTz('reviewed_at')->nullable();
                $table->string('review_notes', 255)->nullable();
                $table->uuid('sale_refund_id')->nullable();
                $table->timestampsTz();
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('sale_id')->references('id')->on('sales')->cascadeOnDelete();
                $table->index(['tenant_id', 'status']);
                $table->index('sale_id');
            });
            DB::statement("ALTER TABLE refund_requests ADD CONSTRAINT refund_requests_status_check CHECK (status IN ('pending', 'approved', 'rejected'))");
        }

        if (Access::ready()) {
            $ids = Access::permissionIds();

            foreach (['cash.approve', 'sales.approve'] as $name) {
                if (!isset($ids[$name])) {
                    continue;
                }

                foreach (DB::table('roles')->whereIn('slug', ['owner', 'admin', 'manager'])->pluck('id') as $roleId) {
                    DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $ids[$name]]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_requests');

        if (Schema::hasColumn('cash_sessions', 'review_status')) {
            DB::statement('ALTER TABLE cash_sessions DROP CONSTRAINT IF EXISTS cash_sessions_review_status_check');
            Schema::table('cash_sessions', function (Blueprint $table) {
                $table->dropColumn(['review_status', 'reviewed_by', 'reviewed_at', 'review_notes']);
            });
        }
    }
};
