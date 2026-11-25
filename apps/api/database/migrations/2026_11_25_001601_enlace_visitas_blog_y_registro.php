<?php

use App\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('link_page_visits')) {
            Schema::create('link_page_visits', function (Blueprint $table) {
                $table->uuid('tenant_id');
                $table->date('day');
                $table->integer('visits')->default(0);
                $table->integer('bookings')->default(0);
                $table->primary(['tenant_id', 'day']);
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            });
            Rls::protect('link_page_visits');
        }

        if (Schema::hasTable('web_posts')) {
            Schema::table('web_posts', function (Blueprint $table) {
                if (!Schema::hasColumn('web_posts', 'keyword')) {
                    $table->string('keyword', 80)->nullable();
                }
                if (!Schema::hasColumn('web_posts', 'views')) {
                    $table->integer('views')->default(0);
                }
            });
        }

        if (Schema::hasTable('web_leads') && !Schema::hasColumn('web_leads', 'heard_from')) {
            Schema::table('web_leads', function (Blueprint $table) {
                $table->string('heard_from', 40)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('web_leads') && Schema::hasColumn('web_leads', 'heard_from')) {
            Schema::table('web_leads', fn (Blueprint $table) => $table->dropColumn('heard_from'));
        }

        foreach (['views', 'keyword'] as $column) {
            if (Schema::hasTable('web_posts') && Schema::hasColumn('web_posts', $column)) {
                Schema::table('web_posts', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }

        Schema::dropIfExists('link_page_visits');
    }
};
