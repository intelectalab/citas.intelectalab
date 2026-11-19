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
        if (!Schema::hasTable('idempotency_keys')) {
            Schema::create('idempotency_keys', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('scope', 120);
                $table->string('key', 100);
                $table->string('method', 10);
                $table->string('path', 255);
                $table->string('request_hash', 64);
                $table->smallInteger('response_status')->nullable();
                $table->string('response_type', 120)->nullable();
                $table->text('response_body')->nullable();
                $table->timestampTz('expires_at');
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();
                $table->unique(['scope', 'key']);
                $table->index('tenant_id');
                $table->index('expires_at');
            });
        }

        Rls::protect('idempotency_keys');
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
