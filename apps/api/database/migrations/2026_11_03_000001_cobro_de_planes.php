<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('platform_integrations')) {
            Schema::create('platform_integrations', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->string('kind', 30)->unique();
                $table->string('provider', 40);
                $table->string('mode', 20)->default('sandbox');
                $table->text('credentials')->nullable();
                $table->jsonb('settings')->default('{}');
                $table->string('status', 20)->default('active');
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();
            });
        }

        if (!Schema::hasTable('plan_payments')) {
            Schema::create('plan_payments', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->uuid('subscription_id');
                $table->uuid('plan_id');
                $table->string('billing_cycle', 20);
                $table->string('kind', 20);
                $table->string('status', 20)->default('pending');
                $table->decimal('amount', 14, 2);
                $table->string('currency', 3);
                $table->string('provider', 40);
                $table->string('external_id', 190)->nullable();
                $table->text('checkout_url')->nullable();
                $table->uuid('invoice_id')->nullable();
                $table->string('message', 255)->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestampTz('paid_at')->nullable();
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();
                $table->index(['tenant_id', 'created_at']);
                $table->index(['status', 'kind']);
                $table->unique(['provider', 'kind', 'external_id']);
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('subscription_id')->references('id')->on('subscriptions')->cascadeOnDelete();
            });
            DB::statement("ALTER TABLE plan_payments ADD CONSTRAINT plan_payments_kind_check CHECK (kind IN ('link', 'card', 'charge'))");
            DB::statement("ALTER TABLE plan_payments ADD CONSTRAINT plan_payments_status_check CHECK (status IN ('pending', 'paid', 'failed', 'cancelled', 'expired'))");
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('subscriptions', 'requires_card')) {
                $table->boolean('requires_card')->default(false);
                $table->string('card_status', 20)->nullable();
                $table->string('card_brand', 40)->nullable();
                $table->string('card_last4', 4)->nullable();
                $table->timestampTz('card_authorized_at')->nullable();
                $table->timestampTz('last_charge_failed_at')->nullable();
                $table->uuid('pending_plan_id')->nullable();
                $table->string('pending_billing_cycle', 20)->nullable();
            }
        });

        Schema::table('payment_links', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_links', 'checkout_url')) {
                $table->text('checkout_url')->nullable();
                $table->timestampTz('checked_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_links', function (Blueprint $table) {
            $table->dropColumn(['checkout_url', 'checked_at']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['requires_card', 'card_status', 'card_brand', 'card_last4', 'card_authorized_at', 'last_charge_failed_at', 'pending_plan_id', 'pending_billing_cycle']);
        });

        Schema::dropIfExists('plan_payments');
        Schema::dropIfExists('platform_integrations');
    }
};
