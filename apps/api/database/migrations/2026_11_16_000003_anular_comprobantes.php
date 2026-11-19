<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices') && !Schema::hasColumn('invoices', 'voided_at')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->timestampTz('voided_at')->nullable();
                $table->uuid('voided_by')->nullable();
                $table->string('void_reason', 255)->nullable();
                $table->timestampTz('sent_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('invoices', 'voided_at')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn(['voided_at', 'voided_by', 'void_reason', 'sent_at']);
            });
        }
    }
};
