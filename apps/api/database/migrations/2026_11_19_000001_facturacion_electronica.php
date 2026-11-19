<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INVOICE_COLUMNS = ['pdf_url', 'xml_url', 'cdr_url', 'send_attempts', 'next_attempt_at', 'void_method', 'void_status', 'void_ticket', 'void_note_id', 'note_type'];

    public function up(): void
    {
        if (Schema::hasTable('invoices') && !Schema::hasColumn('invoices', 'send_attempts')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->string('pdf_url', 500)->nullable();
                $table->string('xml_url', 500)->nullable();
                $table->string('cdr_url', 500)->nullable();
                $table->integer('send_attempts')->default(0);
                $table->timestampTz('next_attempt_at')->nullable();
                $table->string('void_method', 20)->nullable();
                $table->string('void_status', 20)->nullable();
                $table->string('void_ticket', 190)->nullable();
                $table->uuid('void_note_id')->nullable();
                $table->string('note_type', 20)->nullable();
            });

            DB::statement("CREATE INDEX IF NOT EXISTS idx_invoices_retry ON invoices (next_attempt_at) WHERE status = 'issued' AND sunat_status = 'pending'");
        }

        if (Schema::hasTable('branches') && !Schema::hasColumn('branches', 'invoice_series')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->jsonb('invoice_series')->default('{}');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('invoices', 'send_attempts')) {
            DB::statement('DROP INDEX IF EXISTS idx_invoices_retry');
            Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(self::INVOICE_COLUMNS));
        }

        if (Schema::hasColumn('branches', 'invoice_series')) {
            Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('invoice_series'));
        }
    }
};
