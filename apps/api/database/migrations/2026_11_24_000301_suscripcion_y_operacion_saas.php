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
        if (!Schema::hasTable('saas_events')) {
            Schema::create('saas_events', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->uuid('subscription_id')->nullable();
                $table->string('type', 30);
                $table->uuid('plan_id')->nullable();
                $table->uuid('previous_plan_id')->nullable();
                $table->decimal('mrr_before', 14, 2)->default(0);
                $table->decimal('mrr_after', 14, 2)->default(0);
                $table->decimal('mrr_delta', 14, 2)->default(0);
                $table->decimal('amount', 14, 2)->nullable();
                $table->string('currency', 3)->default('PEN');
                $table->jsonb('meta')->default('{}');
                $table->timestampTz('occurred_at', 6)->useCurrent();
                $table->timestampTz('created_at', 6)->useCurrent();
                $table->index(['occurred_at']);
                $table->index(['tenant_id', 'occurred_at']);
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            });
            DB::statement('ALTER TABLE saas_events ADD COLUMN seq bigserial');
            DB::statement("ALTER TABLE saas_events ADD CONSTRAINT saas_events_type_check CHECK (type IN ('trial', 'new', 'expansion', 'contraction', 'churn', 'reactivation', 'payment', 'cancel_scheduled', 'cancel_resumed'))");
            Rls::protect('saas_events');
        }

        if (!Schema::hasTable('data_exports')) {
            Schema::create('data_exports', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->uuid('requested_by')->nullable();
                $table->string('status', 20)->default('queued');
                $table->string('file_path', 255)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->unsignedInteger('rows')->default(0);
                $table->string('error', 255)->nullable();
                $table->timestampTz('started_at')->nullable();
                $table->timestampTz('finished_at')->nullable();
                $table->timestampTz('expires_at')->nullable();
                $table->timestampTz('downloaded_at')->nullable();
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();
                $table->index(['tenant_id', 'created_at']);
                $table->index(['status']);
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            });
            DB::statement("ALTER TABLE data_exports ADD CONSTRAINT data_exports_status_check CHECK (status IN ('queued', 'running', 'ready', 'failed'))");
            Rls::protect('data_exports');
        }

        if (!Schema::hasColumn('tenants', 'billing')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->jsonb('billing')->nullable();
            });
        }

        if (!Schema::hasTable('saas_invoice_series')) {
            Schema::create('saas_invoice_series', function (Blueprint $table) {
                $table->string('doc_type', 20);
                $table->string('serie', 4);
                $table->unsignedInteger('next_number')->default(1);
                $table->timestampTz('created_at')->useCurrent();
                $table->primary(['doc_type', 'serie']);
            });
        }

        if (!Schema::hasColumn('subscription_invoices', 'doc_type')) {
            Schema::table('subscription_invoices', function (Blueprint $table) {
                $table->string('doc_type', 20)->nullable();
                $table->string('serie', 4)->nullable();
                $table->unsignedInteger('number')->nullable();
                $table->string('customer_doc_type', 30)->nullable();
                $table->string('customer_doc_number', 20)->nullable();
                $table->string('customer_name', 200)->nullable();
                $table->string('customer_address', 255)->nullable();
                $table->decimal('tax', 12, 2)->nullable();
                $table->string('provider', 30)->nullable();
                $table->boolean('simulated')->default(false);
                $table->string('sunat_status', 20)->nullable();
                $table->string('sunat_message', 255)->nullable();
                $table->string('external_doc_id', 190)->nullable();
                $table->string('doc_pdf_url', 500)->nullable();
                $table->unsignedSmallInteger('send_attempts')->default(0);
                $table->timestampTz('next_attempt_at')->nullable();
                $table->timestampTz('issued_at')->nullable();
                $table->unique(['doc_type', 'serie', 'number']);
                $table->index(['sunat_status', 'next_attempt_at']);
            });
        }

        if (!Schema::hasTable('error_logs')) {
            Schema::create('error_logs', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->string('fingerprint', 64)->unique();
                $table->string('source', 10);
                $table->string('level', 10)->default('error');
                $table->string('kind', 190)->nullable();
                $table->text('message');
                $table->string('file', 255)->nullable();
                $table->unsignedInteger('line')->nullable();
                $table->string('url', 500)->nullable();
                $table->string('method', 10)->nullable();
                $table->uuid('tenant_id')->nullable();
                $table->uuid('user_id')->nullable();
                $table->unsignedInteger('occurrences')->default(1);
                $table->jsonb('sample')->default('{}');
                $table->timestampTz('first_seen_at')->useCurrent();
                $table->timestampTz('last_seen_at')->useCurrent();
                $table->timestampTz('notified_at')->nullable();
                $table->timestampTz('resolved_at')->nullable();
                $table->uuid('resolved_by')->nullable();
                $table->index(['resolved_at', 'last_seen_at']);
            });
            DB::statement("ALTER TABLE error_logs ADD CONSTRAINT error_logs_source_check CHECK (source IN ('server', 'web'))");
            Rls::protect('error_logs');
        }

        if (!Schema::hasTable('help_articles')) {
            Schema::create('help_articles', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->string('slug', 120)->unique();
                $table->string('title', 150);
                $table->string('category', 80);
                $table->string('summary', 255)->nullable();
                $table->text('body');
                $table->string('permission', 80)->nullable();
                $table->string('link', 255)->nullable();
                $table->string('replaces_topic', 120)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->uuid('created_by')->nullable();
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();
                $table->index(['is_active', 'category']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('help_articles');
        Schema::dropIfExists('error_logs');

        if (Schema::hasColumn('subscription_invoices', 'doc_type')) {
            Schema::table('subscription_invoices', function (Blueprint $table) {
                $table->dropUnique(['doc_type', 'serie', 'number']);
                $table->dropIndex(['sunat_status', 'next_attempt_at']);
                $table->dropColumn(['doc_type', 'serie', 'number', 'customer_doc_type', 'customer_doc_number', 'customer_name', 'customer_address', 'tax', 'provider', 'simulated', 'sunat_status', 'sunat_message', 'external_doc_id', 'doc_pdf_url', 'send_attempts', 'next_attempt_at', 'issued_at']);
            });
        }

        Schema::dropIfExists('saas_invoice_series');

        if (Schema::hasColumn('tenants', 'billing')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->dropColumn('billing');
            });
        }

        Schema::dropIfExists('data_exports');
        Schema::dropIfExists('saas_events');
    }
};
