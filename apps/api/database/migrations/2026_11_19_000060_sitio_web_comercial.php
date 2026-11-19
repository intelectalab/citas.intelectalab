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
        Schema::table('saas_plans', function (Blueprint $table) {
            if (!Schema::hasColumn('saas_plans', 'is_popular')) {
                $table->boolean('is_popular')->default(false);
            }
            if (!Schema::hasColumn('saas_plans', 'tagline')) {
                $table->string('tagline', 160)->nullable();
            }
        });

        DB::table('saas_plans')->where('slug', 'profesional')->whereNotExists(fn ($q) => $q->from('saas_plans')->where('is_popular', true))->update(['is_popular' => true]);

        if (!Schema::hasTable('saas_coupons')) {
            Schema::create('saas_coupons', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->string('code', 30)->unique();
                $table->string('description', 200)->nullable();
                $table->decimal('percent_off', 5, 2)->nullable();
                $table->decimal('amount_off', 12, 2)->nullable();
                $table->integer('months')->default(1);
                $table->integer('max_uses')->nullable();
                $table->integer('used')->default(0);
                $table->date('valid_until')->nullable();
                $table->boolean('active')->default(true);
                $table->jsonb('plan_ids')->default('[]');
                $table->uuid('created_by')->nullable();
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();
            });
            DB::statement('ALTER TABLE saas_coupons ADD CONSTRAINT saas_coupons_value_check CHECK ((percent_off IS NOT NULL AND percent_off > 0 AND percent_off <= 100 AND amount_off IS NULL) OR (amount_off IS NOT NULL AND amount_off > 0 AND percent_off IS NULL))');
            DB::statement('ALTER TABLE saas_coupons ADD CONSTRAINT saas_coupons_months_check CHECK (months BETWEEN 1 AND 36)');
            DB::statement('ALTER TABLE saas_coupons ADD CONSTRAINT saas_coupons_used_check CHECK (used >= 0 AND (max_uses IS NULL OR used <= max_uses))');
        }

        if (!Schema::hasTable('saas_coupon_redemptions')) {
            Schema::create('saas_coupon_redemptions', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('coupon_id');
                $table->uuid('tenant_id');
                $table->uuid('subscription_id');
                $table->string('code', 30);
                $table->decimal('percent_off', 5, 2)->nullable();
                $table->decimal('amount_off', 12, 2)->nullable();
                $table->jsonb('plan_ids')->default('[]');
                $table->integer('cycles_left');
                $table->integer('cycles_used')->default(0);
                $table->string('source', 20)->default('signup');
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();
                $table->foreign('coupon_id')->references('id')->on('saas_coupons')->restrictOnDelete();
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('subscription_id')->references('id')->on('subscriptions')->cascadeOnDelete();
                $table->unique(['coupon_id', 'tenant_id']);
                $table->index('subscription_id');
            });
            Rls::protect('saas_coupon_redemptions');
        }

        if (!Schema::hasTable('web_leads')) {
            Schema::create('web_leads', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id')->nullable();
                $table->string('name', 150);
                $table->string('email', 190)->nullable();
                $table->string('phone', 30)->nullable();
                $table->string('business', 200)->nullable();
                $table->string('business_type', 50)->nullable();
                $table->integer('professionals')->nullable();
                $table->string('source', 20);
                $table->jsonb('utm')->default('{}');
                $table->string('stage', 20)->default('new');
                $table->string('coupon', 30)->nullable();
                $table->string('lost_reason', 200)->nullable();
                $table->timestampTz('trial_at')->nullable();
                $table->timestampTz('paid_at')->nullable();
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();
                $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
                $table->index(['stage', 'created_at']);
                $table->index('tenant_id');
            });
            DB::statement("ALTER TABLE web_leads ADD CONSTRAINT web_leads_stage_check CHECK (stage IN ('new', 'contacted', 'trial', 'paid', 'lost'))");
            Rls::protect('web_leads');
        }

        if (!Schema::hasTable('web_lead_events')) {
            Schema::create('web_lead_events', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('lead_id');
                $table->string('kind', 10);
                $table->string('from_stage', 20)->nullable();
                $table->string('to_stage', 20)->nullable();
                $table->text('body')->nullable();
                $table->uuid('user_id')->nullable();
                $table->string('user_name', 150)->nullable();
                $table->timestampTz('created_at')->useCurrent();
                $table->foreign('lead_id')->references('id')->on('web_leads')->cascadeOnDelete();
                $table->index(['lead_id', 'created_at']);
            });
        }

        if (!Schema::hasTable('web_visits')) {
            Schema::create('web_visits', function (Blueprint $table) {
                $table->date('day');
                $table->string('page', 20);
                $table->string('source', 40);
                $table->string('business_type', 50)->default('');
                $table->integer('visits')->default(0);
                $table->primary(['day', 'page', 'source', 'business_type']);
            });
        }

        if (!Schema::hasTable('web_posts')) {
            Schema::create('web_posts', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->string('slug', 90)->unique();
                $table->string('title', 160);
                $table->string('excerpt', 300)->nullable();
                $table->text('body');
                $table->string('business_type', 50)->nullable();
                $table->string('seo_title', 120)->nullable();
                $table->string('seo_description', 300)->nullable();
                $table->string('cover_path', 255)->nullable();
                $table->string('status', 20)->default('draft');
                $table->timestampTz('published_at')->nullable();
                $table->string('author', 150)->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();
                $table->index(['status', 'published_at']);
            });
            DB::statement("ALTER TABLE web_posts ADD CONSTRAINT web_posts_status_check CHECK (status IN ('draft', 'published'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('web_posts');
        Schema::dropIfExists('web_visits');
        Schema::dropIfExists('web_lead_events');
        Schema::dropIfExists('web_leads');
        Schema::dropIfExists('saas_coupon_redemptions');
        Schema::dropIfExists('saas_coupons');

        Schema::table('saas_plans', function (Blueprint $table) {
            if (Schema::hasColumn('saas_plans', 'tagline')) {
                $table->dropColumn('tagline');
            }
            if (Schema::hasColumn('saas_plans', 'is_popular')) {
                $table->dropColumn('is_popular');
            }
        });
    }
};
