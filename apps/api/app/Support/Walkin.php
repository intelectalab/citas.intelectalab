<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Professional;
use App\Models\ProfessionalSchedule;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\WalkinEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Walkin
{
    public const NOT_READY = 'Falta actualizar la base de datos: ejecuta php artisan migrate.';

    public const STEP = 5;

    private static ?bool $ready = null;

    private static array $slots = [];

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('walkin_entries');
    }

    public static function rules(Tenant $tenant): array
    {
        return [
            'self_checkin' => (bool) AppointmentFlow::rule($tenant, 'walkin.self_checkin'),
            'max_wait'     => max(10, min(300, (int) AppointmentFlow::rule($tenant, 'walkin.max_wait'))),
        ];
    }

    public static function now(Tenant $tenant, Branch $branch): Carbon
    {
        return Carbon::now(AppointmentScheduler::timezone($branch, $tenant));
    }

    public static function lock(Branch $branch): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['walkin:' . $branch->id]);
    }

    public static function nextTicket(Tenant $tenant, Branch $branch): string
    {
        $now = self::now($tenant, $branch);

        $max = WalkinEntry::where('tenant_id', $tenant->id)
            ->where('branch_id', $branch->id)
            ->where('arrived_at', '>=', $now->copy()->startOfDay()->toIso8601String())
            ->where('arrived_at', '<', $now->copy()->addDay()->startOfDay()->toIso8601String())
            ->pluck('ticket')
            ->map(fn ($ticket) => preg_match('/(\d+)$/', (string) $ticket, $m) ? (int) $m[1] : 0)
            ->max() ?? 0;

        return Codes::prefix($tenant->id, 'walkin') . '-' . str_pad((string) ($max + 1), Codes::TYPES['walkin']['digits'], '0', STR_PAD_LEFT);
    }

    public static function waitingToday(Tenant $tenant, Branch $branch, ?string $except = null): Collection
    {
        $now = self::now($tenant, $branch);

        return WalkinEntry::with(['service.status', 'professional'])
            ->where('tenant_id', $tenant->id)
            ->where('branch_id', $branch->id)
            ->where('status', WalkinEntry::WAITING)
            ->when($except, fn ($q, $id) => $q->where('id', '!=', $id))
            ->where('arrived_at', '>=', $now->copy()->startOfDay()->toIso8601String())
            ->where('arrived_at', '<', $now->copy()->addDay()->startOfDay()->toIso8601String())
            ->orderBy('arrived_at')
            ->orderBy('created_at')
            ->get();
    }

    public static function workingToday(Tenant $tenant, Branch $branch): Collection
    {
        $now = self::now($tenant, $branch);

        return Professional::where('tenant_id', $tenant->id)
            ->whereIn('id', ProfessionalSchedule::where('branch_id', $branch->id)->where('weekday', $now->dayOfWeek)->select('professional_id'))
            ->with('status')
            ->orderBy('display_name')
            ->get()
            ->reject(fn (Professional $p) => $p->status?->slug === 'inactive')
            ->values();
    }

    public static function canDo(Service $service, Professional $professional): bool
    {
        $assigned = DB::table('service_professionals')->where('service_id', $service->id)->pluck('professional_id')->all();

        return $assigned === [] || in_array($professional->id, $assigned, true);
    }

    private static function freeStarts(Tenant $tenant, Branch $branch, Service $service, Professional $professional, Carbon $now): array
    {
        $key = implode(':', [$branch->id, $service->id, $professional->id, $now->format('Y-m-d H:i')]);

        return self::$slots[$key] ??= collect(
            (new AppointmentScheduler($tenant))->slots($service, $branch, $now, $professional, null, self::STEP)[0]['slots'] ?? []
        )->map(fn (string $time) => $now->copy()->startOfDay()->setTimeFromTimeString($time))->all();
    }

    public static function estimates(Tenant $tenant, Branch $branch, ?Collection $waiting = null, ?WalkinEntry $extra = null): array
    {
        if (!self::ready()) {
            return [];
        }

        $queue = ($waiting ?? self::waitingToday($tenant, $branch))->values();
        if ($extra) {
            $queue->push($extra);
        }

        if ($queue->isEmpty()) {
            return [];
        }

        $now = self::now($tenant, $branch);
        $professionals = self::workingToday($tenant, $branch);
        $taken = [];
        $results = [];

        foreach ($queue as $index => $entry) {
            $service = $entry->service;
            $best = null;

            if ($service) {
                $duration = max(self::STEP, (int) $service->duration_minutes);
                $after = (int) $service->buffer_after_minutes;

                foreach ($professionals as $professional) {
                    if ($entry->professional_id && $professional->id !== $entry->professional_id) {
                        continue;
                    }

                    foreach (self::freeStarts($tenant, $branch, $service, $professional, $now) as $start) {
                        $end = $start->copy()->addMinutes($duration + $after);
                        $clash = collect($taken[$professional->id] ?? [])->contains(fn (array $range) => $range[0]->lt($end) && $start->lt($range[1]));

                        if (!$clash) {
                            if (!$best || $start->lt($best['start'])) {
                                $best = ['start' => $start, 'end' => $end, 'professional' => $professional];
                            }
                            break;
                        }
                    }
                }
            }

            if ($best) {
                $taken[$best['professional']->id][] = [$best['start'], $best['end']];
            }

            $results[$entry->id] = [
                'position'               => $index + 1,
                'wait_minutes'           => $best ? max(0, (int) ceil($now->diffInSeconds($best['start'], false) / 60)) : null,
                'estimated_at'           => $best ? $best['start']->format('H:i') : null,
                'suggested_professional' => $best ? ['id' => $best['professional']->id, 'display_name' => $best['professional']->display_name] : null,
            ];
        }

        return $results;
    }

    public static function choices(Tenant $tenant, Branch $branch, Service $service, ?string $except = null): array
    {
        $service->loadMissing('status');
        $waiting = self::waitingToday($tenant, $branch, $except);

        $guess = function (?string $professionalId) use ($tenant, $branch, $service, $waiting) {
            $phantom = new WalkinEntry(['service_id' => $service->id, 'professional_id' => $professionalId]);
            $phantom->id = 'new';
            $phantom->setRelation('service', $service);

            return self::estimates($tenant, $branch, $waiting, $phantom)['new'];
        };

        $first = $guess(null);

        return [
            'first_free'    => [
                'wait_minutes'           => $first['wait_minutes'],
                'estimated_at'           => $first['estimated_at'],
                'suggested_professional' => $first['suggested_professional'],
            ],
            'professionals' => self::workingToday($tenant, $branch)
                ->filter(fn (Professional $p) => self::canDo($service, $p))
                ->map(function (Professional $p) use ($guess) {
                    $estimate = $guess($p->id);

                    return [
                        'id'           => $p->id,
                        'display_name' => $p->display_name,
                        'wait_minutes' => $estimate['wait_minutes'],
                        'estimated_at' => $estimate['estimated_at'],
                    ];
                })
                ->values()
                ->all(),
            'waiting'       => $waiting->count(),
        ];
    }

    public static function candidates(Tenant $tenant, Branch $branch, Service $service): array
    {
        $service->loadMissing('status');
        $start = self::now($tenant, $branch)->addMinute()->startOfMinute();
        $scheduler = new AppointmentScheduler($tenant);

        return self::workingToday($tenant, $branch)
            ->filter(fn (Professional $p) => self::canDo($service, $p))
            ->map(function (Professional $p) use ($scheduler, $service, $branch, $start) {
                $check = $scheduler->check($service, $p, $branch, $start, (int) $service->duration_minutes);
                $reason = $check['reasons'][0] ?? null;

                return [
                    'id'           => $p->id,
                    'display_name' => $p->display_name,
                    'available'    => $check['ok'],
                    'overridable'  => $check['overridable'],
                    'reason'       => $reason ? $reason['message'] . ($reason['detail'] !== '' ? " ({$reason['detail']})" : '') : null,
                ];
            })
            ->values()
            ->all();
    }

    public static function book(WalkinEntry $entry, Appointment $appointment): void
    {
        $entry->update([
            'status'          => WalkinEntry::SERVING,
            'professional_id' => $appointment->professional_id,
            'appointment_id'  => $appointment->id,
            'served_at'       => now(),
            'waited_minutes'  => max(0, (int) $entry->arrived_at->diffInMinutes(now())),
        ]);
    }

    public static function appointmentChanged(Tenant $tenant, Appointment $appointment): void
    {
        if (!self::ready()) {
            return;
        }

        $entry = WalkinEntry::where('tenant_id', $tenant->id)
            ->where('appointment_id', $appointment->id)
            ->where('status', WalkinEntry::SERVING)
            ->first();

        if (!$entry) {
            return;
        }

        if ($appointment->status === AppointmentFlow::COMPLETED) {
            $entry->update(['status' => WalkinEntry::DONE, 'done_at' => now()]);
        } elseif (in_array($appointment->status, AppointmentFlow::RELEASED, true)) {
            $entry->update(['status' => WalkinEntry::LEFT, 'left_at' => now()]);
        }
    }
}
