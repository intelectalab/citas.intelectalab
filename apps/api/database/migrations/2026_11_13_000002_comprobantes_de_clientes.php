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
        if (!Schema::hasTable('payment_links')) {
            return;
        }

        DB::statement('ALTER TABLE payment_links DROP CONSTRAINT IF EXISTS payment_links_status_check');
        DB::statement("ALTER TABLE payment_links ADD CONSTRAINT payment_links_status_check CHECK (status IN ('pending', 'review', 'paid', 'expired', 'cancelled', 'failed'))");

        if (Schema::hasTable('tenant_integrations')) {
            DB::statement('ALTER TABLE tenant_integrations DROP CONSTRAINT IF EXISTS tenant_integrations_kind_check');
            DB::statement("ALTER TABLE tenant_integrations ADD CONSTRAINT tenant_integrations_kind_check CHECK (kind IN ('payments', 'invoicing', 'messaging', 'email', 'whatsapp', 'transfer'))");
        }

        if (!Schema::hasColumn('payment_links', 'held_until')) {
            Schema::table('payment_links', function (Blueprint $table) {
                $table->timestampTz('held_until')->nullable();
            });
        }

        if (!Schema::hasTable('payment_vouchers')) {
            Schema::create('payment_vouchers', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->uuid('payment_link_id');
                $table->uuid('branch_id')->nullable();
                $table->uuid('customer_id')->nullable();
                $table->string('method', 20);
                $table->string('account_label', 120)->nullable();
                $table->string('operation_number', 60);
                $table->date('paid_on');
                $table->string('voucher_path', 255);
                $table->decimal('amount', 14, 2);
                $table->string('currency', 3)->default('PEN');
                $table->string('status', 20)->default('review');
                $table->string('reason', 255)->nullable();
                $table->uuid('reviewed_by')->nullable();
                $table->timestampTz('reviewed_at')->nullable();
                $table->timestampsTz();
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('payment_link_id')->references('id')->on('payment_links')->cascadeOnDelete();
                $table->index(['tenant_id', 'status']);
            });
            DB::statement("ALTER TABLE payment_vouchers ADD CONSTRAINT payment_vouchers_status_check CHECK (status IN ('review', 'approved', 'rejected'))");
            DB::statement("ALTER TABLE payment_vouchers ADD CONSTRAINT payment_vouchers_method_check CHECK (method IN ('transfer', 'yape', 'plin'))");
        }

        if (Access::ready()) {
            $ids = Access::permissionIds();

            if (isset($ids['payments.review'])) {
                foreach (DB::table('roles')->whereIn('slug', ['owner', 'admin', 'manager', 'cashier'])->pluck('id') as $roleId) {
                    DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $ids['payments.review']]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_vouchers');

        if (Schema::hasColumn('payment_links', 'held_until')) {
            Schema::table('payment_links', function (Blueprint $table) {
                $table->dropColumn('held_until');
            });
        }
    }
};
