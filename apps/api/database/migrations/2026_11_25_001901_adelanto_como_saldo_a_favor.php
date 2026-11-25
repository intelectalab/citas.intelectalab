<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('sales', 'deposit_outcome')) {
            return;
        }

        DB::statement('ALTER TABLE sales DROP CONSTRAINT IF EXISTS sales_deposit_outcome_check');
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_deposit_outcome_check CHECK (deposit_outcome IN ('refund_due', 'retained', 'refunded', 'credited'))");
    }

    public function down(): void
    {
        if (!Schema::hasColumn('sales', 'deposit_outcome')) {
            return;
        }

        DB::table('sales')->where('deposit_outcome', 'credited')->update(['deposit_outcome' => 'retained']);
        DB::statement('ALTER TABLE sales DROP CONSTRAINT IF EXISTS sales_deposit_outcome_check');
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_deposit_outcome_check CHECK (deposit_outcome IN ('refund_due', 'retained', 'refunded'))");
    }
};
