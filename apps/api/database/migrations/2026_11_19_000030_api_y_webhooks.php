<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('api_keys')) {
            Schema::create('api_keys', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->string('name', 120);
                $table->string('prefix', 20);
                $table->char('key_hash', 64)->unique();
                $table->jsonb('scopes')->default('{}');
                $table->uuid('created_by')->nullable();
                $table->timestampTz('last_used_at')->nullable();
                $table->string('last_used_ip', 45)->nullable();
                $table->timestampTz('revoked_at')->nullable();
                $table->uuid('revoked_by')->nullable();
                $table->timestampsTz();
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
                $table->foreign('revoked_by')->references('id')->on('users')->nullOnDelete();
                $table->index(['tenant_id', 'created_at']);
            });
        }

        if (!Schema::hasTable('webhook_endpoints')) {
            Schema::create('webhook_endpoints', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->string('url', 500);
                $table->string('description', 150)->nullable();
                $table->jsonb('events')->default('[]');
                $table->text('secret');
                $table->boolean('active')->default(true);
                $table->uuid('created_by')->nullable();
                $table->timestampsTz();
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
                $table->index(['tenant_id', 'active']);
            });
        }

        if (!Schema::hasTable('webhook_deliveries')) {
            Schema::create('webhook_deliveries', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->uuid('webhook_id');
                $table->uuid('event_id');
                $table->string('event', 60);
                $table->jsonb('payload');
                $table->string('status', 20)->default('pending');
                $table->integer('attempts')->default(0);
                $table->timestampTz('next_attempt_at')->nullable();
                $table->integer('response_code')->nullable();
                $table->string('response_body', 1000)->nullable();
                $table->string('error', 500)->nullable();
                $table->integer('duration_ms')->nullable();
                $table->timestampTz('delivered_at')->nullable();
                $table->timestampsTz();
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('webhook_id')->references('id')->on('webhook_endpoints')->cascadeOnDelete();
                $table->index(['webhook_id', 'created_at']);
                $table->index(['status', 'next_attempt_at']);
            });

            DB::statement("ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_status_check CHECK (status IN ('pending', 'retrying', 'sent', 'failed'))");
        }

        if (!Schema::hasTable('calendar_feeds')) {
            Schema::create('calendar_feeds', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->uuid('professional_id')->nullable();
                $table->uuid('branch_id')->nullable();
                $table->string('token', 64)->unique();
                $table->string('detail', 20)->default('service');
                $table->timestampTz('last_accessed_at')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestampsTz();
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('professional_id')->references('id')->on('professionals')->cascadeOnDelete();
                $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
                $table->unique(['tenant_id', 'professional_id']);
                $table->unique(['tenant_id', 'branch_id']);
            });

            DB::statement('ALTER TABLE calendar_feeds ADD CONSTRAINT calendar_feeds_owner_check CHECK ((professional_id IS NULL) <> (branch_id IS NULL))');
            DB::statement("ALTER TABLE calendar_feeds ADD CONSTRAINT calendar_feeds_detail_check CHECK (detail IN ('service', 'first_name', 'full'))");
        }

        foreach (['services', 'appointments'] as $name) {
            if (Schema::hasTable($name) && !Schema::hasColumn($name, 'video_url')) {
                Schema::table($name, fn (Blueprint $table) => $table->string('video_url', 500)->nullable());
            }
        }
    }

    public function down(): void
    {
        foreach (['services', 'appointments'] as $name) {
            if (Schema::hasColumn($name, 'video_url')) {
                Schema::table($name, fn (Blueprint $table) => $table->dropColumn('video_url'));
            }
        }

        Schema::dropIfExists('calendar_feeds');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('api_keys');
    }
};
