<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function appRole(): void
{
    $database = DB::connection()->getDatabaseName();

    if (!str_ends_with((string) $database, '_test')) {
        throw new RuntimeException('El rol de prueba solo se crea en una base terminada en _test.');
    }

    DB::unprepared(<<<'SQL'
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app_test') THEN
        CREATE ROLE erp_app_test NOLOGIN NOSUPERUSER NOBYPASSRLS;
    END IF;
END $$;
GRANT USAGE ON SCHEMA public TO erp_app_test;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO erp_app_test;
GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO erp_app_test;
SET ROLE erp_app_test;
SQL);
}

function superRole(): void
{
    DB::unprepared('RESET ROLE');
}

function visible(string $table, ?string $tenantId = null): int
{
    return DB::table($table)->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->count();
}

function failsInsert(callable $insert): bool
{
    try {
        DB::transaction($insert);
    } catch (QueryException $e) {
        return str_contains($e->getMessage(), 'row-level security');
    }

    return false;
}

function newCustomer(string $tenantId, string $name): array
{
    return [
        'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'code' => 'CLI-' . Str::upper(Str::random(6)),
        'first_name' => $name, 'last_name' => 'Prueba', 'created_at' => now(), 'updated_at' => now(),
    ];
}
