<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('refund_requests') && !Schema::hasColumn('refund_requests', 'restock')) {
            Schema::table('refund_requests', function (Blueprint $table) {
                $table->jsonb('restock')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('refund_requests') && Schema::hasColumn('refund_requests', 'restock')) {
            Schema::table('refund_requests', function (Blueprint $table) {
                $table->dropColumn('restock');
            });
        }
    }
};
