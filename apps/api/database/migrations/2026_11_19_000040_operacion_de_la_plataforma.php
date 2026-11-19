<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('saas_announcements')) {
            Schema::create('saas_announcements', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->string('title', 150);
                $table->text('body');
                $table->string('kind', 20)->default('news');
                $table->string('audience', 20)->default('all');
                $table->jsonb('plan_ids')->default('[]');
                $table->jsonb('business_type_ids')->default('[]');
                $table->timestampTz('starts_at');
                $table->timestampTz('ends_at')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();
                $table->index(['starts_at', 'ends_at']);
            });
            DB::statement("ALTER TABLE saas_announcements ADD CONSTRAINT saas_announcements_kind_check CHECK (kind IN ('news', 'maintenance', 'important'))");
            DB::statement("ALTER TABLE saas_announcements ADD CONSTRAINT saas_announcements_audience_check CHECK (audience IN ('all', 'plans', 'business_types'))");
        }

        if (!Schema::hasTable('saas_announcement_dismissals')) {
            Schema::create('saas_announcement_dismissals', function (Blueprint $table) {
                $table->uuid('announcement_id');
                $table->uuid('user_id');
                $table->timestampTz('created_at')->useCurrent();
                $table->primary(['announcement_id', 'user_id']);
                $table->foreign('announcement_id')->references('id')->on('saas_announcements')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('saas_support_tickets')) {
            Schema::create('saas_support_tickets', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->string('code', 20)->unique();
                $table->string('subject', 150);
                $table->string('topic', 30)->default('other');
                $table->string('priority', 10)->default('normal');
                $table->string('status', 20)->default('open');
                $table->uuid('created_by')->nullable();
                $table->uuid('assigned_to')->nullable();
                $table->timestampTz('access_until')->nullable();
                $table->timestampTz('due_at')->nullable();
                $table->timestampTz('first_response_at')->nullable();
                $table->timestampTz('resolved_at')->nullable();
                $table->timestampTz('last_message_at')->useCurrent();
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();
                $table->index(['tenant_id', 'created_at']);
                $table->index(['status', 'due_at']);
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            });
            DB::statement("ALTER TABLE saas_support_tickets ADD CONSTRAINT saas_support_tickets_priority_check CHECK (priority IN ('low', 'normal', 'high', 'urgent'))");
            DB::statement("ALTER TABLE saas_support_tickets ADD CONSTRAINT saas_support_tickets_status_check CHECK (status IN ('open', 'in_progress', 'waiting', 'resolved'))");
        }

        if (!Schema::hasTable('saas_support_messages')) {
            Schema::create('saas_support_messages', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('ticket_id');
                $table->uuid('tenant_id');
                $table->uuid('author_id')->nullable();
                $table->string('side', 10);
                $table->text('body');
                $table->string('attachment_path', 255)->nullable();
                $table->string('attachment_name', 150)->nullable();
                $table->timestampTz('created_at')->useCurrent();
                $table->index(['ticket_id', 'created_at']);
                $table->foreign('ticket_id')->references('id')->on('saas_support_tickets')->cascadeOnDelete();
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            });
            DB::statement("ALTER TABLE saas_support_messages ADD CONSTRAINT saas_support_messages_side_check CHECK (side IN ('tenant', 'platform'))");
        }

        if (!Schema::hasTable('saas_support_sessions')) {
            Schema::create('saas_support_sessions', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->uuid('platform_user_id')->nullable();
                $table->uuid('as_user_id')->nullable();
                $table->unsignedBigInteger('token_id')->nullable();
                $table->string('reason', 500);
                $table->uuid('ticket_id')->nullable();
                $table->timestampTz('started_at')->useCurrent();
                $table->timestampTz('expires_at')->nullable();
                $table->timestampTz('ended_at')->nullable();
                $table->index(['tenant_id', 'started_at']);
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            });
        }

        if (!Schema::hasColumn('tenants', 'referral_code')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->string('referral_code', 12)->nullable()->unique();
            });
        }

        if (!Schema::hasTable('saas_referrals')) {
            Schema::create('saas_referrals', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('referrer_tenant_id');
                $table->uuid('referred_tenant_id')->unique();
                $table->string('code', 12);
                $table->string('status', 20)->default('pending');
                $table->string('reward_kind', 20)->nullable();
                $table->decimal('reward_value', 8, 2)->nullable();
                $table->string('reward_note', 255)->nullable();
                $table->timestampTz('rewarded_at')->nullable();
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();
                $table->index('referrer_tenant_id');
                $table->foreign('referrer_tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('referred_tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            });
            DB::statement("ALTER TABLE saas_referrals ADD CONSTRAINT saas_referrals_status_check CHECK (status IN ('pending', 'rewarded', 'void'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_referrals');

        if (Schema::hasColumn('tenants', 'referral_code')) {
            Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('referral_code'));
        }

        Schema::dropIfExists('saas_support_sessions');
        Schema::dropIfExists('saas_support_messages');
        Schema::dropIfExists('saas_support_tickets');
        Schema::dropIfExists('saas_announcement_dismissals');
        Schema::dropIfExists('saas_announcements');
    }
};
