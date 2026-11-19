<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('membership_events')) {
            Schema::create('membership_events', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->uuid('membership_id');
                $table->string('kind', 30);
                $table->text('detail')->nullable();
                $table->uuid('sale_id')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestampTz('created_at')->useCurrent();
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('membership_id')->references('id')->on('memberships')->cascadeOnDelete();
                $table->index(['membership_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_events');
    }
};
