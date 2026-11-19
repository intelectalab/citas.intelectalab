<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('professionals') && !Schema::hasColumn('professionals', 'goal_month')) {
            Schema::table('professionals', function (Blueprint $table) {
                $table->decimal('goal_month', 12, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('professionals', 'goal_month')) {
            Schema::table('professionals', function (Blueprint $table) {
                $table->dropColumn('goal_month');
            });
        }
    }
};
