<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('messages')) {
            DB::statement('ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_status_check');
            DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_status_check CHECK (status IN ('scheduled', 'queued', 'sent', 'simulated', 'delivered', 'read', 'failed', 'not_sent', 'cancelled'))");

            Schema::table('messages', function (Blueprint $table) {
                if (!Schema::hasColumn('messages', 'provider_message_id')) {
                    $table->string('provider_message_id', 190)->nullable();
                }
                if (!Schema::hasColumn('messages', 'delivered_at')) {
                    $table->timestampTz('delivered_at')->nullable();
                }
                if (!Schema::hasColumn('messages', 'read_at')) {
                    $table->timestampTz('read_at')->nullable();
                }
                if (!Schema::hasColumn('messages', 'meta')) {
                    $table->jsonb('meta')->nullable();
                }
            });

            DB::statement('CREATE INDEX IF NOT EXISTS idx_messages_provider_message ON messages (provider_message_id) WHERE provider_message_id IS NOT NULL');
        }

        if (!Schema::hasTable('whatsapp_conversations')) {
            Schema::create('whatsapp_conversations', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->uuid('customer_id')->nullable();
                $table->string('phone', 30);
                $table->string('contact_name', 190)->nullable();
                $table->string('status', 20)->default('open');
                $table->string('handoff_reason', 255)->nullable();
                $table->timestampTz('handoff_at')->nullable();
                $table->timestampTz('last_inbound_at')->nullable();
                $table->timestampTz('last_message_at')->nullable();
                $table->integer('unread')->default(0);
                $table->timestampsTz();
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
                $table->unique(['tenant_id', 'phone']);
                $table->index(['tenant_id', 'last_message_at']);
            });

            DB::statement("ALTER TABLE whatsapp_conversations ADD CONSTRAINT whatsapp_conversations_status_check CHECK (status IN ('open', 'needs_human', 'closed'))");
        }

        if (!Schema::hasTable('whatsapp_messages')) {
            Schema::create('whatsapp_messages', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->uuid('conversation_id');
                $table->string('direction', 3);
                $table->string('type', 20)->default('text');
                $table->text('body');
                $table->string('template', 120)->nullable();
                $table->string('status', 20);
                $table->string('provider_message_id', 190)->nullable();
                $table->uuid('message_id')->nullable();
                $table->uuid('sent_by')->nullable();
                $table->boolean('by_assistant')->default(false);
                $table->string('error', 500)->nullable();
                $table->timestampTz('sent_at')->nullable();
                $table->timestampTz('delivered_at')->nullable();
                $table->timestampTz('read_at')->nullable();
                $table->timestampsTz();
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('conversation_id')->references('id')->on('whatsapp_conversations')->cascadeOnDelete();
                $table->index(['conversation_id', 'created_at']);
                $table->index(['tenant_id', 'provider_message_id']);
            });

            DB::statement("ALTER TABLE whatsapp_messages ADD CONSTRAINT whatsapp_messages_direction_check CHECK (direction IN ('in', 'out'))");
            DB::statement("ALTER TABLE whatsapp_messages ADD CONSTRAINT whatsapp_messages_status_check CHECK (status IN ('received', 'queued', 'sent', 'simulated', 'delivered', 'read', 'failed'))");
        }

        if (!Schema::hasTable('assistant_logs')) {
            Schema::create('assistant_logs', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->uuid('conversation_id');
                $table->uuid('inbound_id')->nullable();
                $table->uuid('reply_id')->nullable();
                $table->string('provider', 40);
                $table->string('action', 20);
                $table->text('question')->nullable();
                $table->text('reply')->nullable();
                $table->string('reason', 255)->nullable();
                $table->timestampTz('created_at')->useCurrent();
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('conversation_id')->references('id')->on('whatsapp_conversations')->cascadeOnDelete();
                $table->index(['tenant_id', 'created_at']);
            });

            DB::statement("ALTER TABLE assistant_logs ADD CONSTRAINT assistant_logs_action_check CHECK (action IN ('reply', 'handoff', 'blocked', 'booking', 'none', 'error'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_logs');
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_conversations');

        if (Schema::hasTable('messages')) {
            DB::statement('DROP INDEX IF EXISTS idx_messages_provider_message');
            DB::table('messages')->whereIn('status', ['delivered', 'read'])->update(['status' => 'sent']);
            DB::statement('ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_status_check');
            DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_status_check CHECK (status IN ('scheduled', 'queued', 'sent', 'simulated', 'failed', 'not_sent', 'cancelled'))");

            Schema::table('messages', function (Blueprint $table) {
                foreach (['provider_message_id', 'delivered_at', 'read_at', 'meta'] as $column) {
                    if (Schema::hasColumn('messages', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
