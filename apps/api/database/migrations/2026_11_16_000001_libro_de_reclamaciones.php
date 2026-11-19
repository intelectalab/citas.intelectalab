<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('complaints')) {
            Schema::create('complaints', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable();
                $table->string('code', 30);
                $table->integer('year');
                $table->integer('number');
                $table->string('kind', 10);
                $table->jsonb('consumer');
                $table->jsonb('guardian')->nullable();
                $table->string('good_type', 10);
                $table->string('good_description', 500);
                $table->decimal('amount', 14, 2)->nullable();
                $table->string('currency', 3)->default('PEN');
                $table->text('detail');
                $table->text('request');
                $table->jsonb('company');
                $table->date('due_on');
                $table->string('status', 10)->default('open');
                $table->text('response')->nullable();
                $table->uuid('responded_by')->nullable();
                $table->timestampTz('responded_at')->nullable();
                $table->timestampsTz();
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->index(['tenant_id', 'status', 'due_on']);
            });
            DB::statement("CREATE UNIQUE INDEX complaints_number_unique ON complaints (COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000'::uuid), year, number)");
            DB::statement("ALTER TABLE complaints ADD CONSTRAINT complaints_kind_check CHECK (kind IN ('reclamo', 'queja'))");
            DB::statement("ALTER TABLE complaints ADD CONSTRAINT complaints_good_check CHECK (good_type IN ('producto', 'servicio'))");
            DB::statement("ALTER TABLE complaints ADD CONSTRAINT complaints_status_check CHECK (status IN ('open', 'answered'))");
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION complaints_keep_original() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.tenant_id IS DISTINCT FROM OLD.tenant_id OR NEW.code IS DISTINCT FROM OLD.code OR NEW.kind IS DISTINCT FROM OLD.kind
        OR NEW.consumer IS DISTINCT FROM OLD.consumer OR NEW.guardian IS DISTINCT FROM OLD.guardian
        OR NEW.good_type IS DISTINCT FROM OLD.good_type OR NEW.good_description IS DISTINCT FROM OLD.good_description
        OR NEW.amount IS DISTINCT FROM OLD.amount OR NEW.detail IS DISTINCT FROM OLD.detail OR NEW.request IS DISTINCT FROM OLD.request
        OR NEW.company IS DISTINCT FROM OLD.company OR NEW.due_on IS DISTINCT FROM OLD.due_on OR NEW.created_at IS DISTINCT FROM OLD.created_at
        OR (OLD.status = 'answered' AND NEW.response IS DISTINCT FROM OLD.response) THEN
        RAISE EXCEPTION 'Un reclamo enviado no se puede cambiar.';
    END IF;
    RETURN NEW;
END;
$$;
DROP TRIGGER IF EXISTS complaints_keep_original ON complaints;
CREATE TRIGGER complaints_keep_original BEFORE UPDATE ON complaints FOR EACH ROW EXECUTE FUNCTION complaints_keep_original();
SQL);
        }

        DB::statement('ALTER TABLE user_notifications ALTER COLUMN tenant_id DROP NOT NULL');

        if (Access::ready()) {
            $ids = Access::permissionIds();
            $grants = ['owner' => ['view', 'answer'], 'admin' => ['view', 'answer'], 'manager' => ['view']];

            foreach ($grants as $slug => $actions) {
                foreach (DB::table('roles')->where('slug', $slug)->pluck('id') as $roleId) {
                    foreach ($actions as $action) {
                        if (isset($ids["complaints.{$action}"])) {
                            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $ids["complaints.{$action}"]]);
                        }
                    }
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('user_notifications')->whereNull('tenant_id')->delete();
        DB::statement('ALTER TABLE user_notifications ALTER COLUMN tenant_id SET NOT NULL');
        Schema::dropIfExists('complaints');
        DB::unprepared('DROP FUNCTION IF EXISTS complaints_keep_original()');
        DB::table('permissions')->whereIn('name', ['complaints.view', 'complaints.answer'])->delete();
    }
};
