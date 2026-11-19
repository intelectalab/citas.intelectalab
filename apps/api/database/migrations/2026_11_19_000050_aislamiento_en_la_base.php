<?php

use App\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION app_tenant_id() RETURNS uuid LANGUAGE sql STABLE AS
$$ SELECT NULLIF(current_setting('app.tenant_id', true), '')::uuid $$;

CREATE OR REPLACE FUNCTION app_rls_bypass() RETURNS boolean LANGUAGE sql STABLE AS
$$ SELECT coalesce(current_setting('app.bypass', true), '') = 'on' $$;

CREATE OR REPLACE FUNCTION app_rls_protect(target text, shared boolean DEFAULT false) RETURNS void LANGUAGE plpgsql AS
$$
BEGIN
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', target);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', target);
    EXECUTE format('DROP POLICY IF EXISTS tenant_isolation ON %I', target);

    IF shared THEN
        EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (app_rls_bypass() OR tenant_id IS NULL OR tenant_id = app_tenant_id()) WITH CHECK (app_rls_bypass() OR tenant_id = app_tenant_id())', target);
    ELSE
        EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (app_rls_bypass() OR tenant_id = app_tenant_id()) WITH CHECK (app_rls_bypass() OR tenant_id = app_tenant_id())', target);
    END IF;
END
$$;

CREATE OR REPLACE FUNCTION app_rls_protect_all(shared text[] DEFAULT '{}') RETURNS integer LANGUAGE plpgsql AS
$$
DECLARE
    item record;
    total integer := 0;
BEGIN
    FOR item IN
        SELECT c.table_name
        FROM information_schema.columns c
        JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name
        WHERE c.table_schema = current_schema() AND c.column_name = 'tenant_id' AND t.table_type = 'BASE TABLE'
    LOOP
        PERFORM app_rls_protect(item.table_name, item.table_name = ANY(shared));
        total := total + 1;
    END LOOP;

    RETURN total;
END
$$;
SQL);

        Rls::protectAll();
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DO $$
DECLARE
    item record;
BEGIN
    FOR item IN
        SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = current_schema() AND c.relkind = 'r' AND c.relrowsecurity
    LOOP
        EXECUTE format('DROP POLICY IF EXISTS tenant_isolation ON %I', item.relname);
        EXECUTE format('ALTER TABLE %I NO FORCE ROW LEVEL SECURITY', item.relname);
        EXECUTE format('ALTER TABLE %I DISABLE ROW LEVEL SECURITY', item.relname);
    END LOOP;
END
$$;
DROP FUNCTION IF EXISTS app_rls_protect_all(text[]);
DROP FUNCTION IF EXISTS app_rls_protect(text, boolean);
SQL);
    }
};
