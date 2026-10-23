<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->where('name', 'campaigns.delete')->delete();
        }

        if (Schema::hasTable('marketing_templates')) {
            DB::table('marketing_templates')->delete();
        }
    }

    public function down(): void
    {
    }
};
