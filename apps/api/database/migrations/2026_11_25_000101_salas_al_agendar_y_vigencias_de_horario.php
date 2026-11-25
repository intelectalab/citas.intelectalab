<?php

use App\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('service_rooms')) {
            Schema::create('service_rooms', function (Blueprint $table) {
                $table->uuid('tenant_id');
                $table->uuid('service_id');
                $table->uuid('room_id');
                $table->timestampTz('created_at')->useCurrent();
                $table->primary(['service_id', 'room_id']);
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('service_id')->references('id')->on('services')->cascadeOnDelete();
                $table->foreign('room_id')->references('id')->on('rooms')->cascadeOnDelete();
                $table->index(['tenant_id', 'room_id']);
            });
            Rls::protect('service_rooms');
        }

        Schema::table('appointments', function (Blueprint $table) {
            if (!Schema::hasIndex('appointments', 'appointments_room_starts_index')) {
                $table->index(['room_id', 'starts_at'], 'appointments_room_starts_index');
            }
        });

        Schema::table('professional_schedules', function (Blueprint $table) {
            if (!Schema::hasColumn('professional_schedules', 'valid_from')) {
                $table->date('valid_from')->nullable();
                $table->date('valid_until')->nullable();
            }
        });

        if (!$this->hasConstraint('professional_schedules_valid_check')) {
            DB::statement('ALTER TABLE professional_schedules ADD CONSTRAINT professional_schedules_valid_check CHECK (valid_from IS NULL OR valid_until IS NULL OR valid_until >= valid_from)');
        }
    }

    public function down(): void
    {
        if ($this->hasConstraint('professional_schedules_valid_check')) {
            DB::statement('ALTER TABLE professional_schedules DROP CONSTRAINT professional_schedules_valid_check');
        }

        Schema::table('professional_schedules', function (Blueprint $table) {
            if (Schema::hasColumn('professional_schedules', 'valid_from')) {
                $table->dropColumn(['valid_from', 'valid_until']);
            }
        });

        Schema::table('appointments', function (Blueprint $table) {
            if (Schema::hasIndex('appointments', 'appointments_room_starts_index')) {
                $table->dropIndex('appointments_room_starts_index');
            }
        });

        Schema::dropIfExists('service_rooms');
    }

    private function hasConstraint(string $name): bool
    {
        return DB::table('pg_constraint')->where('conname', $name)->exists();
    }
};
