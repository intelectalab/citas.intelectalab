<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_integrations')) {
            DB::statement('ALTER TABLE platform_integrations DROP CONSTRAINT IF EXISTS platform_integrations_kind_unique');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS platform_integrations_kind_provider_unique ON platform_integrations (kind, provider)');
        }

        if (Schema::hasTable('tenant_integrations')) {
            DB::statement('ALTER TABLE tenant_integrations DROP CONSTRAINT IF EXISTS tenant_integrations_tenant_id_kind_key');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS tenant_integrations_tenant_kind_provider_unique ON tenant_integrations (tenant_id, kind, provider)');
        }

        if (!Schema::hasTable('saas_payment_methods')) {
            Schema::create('saas_payment_methods', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->uuid('subscription_id')->nullable();
                $table->string('provider', 40);
                $table->string('customer_ref', 190)->nullable();
                $table->string('card_ref', 190);
                $table->string('brand', 40)->nullable();
                $table->string('last4', 4)->nullable();
                $table->string('status', 20)->default('active');
                $table->uuid('created_by')->nullable();
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();
                $table->index(['tenant_id', 'status']);
                $table->unique(['provider', 'card_ref']);
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('saas_dunning_attempts')) {
            Schema::create('saas_dunning_attempts', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->uuid('subscription_id');
                $table->timestampTz('period_end');
                $table->integer('day');
                $table->string('kind', 20);
                $table->string('result', 20);
                $table->string('message', 255)->nullable();
                $table->uuid('plan_payment_id')->nullable();
                $table->uuid('actor_id')->nullable();
                $table->timestampTz('created_at')->useCurrent();
                $table->index(['subscription_id', 'created_at']);
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('subscription_id')->references('id')->on('subscriptions')->cascadeOnDelete();
            });
            DB::statement("ALTER TABLE saas_dunning_attempts ADD CONSTRAINT saas_dunning_attempts_kind_check CHECK (kind IN ('retry', 'remind', 'readonly', 'cancel', 'extend'))");
            DB::statement('CREATE UNIQUE INDEX saas_dunning_attempts_auto_unique ON saas_dunning_attempts (subscription_id, period_end, day, kind) WHERE actor_id IS NULL');
        }

        if (Schema::hasTable('subscriptions') && !Schema::hasColumn('subscriptions', 'grace_extra_days')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->integer('grace_extra_days')->default(0);
            });
        }

        if (Schema::hasTable('plan_payments') && !Schema::hasColumn('plan_payments', 'intent')) {
            Schema::table('plan_payments', function (Blueprint $table) {
                $table->string('intent', 20)->nullable();
            });
        }

        if (Schema::hasTable('saas_plans')) {
            DB::table('saas_plans')
                ->where('slug', 'empresarial')
                ->whereRaw("(trial_config->>'trial_days')::int = 30")
                ->update(['trial_config' => DB::raw("jsonb_set(trial_config, '{trial_days}', '14'::jsonb)")]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('plan_payments') && Schema::hasColumn('plan_payments', 'intent')) {
            Schema::table('plan_payments', fn (Blueprint $table) => $table->dropColumn('intent'));
        }

        if (Schema::hasTable('subscriptions') && Schema::hasColumn('subscriptions', 'grace_extra_days')) {
            Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn('grace_extra_days'));
        }

        Schema::dropIfExists('saas_dunning_attempts');
        Schema::dropIfExists('saas_payment_methods');

        if (Schema::hasTable('tenant_integrations')) {
            DB::statement("DELETE FROM tenant_integrations a USING tenant_integrations b WHERE a.tenant_id = b.tenant_id AND a.kind = b.kind AND a.provider = 'mercadopago' AND b.provider <> 'mercadopago'");
            DB::statement('DROP INDEX IF EXISTS tenant_integrations_tenant_kind_provider_unique');
            DB::statement('ALTER TABLE tenant_integrations ADD CONSTRAINT tenant_integrations_tenant_id_kind_key UNIQUE (tenant_id, kind)');
        }

        if (Schema::hasTable('platform_integrations')) {
            DB::table('platform_integrations')->where('kind', 'payments')->where('provider', '!=', 'mercadopago')->delete();
            DB::statement('DROP INDEX IF EXISTS platform_integrations_kind_provider_unique');
            DB::statement('ALTER TABLE platform_integrations ADD CONSTRAINT platform_integrations_kind_unique UNIQUE (kind)');
        }
    }
};
