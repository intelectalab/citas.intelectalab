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
        if (!Schema::hasTable('customer_notes')) {
            Schema::create('customer_notes', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->uuid('customer_id');
                $table->text('body');
                $table->boolean('is_private')->default(false);
                $table->uuid('author_id')->nullable();
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();
                $table->timestampTz('deleted_at')->nullable();
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
                $table->foreign('author_id')->references('id')->on('users')->nullOnDelete();
                $table->index(['customer_id', 'created_at']);
            });
        }

        if (!Schema::hasTable('customer_consent_events')) {
            Schema::create('customer_consent_events', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->uuid('customer_id');
                $table->string('channel', 30);
                $table->boolean('granted');
                $table->string('source', 50)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->uuid('user_id')->nullable();
                $table->uuid('actor_customer_id')->nullable();
                $table->timestampTz('created_at')->useCurrent();
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                $table->index(['customer_id', 'created_at']);
            });
        }

        if (Schema::hasTable('customer_tag_assignments')) {
            DB::statement('CREATE INDEX IF NOT EXISTS customer_tag_assignments_tag ON customer_tag_assignments (customer_tag_id)');
        }

        if (Access::ready()) {
            $ids = Access::permissionIds();

            foreach (['owner', 'admin', 'manager'] as $slug) {
                foreach (DB::table('roles')->where('slug', $slug)->pluck('id') as $roleId) {
                    foreach (['customers.notes', 'customers.import'] as $name) {
                        if (isset($ids[$name])) {
                            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $ids[$name]]);
                        }
                    }
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_consent_events');
        Schema::dropIfExists('customer_notes');
        DB::statement('DROP INDEX IF EXISTS customer_tag_assignments_tag');
        DB::table('permissions')->whereIn('name', ['customers.notes', 'customers.import'])->delete();
    }
};
