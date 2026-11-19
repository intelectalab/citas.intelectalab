<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('subscriptions')) {
            return;
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('subscriptions', 'cancel_at')) {
                $table->timestampTz('cancel_at')->nullable();
            }
            if (!Schema::hasColumn('subscriptions', 'discount_percent')) {
                $table->decimal('discount_percent', 5, 2)->default(0);
            }
            if (!Schema::hasColumn('subscriptions', 'discount_cycles')) {
                $table->integer('discount_cycles')->default(0);
            }
        });

        if (!Schema::hasTable('saas_cancellations')) {
            Schema::create('saas_cancellations', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->uuid('subscription_id');
                $table->string('reason', 30);
                $table->string('details', 500)->nullable();
                $table->string('status', 20)->default('scheduled');
                $table->string('offer', 120)->nullable();
                $table->boolean('offer_accepted')->default(false);
                $table->timestampTz('effective_at')->nullable();
                $table->uuid('requested_by')->nullable();
                $table->timestampTz('resumed_at')->nullable();
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();
                $table->index(['tenant_id', 'created_at']);
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('subscription_id')->references('id')->on('subscriptions')->cascadeOnDelete();
            });
            DB::statement("ALTER TABLE saas_cancellations ADD CONSTRAINT saas_cancellations_status_check CHECK (status IN ('scheduled', 'done', 'resumed', 'kept'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_cancellations');

        foreach (['cancel_at', 'discount_percent', 'discount_cycles'] as $column) {
            if (Schema::hasColumn('subscriptions', $column)) {
                Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
