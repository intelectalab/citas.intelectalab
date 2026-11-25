<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_dependents') && !Schema::hasColumn('customer_dependents', 'alert')) {
            Schema::table('customer_dependents', function (Blueprint $table) {
                $table->string('alert', 160)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customer_dependents', 'alert')) {
            Schema::table('customer_dependents', function (Blueprint $table) {
                $table->dropColumn('alert');
            });
        }
    }
};
