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
        if (!Schema::hasTable('whatsapp_templates')) {
            Schema::create('whatsapp_templates', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->string('provider', 20);
                $table->string('name', 120);
                $table->string('language', 10);
                $table->string('status', 20);
                $table->string('category', 30)->nullable();
                $table->text('body')->nullable();
                $table->unsignedSmallInteger('params')->default(0);
                $table->string('reason', 500)->nullable();
                $table->string('external_id', 80)->nullable();
                $table->uuid('submitted_by')->nullable();
                $table->timestampTz('submitted_at')->nullable();
                $table->timestampTz('synced_at')->nullable();
                $table->timestampsTz();
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->unique(['tenant_id', 'name', 'language']);
            });

            Rls::protect('whatsapp_templates');
        }

        if (!Schema::hasTable('payment_webhooks')) {
            Schema::create('payment_webhooks', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id')->nullable();
                $table->string('owner_key', 40);
                $table->string('provider', 30);
                $table->string('event_key', 190);
                $table->string('topic', 80)->nullable();
                $table->string('reference', 120)->nullable();
                $table->string('status', 20)->default('received');
                $table->string('message', 500)->nullable();
                $table->jsonb('payload')->nullable();
                $table->jsonb('query')->nullable();
                $table->jsonb('headers')->nullable();
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->unsignedSmallInteger('repeats')->default(0);
                $table->timestampTz('next_retry_at')->nullable();
                $table->timestampTz('processed_at')->nullable();
                $table->timestampsTz();
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->unique(['provider', 'owner_key', 'event_key']);
                $table->index(['owner_key', 'created_at']);
                $table->index(['status', 'next_retry_at']);
            });

            Rls::protect('payment_webhooks');
        }

        if (Schema::hasTable('whatsapp_conversations') && !Schema::hasColumn('whatsapp_conversations', 'assistant_started_at')) {
            Schema::table('whatsapp_conversations', function (Blueprint $table) {
                $table->timestampTz('assistant_started_at')->nullable();
                $table->text('handoff_summary')->nullable();
            });
        }

        if (Schema::hasTable('subscriptions') && !Schema::hasColumn('subscriptions', 'card_exp_month')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->unsignedSmallInteger('card_exp_month')->nullable();
                $table->unsignedSmallInteger('card_exp_year')->nullable();
                $table->string('card_expiry_notice', 40)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('subscriptions', 'card_exp_month')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropColumn(['card_exp_month', 'card_exp_year', 'card_expiry_notice']);
            });
        }

        if (Schema::hasColumn('whatsapp_conversations', 'assistant_started_at')) {
            Schema::table('whatsapp_conversations', function (Blueprint $table) {
                $table->dropColumn(['assistant_started_at', 'handoff_summary']);
            });
        }

        Schema::dropIfExists('payment_webhooks');
        Schema::dropIfExists('whatsapp_templates');
    }
};
