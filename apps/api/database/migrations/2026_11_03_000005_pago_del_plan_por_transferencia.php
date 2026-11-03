<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('plan_payments')) {
            return;
        }

        Schema::table('plan_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('plan_payments', 'method_label')) {
                $table->string('method_id', 40)->nullable();
                $table->string('method_label', 120)->nullable();
                $table->string('operation_number', 60)->nullable();
                $table->date('paid_on')->nullable();
                $table->string('voucher_path', 255)->nullable();
                $table->uuid('reviewed_by')->nullable();
                $table->timestampTz('reviewed_at')->nullable();
            }
        });

        DB::statement('ALTER TABLE plan_payments DROP CONSTRAINT IF EXISTS plan_payments_kind_check');
        DB::statement("ALTER TABLE plan_payments ADD CONSTRAINT plan_payments_kind_check CHECK (kind IN ('link', 'card', 'charge', 'transfer'))");
        DB::statement('ALTER TABLE plan_payments DROP CONSTRAINT IF EXISTS plan_payments_status_check');
        DB::statement("ALTER TABLE plan_payments ADD CONSTRAINT plan_payments_status_check CHECK (status IN ('pending', 'review', 'paid', 'failed', 'rejected', 'cancelled', 'expired'))");
    }

    public function down(): void
    {
        if (!Schema::hasTable('plan_payments')) {
            return;
        }

        DB::table('plan_payments')->where('kind', 'transfer')->delete();
        DB::statement('ALTER TABLE plan_payments DROP CONSTRAINT IF EXISTS plan_payments_kind_check');
        DB::statement("ALTER TABLE plan_payments ADD CONSTRAINT plan_payments_kind_check CHECK (kind IN ('link', 'card', 'charge'))");
        DB::statement('ALTER TABLE plan_payments DROP CONSTRAINT IF EXISTS plan_payments_status_check');
        DB::statement("ALTER TABLE plan_payments ADD CONSTRAINT plan_payments_status_check CHECK (status IN ('pending', 'paid', 'failed', 'cancelled', 'expired'))");

        Schema::table('plan_payments', function (Blueprint $table) {
            $table->dropColumn(['method_id', 'method_label', 'operation_number', 'paid_on', 'voucher_path', 'reviewed_by', 'reviewed_at']);
        });
    }
};
