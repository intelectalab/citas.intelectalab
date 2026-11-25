<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenants') && !Schema::hasColumn('tenants', 'delete_on')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->timestampTz('deletion_requested_at')->nullable();
                $table->timestampTz('delete_on')->nullable();
                $table->string('deletion_reason', 500)->nullable();
                $table->uuid('deletion_requested_by')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tenants') && Schema::hasColumn('tenants', 'delete_on')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->dropColumn(['deletion_requested_at', 'delete_on', 'deletion_reason', 'deletion_requested_by']);
            });
        }
    }
};
