<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Professional;
use Illuminate\Support\Facades\DB;

class ProfessionalHistory
{
    public const SETUP = ['professional_schedules', 'professional_specialties', 'service_professionals', 'availability_blocks', 'commission_rules', 'calendar_feeds', 'customers'];

    private static ?array $references = null;

    public static function references(): array
    {
        return self::$references ??= collect(DB::select(<<<'SQL'
            SELECT cl.relname AS table_name, a.attname AS column_name
            FROM pg_constraint c
            JOIN pg_class cl ON cl.oid = c.conrelid
            JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
            WHERE c.contype = 'f' AND c.confrelid = 'public.professionals'::regclass
            SQL))
            ->map(fn ($row) => [$row->table_name, $row->column_name])
            ->all();
    }

    public static function has(Professional $professional): bool
    {
        foreach (self::references() as [$table, $column]) {
            if (in_array($table, self::SETUP, true)) {
                continue;
            }

            if (DB::table($table)->where($column, $professional->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    public static function forget(Professional $professional): void
    {
        DB::transaction(function () use ($professional) {
            foreach (self::references() as [$table, $column]) {
                if (in_array($table, self::SETUP, true) && $table !== 'customers') {
                    DB::table($table)->where($column, $professional->id)->delete();
                }
            }

            $professional->delete();
        });
    }

    public static function future(Professional $professional)
    {
        return Appointment::where('tenant_id', $professional->tenant_id)
            ->where('professional_id', $professional->id)
            ->where('starts_at', '>', now())
            ->whereIn('status', [AppointmentFlow::PENDING, AppointmentFlow::CONFIRMED, AppointmentFlow::CHECKED_IN]);
    }

    public static function futureList(Professional $professional): array
    {
        return self::future($professional)
            ->with(['customer:id,first_name,last_name', 'branch:id,name,timezone', 'service:id,name'])
            ->orderBy('starts_at')
            ->limit(20)
            ->get()
            ->map(fn (Appointment $a) => [
                'id'        => $a->id,
                'code'      => $a->code,
                'starts_at' => $a->starts_at->copy()->setTimezone($a->branch?->timezone ?: 'America/Lima')->format('Y-m-d\TH:i'),
                'customer'  => $a->customer ? Person::name($a->customer) : null,
                'service'   => $a->service?->name,
                'branch'    => $a->branch?->name,
            ])
            ->all();
    }
}
