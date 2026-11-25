<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('saas_announcements')) {
            Schema::table('saas_announcements', function (Blueprint $table) {
                if (!Schema::hasColumn('saas_announcements', 'link_url')) {
                    $table->string('link_url', 500)->nullable();
                }

                if (!Schema::hasColumn('saas_announcements', 'link_label')) {
                    $table->string('link_label', 60)->nullable();
                }

                if (!Schema::hasColumn('saas_announcements', 'published')) {
                    $table->boolean('published')->default(true);
                }
            });
        }

        if (Schema::hasTable('business_types') && !Schema::hasColumn('business_types', 'factory')) {
            Schema::table('business_types', function (Blueprint $table) {
                $table->jsonb('factory')->nullable();
            });
        }

        \App\Support\BusinessTemplates::applyExtras();

        if (Schema::hasTable('subscription_invoices') && !Schema::hasColumn('subscription_invoices', 'list_amount')) {
            Schema::table('subscription_invoices', function (Blueprint $table) {
                $table->decimal('list_amount', 12, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('business_types', 'factory')) {
            Schema::table('business_types', fn (Blueprint $table) => $table->dropColumn('factory'));
        }

        if (Schema::hasColumn('subscription_invoices', 'list_amount')) {
            Schema::table('subscription_invoices', fn (Blueprint $table) => $table->dropColumn('list_amount'));
        }

        foreach (['link_url', 'link_label', 'published'] as $column) {
            if (Schema::hasColumn('saas_announcements', $column)) {
                Schema::table('saas_announcements', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
