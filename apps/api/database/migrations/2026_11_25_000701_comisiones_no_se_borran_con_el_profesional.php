<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->relink('RESTRICT');
    }

    public function down(): void
    {
        $this->relink('CASCADE');
    }

    private function relink(string $rule): void
    {
        if (!Schema::hasTable('commission_entries')) {
            return;
        }

        $current = DB::table('pg_constraint')
            ->where('conrelid', DB::raw("'public.commission_entries'::regclass"))
            ->where('confrelid', DB::raw("'public.professionals'::regclass"))
            ->where('contype', 'f')
            ->pluck('conname');

        foreach ($current as $name) {
            DB::statement("ALTER TABLE commission_entries DROP CONSTRAINT \"{$name}\"");
        }

        DB::statement("ALTER TABLE commission_entries ADD CONSTRAINT commission_entries_professional_id_foreign FOREIGN KEY (professional_id) REFERENCES professionals(id) ON DELETE {$rule}");
    }
};
