<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ClassSessions
{
    public const NOT_READY = 'Falta actualizar la base de datos para usar las clases: ejecuta php artisan migrate.';

    public const STATUS_LABELS = [
        'enrolled'  => 'Inscrito',
        'waitlist'  => 'En espera',
        'attended'  => 'Asistió',
        'no_show'   => 'No asistió',
        'cancelled' => 'Salió de la clase',
    ];

    public const SOURCE_LABELS = [
        'staff'  => 'Desde el panel',
        'portal' => 'Desde el portal',
        'online' => 'Reserva en línea',
    ];

    public const PAID_LABELS = [
        'sale'       => 'Venta',
        'package'    => 'Paquete',
        'membership' => 'Membresía',
    ];

    public const TAKEN = ['enrolled', 'attended', 'no_show'];

    public const MAX_WEEKS = 12;

    private static ?bool $ready = null;

    public static ?string $ignore = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('class_enrollments');
    }

    public static function enabled(Tenant $tenant): bool
    {
        return self::ready() && $tenant->hasModule('classes');
    }

    public static function rules(Tenant $tenant): array
    {
        return [
            'waitlist'           => (bool) AppointmentFlow::rule($tenant, 'classes.waitlist'),
            'auto_promote'       => (bool) AppointmentFlow::rule($tenant, 'classes.auto_promote'),
            'portal_leave_hours' => max(0, min(168, (int) AppointmentFlow::rule($tenant, 'classes.portal_leave_hours'))),
        ];
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public static function busy(string $tenantId, array $professionalIds, Carbon $from, Carbon $to, ?string $branchId = null): Collection
    {
        if (!self::ready()) {
            return collect();
        }

        return DB::table('class_sessions')
            ->where('tenant_id', $tenantId)
            ->where('status', 'scheduled')
            ->when($professionalIds !== [], fn ($q) => $q->whereIn('professional_id', $professionalIds))
            ->when($branchId, fn ($q, $id) => $q->where('branch_id', $id))
            ->when(self::$ignore, fn ($q, $id) => $q->where('id', '!=', $id))
            ->where('starts_at', '<', $to->toIso8601String())
            ->where('ends_at', '>', $from->toIso8601String())
            ->orderBy('starts_at')
            ->get();
    }

    public static function agendaRows(string $tenantId, string $branchId, Carbon $from, Carbon $to, ?string $professionalId = null): Collection
    {
        if (!self::ready() || ($professionalId && !Str::isUuid($professionalId))) {
            return collect();
        }

        $rows = self::busy($tenantId, $professionalId ? [$professionalId] : [], $from, $to, $branchId);
        $counts = self::counts($rows->pluck('id')->all());

        return $rows->map(fn ($row) => (object) [
            'id'              => $row->id,
            'professional_id' => $row->professional_id,
            'starts_at'       => $row->starts_at,
            'ends_at'         => $row->ends_at,
            'reason'          => "{$row->title} · " . ($counts[$row->id]['taken'] ?? 0) . " de {$row->capacity}",
        ]);
    }

    public static function counts(array $ids): array
    {
        if ($ids === [] || !self::ready()) {
            return [];
        }

        $rows = DB::table('class_enrollments')
            ->whereIn('class_session_id', $ids)
            ->groupBy('class_session_id', 'status')
            ->selectRaw('class_session_id, status, count(*) AS total')
            ->get();

        $result = [];

        foreach ($ids as $id) {
            $result[$id] = ['taken' => 0, 'waitlist' => 0, 'attended' => 0, 'no_show' => 0];
        }

        foreach ($rows as $row) {
            if (in_array($row->status, self::TAKEN, true)) {
                $result[$row->class_session_id]['taken'] += (int) $row->total;
            }
            if (isset($result[$row->class_session_id][$row->status])) {
                $result[$row->class_session_id][$row->status] += (int) $row->total;
            }
        }

        return $result;
    }

    public static function check(Tenant $tenant, Service $service, Professional $professional, Branch $branch, ?string $roomId, Carbon $start, ?string $ignoreId = null): array
    {
        $duration = (int) $service->duration_minutes;
        $end = $start->copy()->addMinutes($duration);

        self::$ignore = $ignoreId;

        try {
            $result = (new AppointmentScheduler($tenant))->check($service, $professional, $branch, $start, $duration);
        } finally {
            self::$ignore = null;
        }

        if ($roomId) {
            $other = DB::table('class_sessions')
                ->where('tenant_id', $tenant->id)
                ->where('room_id', $roomId)
                ->where('status', 'scheduled')
                ->when($ignoreId, fn ($q, $id) => $q->where('id', '!=', $id))
                ->where('starts_at', '<', $end->toIso8601String())
                ->where('ends_at', '>', $start->toIso8601String())
                ->first();

            if ($other) {
                $from = Carbon::parse($other->starts_at)->setTimezone($start->getTimezone())->format('H:i');
                $result['ok'] = false;
                $result['overridable'] = false;
                $result['reasons'][] = ['code' => 'ROOM_BUSY', 'message' => 'La sala ya tiene otra clase a esa hora', 'detail' => "{$other->title} {$from}"];
            }
        }

        return $result;
    }

    public static function lock(string $tenantId, string $id): object
    {
        $row = DB::table('class_sessions')->where('tenant_id', $tenantId)->where('id', $id)->lockForUpdate()->first();
        abort_unless($row, 404, 'No encontramos esa clase.');

        return $row;
    }

    public static function enroll(Tenant $tenant, string $sessionId, Customer $customer, string $source, ?User $user): array
    {
        return DB::transaction(function () use ($tenant, $sessionId, $customer, $source, $user) {
            $session = self::lock($tenant->id, $sessionId);
            $staff = $source === 'staff';

            if ($session->status === 'cancelled') {
                self::fail('class', 'Esta clase se canceló: ya no recibe inscripciones.');
            }

            $limit = $staff ? Carbon::parse($session->ends_at) : Carbon::parse($session->starts_at);

            if ($limit->lte(now())) {
                self::fail('class', $staff ? 'La clase ya terminó.' : 'La clase ya empezó: ya no se puede inscribir en línea.');
            }

            $existing = DB::table('class_enrollments')
                ->where('class_session_id', $session->id)
                ->where('customer_id', $customer->id)
                ->where('status', '!=', 'cancelled')
                ->first();

            if ($existing) {
                self::fail('customer_id', $existing->status === 'waitlist'
                    ? Person::name($customer) . ' ya está en la lista de espera de esta clase.'
                    : Person::name($customer) . ' ya está inscrito en esta clase.');
            }

            $taken = self::counts([$session->id])[$session->id]['taken'];
            $status = 'enrolled';

            if ($taken >= (int) $session->capacity) {
                if (!AppointmentFlow::rule($tenant, 'classes.waitlist')) {
                    self::fail('class', 'La clase está completa.');
                }
                $status = 'waitlist';
            }

            $id = (string) Str::uuid();

            DB::table('class_enrollments')->insert([
                'id'               => $id,
                'tenant_id'        => $tenant->id,
                'class_session_id' => $session->id,
                'customer_id'      => $customer->id,
                'status'           => $status,
                'source'           => $source,
                'created_by'       => $user?->id,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            if (!$staff) {
                self::notifyProfessional($tenant, $session, $customer, $status);
            }

            return ['session' => $session, 'enrollment' => DB::table('class_enrollments')->where('id', $id)->first()];
        });
    }

    private static function notifyProfessional(Tenant $tenant, object $session, Customer $customer, string $status): void
    {
        try {
            $userId = DB::table('professionals')->where('id', $session->professional_id)->value('user_id');

            if (!$userId) {
                return;
            }

            $local = self::local($tenant, $session, 'starts_at');
            $what = $status === 'waitlist' ? 'se anotó en la lista de espera de' : 'se inscribió en';

            Notifier::send($tenant->id, $userId, 'class_enrolled', Person::name($customer) . " {$what} {$session->title}", 'Clase del ' . Messaging::longDate($local) . ' a las ' . $local->format('H:i') . '.', "/classes/{$session->id}", 'class_session', $session->id);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function local(Tenant $tenant, object $session, string $column): Carbon
    {
        $zone = DB::table('branches')->where('id', $session->branch_id)->value('timezone') ?: $tenant->zone();

        return Carbon::parse($session->{$column})->setTimezone($zone);
    }

    public static function leave(Tenant $tenant, string $enrollmentId, ?User $user): array
    {
        return DB::transaction(function () use ($tenant, $enrollmentId, $user) {
            $enrollment = DB::table('class_enrollments')->where('tenant_id', $tenant->id)->where('id', $enrollmentId)->lockForUpdate()->first();
            abort_unless($enrollment, 404, 'No encontramos esa inscripción.');
            $session = self::lock($tenant->id, $enrollment->class_session_id);

            if (!in_array($enrollment->status, ['enrolled', 'waitlist'], true)) {
                self::fail('enrollment', 'Solo sale de la clase quien está inscrito o en espera.');
            }

            if ($enrollment->status === 'enrolled' && $enrollment->paid_with === 'package') {
                self::returnPackage($enrollment);
            }

            DB::table('class_enrollments')->where('id', $enrollment->id)->update([
                'status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now(),
            ] + ($enrollment->paid_with === 'package' ? ['paid_with' => null, 'customer_package_id' => null, 'package_usage_id' => null] : []));

            $promoted = null;

            if ($enrollment->status === 'enrolled' && Carbon::parse($session->starts_at)->isFuture() && AppointmentFlow::rule($tenant, 'classes.auto_promote')) {
                $promoted = self::promoteNext($tenant, $session);
            }

            return ['session' => $session, 'enrollment' => $enrollment, 'promoted' => $promoted];
        });
    }

    public static function promoteNext(Tenant $tenant, object $session, ?string $enrollmentId = null): ?object
    {
        $taken = self::counts([$session->id])[$session->id]['taken'];

        if ($taken >= (int) $session->capacity) {
            return null;
        }

        $next = DB::table('class_enrollments')
            ->where('class_session_id', $session->id)
            ->where('status', 'waitlist')
            ->when($enrollmentId, fn ($q, $id) => $q->where('id', $id))
            ->orderBy('created_at')
            ->lockForUpdate()
            ->first();

        if (!$next) {
            return null;
        }

        DB::table('class_enrollments')->where('id', $next->id)->update(['status' => 'enrolled', 'promoted_at' => now(), 'updated_at' => now()]);

        $customer = Customer::where('tenant_id', $tenant->id)->find($next->customer_id);
        Messaging::queue($tenant, 'class_spot', $customer, self::vars($tenant, $session), [
            'branch_id' => $session->branch_id, 'entity_type' => 'class_session', 'entity_id' => $session->id, 'dedupe_key' => "class-spot:{$next->id}",
        ]);

        return DB::table('class_enrollments')->where('id', $next->id)->first();
    }

    public static function vars(Tenant $tenant, object $session): array
    {
        $local = self::local($tenant, $session, 'starts_at');

        return [
            'Servicio' => $session->title,
            'Fecha'    => Messaging::longDate($local),
            'Hora'     => Packages::hour($local->format('H:i')),
            'Sede'     => (string) DB::table('branches')->where('id', $session->branch_id)->value('name'),
        ];
    }

    public static function mark(Tenant $tenant, string $enrollmentId, string $status): object
    {
        return DB::transaction(function () use ($tenant, $enrollmentId, $status) {
            $enrollment = DB::table('class_enrollments')->where('tenant_id', $tenant->id)->where('id', $enrollmentId)->lockForUpdate()->first();
            abort_unless($enrollment, 404, 'No encontramos esa inscripción.');
            $session = self::lock($tenant->id, $enrollment->class_session_id);

            if ($session->status === 'cancelled') {
                self::fail('enrollment', 'La clase se canceló.');
            }

            if (!in_array($enrollment->status, self::TAKEN, true)) {
                self::fail('enrollment', 'Solo se marca la asistencia de quien está inscrito.');
            }

            if ($status !== 'enrolled' && Carbon::parse($session->starts_at)->isFuture()) {
                self::fail('enrollment', 'La asistencia se marca cuando la clase ya empezó.');
            }

            DB::table('class_enrollments')->where('id', $enrollment->id)->update([
                'status'      => $status,
                'attended_at' => $status === 'attended' ? now() : null,
                'updated_at'  => now(),
            ]);

            return DB::table('class_enrollments')->where('id', $enrollment->id)->first();
        });
    }

    public static function cancel(Tenant $tenant, string $sessionId, string $reason, ?User $user): array
    {
        return DB::transaction(function () use ($tenant, $sessionId, $reason, $user) {
            $session = self::lock($tenant->id, $sessionId);

            if ($session->status === 'cancelled') {
                self::fail('reason', 'Esta clase ya estaba cancelada.');
            }

            if (Carbon::parse($session->ends_at)->isPast()) {
                self::fail('reason', 'La clase ya terminó: no se cancela.');
            }

            $people = DB::table('class_enrollments')->where('class_session_id', $session->id)->whereIn('status', ['enrolled', 'waitlist'])->get();

            DB::table('class_sessions')->where('id', $session->id)->update([
                'status' => 'cancelled', 'cancel_reason' => $reason, 'cancelled_at' => now(), 'cancelled_by' => $user?->id, 'updated_at' => now(),
            ]);

            $paid = [];

            foreach ($people as $enrollment) {
                if ($enrollment->status === 'enrolled' && $enrollment->paid_with === 'package') {
                    self::returnPackage($enrollment);
                }

                DB::table('class_enrollments')->where('id', $enrollment->id)->update([
                    'status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now(),
                ] + ($enrollment->paid_with === 'package' ? ['paid_with' => null, 'customer_package_id' => null, 'package_usage_id' => null] : []));

                if ($enrollment->sale_id && in_array($enrollment->paid_with, ['sale', 'membership'], true)) {
                    $code = DB::table('sales')->where('id', $enrollment->sale_id)->whereNotIn('status', ['void', 'refunded'])->value('code');
                    if ($code) {
                        $paid[] = $code;
                    }
                }
            }

            $vars = self::vars($tenant, $session) + ['Detalle' => trim($reason) !== '' ? "Motivo: {$reason}." : ''];
            $customers = Customer::where('tenant_id', $tenant->id)->whereIn('id', $people->pluck('customer_id'))->get();

            foreach ($customers as $customer) {
                Messaging::queue($tenant, 'class_cancelled', $customer, $vars, [
                    'branch_id' => $session->branch_id, 'entity_type' => 'class_session', 'entity_id' => $session->id,
                    'dedupe_key' => "class-cancel:{$session->id}:{$customer->id}", 'user' => $user,
                ]);
            }

            return ['session' => $session, 'notified' => $customers->count(), 'paid_sales' => $paid];
        });
    }

    private static function returnPackage(object $enrollment): void
    {
        if (!$enrollment->package_usage_id || !$enrollment->customer_package_id) {
            return;
        }

        $usage = DB::table('package_usages')->where('id', $enrollment->package_usage_id)->first();

        if (!$usage) {
            return;
        }

        DB::table('package_usages')->where('id', $usage->id)->delete();
        DB::table('customer_package_items')
            ->where('customer_package_id', $enrollment->customer_package_id)
            ->where('service_id', $usage->service_id)
            ->where('sessions_used', '>', 0)
            ->decrement('sessions_used');
        DB::table('customer_packages')->where('id', $enrollment->customer_package_id)->where('status', 'consumed')->update(['status' => 'active', 'updated_at' => now()]);

        if (Schema::hasTable('commission_entries') && Schema::hasColumn('commission_entries', 'package_usage_id')) {
            DB::table('commission_entries')->where('package_usage_id', $usage->id)->where('status', 'calculated')->delete();
        }
    }

    public static function usePackage(Tenant $tenant, string $enrollmentId, ?string $packageId, User $user): object
    {
        return DB::transaction(function () use ($tenant, $enrollmentId, $packageId, $user) {
            $enrollment = DB::table('class_enrollments')->where('tenant_id', $tenant->id)->where('id', $enrollmentId)->lockForUpdate()->first();
            abort_unless($enrollment, 404, 'No encontramos esa inscripción.');
            $session = self::lock($tenant->id, $enrollment->class_session_id);
            self::assertChargeable($enrollment);

            $usable = collect(Packages::usable($tenant->id, $enrollment->customer_id, $session->service_id, Carbon::parse($session->starts_at)));
            $chosen = $packageId ? $usable->firstWhere('id', $packageId) : $usable->first();

            if (!$chosen) {
                self::fail('package_id', 'No tiene un paquete vigente con sesiones de esta clase.');
            }

            $package = Packages::lock($tenant->id, $chosen['id']);
            $local = self::local($tenant, $session, 'starts_at');

            if (!Packages::consume($tenant, $package, $session->service_id, null, 'attended', "Clase {$session->title} del " . $local->format('d/m H:i'), $user)) {
                self::fail('package_id', 'Ese paquete ya no tiene sesiones de esta clase.');
            }

            $usageId = DB::table('package_usages')->where('customer_package_id', $package->id)->where('service_id', $session->service_id)->orderByDesc('used_at')->value('id');

            if ($usageId) {
                DB::table('package_usages')->where('id', $usageId)->update(['professional_id' => $session->professional_id]);
                Commissions::forUsage($tenant, DB::table('package_usages')->where('id', $usageId)->first());
            }

            DB::table('class_enrollments')->where('id', $enrollment->id)->update([
                'paid_with' => 'package', 'customer_package_id' => $package->id, 'package_usage_id' => $usageId, 'updated_at' => now(),
            ]);

            return DB::table('class_enrollments')->where('id', $enrollment->id)->first();
        });
    }

    private static function assertChargeable(object $enrollment): void
    {
        if (!in_array($enrollment->status, self::TAKEN, true)) {
            self::fail('enrollment', 'Solo se cobra a quien está inscrito en la clase.');
        }

        if ($enrollment->paid_with === 'package') {
            self::fail('enrollment', 'Esta inscripción ya se pagó con un paquete.');
        }

        if ($enrollment->sale_id) {
            $sale = DB::table('sales')->where('id', $enrollment->sale_id)->first(['code', 'status']);

            if ($sale && !in_array($sale->status, ['void', 'refunded'], true)) {
                self::fail('enrollment', $sale->status === 'pending'
                    ? "Ya está en la venta {$sale->code}, que quedó por cobrar. Ábrela en el punto de venta."
                    : "Ya se cobró en la venta {$sale->code}.");
            }
        }
    }

    public static function charge(Tenant $tenant, string $enrollmentId, User $user): object
    {
        $enrollment = DB::table('class_enrollments')->where('tenant_id', $tenant->id)->where('id', $enrollmentId)->first();
        abort_unless($enrollment, 404, 'No encontramos esa inscripción.');
        $session = DB::table('class_sessions')->where('id', $enrollment->class_session_id)->first();
        self::assertChargeable($enrollment);

        $sale = Cart::save($tenant, $user, [
            'branch_id'   => $session->branch_id,
            'customer_id' => $enrollment->customer_id,
            'items'       => [['type' => 'service', 'id' => $session->service_id, 'quantity' => 1, 'professional_id' => $session->professional_id]],
        ]);

        DB::table('class_enrollments')->where('id', $enrollment->id)->update([
            'sale_id'    => $sale->id,
            'paid_with'  => $sale->status === 'paid' && (float) $sale->total <= 0 ? 'membership' : 'sale',
            'updated_at' => now(),
        ]);

        return $sale;
    }

    public static function presentEnrollments(Tenant $tenant, Collection $rows, object $session): array
    {
        $customers = Customer::where('tenant_id', $tenant->id)->whereIn('id', $rows->pluck('customer_id'))->get()->keyBy('id');
        $sales = DB::table('sales')->whereIn('id', $rows->pluck('sale_id')->filter())->get(['id', 'code', 'status', 'total', 'balance'])->keyBy('id');
        $packages = DB::table('customer_packages')->whereIn('id', $rows->pluck('customer_package_id')->filter())->get(['id', 'code', 'name'])->keyBy('id');
        $waitPosition = 0;
        $start = Carbon::parse($session->starts_at);
        $memberships = Memberships::ready() && $tenant->hasModule('memberships');
        $withPackages = Packages::enabled($tenant);

        return $rows->map(function ($e) use ($customers, $sales, $packages, &$waitPosition, $session, $tenant, $start, $memberships, $withPackages) {
            $customer = $customers[$e->customer_id] ?? null;
            $sale = $e->sale_id ? ($sales[$e->sale_id] ?? null) : null;
            $saleAlive = $sale && !in_array($sale->status, ['void', 'refunded'], true);
            $paid = $e->paid_with === 'package' || ($saleAlive && $sale->status === 'paid');
            $open = in_array($e->status, self::TAKEN, true) && !$paid && !$saleAlive;
            $package = $e->customer_package_id ? ($packages[$e->customer_package_id] ?? null) : null;

            $hasPackage = $open && $withPackages && $customer && Packages::usable($tenant->id, $customer->id, $session->service_id, $start) !== [];
            $membership = $open && $memberships && $customer ? Memberships::current($tenant, $customer->id) : null;

            return [
                'id'           => $e->id,
                'status'       => $e->status,
                'status_label' => self::STATUS_LABELS[$e->status] ?? $e->status,
                'source'       => $e->source,
                'source_label' => self::SOURCE_LABELS[$e->source] ?? $e->source,
                'position'     => $e->status === 'waitlist' ? ++$waitPosition : null,
                'created_at'   => Carbon::parse($e->created_at)->toIso8601String(),
                'promoted'     => (bool) $e->promoted_at,
                'customer'     => $customer ? ['id' => $customer->id, 'name' => Person::name($customer), 'phone' => $customer->phone, 'email' => $customer->email] : null,
                'paid'         => $paid,
                'paid_with'    => $paid ? $e->paid_with : null,
                'paid_label'   => $paid ? (self::PAID_LABELS[$e->paid_with] ?? null) : null,
                'sale'         => $saleAlive ? ['id' => $sale->id, 'code' => $sale->code, 'status' => $sale->status, 'total' => round((float) $sale->total, 2), 'balance' => round((float) $sale->balance, 2)] : null,
                'package'      => $package ? ['id' => $package->id, 'code' => $package->code ?? null, 'name' => $package->name ?? null] : null,
                'has_package'  => $hasPackage,
                'membership'   => $membership ? ['id' => $membership->id, 'name' => $membership->name] : null,
            ];
        })->values()->all();
    }

    public static function present(Tenant $tenant, object $row, array $counts, array $lookups = []): array
    {
        $zone = $lookups['zones'][$row->branch_id] ?? $tenant->zone();
        $start = Carbon::parse($row->starts_at)->setTimezone($zone);
        $end = Carbon::parse($row->ends_at)->setTimezone($zone);
        $c = $counts[$row->id] ?? ['taken' => 0, 'waitlist' => 0, 'attended' => 0, 'no_show' => 0];
        $state = match (true) {
            $row->status === 'cancelled' => 'cancelled',
            $end->isPast()               => 'done',
            $start->isPast()             => 'running',
            $c['taken'] >= $row->capacity => 'full',
            default                       => 'open',
        };

        return [
            'id'             => $row->id,
            'title'          => $row->title,
            'status'         => $row->status,
            'state'          => $state,
            'state_label'    => ['cancelled' => 'Cancelada', 'done' => 'Realizada', 'running' => 'En curso', 'full' => 'Completa', 'open' => 'Con cupos'][$state],
            'starts_at'      => $start->format('Y-m-d\TH:i'),
            'ends_at'        => $end->format('Y-m-d\TH:i'),
            'date'           => $start->toDateString(),
            'time'           => $start->format('H:i'),
            'end_time'       => $end->format('H:i'),
            'capacity'       => (int) $row->capacity,
            'taken'          => $c['taken'],
            'free'           => max(0, (int) $row->capacity - $c['taken']),
            'waitlist'       => $c['waitlist'],
            'attended'       => $c['attended'],
            'no_show'        => $c['no_show'],
            'online_booking' => (bool) $row->online_booking,
            'notes'          => $row->notes,
            'series_id'      => $row->series_id,
            'cancel_reason'  => $row->cancel_reason,
            'cancelled_at'   => $row->cancelled_at ? Carbon::parse($row->cancelled_at)->toIso8601String() : null,
            'service'        => isset($lookups['services'][$row->service_id]) ? $lookups['services'][$row->service_id] : ['id' => $row->service_id, 'name' => $row->title],
            'professional'   => $lookups['professionals'][$row->professional_id] ?? null,
            'branch'         => $lookups['branches'][$row->branch_id] ?? null,
            'room'           => $row->room_id ? ($lookups['rooms'][$row->room_id] ?? null) : null,
        ];
    }

    public static function lookups(string $tenantId, Collection $rows): array
    {
        $branches = DB::table('branches')->where('tenant_id', $tenantId)->whereIn('id', $rows->pluck('branch_id')->unique())->get(['id', 'name', 'timezone']);

        return [
            'zones'         => $branches->mapWithKeys(fn ($b) => [$b->id => $b->timezone ?: 'America/Lima'])->all(),
            'branches'      => $branches->mapWithKeys(fn ($b) => [$b->id => ['id' => $b->id, 'name' => $b->name]])->all(),
            'services'      => DB::table('services')->whereIn('id', $rows->pluck('service_id')->unique())->get(['id', 'name', 'price', 'duration_minutes'])
                ->mapWithKeys(fn ($s) => [$s->id => ['id' => $s->id, 'name' => $s->name, 'price' => round((float) $s->price, 2), 'duration_minutes' => (int) $s->duration_minutes]])->all(),
            'professionals' => DB::table('professionals')->whereIn('id', $rows->pluck('professional_id')->unique())->get(['id', 'display_name', 'color_code'])
                ->mapWithKeys(fn ($p) => [$p->id => ['id' => $p->id, 'display_name' => $p->display_name, 'color_code' => $p->color_code]])->all(),
            'rooms'         => DB::table('rooms')->whereIn('id', $rows->pluck('room_id')->filter()->unique())->get(['id', 'name'])
                ->mapWithKeys(fn ($r) => [$r->id => ['id' => $r->id, 'name' => $r->name]])->all(),
        ];
    }

    public static function presentMany(Tenant $tenant, Collection $rows): array
    {
        $counts = self::counts($rows->pluck('id')->all());
        $lookups = self::lookups($tenant->id, $rows);

        return $rows->map(fn ($row) => self::present($tenant, $row, $counts, $lookups))->values()->all();
    }
}
