<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class ProfessionalSchedule extends Model
{
    use HasUuids;

    protected $table = 'professional_schedules';
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'professional_id',
        'branch_id',
        'weekday',
        'starts_local',
        'ends_local',
        'valid_from',
        'valid_until',
    ];

    protected $casts = [
        'weekday' => 'integer',
    ];

    private static ?bool $periodsReady = null;

    public static function periodsReady(): bool
    {
        return self::$periodsReady ??= Schema::hasColumn('professional_schedules', 'valid_from');
    }

    public static function dateOf(mixed $value): ?string
    {
        return $value ? substr((string) $value, 0, 10) : null;
    }

    public static function covers(object $row, string $date): bool
    {
        $from = self::dateOf($row->valid_from ?? null);
        $until = self::dateOf($row->valid_until ?? null);

        return (!$from || $from <= $date) && (!$until || $date <= $until);
    }

    public static function periodKey(object $row): string
    {
        return (self::dateOf($row->valid_from ?? null) ?? '') . '|' . (self::dateOf($row->valid_until ?? null) ?? '');
    }

    public static function periodOn(iterable $rows, string $date): Collection
    {
        return collect($rows)
            ->groupBy(fn ($row) => $row->professional_id . ':' . $row->branch_id)
            ->flatMap(function (Collection $group) use ($date) {
                $covering = $group->filter(fn ($row) => self::periodKey($row) !== '|' && self::covers($row, $date))
                    ->sortByDesc(fn ($row) => self::periodKey($row));
                $period = $covering->isEmpty() ? '|' : self::periodKey($covering->first());

                return $group->filter(fn ($row) => self::periodKey($row) === $period)->values();
            })
            ->values();
    }

    public static function effective(iterable $rows, string $date): Collection
    {
        $weekday = (int) \Illuminate\Support\Carbon::parse($date)->dayOfWeek;

        return self::periodOn($rows, $date)->filter(fn ($row) => (int) $row->weekday === $weekday)->values();
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'professional_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
