<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $active = DB::table('statuses as s')
            ->join('status_modules as m', 'm.id', '=', 's.module_id')
            ->where('m.name', 'services')
            ->where('s.slug', 'active')
            ->whereNull('s.tenant_id')
            ->value('s.id');

        if ($active) {
            DB::table('services')->whereNull('status_id')->update(['status_id' => $active, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
    }
};
