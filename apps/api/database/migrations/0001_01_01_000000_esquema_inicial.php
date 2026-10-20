<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::hasTable('tenants')) {
            return;
        }

        DB::unprepared(file_get_contents(base_path('infrastructure/docker/postgres/init.sql')));
    }

    public function down(): void
    {
        DB::unprepared('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
    }
};
