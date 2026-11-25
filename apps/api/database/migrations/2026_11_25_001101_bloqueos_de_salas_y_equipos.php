<?php

use App\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('room_blocks')) {
            return;
        }

        Schema::create('room_blocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('room_id');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('reason', 255);
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('room_id')->references('id')->on('rooms')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'room_id', 'starts_at']);
        });

        Rls::protect('room_blocks');
    }

    public function down(): void
    {
        Schema::dropIfExists('room_blocks');
    }
};
