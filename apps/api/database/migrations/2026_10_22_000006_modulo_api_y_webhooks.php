<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('saas_modules')) {
            DB::table('saas_modules')->where('key', 'api')->where('name', 'API e integraciones')->update(['name' => 'API y webhooks']);
        }

        if (Schema::hasTable('saas_plans')) {
            DB::table('saas_plans')->where('description', 'Todo el producto, con API e integraciones')->update(['description' => 'Todo el producto, con API y webhooks']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('saas_modules')) {
            DB::table('saas_modules')->where('key', 'api')->where('name', 'API y webhooks')->update(['name' => 'API e integraciones']);
        }

        if (Schema::hasTable('saas_plans')) {
            DB::table('saas_plans')->where('description', 'Todo el producto, con API y webhooks')->update(['description' => 'Todo el producto, con API e integraciones']);
        }
    }
};
