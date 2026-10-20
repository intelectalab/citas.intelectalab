<?php

namespace App\Support;

use App\Models\Appointment;
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
use Illuminate\Validation\ValidationException;

class Packages
{
    public const NOT_READY = 'Falta actualizar la base de datos para usar los paquetes: carga init.sql, seed.sql y seed_demo.sql.';

    public const STATUS_LABELS = [
        'active'    => 'Vigente',
        'consumed'  => 'Agotado',
        'expired'   => 'Vencido',
        'cancelled' => 'Anulado',
    ];

    public const MODES = [
        'free'      => 'Libre: el cliente reserva cada sesión cuando quiere',
        'scheduled' => 'Con fechas acordadas al venderlo',
    ];

    public const FREQUENCIES = [
        'weekly'       => 'Cada semana',
        'twice_weekly' => '2 veces por semana',
        'biweekly'     => 'Cada 15 días',
        'monthly'      => 'Cada mes',
        'custom'       => 'Personalizado',
    ];

    public const PROFESSIONAL_MODES = [
        'chosen' => 'Se elige al profesional',
        'any'    => 'El que esté libre',
    ];

    public const LATE_POLICIES = [
        'lose'       => 'Avisa tarde o no llega: la sesión se pierde',
        'reschedule' => 'Siempre se reprograma',
    ];

    public const REFUND_POLICIES = [
        'none'           => 'No se devuelve',
        'unused_normal'  => 'Se devuelve lo no usado, cobrando lo usado a precio normal',
        'unused_package' => 'Se devuelve lo no usado, a precio del paquete',
    ];

    public const USAGE_KINDS = [
        'attended'    => 'Atendida',
        'no_show'     => 'No asistió: la sesión se perdió',
        'late_cancel' => 'Canceló tarde: la sesión se perdió',
        'manual'      => 'Registrada a mano',
    ];

