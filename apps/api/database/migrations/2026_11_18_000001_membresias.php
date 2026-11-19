<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            if (!Schema::hasColumn('membership_plans', 'currency')) {
                $table->string('currency', 3)->nullable();
            }
            if (!Schema::hasColumn('membership_plans', 'discount_scope')) {
                $table->string('discount_scope', 20)->default('services');
            }
            if (!Schema::hasColumn('membership_plans', 'deleted_at')) {
                $table->timestampTz('deleted_at')->nullable();
            }
        });

        DB::statement('ALTER TABLE membership_plans DROP CONSTRAINT IF EXISTS membership_plans_tenant_id_name_key');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS membership_plans_name_alive ON membership_plans (tenant_id, lower(name)) WHERE deleted_at IS NULL');
        DB::statement("ALTER TABLE membership_plans DROP CONSTRAINT IF EXISTS membership_plans_discount_scope_check");
        DB::statement("ALTER TABLE membership_plans ADD CONSTRAINT membership_plans_discount_scope_check CHECK (discount_scope IN ('services', 'all'))");

        if (!Schema::hasTable('membership_plan_items')) {
            Schema::create('membership_plan_items', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->uuid('membership_plan_id');
                $table->uuid('service_id');
                $table->integer('sessions');
                $table->integer('position')->default(0);
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('membership_plan_id')->references('id')->on('membership_plans')->cascadeOnDelete();
                $table->foreign('service_id')->references('id')->on('services')->cascadeOnDelete();
                $table->unique(['membership_plan_id', 'service_id']);
            });
            DB::statement('ALTER TABLE membership_plan_items ADD CONSTRAINT membership_plan_items_sessions_check CHECK (sessions BETWEEN 1 AND 99)');
        }

        Schema::table('memberships', function (Blueprint $table) {
            if (!Schema::hasColumn('memberships', 'code')) {
                $table->string('code', 30)->nullable();
            }
            if (!Schema::hasColumn('memberships', 'branch_id')) {
                $table->uuid('branch_id')->nullable();
            }
            if (!Schema::hasColumn('memberships', 'name')) {
                $table->string('name', 150)->nullable();
            }
            if (!Schema::hasColumn('memberships', 'price')) {
                $table->decimal('price', 14, 2)->default(0);
            }
            if (!Schema::hasColumn('memberships', 'currency')) {
                $table->string('currency', 3)->nullable();
            }
            if (!Schema::hasColumn('memberships', 'benefits')) {
                $table->jsonb('benefits')->nullable();
            }
            if (!Schema::hasColumn('memberships', 'created_by')) {
                $table->uuid('created_by')->nullable();
            }
            if (!Schema::hasColumn('memberships', 'cancel_reason')) {
                $table->text('cancel_reason')->nullable();
            }
        });

        if (!Schema::hasTable('membership_usages')) {
            Schema::create('membership_usages', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->uuid('membership_id');
                $table->uuid('sale_id');
                $table->uuid('service_id');
                $table->uuid('appointment_id')->nullable();
                $table->integer('quantity')->default(1);
                $table->date('period_start');
                $table->timestampTz('created_at')->useCurrent();
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('membership_id')->references('id')->on('memberships')->cascadeOnDelete();
                $table->foreign('sale_id')->references('id')->on('sales')->cascadeOnDelete();
                $table->index(['membership_id', 'period_start']);
            });
        }

        if (Access::ready()) {
            $ids = Access::permissionIds();
            $grants = [
                'owner'     => ['view', 'create', 'edit', 'delete'],
                'admin'     => ['view', 'create', 'edit', 'delete'],
                'manager'   => ['view', 'create', 'edit', 'delete'],
                'reception' => ['view'],
                'cashier'   => ['view'],
            ];

            foreach ($grants as $slug => $actions) {
                foreach (DB::table('roles')->where('slug', $slug)->pluck('id') as $roleId) {
                    foreach ($actions as $action) {
                        if (isset($ids["memberships.{$action}"])) {
                            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $ids["memberships.{$action}"]]);
                        }
                    }
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_usages');
        Schema::dropIfExists('membership_plan_items');
        DB::statement('DROP INDEX IF EXISTS membership_plans_name_alive');
        DB::statement('ALTER TABLE membership_plans DROP CONSTRAINT IF EXISTS membership_plans_discount_scope_check');

        Schema::table('memberships', function (Blueprint $table) {
            foreach (['code', 'branch_id', 'name', 'price', 'currency', 'benefits', 'created_by', 'cancel_reason'] as $column) {
                if (Schema::hasColumn('memberships', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('membership_plans', function (Blueprint $table) {
            foreach (['currency', 'discount_scope', 'deleted_at'] as $column) {
                if (Schema::hasColumn('membership_plans', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        DB::table('permissions')->where('name', 'like', 'memberships.%')->delete();
    }
};
