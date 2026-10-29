<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products') || !Schema::hasTable('inventory_movements')) {
            return;
        }

        DB::table('products as p')
            ->where('p.track_stock', true)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('inventory_movements as m')
                ->whereColumn('m.product_id', 'p.id'))
            ->update(['track_stock' => false]);
    }

    public function down(): void
    {
    }
};