    public const WEEKDAYS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    public const MAX_SLOTS = 60;

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('customer_package_events') && Schema::hasColumn('appointments', 'customer_package_id');
    }

    public static function enabled(Tenant $tenant): bool
    {
        return $tenant->hasModule('packages') && self::ready();
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public static function hour(?string $time): string
    {
        if (!$time) {
            return '';
        }

        [$h, $m] = array_map('intval', explode(':', $time));

        return sprintf('%d:%02d %s', $h % 12 ?: 12, $m, $h < 12 ? 'a. m.' : 'p. m.');
    }

    public static function expiresFrom(Tenant $tenant, Carbon $from, int $days): Carbon
    {
        return $from->copy()->setTimezone($tenant->zone())->addDays($days)->endOfDay()->utc();
    }

    public static function sessions(int $count): string
    {
        return $count === 1 ? '1 sesión' : "{$count} sesiones";
    }

    public static function conditionsOf(object $plan): array
    {
        $days = is_string($plan->custom_days ?? null) ? json_decode($plan->custom_days, true) : ($plan->custom_days ?? []);

        return [
            'validity_days'     => (int) $plan->validity_days,
            'booking_mode'      => $plan->booking_mode,
            'frequency'         => $plan->frequency,
            'custom_days'       => array_values($days ?: []),
            'repeat_weeks'      => (int) ($plan->repeat_weeks ?: 1),
            'professional_mode' => $plan->professional_mode,
            'late_policy'       => $plan->late_policy,
            'refund_policy'     => $plan->refund_policy,
            'terms'             => $plan->terms ?: null,
        ];
    }

    public static function conditions(object $package): array
    {
        $saved = is_string($package->conditions) ? json_decode($package->conditions, true) : (array) $package->conditions;

        return $saved + [
            'validity_days' => 90, 'booking_mode' => 'free', 'frequency' => 'weekly', 'custom_days' => [], 'repeat_weeks' => 1,
            'professional_mode' => 'chosen', 'late_policy' => 'lose', 'refund_policy' => 'unused_normal', 'terms' => null,
        ];
    }

    public static function scheduleLabel(array $c): string
    {
        if ($c['booking_mode'] !== 'scheduled') {
            return 'Libre';
        }

        if ($c['frequency'] !== 'custom') {
            return self::FREQUENCIES[$c['frequency']] ?? $c['frequency'];
        }

        $days = collect($c['custom_days'])->sortBy('weekday')->map(fn ($d) => self::WEEKDAYS[(int) $d['weekday']] . (!empty($d['time']) ? ' ' . self::hour($d['time']) : ''));
        $last = $days->pop();
        $text = $days->isEmpty() ? (string) $last : $days->implode(', ') . ' y ' . $last;

        return ucfirst($text) . ((int) $c['repeat_weeks'] === 2 ? ', cada 2 semanas' : ', cada semana');
    }

    public static function terms(Tenant $tenant, array $c, array $items, string $currency): string
    {
        if (!empty($c['terms'])) {
            return $c['terms'];
        }

        $parts = array_map(fn (array $i) => self::sessions((int) $i['sessions']) . ' de ' . $i['name'], $items);
        $last = array_pop($parts);
        $what = $parts ? implode(', ', $parts) . ' y ' . $last : (string) $last;
        $hours = (int) AppointmentFlow::rule($tenant, 'cancel.window_hours');

        $text = ucfirst($what) . ', válidas ' . $c['validity_days'] . ' días desde la compra.';

        if ($c['booking_mode'] === 'scheduled') {
            $text .= ' Las fechas se acuerdan al comprarlo (' . mb_strtolower(self::scheduleLabel($c)) . ').';
        }

        $text .= $c['late_policy'] === 'lose'
            ? " Si no puedes venir, avisa con {$hours} h de anticipación para reprogramar; si no, la sesión se pierde."
            : ' Si no puedes venir, la sesión se reprograma.';

        $text .= match ($c['refund_policy']) {
            'none'           => ' No se devuelve el dinero.',
            'unused_package' => ' Puedes pedir la devolución de lo no usado mientras esté vigente, al precio del paquete.',
            default          => ' Puedes pedir la devolución de lo no usado mientras esté vigente: las sesiones usadas se cobran a su precio normal ('
                . implode(', ', array_map(fn (array $i) => $i['name'] . ' a ' . Currency::format((float) $i['price'], $currency), $items)) . ').',
        };

        return $text;
    }

    public static function planItems(array $planIds): Collection
    {
        return DB::table('package_plan_items as i')
            ->join('services as s', 's.id', '=', 'i.service_id')
            ->whereIn('i.package_plan_id', $planIds)
            ->orderBy('i.position')
            ->get(['i.package_plan_id', 'i.service_id', 'i.sessions', 's.name', 's.price', 's.duration_minutes'])
            ->groupBy('package_plan_id');
    }

    public static function presentPlan(Tenant $tenant, object $plan, Collection $items, int $sold = 0): array
    {
        $c = self::conditionsOf($plan);
        $rows = $items->map(fn ($i) => [
            'service_id' => $i->service_id,
            'name'       => $i->name,
            'sessions'   => (int) $i->sessions,
            'price'      => round((float) $i->price, 2),
            'duration_minutes' => (int) $i->duration_minutes,
        ])->values()->all();
        $normal = round(array_sum(array_map(fn ($i) => $i['sessions'] * $i['price'], $rows)), 2);
        $price = round((float) $plan->price, 2);
        $currency = $plan->currency ?? Currency::of($tenant);

        return [
            'id'                => $plan->id,
            'name'              => $plan->name,
            'description'       => $plan->description,
            'price'             => $price,
            'currency'          => $currency,
            'status'            => $plan->status,
            'items'             => $rows,
            'sessions'          => array_sum(array_column($rows, 'sessions')),
            'normal_total'      => $normal,
            'savings'           => $normal > $price ? round($normal - $price, 2) : 0.0,
            'savings_percent'   => $normal > $price && $normal > 0 ? (int) round((1 - $price / $normal) * 100) : 0,
            'schedule_label'    => self::scheduleLabel($c),
            'terms_text'        => self::terms($tenant, $c, $rows, $currency),
            'sold'              => $sold,
            'created_at'        => Carbon::parse($plan->created_at)->toIso8601String(),
        ] + $c;
    }

    public static function expireDue(string $tenantId): void
    {
        if (!self::ready()) {
            return;
        }

        DB::table('customer_packages')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('expires_at', '<', now()->toIso8601String())
            ->update(['status' => 'expired', 'updated_at' => now()]);
    }

    public static function scheduledCounts(array $packageIds): array
    {
        if (!$packageIds) {
            return [];
        }

        return DB::table('appointments')
            ->whereIn('customer_package_id', $packageIds)
            ->whereNotIn('status', array_merge(AppointmentFlow::RELEASED, [AppointmentFlow::COMPLETED]))
            ->whereNotIn('id', DB::table('package_usages')->whereNotNull('appointment_id')->select('appointment_id'))
            ->groupBy('customer_package_id', 'service_id')
            ->selectRaw('customer_package_id, service_id, count(*) as total')
            ->get()
            ->mapWithKeys(fn ($r) => ["{$r->customer_package_id}:{$r->service_id}" => (int) $r->total])
            ->all();
    }

    public static function items(array $packageIds): Collection
    {
        return DB::table('customer_package_items as i')
            ->join('services as s', 's.id', '=', 'i.service_id')
            ->whereIn('i.customer_package_id', $packageIds)
            ->orderBy('i.position')
            ->get(['i.*', 's.name', 's.duration_minutes'])
            ->groupBy('customer_package_id');
    }

    public static function present(object $package, Collection $items, array $scheduled, ?object $customer = null): array
    {
        $rows = $items->map(function ($i) use ($package, $scheduled) {
            $planned = $scheduled["{$package->id}:{$i->service_id}"] ?? 0;
            $left = max(0, (int) $i->sessions_total - (int) $i->sessions_used);

            return [
                'service_id' => $i->service_id,
                'name'       => $i->name,
                'total'      => (int) $i->sessions_total,
                'used'       => (int) $i->sessions_used,
                'scheduled'  => min($planned, $left),
                'available'  => max(0, $left - $planned),
                'unit_price' => round((float) $i->unit_price, 2),
                'duration_minutes' => (int) $i->duration_minutes,
            ];
        })->values()->all();

        $total = array_sum(array_column($rows, 'total'));
        $used = array_sum(array_column($rows, 'used'));
        $expires = Carbon::parse($package->expires_at);
        $c = self::conditions($package);
        $price = round((float) $package->price, 2);

        return [
            'id'                 => $package->id,
            'code'               => $package->code,
            'name'               => $package->name,
            'status'             => $package->status,
            'status_label'       => self::STATUS_LABELS[$package->status] ?? $package->status,
            'customer'           => $customer ? ['id' => $customer->id, 'name' => \App\Support\Person::name($customer)] : null,
            'price'              => $price,
            'currency'           => $package->currency ?? Currency::DEFAULT,
            'purchased_at'       => Carbon::parse($package->purchased_at)->toIso8601String(),
            'expires_at'         => $expires->toIso8601String(),
            'days_left'          => (int) floor(now()->diffInDays($expires, false)),
            'items'              => $rows,
            'sessions_total'     => $total,
            'sessions_used'      => $used,
            'sessions_scheduled' => array_sum(array_column($rows, 'scheduled')),
            'sessions_available' => array_sum(array_column($rows, 'available')),
            'pending_value'      => $package->status === 'active' && $total > 0 ? round($price * ($total - $used) / $total, 2) : 0.0,
            'refunded_amount'    => round((float) $package->refunded_amount, 2),
            'sale_id'            => $package->sale_id,
            'professional_id'    => $package->professional_id,
            'schedule_label'     => self::scheduleLabel($c),
            'conditions'         => $c,
        ];
    }

    public static function presentMany(Collection $packages, bool $withCustomer = true): array
    {
        $ids = $packages->pluck('id')->all();
        $items = self::items($ids);
        $scheduled = self::scheduledCounts($ids);
        $customers = $withCustomer ? DB::table('customers')->whereIn('id', $packages->pluck('customer_id')->unique())->get(['id', 'first_name', 'last_name'])->keyBy('id') : collect();

        return $packages->map(fn ($p) => self::present($p, $items[$p->id] ?? collect(), $scheduled, $customers[$p->customer_id] ?? null))->values()->all();
    }

    public static function usable(string $tenantId, string $customerId, string $serviceId, ?Carbon $at = null): array
    {
        if (!self::ready()) {
            return [];
        }

        self::expireDue($tenantId);

        $packages = DB::table('customer_packages')
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->where('status', 'active')
            ->when($at, fn ($q) => $q->where('expires_at', '>=', $at->toIso8601String()))
            ->whereIn('id', DB::table('customer_package_items')->where('service_id', $serviceId)->whereColumn('sessions_used', '<', 'sessions_total')->select('customer_package_id'))
            ->orderBy('expires_at')
            ->get();

        return collect(self::presentMany($packages, false))
            ->filter(fn (array $p) => collect($p['items'])->contains(fn ($i) => $i['service_id'] === $serviceId && $i['available'] > 0))
            ->values()
            ->all();
    }

    public static function summary(?object $package): ?array
    {
        if (!$package) {
            return null;
        }

        return [
            'id'     => $package->id,
            'code'   => $package->code,
            'name'   => $package->name,
            'status' => $package->status,
        ];
    }

    private static function event(object $package, string $kind, ?string $detail, ?User $user, ?int $days = null, ?float $amount = null): void
    {
        DB::table('customer_package_events')->insert([
            'id'                  => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id'           => $package->tenant_id,
            'customer_package_id' => $package->id,
            'kind'                => $kind,
            'detail'              => $detail ? mb_substr($detail, 0, 500) : null,
            'days'                => $days,
            'amount'              => $amount,
            'created_by'          => $user?->id,
            'created_at'          => now(),
        ]);
    }

    public static function lock(string $tenantId, string $id): object
    {
        $package = DB::table('customer_packages')->where('tenant_id', $tenantId)->where('id', $id)->lockForUpdate()->first();
        abort_unless($package, 404);

        return $package;
    }

    public static function link(Tenant $tenant, Appointment $appointment, string $packageId, ?User $user): object
    {
        $package = self::lock($tenant->id, $packageId);
        $code = $appointment->code;

        if ($appointment->customer_package_id) {
            self::fail('customer_package_id', $appointment->customer_package_id === $package->id ? "La cita {$code} ya usa este paquete." : "La cita {$code} ya usa otro paquete.");
        }

        if ($package->customer_id !== $appointment->customer_id) {
            self::fail('customer_package_id', 'Ese paquete es de otra persona.');
        }

        if (in_array($appointment->status, AppointmentFlow::RELEASED, true)) {
            self::fail('customer_package_id', "La cita {$code} está " . mb_strtolower(AppointmentFlow::name($tenant, $appointment->status)) . ': no usa sesiones.');
        }

        if ($package->status !== 'active') {
            self::fail('customer_package_id', "El paquete {$package->code} está " . mb_strtolower(self::STATUS_LABELS[$package->status] ?? $package->status) . '.');
        }

        if ($appointment->starts_at->gt(Carbon::parse($package->expires_at))) {
            self::fail('customer_package_id', "El paquete {$package->code} vence el " . Carbon::parse($package->expires_at)->setTimezone($tenant->zone())->format('d/m/Y') . ' y la cita es después.');
        }

        $item = collect(self::present($package, self::items([$package->id])[$package->id] ?? collect(), self::scheduledCounts([$package->id]))['items'])->firstWhere('service_id', $appointment->service_id);

        if (!$item) {
            self::fail('customer_package_id', "El paquete {$package->code} no incluye {$appointment->service?->name}.");
        }

        if ($item['available'] <= 0) {
            self::fail('customer_package_id', "Al paquete {$package->code} no le quedan sesiones libres de {$item['name']}.");
        }

        if (Sales::ready() && ($sale = Sales::ofAppointment($tenant->id, $appointment->id))) {
            if ((float) $sale->paid > 0 || $sale->status !== 'pending') {
                self::fail('customer_package_id', "La cita {$code} ya se cobró en la venta {$sale->code}.");
            }

            $lines = DB::table('sale_items')->where('sale_id', $sale->id)->count();

            if ($lines > 1) {
                self::fail('customer_package_id', "La cita {$code} está en la venta {$sale->code}, que quedó por cobrar con otras cosas. Quítala de esa venta desde \"Por cobrar\" y vuelve a intentarlo.");
            }

            Refunds::void($sale, "Se pagó con el paquete {$package->code}.");
        }

        $appointment->update(['customer_package_id' => $package->id]);

        if ($appointment->status === AppointmentFlow::COMPLETED) {
            self::consume($tenant, $package, $appointment->service_id, $appointment, 'attended', null, $user);
        }

        return $package;
    }

    public static function unlink(Tenant $tenant, Appointment $appointment, ?User $user, ?string $why = null): void
    {
        if (!$appointment->customer_package_id) {
            return;
        }

        if (DB::table('package_usages')->where('appointment_id', $appointment->id)->exists()) {
            self::fail('customer_package_id', "La sesión de la cita {$appointment->code} ya se descontó del paquete.");
        }

        $package = DB::table('customer_packages')->where('id', $appointment->customer_package_id)->first();
        $appointment->update(['customer_package_id' => null]);

        if ($package) {
            self::event($package, 'unscheduled', $why ?? "La cita {$appointment->code} ya no usa el paquete: la sesión vuelve a estar libre.", $user);
        }
    }

    public static function consume(Tenant $tenant, object $package, string $serviceId, ?Appointment $appointment, string $kind, ?string $note, ?User $user): bool
    {
        $item = DB::table('customer_package_items')->where('customer_package_id', $package->id)->where('service_id', $serviceId)->lockForUpdate()->first();

        if (!$item || (int) $item->sessions_used >= (int) $item->sessions_total) {
            return false;
        }

        if ($appointment && DB::table('package_usages')->where('appointment_id', $appointment->id)->exists()) {
            return false;
        }

        DB::table('package_usages')->insert([
            'id'                  => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id'           => $tenant->id,
            'customer_package_id' => $package->id,
            'service_id'          => $serviceId,
            'appointment_id'      => $appointment?->id,
            'professional_id'     => $appointment?->professional_id,
            'sessions'            => 1,
            'kind'                => $kind,
            'note'                => $note ? mb_substr($note, 0, 255) : null,
            'created_by'          => $user?->id,
            'used_at'             => now(),
        ]);

        DB::table('customer_package_items')->where('id', $item->id)->increment('sessions_used');
        Messaging::packageLow($tenant, $package);

        $left = DB::table('customer_package_items')->where('customer_package_id', $package->id)->whereColumn('sessions_used', '<', 'sessions_total')->exists();

        if (!$left) {
            DB::table('customer_packages')->where('id', $package->id)->update(['status' => 'consumed', 'updated_at' => now()]);
        }

        return true;
    }

    public static function appointmentChanged(Tenant $tenant, Appointment $appointment, ?string $from, string $to, User|Customer|null $actor): void
    {
        try {
            if (!self::ready() || !$appointment->customer_package_id || $from === $to) {
                return;
            }

            $package = DB::table('customer_packages')->where('id', $appointment->customer_package_id)->first();

            if (!$package) {
                return;
            }

            $user = $actor instanceof User ? $actor : null;
            $c = self::conditions($package);

            if ($to === AppointmentFlow::COMPLETED) {
                self::consume($tenant, $package, $appointment->service_id, $appointment, 'attended', null, $user);

                return;
            }

            if (!in_array($to, AppointmentFlow::RELEASED, true)) {
                return;
            }

            $hours = (float) AppointmentFlow::rule($tenant, 'cancel.window_hours');
            $late = $to === AppointmentFlow::NO_SHOW || now()->gt($appointment->starts_at->copy()->subMinutes((int) round($hours * 60)));

            if ($late && $c['late_policy'] === 'lose') {
                self::consume($tenant, $package, $appointment->service_id, $appointment, $to === AppointmentFlow::NO_SHOW ? 'no_show' : 'late_cancel', null, $user);

                return;
            }

            $why = $to === AppointmentFlow::NO_SHOW
                ? "No asistió a la cita {$appointment->code}: la sesión vuelve a estar libre para reprogramarla."
                : "Se canceló la cita {$appointment->code}: la sesión vuelve a estar libre para reprogramarla.";

            DB::table('appointments')->where('id', $appointment->id)->update(['customer_package_id' => null]);
            $appointment->customer_package_id = null;
            self::event($package, 'unscheduled', $why, $user);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function manualUse(Tenant $tenant, string $packageId, string $serviceId, string $note, User $user): void
    {
        $package = self::lock($tenant->id, $packageId);

        if ($package->status !== 'active') {
            self::fail('service_id', "El paquete {$package->code} está " . mb_strtolower(self::STATUS_LABELS[$package->status] ?? $package->status) . '.');
        }

        $item = collect(self::present($package, self::items([$package->id])[$package->id] ?? collect(), self::scheduledCounts([$package->id]))['items'])->firstWhere('service_id', $serviceId);

        if (!$item) {
            self::fail('service_id', 'Ese servicio no está en el paquete.');
        }

        if ($item['available'] <= 0) {
            self::fail('service_id', $item['scheduled'] > 0
                ? "Las sesiones que quedan de {$item['name']} ya tienen cita: se descuentan solas al atenderlas."
                : "No quedan sesiones de {$item['name']}.");
        }

        self::consume($tenant, $package, $serviceId, null, 'manual', $note, $user);
    }

    public static function extend(Tenant $tenant, string $packageId, int $days, string $reason, User $user): object
    {
        $package = self::lock($tenant->id, $packageId);

        if (!in_array($package->status, ['active', 'expired'], true)) {
            self::fail('days', "El paquete {$package->code} está " . mb_strtolower(self::STATUS_LABELS[$package->status] ?? $package->status) . ': no se extiende.');
        }

        $base = Carbon::parse($package->expires_at)->max(now());
        $until = self::expiresFrom($tenant, $base, $days);
        $timezone = $tenant->zone();

        DB::table('customer_packages')->where('id', $package->id)->update(['expires_at' => $until, 'status' => 'active', 'updated_at' => now()]);
        self::event($package, 'extended', "{$days} días más: ahora vence el " . $until->copy()->setTimezone($timezone)->format('d/m/Y') . ". Motivo: {$reason}", $user, $days);

        return DB::table('customer_packages')->where('id', $package->id)->first();
    }

    public static function refundQuote(Tenant $tenant, object $package): array
    {
        $items = self::items([$package->id])[$package->id] ?? collect();
        $c = self::conditions($package);
        $price = round((float) $package->price, 2);
        $currency = $package->currency ?? Currency::of($tenant);
        $total = (int) $items->sum('sessions_total');
        $used = (int) $items->sum('sessions_used');
        $usedValue = round((float) $items->sum(fn ($i) => (int) $i->sessions_used * (float) $i->unit_price), 2);
        $format = fn (float $v) => Currency::format($v, $currency);

        [$amount, $explain] = match ($c['refund_policy']) {
            'none' => [0.0, 'Este paquete se vendió sin devolución de dinero.'],
            'unused_package' => [
                $total > 0 ? round($price * ($total - $used) / $total, 2) : 0.0,
                "Pagó {$format($price)} por " . self::sessions($total) . ' y usó ' . $used . '. Se devuelven las ' . ($total - $used) . " que faltan, al precio del paquete.",
            ],
            default => [
                max(0.0, round($price - $usedValue, 2)),
                "Pagó {$format($price)}. " . ($used > 0
                    ? 'Usó ' . self::sessions($used) . ' a su precio normal: ' . $format($usedValue) . '. Se devuelve el resto.'
                    : 'Aún no usó ninguna sesión: se devuelve todo.'),
            ],
        };

        $sale = $package->sale_id ? DB::table('sales')->where('id', $package->sale_id)->first() : null;
        $blocked = null;
        $max = $amount;

        if ($amount > 0) {
            if (!$sale) {
                $blocked = 'Este paquete no tiene una venta registrada en el sistema: si corresponde devolver dinero, hazlo por fuera y anúlalo sin devolución.';
            } else {
                $state = Refunds::availability($tenant, $sale);
                $blocked = $state['blocked'];
                $max = min($amount, $state['available']);
            }
        }

        return [
            'policy'       => $c['refund_policy'],
            'policy_label' => self::REFUND_POLICIES[$c['refund_policy']] ?? $c['refund_policy'],
            'amount'       => round(max(0, $max), 2),
            'suggested'    => $amount,
            'explain'      => $explain,
            'currency'     => $currency,
            'blocked'      => $blocked,
            'sale'         => $sale ? ['id' => $sale->id, 'code' => $sale->code] : null,
        ];
    }

    private static function cancelAppointments(Tenant $tenant, object $package, string $reason, ?User $user): array
    {
        $codes = [];

        $appointments = Appointment::where('customer_package_id', $package->id)
            ->whereNotIn('status', array_merge(AppointmentFlow::RELEASED, [AppointmentFlow::COMPLETED]))
            ->whereNotIn('id', DB::table('package_usages')->whereNotNull('appointment_id')->select('appointment_id'))
            ->get();

        foreach ($appointments as $appointment) {
            $from = $appointment->status;
            $appointment->update([
                'customer_package_id' => null,
                'status'              => AppointmentFlow::CANCELLED,
                'cancelled_at'        => now(),
                'cancel_reason'       => mb_substr($reason, 0, 255),
                'cancelled_by'        => 'staff',
            ]);

            AppointmentEvents::record($appointment, $from, AppointmentFlow::CANCELLED, $user, $reason, AppointmentEvents::QUIET);

            $codes[] = $appointment->code;
        }

        return $codes;
    }

    public static function cancel(Tenant $tenant, string $packageId, array $data, User $user): array
    {
        $package = self::lock($tenant->id, $packageId);

        if (!in_array($package->status, ['active', 'expired'], true)) {
            self::fail('reason', "El paquete {$package->code} está " . mb_strtolower(self::STATUS_LABELS[$package->status] ?? $package->status) . ': no se anula.');
        }

        $reason = trim($data['reason']);
        $amount = !empty($data['refund']) ? round((float) ($data['amount'] ?? 0), 2) : 0.0;

        if ($amount > 0 && ($blocked = self::refundQuote($tenant, $package)['blocked'])) {
            self::fail('amount', $blocked);
        }

        $codes = self::cancelAppointments($tenant, $package, "Se anuló el paquete {$package->code}", $user);

        DB::table('customer_packages')->where('id', $package->id)->update([
            'status'          => 'cancelled',
            'cancelled_at'    => now(),
            'cancel_reason'   => mb_substr($reason, 0, 255),
            'refunded_amount' => round((float) $package->refunded_amount + $amount, 2),
            'updated_at'      => now(),
        ]);

        if ($amount > 0) {
            Refunds::refund($tenant, $package->sale_id, [
                'amount'          => $amount,
                'method'          => $data['method'],
                'reason'          => "Anulación del paquete {$package->code}: {$reason}",
                'cash_session_id' => $data['cash_session_id'] ?? null,
            ], $user);
        }

        self::event($package, 'cancelled', $reason . ($codes ? ' · Se cancelaron las citas ' . implode(', ', $codes) . '.' : ''), $user);

        if ($amount > 0) {
            self::event($package, 'refunded', 'Devolución de ' . Currency::format($amount, $package->currency) . ' por ' . (Sales::METHODS[$data['method']] ?? Sales::OTHER_METHODS[$data['method']] ?? $data['method']) . '.', $user, null, $amount);
        }

        return ['cancelled_appointments' => $codes, 'refunded' => $amount];
    }

    public static function afterSaleRefunded(Tenant $tenant, object $sale, ?User $user): void
    {
        if (!self::ready() || $sale->status !== 'refunded') {
            return;
        }

        foreach (DB::table('customer_packages')->where('sale_id', $sale->id)->whereIn('status', ['active', 'expired'])->get() as $package) {
            $codes = self::cancelAppointments($tenant, $package, "Se devolvió la venta {$sale->code} del paquete {$package->code}", $user);

            DB::table('customer_packages')->where('id', $package->id)->update([
                'status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => "Se devolvió la venta {$sale->code}.", 'updated_at' => now(),
            ]);

            self::event($package, 'cancelled', "Se devolvió la venta {$sale->code}." . ($codes ? ' Se cancelaron las citas ' . implode(', ', $codes) . '.' : ''), $user);
        }
    }

    public static function issueForSale(Tenant $tenant, object $sale, ?User $user): array
    {
        if (!self::ready() || !$sale->customer_id || DB::table('customer_packages')->where('sale_id', $sale->id)->exists()) {
            return [];
        }

        $lines = DB::table('sale_items')->where('sale_id', $sale->id)->where('item_type', 'package')->when(Schema::hasColumn('sale_items', 'position'), fn ($q) => $q->orderBy('position'))->get();
        $results = [];

        foreach ($lines as $line) {
            $plan = DB::table('package_plans')->where('tenant_id', $tenant->id)->where('id', $line->reference_id)->first();

            if (!$plan) {
                continue;
            }

            $details = $line->details ? json_decode($line->details, true) : [];
            $c = self::conditionsOf($plan);
            $planItems = self::planItems([$plan->id])[$plan->id] ?? collect();
            $id = (string) \Illuminate\Support\Str::uuid();
            $purchased = now();

            DB::table('customer_packages')->insert([
                'id'              => $id,
                'tenant_id'       => $tenant->id,
                'code'            => sprintf('PQ-%04d', Sales::nextNumber($tenant->id, 'package')),
                'package_plan_id' => $plan->id,
                'customer_id'     => $sale->customer_id,
                'branch_id'       => $sale->branch_id,
                'sale_id'         => $sale->id,
                'name'            => $plan->name,
                'price'           => round((float) $line->unit_price, 2),
                'currency'        => $sale->currency ?? Currency::of($tenant),
                'conditions'      => json_encode($c + ['terms_text' => self::terms($tenant, $c, $planItems->map(fn ($i) => ['name' => $i->name, 'sessions' => (int) $i->sessions, 'price' => (float) $i->price])->all(), $sale->currency ?? Currency::of($tenant))]),
                'professional_id' => $details['professional_id'] ?? null,
                'status'          => 'active',
                'purchased_at'    => $purchased,
                'expires_at'      => self::expiresFrom($tenant, $purchased, $c['validity_days']),
                'created_by'      => $user?->id ?? $sale->created_by,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            DB::table('customer_package_items')->insert($planItems->values()->map(fn ($i, $position) => [
                'id'                  => (string) \Illuminate\Support\Str::uuid(),
                'tenant_id'           => $tenant->id,
                'customer_package_id' => $id,
                'service_id'          => $i->service_id,
                'sessions_total'      => (int) $i->sessions,
                'sessions_used'       => 0,
                'unit_price'          => round((float) $i->price, 2),
                'position'            => $position,
            ])->all());

            $package = DB::table('customer_packages')->where('id', $id)->first();
            self::event($package, 'sold', "Vendido en la venta {$sale->code}.", $user, null, (float) $line->unit_price);

            $result = ['id' => $id, 'code' => $package->code, 'name' => $package->name, 'created' => [], 'failed' => []];

            if (!empty($details['slots'])) {
                $result = array_merge($result, self::book($tenant, $package, $details['slots'], $user, $details['branch_id'] ?? null));
            }

            $results[] = $result;
        }

        return $results;
    }

    public static function generate(array $c, Carbon $first, ?string $time, int $count): array
    {
        $count = max(0, min(self::MAX_SLOTS, $count));
        $slots = [];

        if ($c['frequency'] === 'custom') {
            $days = collect($c['custom_days'])->keyBy(fn ($d) => (int) $d['weekday']);
            $step = max(1, (int) $c['repeat_weeks']);
            $weekStart = $first->copy()->startOfWeek(Carbon::SUNDAY);
            $cursor = $first->copy();

            for ($guard = 0; count($slots) < $count && $guard < 800 && $days->isNotEmpty(); $guard++, $cursor->addDay()) {
                $week = intdiv((int) $weekStart->diffInDays($cursor->copy()->startOfWeek(Carbon::SUNDAY)), 7);

                if ($week % $step === 0 && $days->has($cursor->dayOfWeek)) {
                    $slots[] = ['date' => $cursor->format('Y-m-d'), 'time' => $days[$cursor->dayOfWeek]['time'] ?: $time];
                }
            }

            return $slots;
        }

        $cursor = $first->copy();

        for ($i = 0; $i < $count; $i++) {
            $slots[] = ['date' => $cursor->format('Y-m-d'), 'time' => $time];

            match ($c['frequency']) {
                'twice_weekly' => $cursor->addDays($i % 2 === 0 ? 3 : 4),
                'biweekly'     => $cursor->addDays(14),
                'monthly'      => $cursor->addMonthNoOverflow(),
                default        => $cursor->addDays(7),
            };
        }

        return $slots;
    }

    private static function candidates(Tenant $tenant, Service $service, Branch $branch): Collection
    {
        $assigned = DB::table('service_professionals')->where('service_id', $service->id)->pluck('professional_id')->all();

        return Professional::where('tenant_id', $tenant->id)
            ->whereIn('id', Access::professionalsAt($branch->id))
            ->when($assigned, fn ($q) => $q->whereIn('id', $assigned))
            ->with('status')
            ->orderBy('display_name')
            ->get()
            ->reject(fn (Professional $p) => $p->status?->slug === 'inactive')
            ->values();
    }

    private static function customerBusy(Tenant $tenant, string $customerId, Carbon $start, Carbon $end): ?string
    {
        if (!AppointmentFlow::rule($tenant, 'agenda.client_overlap')) {
            return null;
        }

        return Appointment::where('tenant_id', $tenant->id)
            ->where('customer_id', $customerId)
            ->whereIn('status', [AppointmentFlow::PENDING, AppointmentFlow::CONFIRMED, AppointmentFlow::CHECKED_IN, AppointmentFlow::IN_PROGRESS])
            ->where('starts_at', '<', $end->toIso8601String())
            ->where('ends_at', '>', $start->toIso8601String())
            ->value('code');
    }

    public static function check(Tenant $tenant, Branch $branch, Service $service, string $customerId, array $slots, ?string $professionalId, Carbon $expiresAt): array
    {
        $timezone = AppointmentScheduler::timezone($branch, $tenant);
        $scheduler = new AppointmentScheduler($tenant);
        $duration = (int) $service->duration_minutes;
        $candidates = self::candidates($tenant, $service, $branch);
        $chosen = $professionalId ? Professional::where('tenant_id', $tenant->id)->with('status')->find($professionalId) : null;
        $taken = [];
        $result = [];

        foreach (array_slice($slots, 0, self::MAX_SLOTS) as $index => $slot) {
            $date = $slot['date'] ?? null;
            $time = $slot['time'] ?? null;
            $row = ['index' => $index, 'date' => $date, 'time' => $time, 'ok' => false, 'professional' => null, 'reasons' => []];

            if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $row['reasons'][] = ['code' => 'NO_DATE', 'message' => 'Falta el día'];
                $result[] = $row;
                continue;
            }

            $row['weekday'] = self::WEEKDAYS[Carbon::parse($date)->dayOfWeek];

            if (!$time || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                $row['reasons'][] = ['code' => 'NO_TIME', 'message' => 'Falta la hora'];
                $result[] = $row;
                continue;
            }

            $start = Carbon::createFromFormat('Y-m-d H:i', "{$date} {$time}", $timezone);
            $end = $start->copy()->addMinutes($duration);
            $wanted = !empty($slot['professional_id']) ? $candidates->firstWhere('id', $slot['professional_id']) ?? Professional::where('tenant_id', $tenant->id)->with('status')->find($slot['professional_id']) : $chosen;
            $reasons = [];
            $picked = null;

            if ($start->gt($expiresAt)) {
                $reasons[] = ['code' => 'AFTER_EXPIRY', 'message' => 'Es después del vencimiento del paquete (' . $expiresAt->copy()->setTimezone($timezone)->format('d/m/Y') . ')'];
            }

            foreach ($taken as $other) {
                if ($other[0]->lt($end) && $start->lt($other[1])) {
                    $reasons[] = ['code' => 'SAME_LIST', 'message' => 'Se cruza con otra sesión de esta lista'];
                    break;
                }
            }

            if ($busy = self::customerBusy($tenant, $customerId, $start, $end)) {
                $reasons[] = ['code' => 'CLIENT_OVERLAP', 'message' => "El cliente ya tiene la cita {$busy} a esa hora"];
            }

            if ($wanted) {
                $check = $scheduler->check($service, $wanted, $branch, $start, $duration);
                $picked = $wanted;
                $reasons = array_merge($reasons, array_map(fn ($r) => ['code' => $r['code'], 'message' => $r['message'] . ($r['detail'] ? " ({$r['detail']})" : '')], $check['reasons']));
            } else {
                $first = null;

                foreach ($candidates as $candidate) {
                    $check = $scheduler->check($service, $candidate, $branch, $start, $duration);
                    $first ??= $check;

                    if ($check['ok']) {
                        $picked = $candidate;
                        break;
                    }
                }

                if (!$picked) {
                    $reasons[] = ['code' => 'NOBODY_FREE', 'message' => $candidates->isEmpty() ? 'Nadie de esta sede hace este servicio' : 'No hay nadie libre a esa hora'];
                }
            }

            $row['ok'] = $reasons === [] && $picked !== null;
            $row['professional'] = $picked ? ['id' => $picked->id, 'name' => $picked->display_name] : null;
            $row['reasons'] = $reasons;
            $row['starts_at'] = $start->format('Y-m-d\TH:i');

            if ($row['ok']) {
                $taken[] = [$start, $end];
            }

            $result[] = $row;
        }

        return $result;
    }

    public static function freeTimes(Tenant $tenant, Branch $branch, Service $service, Carbon $date, ?Professional $professional, ?string $customerId = null): array
    {
        $scheduler = new AppointmentScheduler($tenant);
        $duration = (int) $service->duration_minutes;
        $watchCustomer = $customerId && AppointmentFlow::rule($tenant, 'agenda.client_overlap');

        $collect = function (Carbon $day) use ($scheduler, $service, $branch, $professional, $tenant, $customerId, $watchCustomer, $duration) {
            $groups = $professional ? $scheduler->slots($service, $branch, $day, $professional) : collect($scheduler->slots($service, $branch, $day))->all();
            $busy = $watchCustomer
                ? Appointment::where('tenant_id', $tenant->id)->where('customer_id', $customerId)
                    ->whereIn('status', [AppointmentFlow::PENDING, AppointmentFlow::CONFIRMED, AppointmentFlow::CHECKED_IN, AppointmentFlow::IN_PROGRESS])
                    ->where('starts_at', '<', $day->copy()->addDay()->toIso8601String())
                    ->where('ends_at', '>', $day->toIso8601String())
                    ->get(['starts_at', 'ends_at'])
                : collect();
            $times = [];

            foreach ($groups as $group) {
                foreach ($group['slots'] as $time) {
                    $start = $day->copy()->setTimeFromTimeString($time);
                    $end = $start->copy()->addMinutes($duration);

                    if ($busy->contains(fn ($a) => $a->starts_at->lt($end) && $start->lt($a->ends_at))) {
                        continue;
                    }

                    $times[$time][] = ['id' => $group['professional']['id'], 'name' => $group['professional']['display_name']];
                }
            }

            ksort($times);

            return collect($times)->map(fn ($pros, $time) => ['time' => $time, 'label' => self::hour($time), 'professionals' => $pros])->values()->all();
        };

        $times = $collect($date);
        $next = [];

        if ($times === []) {
            $cursor = $date->copy()->addDay();

            for ($i = 0; $i < 21 && count($next) < 4; $i++, $cursor->addDay()) {
                $found = $collect($cursor);

                if ($found) {
                    $next[] = [
                        'date'    => $cursor->format('Y-m-d'),
                        'weekday' => self::WEEKDAYS[$cursor->dayOfWeek],
                        'count'   => count($found),
                        'first'   => array_slice($found, 0, 4),
                    ];
                }
            }
        }

        return ['date' => $date->format('Y-m-d'), 'weekday' => self::WEEKDAYS[$date->dayOfWeek], 'times' => $times, 'next_days' => $next];
    }

    public static function book(Tenant $tenant, object $package, array $slots, ?User $user, ?string $branchId = null): array
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['appointments:' . $tenant->id]);

        $items = self::present($package, self::items([$package->id])[$package->id] ?? collect(), self::scheduledCounts([$package->id]))['items'];
        $item = $items[0] ?? null;
        $branch = Branch::where('tenant_id', $tenant->id)->find($branchId ?? $package->branch_id);

        if (!$item || !$branch) {
            return ['created' => [], 'failed' => array_map(fn ($s) => ['date' => $s['date'] ?? null, 'time' => $s['time'] ?? null, 'message' => 'No se encontró la sede o el servicio del paquete'], $slots)];
        }

        $service = Service::where('tenant_id', $tenant->id)->with('status')->find($item['service_id']);
        $slots = array_slice(array_values($slots), 0, $item['available']);
        $checked = self::check($tenant, $branch, $service, $package->customer_id, $slots, $package->professional_id, Carbon::parse($package->expires_at));
        $timezone = AppointmentScheduler::timezone($branch, $tenant);
        $created = [];
        $failed = [];

        foreach ($checked as $row) {
            if ($row['ok'] && ($reached = AppointmentEvents::monthLimit($tenant, Carbon::createFromFormat('Y-m-d\TH:i', $row['starts_at'], $timezone)))) {
                $row['ok'] = false;
                $row['reasons'][] = ['code' => 'PLAN_LIMIT', 'message' => "Tu plan permite {$reached['limit']} citas por mes y ese mes ya las tiene"];
            }

            if (!$row['ok']) {
                $failed[] = ['date' => $row['date'], 'time' => $row['time'], 'message' => collect($row['reasons'])->pluck('message')->implode(' · ')];
                continue;
            }

            $start = Carbon::createFromFormat('Y-m-d\TH:i', $row['starts_at'], $timezone);

            $appointment = Appointment::create([
                'tenant_id'           => $tenant->id,
                'code'                => Appointment::nextCode($tenant->id),
                'branch_id'           => $branch->id,
                'customer_id'         => $package->customer_id,
                'service_id'          => $service->id,
                'professional_id'     => $row['professional']['id'],
                'starts_at'           => $start,
                'ends_at'             => $start->copy()->addMinutes((int) $service->duration_minutes),
                'status'              => AppointmentFlow::CONFIRMED,
                'source'              => 'package',
                'price'               => $service->price,
            ] + Currency::column($tenant) + [
                'confirmed_at'        => now(),
                'created_by'          => $user?->id,
                'customer_package_id' => $package->id,
            ]);

            AppointmentEvents::record($appointment, null, AppointmentFlow::CONFIRMED, $user, "Sesión del paquete {$package->code}", ['packages', 'messaging']);

            $created[] = ['id' => $appointment->id, 'code' => $appointment->code, 'starts_at' => $row['starts_at'], 'professional' => $row['professional']['name']];
        }

        if ($created) {
            self::event($package, 'scheduled', 'Se agendaron ' . self::sessions(count($created)) . ': ' . implode(', ', array_column($created, 'code')) . '.', $user);
        }

        if ($failed) {
            self::event($package, 'scheduled', 'No se pudieron agendar ' . self::sessions(count($failed)) . ' porque el horario ya no estaba libre. Agéndalas desde el paquete.', $user);
        }

        return ['created' => $created, 'failed' => $failed];
    }
}
