<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('account_tokens')) {
            return;
        }

        DB::statement('ALTER TABLE account_tokens DROP CONSTRAINT IF EXISTS account_tokens_kind_check');
        DB::statement("ALTER TABLE account_tokens ADD CONSTRAINT account_tokens_kind_check CHECK (kind IN ('reset', 'invite', 'login_code', 'trusted_device'))");
    }

    public function down(): void
    {
        if (!Schema::hasTable('account_tokens')) {
            return;
        }

        DB::table('account_tokens')->where('kind', 'invite')->delete();
        DB::statement('ALTER TABLE account_tokens DROP CONSTRAINT IF EXISTS account_tokens_kind_check');
        DB::statement("ALTER TABLE account_tokens ADD CONSTRAINT account_tokens_kind_check CHECK (kind IN ('reset', 'login_code', 'trusted_device'))");
    }
};
