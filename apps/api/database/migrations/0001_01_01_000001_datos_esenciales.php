<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::unprepared(file_get_contents(base_path('infrastructure/docker/postgres/seed.sql')));
    }

    public function down(): void
    {
    }
};
