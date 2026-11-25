<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('messages')) {
            DB::statement('ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_channel_check');
            DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_channel_check CHECK (channel IN ('email', 'whatsapp', 'call'))");
        }

        if (Schema::hasTable('customer_dependents') && !Schema::hasColumn('customer_dependents', 'microchip')) {
            Schema::table('customer_dependents', function (Blueprint $table) {
                $table->decimal('weight_kg', 6, 2)->nullable();
                $table->string('color', 60)->nullable();
                $table->boolean('sterilized')->nullable();
                $table->string('microchip', 40)->nullable();
                $table->string('relationship', 60)->nullable();
                $table->string('document_number', 30)->nullable();
            });
        }

        if (Schema::hasTable('record_templates') && !Schema::hasColumn('record_templates', 'is_confidential')) {
            Schema::table('record_templates', function (Blueprint $table) {
                $table->boolean('is_confidential')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('record_templates', 'is_confidential')) {
            Schema::table('record_templates', function (Blueprint $table) {
                $table->dropColumn('is_confidential');
            });
        }

        if (Schema::hasColumn('customer_dependents', 'microchip')) {
            Schema::table('customer_dependents', function (Blueprint $table) {
                $table->dropColumn(['weight_kg', 'color', 'sterilized', 'microchip', 'relationship', 'document_number']);
            });
        }

        if (Schema::hasTable('messages')) {
            DB::table('messages')->where('channel', 'call')->delete();
            DB::statement('ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_channel_check');
            DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_channel_check CHECK (channel IN ('email', 'whatsapp'))");
        }
    }
};
