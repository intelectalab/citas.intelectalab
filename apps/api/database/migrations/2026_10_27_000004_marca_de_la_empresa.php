<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_settings') && !Schema::hasColumn('tenant_settings', 'branding')) {
            Schema::table('tenant_settings', function (Blueprint $table) {
                $table->jsonb('branding')->default('{}');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenant_settings', 'branding')) {
            Schema::table('tenant_settings', function (Blueprint $table) {
                $table->dropColumn('branding');
            });
        }
    }
};
