<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $exists = DB::selectOne("SELECT 1 AS ok FROM pg_constraint WHERE conname = 'appointments_no_overlap'");

        if ($exists) {
            return;
        }

        $crossed = DB::select("
            SELECT a.code AS first, b.code AS second, p.display_name AS professional
            FROM appointments a
            JOIN appointments b ON b.professional_id = a.professional_id AND a.id < b.id
                AND tstzrange(a.starts_at, a.ends_at, '[)') && tstzrange(b.starts_at, b.ends_at, '[)')
            LEFT JOIN professionals p ON p.id = a.professional_id
            WHERE a.status NOT IN ('cancelled', 'no_show') AND b.status NOT IN ('cancelled', 'no_show')
            ORDER BY a.starts_at
            LIMIT 20
        ");

        if ($crossed) {
            $list = implode('; ', array_map(fn ($row) => "{$row->first} y {$row->second} ({$row->professional})", $crossed));

            throw new RuntimeException("Hay citas del mismo profesional que se cruzan en el horario: {$list}. Cancela o mueve una de cada par y vuelve a ejecutar php artisan migrate.");
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement("
            ALTER TABLE appointments ADD CONSTRAINT appointments_no_overlap
            EXCLUDE USING gist (professional_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&)
            WHERE (status NOT IN ('cancelled', 'no_show'))
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_no_overlap');
        }
    }
};
