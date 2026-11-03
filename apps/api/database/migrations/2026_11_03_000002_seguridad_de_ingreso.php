<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('account_tokens')) {
            Schema::create('account_tokens', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->string('kind', 20);
                $table->string('account_type', 10);
                $table->uuid('account_id');
                $table->string('token_hash', 64);
                $table->smallInteger('attempts')->default(0);
                $table->timestampTz('expires_at');
                $table->timestampTz('used_at')->nullable();
                $table->timestampTz('sent_at')->nullable();
                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->timestampTz('created_at')->useCurrent();
                $table->index(['account_type', 'account_id', 'kind']);
                $table->index('token_hash');
            });
            DB::statement("ALTER TABLE account_tokens ADD CONSTRAINT account_tokens_kind_check CHECK (kind IN ('reset', 'login_code', 'trusted_device'))");
            DB::statement("ALTER TABLE account_tokens ADD CONSTRAINT account_tokens_account_type_check CHECK (account_type IN ('user', 'customer'))");
        }

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'mfa_mode')) {
                $table->string('mfa_mode', 10)->nullable();
                $table->smallInteger('failed_logins')->default(0);
                $table->timestampTz('locked_until')->nullable();
            }
        });

        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'failed_logins')) {
                $table->smallInteger('failed_logins')->default(0);
                $table->timestampTz('locked_until')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['failed_logins', 'locked_until']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_mode', 'failed_logins', 'locked_until']);
        });

        Schema::dropIfExists('account_tokens');
    }
};
