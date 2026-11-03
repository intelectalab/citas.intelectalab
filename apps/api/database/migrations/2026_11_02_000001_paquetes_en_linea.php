<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('package_plans') && !Schema::hasColumn('package_plans', 'sell_online')) {
            Schema::table('package_plans', function (Blueprint $table) {
                $table->boolean('sell_online')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('package_plans', 'sell_online')) {
            Schema::table('package_plans', function (Blueprint $table) {
                $table->dropColumn('sell_online');
            });
        }
    }
};
