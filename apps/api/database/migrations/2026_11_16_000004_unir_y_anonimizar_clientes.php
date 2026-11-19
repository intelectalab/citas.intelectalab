<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customers') && !Schema::hasColumn('customers', 'anonymized_at')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->timestampTz('anonymized_at')->nullable();
                $table->uuid('merged_into_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'anonymized_at')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn(['anonymized_at', 'merged_into_id']);
            });
        }
    }
};
