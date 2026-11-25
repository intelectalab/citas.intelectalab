<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('data_exports')) {
            Schema::table('data_exports', function (Blueprint $table) {
                if (!Schema::hasColumn('data_exports', 'kind')) {
                    $table->string('kind', 20)->default('all');
                }
                if (!Schema::hasColumn('data_exports', 'title')) {
                    $table->string('title', 150)->nullable();
                }
                if (!Schema::hasColumn('data_exports', 'params')) {
                    $table->jsonb('params')->nullable();
                }
            });
        }

        if (Schema::hasTable('payment_links') && !Schema::hasColumn('payment_links', 'tracked_at')) {
            Schema::table('payment_links', function (Blueprint $table) {
                $table->timestampTz('tracked_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payment_links', 'tracked_at')) {
            Schema::table('payment_links', fn (Blueprint $table) => $table->dropColumn('tracked_at'));
        }

        foreach (['params', 'title', 'kind'] as $column) {
            if (Schema::hasTable('data_exports') && Schema::hasColumn('data_exports', $column)) {
                Schema::table('data_exports', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
