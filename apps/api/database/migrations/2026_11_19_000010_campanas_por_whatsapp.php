<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('marketing_campaigns')) {
            DB::statement('ALTER TABLE marketing_campaigns DROP CONSTRAINT IF EXISTS marketing_campaigns_channel_check');
            DB::statement("ALTER TABLE marketing_campaigns ADD CONSTRAINT marketing_campaigns_channel_check CHECK (channel IN ('whatsapp', 'email', 'sms', 'both'))");
            DB::statement('ALTER TABLE marketing_campaigns DROP CONSTRAINT IF EXISTS marketing_campaigns_status_check');
            DB::statement("ALTER TABLE marketing_campaigns ADD CONSTRAINT marketing_campaigns_status_check CHECK (status IN ('draft', 'scheduled', 'sending', 'sent', 'cancelled', 'blocked'))");

            if (!Schema::hasColumn('marketing_campaigns', 'whatsapp')) {
                Schema::table('marketing_campaigns', function (Blueprint $table) {
                    $table->jsonb('whatsapp')->nullable();
                });
            }
        }

        if (Schema::hasTable('marketing_campaign_recipients')) {
            if (!Schema::hasColumn('marketing_campaign_recipients', 'channel')) {
                Schema::table('marketing_campaign_recipients', function (Blueprint $table) {
                    $table->string('channel', 20)->default('email');
                });
            }

            DB::statement('ALTER TABLE marketing_campaign_recipients DROP CONSTRAINT IF EXISTS marketing_campaign_recipients_marketing_campaign_id_custome_key');
            DB::statement('ALTER TABLE marketing_campaign_recipients DROP CONSTRAINT IF EXISTS marketing_campaign_recipients_campaign_customer_channel_key');
            DB::statement('ALTER TABLE marketing_campaign_recipients ADD CONSTRAINT marketing_campaign_recipients_campaign_customer_channel_key UNIQUE (marketing_campaign_id, customer_id, channel)');
        }

        if (Schema::hasTable('messages')) {
            DB::statement("CREATE INDEX IF NOT EXISTS idx_messages_month_usage ON messages (tenant_id, channel, sent_at) WHERE status IN ('sent', 'simulated', 'delivered', 'read')");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('marketing_campaign_recipients') && Schema::hasColumn('marketing_campaign_recipients', 'channel')) {
            DB::statement("DELETE FROM marketing_campaign_recipients WHERE channel <> 'email'");
            DB::statement('ALTER TABLE marketing_campaign_recipients DROP CONSTRAINT IF EXISTS marketing_campaign_recipients_campaign_customer_channel_key');
            DB::statement('ALTER TABLE marketing_campaign_recipients ADD CONSTRAINT marketing_campaign_recipients_marketing_campaign_id_custome_key UNIQUE (marketing_campaign_id, customer_id)');
            Schema::table('marketing_campaign_recipients', fn (Blueprint $table) => $table->dropColumn('channel'));
        }

        DB::statement('DROP INDEX IF EXISTS idx_messages_month_usage');

        if (Schema::hasTable('marketing_campaigns') && Schema::hasColumn('marketing_campaigns', 'whatsapp')) {
            Schema::table('marketing_campaigns', fn (Blueprint $table) => $table->dropColumn('whatsapp'));
        }
    }
};
