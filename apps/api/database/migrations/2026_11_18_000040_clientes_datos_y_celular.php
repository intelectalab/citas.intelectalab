<?php

use App\Support\Phones;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('customers')) {
            return;
        }

        if (!Schema::hasColumn('customers', 'alert')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->uuid('home_branch_id')->nullable();
                $table->uuid('preferred_professional_id')->nullable();
                $table->string('alert', 160)->nullable();
                $table->foreign('home_branch_id')->references('id')->on('branches')->nullOnDelete();
                $table->foreign('preferred_professional_id')->references('id')->on('professionals')->nullOnDelete();
            });
        }

        $prefixes = [];

        DB::table('customers')
            ->where(fn ($q) => $q->whereNotNull('phone')->orWhereNotNull('emergency_contact_phone'))
            ->orderBy('id')
            ->select(['id', 'tenant_id', 'phone', 'emergency_contact_phone'])
            ->chunk(500, function ($rows) use (&$prefixes) {
                foreach ($rows as $row) {
                    $prefix = $prefixes[$row->tenant_id] ??= Phones::prefixForTenantId($row->tenant_id);
                    $changes = [];

                    foreach (['phone', 'emergency_contact_phone'] as $column) {
                        $normalized = Phones::normalize($row->{$column}, $prefix);

                        if ($row->{$column} !== null && $normalized !== null && $normalized !== $row->{$column}) {
                            $changes[$column] = $normalized;
                        }
                    }

                    if ($changes) {
                        DB::table('customers')->where('id', $row->id)->update($changes);
                    }
                }
            });

        DB::statement("CREATE INDEX IF NOT EXISTS customers_phone_digits ON customers (tenant_id, (regexp_replace(coalesce(phone, ''), '[^0-9]', '', 'g')))");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS customers_phone_digits');

        if (Schema::hasColumn('customers', 'alert')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropForeign(['home_branch_id']);
                $table->dropForeign(['preferred_professional_id']);
                $table->dropColumn(['home_branch_id', 'preferred_professional_id', 'alert']);
            });
        }
    }
};
