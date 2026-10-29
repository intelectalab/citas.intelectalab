<?php

use App\Models\Tenant;
use App\Support\Quotes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('customer_packages') || !Schema::hasTable('quotes')) {
            return;
        }

        if (!Schema::hasColumn('customer_packages', 'quote_id')) {
            Schema::table('customer_packages', function (Blueprint $table) {
                $table->uuid('quote_id')->nullable();
                $table->foreign('quote_id')->references('id')->on('quotes')->nullOnDelete();
                $table->index('quote_id');
            });
        }

        $accepted = DB::table('quotes')->where('status', 'accepted')->get();

        foreach ($accepted as $quote) {
            $tenant = Tenant::find($quote->tenant_id);

            if ($tenant) {
                Quotes::createPlan($tenant, $quote, null);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customer_packages', 'quote_id')) {
            Schema::table('customer_packages', function (Blueprint $table) {
                $table->dropForeign(['quote_id']);
                $table->dropIndex(['quote_id']);
                $table->dropColumn('quote_id');
            });
        }
    }
};
