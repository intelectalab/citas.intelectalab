<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('service_branches')) {
            return;
        }

        Schema::create('service_branches', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->uuid('service_id');
            $table->uuid('branch_id');
            $table->primary(['service_id', 'branch_id']);
            $table->index('branch_id');
            $table->foreign('service_id')->references('id')->on('services')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_branches');
    }
};
