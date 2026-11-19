<?php

use App\Casts\Sealed;
use App\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('care_record_reads')) {
            Schema::create('care_record_reads', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('care_record_id')->constrained('care_records')->cascadeOnDelete();
                $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 20);
                $table->boolean('by_emergency')->default(false);
                $table->string('ip_address', 45)->nullable();
                $table->timestampTz('created_at')->useCurrent();
                $table->index(['care_record_id', 'created_at']);
                $table->index(['tenant_id', 'user_id']);
            });
        }

        Rls::protect('care_record_reads');

        if (Schema::hasTable('care_records') && $this->columnType('care_records', 'data') === 'jsonb') {
            DB::statement('alter table care_records alter column data drop default');
            DB::statement('alter table care_records alter column data drop not null');
            DB::statement('alter table care_records alter column data type text using data::text');
        }

        if (Schema::hasTable('care_records')) {
            $this->sealColumn('care_records', 'data');
        }

        if (Schema::hasTable('care_record_addenda')) {
            $this->sealColumn('care_record_addenda', 'note');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('care_record_addenda')) {
            $this->openColumn('care_record_addenda', 'note');
        }

        if (Schema::hasTable('care_records') && $this->columnType('care_records', 'data') === 'text') {
            $this->openColumn('care_records', 'data');
            DB::statement("update care_records set data = '{}' where data is null or data = ''");
            DB::statement('alter table care_records alter column data type jsonb using data::jsonb');
            DB::statement("alter table care_records alter column data set default '{}'::jsonb");
            DB::statement('alter table care_records alter column data set not null');
        }

        Schema::dropIfExists('care_record_reads');
    }

    private function columnType(string $table, string $column): ?string
    {
        return DB::selectOne('select data_type from information_schema.columns where table_schema = current_schema() and table_name = ? and column_name = ?', [$table, $column])?->data_type;
    }

    private function sealColumn(string $table, string $column): void
    {
        DB::table($table)->whereNotNull($column)->orderBy('id')->select(['id', $column])->chunk(500, function ($rows) use ($table, $column) {
            foreach ($rows as $row) {
                if (!Sealed::isSealed($row->{$column})) {
                    DB::table($table)->where('id', $row->id)->update([$column => Sealed::seal($row->{$column})]);
                }
            }
        });
    }

    private function openColumn(string $table, string $column): void
    {
        DB::table($table)->whereNotNull($column)->orderBy('id')->select(['id', $column])->chunk(500, function ($rows) use ($table, $column) {
            foreach ($rows as $row) {
                if (Sealed::isSealed($row->{$column})) {
                    DB::table($table)->where('id', $row->id)->update([$column => Sealed::open($row->{$column})]);
                }
            }
        });
    }
};
