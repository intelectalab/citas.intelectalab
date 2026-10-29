<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = ['actor_type', 'actor_customer_id', 'actor_name', 'impersonator_user_id', 'branch_id', 'entity_label'];

    public function up(): void
    {
        if (!Schema::hasTable('audit_events')) {
            return;
        }

        Schema::table('audit_events', function (Blueprint $table) {
            if (!Schema::hasColumn('audit_events', 'actor_type')) {
                $table->string('actor_type', 20)->nullable();
            }
            if (!Schema::hasColumn('audit_events', 'actor_customer_id')) {
                $table->uuid('actor_customer_id')->nullable();
            }
            if (!Schema::hasColumn('audit_events', 'actor_name')) {
                $table->string('actor_name', 150)->nullable();
            }
            if (!Schema::hasColumn('audit_events', 'impersonator_user_id')) {
                $table->uuid('impersonator_user_id')->nullable();
            }
            if (!Schema::hasColumn('audit_events', 'branch_id')) {
                $table->uuid('branch_id')->nullable();
            }
            if (!Schema::hasColumn('audit_events', 'entity_label')) {
                $table->string('entity_label', 200)->nullable();
            }

            $table->index(['actor_user_id', 'created_at'], 'idx_audit_actor_time');
            $table->index(['action', 'created_at'], 'idx_audit_action_time');
            $table->index(['impersonator_user_id', 'created_at'], 'idx_audit_impersonator_time');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('audit_events')) {
            return;
        }

        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropIndex('idx_audit_actor_time');
            $table->dropIndex('idx_audit_action_time');
            $table->dropIndex('idx_audit_impersonator_time');
            $table->dropColumn(array_values(array_filter(self::COLUMNS, fn ($column) => Schema::hasColumn('audit_events', $column))));
        });
    }
};
