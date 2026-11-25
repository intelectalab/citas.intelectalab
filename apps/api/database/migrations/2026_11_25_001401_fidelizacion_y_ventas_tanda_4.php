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
        if (!Schema::hasTable('customer_referrals')) {
            Schema::create('customer_referrals', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->uuid('referrer_customer_id');
                $table->uuid('referred_customer_id');
                $table->unsignedInteger('bonus_points')->default(0);
                $table->string('status', 20)->default('pending');
                $table->uuid('created_by')->nullable();
                $table->timestampTz('rewarded_at')->nullable();
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();
                $table->unique(['tenant_id', 'referred_customer_id']);
                $table->index(['tenant_id', 'referrer_customer_id']);
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('referrer_customer_id')->references('id')->on('customers')->cascadeOnDelete();
                $table->foreign('referred_customer_id')->references('id')->on('customers')->cascadeOnDelete();
            });
            DB::statement("ALTER TABLE customer_referrals ADD CONSTRAINT customer_referrals_status_check CHECK (status IN ('pending', 'rewarded', 'void'))");
            DB::statement('ALTER TABLE customer_referrals ADD CONSTRAINT customer_referrals_distinct_check CHECK (referrer_customer_id <> referred_customer_id)');
            Rls::protect('customer_referrals');
        }

        if (Schema::hasTable('cash_sessions') && !Schema::hasColumn('cash_sessions', 'closing_count')) {
            Schema::table('cash_sessions', function (Blueprint $table) {
                $table->jsonb('closing_count')->nullable();
            });
        }

        if (Schema::hasTable('gift_card_transactions') && !Schema::hasColumn('gift_card_transactions', 'reason')) {
            Schema::table('gift_card_transactions', function (Blueprint $table) {
                $table->string('reason', 255)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('gift_card_transactions', 'reason')) {
            Schema::table('gift_card_transactions', function (Blueprint $table) {
                $table->dropColumn('reason');
            });
        }

        if (Schema::hasColumn('cash_sessions', 'closing_count')) {
            Schema::table('cash_sessions', function (Blueprint $table) {
                $table->dropColumn('closing_count');
            });
        }

        Schema::dropIfExists('customer_referrals');
    }
};
