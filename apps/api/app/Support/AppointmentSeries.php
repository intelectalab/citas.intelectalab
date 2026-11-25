<?php

namespace App\Support;

use App\Models\Appointment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AppointmentSeries
{
    public const MAX = 52;

    public const FREQUENCIES = ['weekly', 'days', 'monthly'];

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('appointment_series') && Schema::hasColumn('appointments', 'series_id');
    }

    public static function dates(string $frequency, int $every, string $firstDate, ?string $until, ?int $occurrences): array
    {
        $every = max(1, $every);
        $first = Carbon::createFromFormat('Y-m-d', $firstDate)->startOfDay();
        $limit = $until ? Carbon::createFromFormat('Y-m-d', $until)->startOfDay() : null;
        $count = $occurrences ? min(self::MAX, max(1, $occurrences)) : self::MAX;
        $dates = [];

        for ($i = 0; count($dates) < $count; $i++) {
            $date = match ($frequency) {
                'weekly'  => $first->copy()->addWeeks($i * $every),
                'days'    => $first->copy()->addDays($i * $every),
                'monthly' => $first->copy()->addMonthsNoOverflow($i * $every),
            };

            if ($limit && $date->gt($limit)) {
                break;
            }

            $dates[] = $date->format('Y-m-d');
        }

        return $dates;
    }

    public static function describe(string $frequency, int $every): string
    {
        return match ($frequency) {
            'weekly'  => $every === 1 ? 'Cada semana' : "Cada {$every} semanas",
            'days'    => $every === 1 ? 'Cada día' : "Cada {$every} días",
            'monthly' => $every === 1 ? 'Cada mes' : "Cada {$every} meses",
            default   => 'Repetida',
        };
    }

    public static function present(Appointment $appointment): ?array
    {
        if (!self::ready() || !$appointment->series_id) {
            return null;
        }

        $series = DB::table('appointment_series')->where('id', $appointment->series_id)->first();

        if (!$series) {
            return null;
        }

        $siblings = Appointment::where('series_id', $series->id);

        return [
            'id'        => $series->id,
            'label'     => self::describe($series->frequency, (int) $series->every),
            'number'    => $appointment->series_number,
            'total'     => (clone $siblings)->count(),
            'following' => (clone $siblings)
                ->where('id', '!=', $appointment->id)
                ->where('starts_at', '>', $appointment->starts_at)
                    ->whereIn('status', AppointmentFlow::RESCHEDULABLE)
                ->count(),
            'skipped'   => json_decode((string) $series->skipped, true) ?: [],
        ];
    }

    public static function siblingsToCancel(Appointment $appointment, string $scope): array
    {
        if (!self::ready() || !$appointment->series_id || !in_array($scope, ['following', 'all'], true)) {
            return [];
        }

        return Appointment::where('tenant_id', $appointment->tenant_id)
            ->where('series_id', $appointment->series_id)
            ->where('id', '!=', $appointment->id)
            ->whereIn('status', AppointmentFlow::RESCHEDULABLE)
            ->when($scope === 'following', fn ($q) => $q->where('starts_at', '>', $appointment->starts_at))
            ->when($scope === 'all', fn ($q) => $q->where('starts_at', '>', now()))
            ->orderBy('starts_at')
            ->pluck('id')
            ->all();
    }
}
