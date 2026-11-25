<?php

use App\Support\Access;
use App\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table) {
                if (!Schema::hasColumn('sales', 'manual_discount')) {
                    $table->decimal('manual_discount', 14, 2)->default(0);
                }

                if (!Schema::hasColumn('sales', 'discount_reason')) {
                    $table->string('discount_reason', 255)->nullable();
                }

                if (!Schema::hasColumn('sales', 'discount_by')) {
                    $table->uuid('discount_by')->nullable();
                }
            });

            DB::statement('ALTER TABLE sales DROP CONSTRAINT IF EXISTS sales_manual_discount_check');
            DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_manual_discount_check CHECK (manual_discount >= 0)');
        }

        if (Schema::hasTable('invoices')) {
            DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_sunat_status_check');
            DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_sunat_status_check CHECK (sunat_status IN ('pending', 'accepted', 'observed', 'rejected', 'not_applicable'))");
        }

        if (!Schema::hasTable('care_record_files') && Schema::hasTable('care_records')) {
            Schema::create('care_record_files', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('care_record_id')->constrained('care_records')->cascadeOnDelete();
                $table->string('file_name', 190);
                $table->string('mime', 100);
                $table->unsignedBigInteger('size');
                $table->string('path', 300);
                $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('deleted_at')->nullable();
                $table->index(['care_record_id', 'created_at']);
            });
        }

        if (Schema::hasTable('care_record_files')) {
            Rls::protect('care_record_files');
        }

        if (Access::ready()) {
            $ids = Access::permissionIds();
            $grant = function (iterable $roleIds, string $name) use ($ids) {
                if (!isset($ids[$name])) {
                    return;
                }

                foreach ($roleIds as $roleId) {
                    DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $ids[$name]]);
                }
            };

            $leaders = DB::table('roles')->whereIn('slug', ['owner', 'admin', 'manager'])->pluck('id');
            $grant($leaders, 'pos.discount');
            $grant($leaders, 'records.all');

            if (isset($ids['data.export'])) {
                $grant(DB::table('role_permissions')->where('permission_id', $ids['data.export'])->pluck('role_id'), 'customers.export');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('care_record_files');

        if (Schema::hasTable('invoices')) {
            DB::table('invoices')->where('sunat_status', 'observed')->update(['sunat_status' => 'accepted']);
            DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_sunat_status_check');
            DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_sunat_status_check CHECK (sunat_status IN ('pending', 'accepted', 'rejected', 'not_applicable'))");
        }

        if (Schema::hasTable('sales')) {
            DB::statement('ALTER TABLE sales DROP CONSTRAINT IF EXISTS sales_manual_discount_check');

            Schema::table('sales', function (Blueprint $table) {
                foreach (['manual_discount', 'discount_reason', 'discount_by'] as $column) {
                    if (Schema::hasColumn('sales', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        DB::table('permissions')->whereIn('name', ['pos.discount', 'records.all', 'customers.export'])->delete();
    }
};
