<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('professionals') && !Schema::hasColumn('professionals', 'online_booking')) {
            Schema::table('professionals', function (Blueprint $table) {
                $table->boolean('online_booking')->default(true);
            });
        }

        if (Schema::hasTable('tenant_settings') && !Schema::hasColumn('tenant_settings', 'booking_page')) {
            Schema::table('tenant_settings', function (Blueprint $table) {
                $table->jsonb('booking_page')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('professionals', 'online_booking')) {
            Schema::table('professionals', function (Blueprint $table) {
                $table->dropColumn('online_booking');
            });
        }

        if (Schema::hasColumn('tenant_settings', 'booking_page')) {
            Schema::table('tenant_settings', function (Blueprint $table) {
                $table->dropColumn('booking_page');
            });
        }
    }
};
