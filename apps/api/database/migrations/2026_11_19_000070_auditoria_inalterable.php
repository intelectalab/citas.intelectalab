<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('audit_events')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION audit_events_inalterable() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Los registros de auditoría no se pueden modificar ni borrar.'
        USING ERRCODE = 'insufficient_privilege';
END;
$$;

DROP TRIGGER IF EXISTS audit_events_sin_cambios ON audit_events;
CREATE TRIGGER audit_events_sin_cambios
    BEFORE UPDATE OR DELETE ON audit_events
    FOR EACH ROW EXECUTE FUNCTION audit_events_inalterable();

DROP TRIGGER IF EXISTS audit_events_sin_vaciar ON audit_events;
CREATE TRIGGER audit_events_sin_vaciar
    BEFORE TRUNCATE ON audit_events
    FOR EACH STATEMENT EXECUTE FUNCTION audit_events_inalterable();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS audit_events_sin_vaciar ON audit_events;
DROP TRIGGER IF EXISTS audit_events_sin_cambios ON audit_events;
DROP FUNCTION IF EXISTS audit_events_inalterable();
SQL);
    }
};
