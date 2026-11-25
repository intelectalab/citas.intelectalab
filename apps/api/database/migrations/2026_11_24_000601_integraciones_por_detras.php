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
        if (!Schema::hasTable('integration_logs')) {
            Schema::create('integration_logs', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->string('kind', 30);
                $table->string('provider', 40);
                $table->string('action', 40);
                $table->boolean('ok')->default(false);
                $table->string('message', 500)->nullable();
                $table->timestampTz('created_at')->useCurrent();
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->index(['tenant_id', 'kind', 'created_at']);
            });

            Rls::protect('integration_logs');
        }

        if (!Schema::hasTable('platform_integration_logs')) {
            Schema::create('platform_integration_logs', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->string('kind', 30);
                $table->string('provider', 40);
                $table->string('action', 40);
                $table->boolean('ok')->default(false);
                $table->string('message', 500)->nullable();
                $table->timestampTz('created_at')->useCurrent();
                $table->index(['kind', 'created_at']);
            });
        }

        if (Schema::hasTable('tenant_integrations')) {
            DB::statement('ALTER TABLE tenant_integrations DROP CONSTRAINT IF EXISTS tenant_integrations_kind_check');
            DB::statement("ALTER TABLE tenant_integrations ADD CONSTRAINT tenant_integrations_kind_check CHECK (kind IN ('payments', 'invoicing', 'messaging', 'email', 'whatsapp', 'transfer', 'ai'))");
        }

        if (Schema::hasTable('assistant_logs')) {
            DB::statement('ALTER TABLE assistant_logs DROP CONSTRAINT IF EXISTS assistant_logs_action_check');
            DB::statement("ALTER TABLE assistant_logs ADD CONSTRAINT assistant_logs_action_check CHECK (action IN ('reply', 'handoff', 'blocked', 'booking', 'acted', 'none', 'error'))");
        }

        if (Schema::hasTable('whatsapp_messages') && !Schema::hasColumn('whatsapp_messages', 'media_path')) {
            Schema::table('whatsapp_messages', function (Blueprint $table) {
                $table->string('media_path', 500)->nullable();
                $table->string('media_mime', 120)->nullable();
                $table->string('media_name', 190)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('whatsapp_messages', 'media_path')) {
            Schema::table('whatsapp_messages', function (Blueprint $table) {
                $table->dropColumn(['media_path', 'media_mime', 'media_name']);
            });
        }

        if (Schema::hasTable('assistant_logs')) {
            DB::statement("UPDATE assistant_logs SET action = 'booking' WHERE action = 'acted'");
            DB::statement('ALTER TABLE assistant_logs DROP CONSTRAINT IF EXISTS assistant_logs_action_check');
            DB::statement("ALTER TABLE assistant_logs ADD CONSTRAINT assistant_logs_action_check CHECK (action IN ('reply', 'handoff', 'blocked', 'booking', 'none', 'error'))");
        }

        if (Schema::hasTable('tenant_integrations')) {
            DB::statement("DELETE FROM tenant_integrations WHERE kind = 'ai'");
            DB::statement('ALTER TABLE tenant_integrations DROP CONSTRAINT IF EXISTS tenant_integrations_kind_check');
            DB::statement("ALTER TABLE tenant_integrations ADD CONSTRAINT tenant_integrations_kind_check CHECK (kind IN ('payments', 'invoicing', 'messaging', 'email', 'whatsapp', 'transfer'))");
        }

        Schema::dropIfExists('platform_integration_logs');
        Schema::dropIfExists('integration_logs');
    }
};
