<?php

use App\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('saas_addons')) {
            Schema::create('saas_addons', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->string('code', 40)->unique();
                $table->string('name', 120);
                $table->string('description', 300)->nullable();
                $table->decimal('monthly_price', 12, 2)->default(0);
                $table->string('currency', 3)->default('PEN');
                $table->string('module_key', 60)->nullable();
                $table->string('limit_key', 40)->nullable();
                $table->unsignedInteger('limit_amount')->default(0);
                $table->unsignedSmallInteger('max_quantity')->default(1);
                $table->string('status', 20)->default('inactive');
                $table->unsignedSmallInteger('presentation_order')->default(0);
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();
            });
            DB::statement("ALTER TABLE saas_addons ADD CONSTRAINT saas_addons_status_check CHECK (status IN ('active', 'inactive'))");

            $now = now();
            DB::table('saas_addons')->insert([
                ['id' => (string) Str::uuid(), 'code' => 'sede-adicional', 'name' => 'Sede adicional', 'description' => 'Una sede más de las que trae tu plan, con su propia agenda y caja.', 'monthly_price' => 39, 'module_key' => 'multi_branch', 'limit_key' => 'branches', 'limit_amount' => 1, 'max_quantity' => 10, 'presentation_order' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['id' => (string) Str::uuid(), 'code' => 'usuario-adicional', 'name' => 'Usuario adicional', 'description' => 'Una cuenta más para tu equipo (recepción, caja o gerencia).', 'monthly_price' => 15, 'module_key' => null, 'limit_key' => 'users', 'limit_amount' => 1, 'max_quantity' => 20, 'presentation_order' => 2, 'created_at' => $now, 'updated_at' => $now],
                ['id' => (string) Str::uuid(), 'code' => 'profesional-adicional', 'name' => 'Profesional adicional', 'description' => 'Una persona más que atiende y tiene su propia agenda.', 'monthly_price' => 25, 'module_key' => null, 'limit_key' => 'professionals', 'limit_amount' => 1, 'max_quantity' => 20, 'presentation_order' => 3, 'created_at' => $now, 'updated_at' => $now],
                ['id' => (string) Str::uuid(), 'code' => 'whatsapp-500', 'name' => 'Paquete de 500 mensajes de WhatsApp', 'description' => '500 mensajes de WhatsApp más cada mes, para recordatorios y campañas.', 'monthly_price' => 29, 'module_key' => null, 'limit_key' => 'whatsapp_month', 'limit_amount' => 500, 'max_quantity' => 10, 'presentation_order' => 4, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }

        if (!Schema::hasTable('tenant_addons')) {
            Schema::create('tenant_addons', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('tenant_id');
                $table->uuid('addon_id');
                $table->unsignedSmallInteger('quantity')->default(1);
                $table->decimal('monthly_price', 12, 2)->default(0);
                $table->string('status', 20)->default('active');
                $table->uuid('plan_payment_id')->nullable();
                $table->uuid('requested_by')->nullable();
                $table->timestampTz('since')->useCurrent();
                $table->timestampTz('ends_at')->nullable();
                $table->timestampTz('removed_at')->nullable();
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();
                $table->unique(['tenant_id', 'addon_id']);
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('addon_id')->references('id')->on('saas_addons')->restrictOnDelete();
            });
            DB::statement("ALTER TABLE tenant_addons ADD CONSTRAINT tenant_addons_status_check CHECK (status IN ('pending', 'active', 'removed'))");
            Rls::protect('tenant_addons');
        }

        if (Schema::hasTable('permissions') && !DB::table('permissions')->where('name', 'admin.plan')->exists()) {
            DB::table('permissions')->insert([
                'id'          => (string) Str::uuid(),
                'name'        => 'admin.plan',
                'module'      => 'admin',
                'description' => 'Ver el plan con sus montos, pagarlo, cambiarlo, contratar complementos y descargar todos los datos',
            ]);
        }

        if (Schema::hasTable('tenants') && !Schema::hasColumn('tenants', 'data_purged_at')) {
            Schema::table('tenants', fn (Blueprint $table) => $table->timestampTz('data_purged_at')->nullable());
        }

        if (!Schema::hasTable('platform_audit_logs')) {
            DB::statement('CREATE SEQUENCE IF NOT EXISTS platform_audit_logs_number_seq');
            Schema::create('platform_audit_logs', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->unsignedBigInteger('number')->unique();
                $table->string('code', 20)->unique();
                $table->uuid('tenant_id')->nullable();
                $table->uuid('actor_user_id')->nullable();
                $table->string('actor_type', 20)->nullable();
                $table->string('actor_name', 150)->nullable();
                $table->uuid('impersonator_user_id')->nullable();
                $table->string('action', 80);
                $table->string('entity_type', 60)->nullable();
                $table->uuid('entity_id')->nullable();
                $table->string('entity_label', 200)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->jsonb('before_data')->nullable();
                $table->jsonb('after_data')->nullable();
                $table->uuid('audit_event_id')->nullable()->unique();
                $table->timestampTz('created_at')->useCurrent();
                $table->index(['created_at']);
                $table->index(['tenant_id', 'created_at']);
                $table->index(['action']);
            });

            if (Schema::hasTable('audit_events')) {
                $columns = Schema::getColumnListing('audit_events');
                $has = fn (string $column) => in_array($column, $columns, true);
                $pick = fn (string $column, string $fallback = 'NULL') => $has($column) ? "a.{$column}" : $fallback;
                $where = ['(a.actor_user_id IS NOT NULL AND u.tenant_id IS NULL AND u.id IS NOT NULL)'];

                if ($has('impersonator_user_id')) {
                    $where[] = 'a.impersonator_user_id IS NOT NULL';
                }

                if ($has('actor_type')) {
                    $where[] = "a.actor_type = 'platform'";
                }

                DB::statement('INSERT INTO platform_audit_logs (id, number, code, tenant_id, actor_user_id, actor_type, actor_name, impersonator_user_id, action, entity_type, entity_id, entity_label, ip_address, user_agent, before_data, after_data, audit_event_id, created_at)
                    SELECT gen_random_uuid(), x.n, \'PLT-\' || lpad(x.n::text, 6, \'0\'), x.tenant_id, x.actor_user_id, x.actor_type, x.actor_name, x.impersonator_user_id, x.action, x.entity_type, x.entity_id, x.entity_label, x.ip_address, x.user_agent, x.before_data, x.after_data, x.id, x.created_at
                    FROM (
                        SELECT row_number() OVER (ORDER BY a.created_at, a.id) AS n, a.id, a.tenant_id, a.actor_user_id, ' . $pick('actor_type', "'platform'") . ' AS actor_type, ' . $pick('actor_name') . ' AS actor_name, '
                        . $pick('impersonator_user_id') . ' AS impersonator_user_id, a.action, a.entity_type, CASE WHEN a.entity_id::text ~ \'^[0-9a-fA-F-]{36}$\' THEN a.entity_id::text::uuid END AS entity_id, ' . $pick('entity_label') . ' AS entity_label, left(a.ip_address::text, 45) AS ip_address, left(a.user_agent, 500) AS user_agent, a.before_data::jsonb AS before_data, a.after_data::jsonb AS after_data, a.created_at
                        FROM audit_events a LEFT JOIN users u ON u.id = a.actor_user_id
                        WHERE ' . implode(' OR ', $where) . '
                    ) x');
            }

            DB::statement("SELECT setval('platform_audit_logs_number_seq', GREATEST((SELECT COALESCE(MAX(number), 0) FROM platform_audit_logs), 1), (SELECT COUNT(*) > 0 FROM platform_audit_logs))");

            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION platform_audit_logs_inalterable() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'La bitácora de la plataforma no se puede modificar ni borrar.'
        USING ERRCODE = 'insufficient_privilege';
END;
$$;

CREATE TRIGGER platform_audit_logs_sin_cambios
    BEFORE UPDATE OR DELETE ON platform_audit_logs
    FOR EACH ROW EXECUTE FUNCTION platform_audit_logs_inalterable();

CREATE TRIGGER platform_audit_logs_sin_vaciar
    BEFORE TRUNCATE ON platform_audit_logs
    FOR EACH STATEMENT EXECUTE FUNCTION platform_audit_logs_inalterable();
SQL);
            Rls::protect('platform_audit_logs');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('platform_audit_logs')) {
            DB::unprepared('DROP TRIGGER IF EXISTS platform_audit_logs_sin_vaciar ON platform_audit_logs; DROP TRIGGER IF EXISTS platform_audit_logs_sin_cambios ON platform_audit_logs;');
            Schema::drop('platform_audit_logs');
            DB::unprepared('DROP FUNCTION IF EXISTS platform_audit_logs_inalterable(); DROP SEQUENCE IF EXISTS platform_audit_logs_number_seq;');
        }

        if (Schema::hasColumn('tenants', 'data_purged_at')) {
            Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('data_purged_at'));
        }

        Schema::dropIfExists('tenant_addons');
        Schema::dropIfExists('saas_addons');
    }
};
