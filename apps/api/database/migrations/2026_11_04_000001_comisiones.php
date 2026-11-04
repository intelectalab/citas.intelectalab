<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const HEALTH = ['medical', 'dental', 'physio', 'psychology'];

    public function up(): void
    {
        if (!Schema::hasColumn('professionals', 'commission_percent')) {
            Schema::table('professionals', function (Blueprint $table) {
                $table->decimal('commission_percent', 5, 2)->nullable();
            });
        }

        if (!Schema::hasTable('commission_rules')) {
            Schema::create('commission_rules', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->string('name', 120);
                $table->string('kind', 20)->default('service');
                $table->uuid('professional_id')->nullable();
                $table->uuid('service_id')->nullable();
                $table->uuid('category_id')->nullable();
                $table->uuid('product_id')->nullable();
                $table->decimal('percent', 5, 2)->default(0);
                $table->decimal('fixed', 12, 2)->default(0);
                $table->boolean('active')->default(true);
                $table->uuid('created_by')->nullable();
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();
                $table->index(['tenant_id', 'kind', 'active']);
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('professional_id')->references('id')->on('professionals')->cascadeOnDelete();
                $table->foreign('service_id')->references('id')->on('services')->cascadeOnDelete();
                $table->foreign('category_id')->references('id')->on('service_categories')->cascadeOnDelete();
            });
            DB::statement("ALTER TABLE commission_rules ADD CONSTRAINT commission_rules_kind_check CHECK (kind IN ('service', 'product'))");
            DB::statement('ALTER TABLE commission_rules ADD CONSTRAINT commission_rules_amount_check CHECK (percent >= 0 AND percent <= 100 AND fixed >= 0)');
        }

        if (!Schema::hasTable('commission_entries')) {
            Schema::create('commission_entries', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->uuid('branch_id')->nullable();
                $table->uuid('professional_id');
                $table->string('source', 20);
                $table->uuid('sale_id')->nullable();
                $table->uuid('sale_item_id')->nullable();
                $table->uuid('package_usage_id')->nullable();
                $table->uuid('refund_id')->nullable();
                $table->uuid('reverses_id')->nullable();
                $table->string('kind', 20)->default('service');
                $table->string('description', 255);
                $table->decimal('quantity', 14, 4)->default(1);
                $table->decimal('base', 14, 2)->default(0);
                $table->decimal('percent', 5, 2)->default(0);
                $table->decimal('fixed', 12, 2)->default(0);
                $table->uuid('rule_id')->nullable();
                $table->string('rule_name', 150)->nullable();
                $table->decimal('amount', 14, 2);
                $table->string('currency', 3)->default('PEN');
                $table->char('period', 7);
                $table->string('status', 20)->default('calculated');
                $table->timestampTz('earned_at');
                $table->timestampTz('approved_at')->nullable();
                $table->uuid('approved_by')->nullable();
                $table->timestampTz('paid_at')->nullable();
                $table->uuid('paid_by')->nullable();
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();
                $table->index(['tenant_id', 'period']);
                $table->index(['tenant_id', 'professional_id', 'period']);
                $table->index('sale_id');
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('professional_id')->references('id')->on('professionals')->cascadeOnDelete();
                $table->foreign('sale_id')->references('id')->on('sales')->nullOnDelete();
            });
            DB::statement("ALTER TABLE commission_entries ADD CONSTRAINT commission_entries_source_check CHECK (source IN ('sale', 'package_use', 'refund'))");
            DB::statement("ALTER TABLE commission_entries ADD CONSTRAINT commission_entries_status_check CHECK (status IN ('calculated', 'approved', 'paid'))");
            DB::statement("CREATE UNIQUE INDEX commission_entries_sale_item_unique ON commission_entries (sale_item_id) WHERE source = 'sale'");
            DB::statement("CREATE UNIQUE INDEX commission_entries_usage_unique ON commission_entries (package_usage_id) WHERE source = 'package_use'");
            DB::statement("CREATE UNIQUE INDEX commission_entries_refund_unique ON commission_entries (refund_id, reverses_id) WHERE source = 'refund'");
        }

        foreach (self::HEALTH as $slug) {
            DB::statement(
                "UPDATE business_types SET modules_off = modules_off || '[\"commissions\"]'::jsonb WHERE slug = ? AND NOT (modules_off @> '[\"commissions\"]'::jsonb)",
                [$slug],
            );
        }

        if (Schema::hasTable('tenant_settings')) {
            DB::statement(
                "UPDATE tenant_settings AS s SET modules_off = coalesce(s.modules_off, '[]'::jsonb) || '[\"commissions\"]'::jsonb
                 FROM tenants AS t JOIN business_types AS b ON b.id = t.business_type_id
                 WHERE t.id = s.tenant_id AND b.slug IN ('medical', 'dental', 'physio', 'psychology')
                 AND NOT (coalesce(s.modules_off, '[]'::jsonb) @> '[\"commissions\"]'::jsonb)"
            );
        }

        if (!Access::ready()) {
            return;
        }

        $ids = Access::permissionIds();
        $grants = ['manager' => ['commissions.view', 'commissions.approve'], 'professional' => ['commissions.view']];

        foreach ($grants as $slug => $names) {
            foreach (DB::table('roles')->where('slug', $slug)->whereNotNull('permissions_seeded_at')->pluck('id') as $roleId) {
                foreach ($names as $name) {
                    if (isset($ids[$name])) {
                        DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $ids[$name]]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_entries');
        Schema::dropIfExists('commission_rules');

        if (Schema::hasColumn('professionals', 'commission_percent')) {
            Schema::table('professionals', function (Blueprint $table) {
                $table->dropColumn('commission_percent');
            });
        }
    }
};
