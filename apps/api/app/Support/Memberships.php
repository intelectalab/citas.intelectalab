<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Memberships
{
    public const NOT_READY = 'Falta actualizar la base de datos para usar membresías: ejecuta php artisan migrate.';

    public const PERIODS = ['monthly' => 'Mensual', 'quarterly' => 'Trimestral', 'semiannual' => 'Semestral', 'yearly' => 'Anual'];

    public const MONTHS = ['monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'yearly' => 12];

    public const PER = ['monthly' => 'al mes', 'quarterly' => 'cada 3 meses', 'semiannual' => 'cada 6 meses', 'yearly' => 'al año'];

    public const SCOPES = ['services' => 'Solo en servicios', 'all' => 'En servicios y productos'];

    public const STATUS_LABELS = ['active' => 'Vigente', 'grace' => 'En gracia', 'paused' => 'Pausada', 'expired' => 'Vencida', 'cancelled' => 'Cancelada'];

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('membership_plan_items') && Schema::hasTable('membership_usages') && Schema::hasColumn('memberships', 'benefits');
    }

    public static function enabled(Tenant $tenant): bool
    {
        return self::ready() && $tenant->hasModule('memberships');
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public static function today(Tenant $tenant): Carbon
    {
        return now($tenant->zone())->startOfDay();
    }

    public static function planItems(array $planIds): Collection
    {
        return DB::table('membership_plan_items as i')
            ->join('services as s', 's.id', '=', 'i.service_id')
            ->whereIn('i.membership_plan_id', $planIds)
            ->orderBy('i.position')
            ->get(['i.membership_plan_id', 'i.service_id', 'i.sessions', 's.name', 's.price'])
            ->groupBy('membership_plan_id');
    }

    public static function benefitText(array $b, string $currency): string
    {
        $parts = [];

        if (($b['discount_percent'] ?? 0) > 0) {
            $parts[] = rtrim(rtrim(number_format((float) $b['discount_percent'], 2, '.', ''), '0'), '.') . ' % de descuento ' . (($b['discount_scope'] ?? 'services') === 'all' ? 'en servicios y productos' : 'en servicios');
        }

        foreach ($b['items'] ?? [] as $item) {
            $parts[] = $item['sessions'] . ' × ' . $item['name'] . ' ' . (self::PER[$b['period']] ?? 'por periodo');
        }

        return $parts ? implode(' · ', $parts) : 'Sin beneficios todavía';
    }

    public static function snapshot(object $plan, Collection $items): array
    {
        return [
            'period'           => $plan->billing_period,
            'months'           => self::MONTHS[$plan->billing_period] ?? 1,
            'discount_percent' => round((float) $plan->discount_percent, 2),
            'discount_scope'   => $plan->discount_scope ?? 'services',
            'items'            => $items->map(fn ($i) => ['service_id' => $i->service_id, 'name' => $i->name, 'sessions' => (int) $i->sessions])->values()->all(),
        ];
    }

    public static function presentPlan(Tenant $tenant, object $plan, Collection $items, int $members = 0): array
    {
        $currency = $plan->currency ?? Currency::of($tenant);
        $snapshot = self::snapshot($plan, $items);

        return [
            'id'               => $plan->id,
            'name'             => $plan->name,
            'description'      => $plan->description,
            'price'            => round((float) $plan->price, 2),
            'currency'         => $currency,
            'billing_period'   => $plan->billing_period,
            'period_label'     => self::PERIODS[$plan->billing_period] ?? $plan->billing_period,
            'per_label'        => self::PER[$plan->billing_period] ?? '',
            'discount_percent' => $snapshot['discount_percent'],
            'discount_scope'   => $snapshot['discount_scope'],
            'items'            => $items->map(fn ($i) => ['service_id' => $i->service_id, 'name' => $i->name, 'sessions' => (int) $i->sessions, 'price' => round((float) $i->price, 2)])->values()->all(),
            'benefits_text'    => self::benefitText($snapshot, $currency),
            'status'           => $plan->status,
            'members'          => $members,
            'created_at'       => Carbon::parse($plan->created_at)->toIso8601String(),
        ];
    }

    public const ALIVE = ['active', 'grace'];

    public const OPEN = ['active', 'grace', 'paused'];

    public const NOTICE_DAYS = 7;

    public static function graceDays(Tenant $tenant): int
    {
        return max(0, min(60, (int) AppointmentFlow::rule($tenant, 'membership.grace_days')));
    }

    public static function graceFloor(Tenant $tenant): string
    {
        return self::today($tenant)->subDays(self::graceDays($tenant))->toDateString();
    }

    public static function graceUntil(Tenant $tenant, object $membership): string
    {
        return Carbon::parse($membership->ends_at)->addDays(self::graceDays($tenant))->toDateString();
    }

    public static function expireDue(Tenant $tenant): void
    {
        if (!self::ready()) {
            return;
        }

        $today = self::today($tenant)->toDateString();
        $floor = self::graceFloor($tenant);
        $base = fn () => DB::table('memberships')->where('tenant_id', $tenant->id);

        $base()->whereIn('status', self::ALIVE)->where('ends_at', '<', $floor)->update(['status' => 'expired', 'updated_at' => now()]);
        $base()->where('status', 'active')->where('ends_at', '<', $today)->where('ends_at', '>=', $floor)->update(['status' => 'grace', 'updated_at' => now()]);
        $base()->where('status', 'grace')->where('ends_at', '>=', $today)->update(['status' => 'active', 'updated_at' => now()]);
    }

    public static function current(Tenant $tenant, ?string $customerId): ?object
    {
        if (!$customerId || !self::enabled($tenant)) {
            return null;
        }

        $today = self::today($tenant)->toDateString();

        return DB::table('memberships')
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $customerId)
            ->whereIn('status', self::ALIVE)
            ->where('starts_at', '<=', $today)
            ->where('ends_at', '>=', self::graceFloor($tenant))
            ->orderByDesc('ends_at')
            ->first();
    }

    public static function holder(Tenant $tenant, ?string $customerId): ?object
    {
        if (!$customerId || !self::ready()) {
            return null;
        }

        return DB::table('memberships')
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $customerId)
            ->where(fn ($q) => $q->where('status', 'paused')->orWhere(fn ($q) => $q->whereIn('status', self::ALIVE)->where('ends_at', '>=', self::graceFloor($tenant))))
            ->orderByDesc('ends_at')
            ->first();
    }

    public static function inGrace(Tenant $tenant, object $membership): bool
    {
        return in_array($membership->status, self::ALIVE, true) && Carbon::parse($membership->ends_at)->toDateString() < self::today($tenant)->toDateString();
    }

    public static function event(Tenant $tenant, string $membershipId, string $kind, ?string $detail, ?User $user = null, ?string $saleId = null): void
    {
        static $ready = null;

        if (!($ready ??= Schema::hasTable('membership_events'))) {
            return;
        }

        DB::table('membership_events')->insert([
            'id'            => (string) Str::uuid(),
            'tenant_id'     => $tenant->id,
            'membership_id' => $membershipId,
            'kind'          => $kind,
            'detail'        => $detail,
            'sale_id'       => $saleId,
            'created_by'    => $user?->id,
            'created_at'    => now(),
        ]);
    }

    private static function locked(Tenant $tenant, string $id): object
    {
        $row = DB::table('memberships')->where('tenant_id', $tenant->id)->where('id', $id)->lockForUpdate()->first();
        abort_unless($row, 404);

        return $row;
    }

    private static function day(Carbon|string $date): string
    {
        return Carbon::parse($date)->format('d/m/Y');
    }

    public static function pause(Tenant $tenant, string $id, string $reason, ?User $user): object
    {
        self::expireDue($tenant);
        $row = self::locked($tenant, $id);
        $today = self::today($tenant);

        if ($row->status === 'paused') {
            self::fail('membership', 'Ya está pausada desde el ' . self::day(Carbon::parse($row->paused_at)->setTimezone($tenant->zone())) . '.');
        }

        if ($row->status !== 'active' || Carbon::parse($row->ends_at)->toDateString() < $today->toDateString()) {
            self::fail('membership', $row->status === 'grace' ? 'Ya venció y está en sus días de gracia: no se puede pausar. Renuévala en el punto de venta.' : 'Solo se pausa una membresía vigente.');
        }

        if (Carbon::parse($row->starts_at)->toDateString() > $today->toDateString()) {
            self::fail('membership', 'Todavía no empieza: no hace falta pausarla.');
        }

        DB::table('memberships')->where('id', $row->id)->update(['status' => 'paused', 'paused_at' => now(), 'updated_at' => now()]);
        self::event($tenant, $row->id, 'paused', "Pausada: {$reason}", $user);

        return DB::table('memberships')->where('id', $row->id)->first();
    }

    public static function resume(Tenant $tenant, string $id, ?User $user): array
    {
        $row = self::locked($tenant, $id);

        if ($row->status !== 'paused') {
            self::fail('membership', 'Solo se reactiva una membresía pausada.');
        }

        $today = self::today($tenant);
        $since = $row->paused_at ? Carbon::parse($row->paused_at)->setTimezone($tenant->zone())->startOfDay() : $today->copy();
        $days = max(0, (int) $since->diffInDays($today));
        $oldPeriod = self::periodStart($row, $since)->toDateString();
        $ends = Carbon::parse($row->ends_at)->addDays($days)->toDateString();

        DB::table('memberships')->where('id', $row->id)->update([
            'status'      => 'active',
            'paused_at'   => null,
            'paused_days' => (int) $row->paused_days + $days,
            'ends_at'     => $ends,
            'updated_at'  => now(),
        ]);

        $fresh = DB::table('memberships')->where('id', $row->id)->first();
        $newPeriod = self::periodStart($fresh, $today)->toDateString();

        if ($days > 0 && $newPeriod !== $oldPeriod) {
            DB::table('membership_usages')->where('membership_id', $row->id)->where('period_start', $oldPeriod)->update(['period_start' => $newPeriod]);
        }

        self::expireDue($tenant);
        $label = $days === 1 ? '1 día' : "{$days} días";
        self::event($tenant, $row->id, 'resumed', $days > 0 ? "Reactivada. Estuvo pausada {$label}: ahora vence el " . self::day($ends) . '.' : 'Reactivada el mismo día: sigue venciendo el ' . self::day($ends) . '.', $user);

        return ['row' => DB::table('memberships')->where('id', $row->id)->first(), 'days' => $days, 'ends_at' => $ends, 'before' => Carbon::parse($row->ends_at)->toDateString()];
    }

    public static function cancel(Tenant $tenant, string $id, string $reason, ?User $user): object
    {
        self::expireDue($tenant);
        $row = self::locked($tenant, $id);

        if (!in_array($row->status, self::OPEN, true)) {
            self::fail('membership', $row->status === 'cancelled' ? 'Ya está cancelada.' : 'Ya venció: no hace falta cancelarla.');
        }

        DB::table('memberships')->where('id', $row->id)->update([
            'status'        => 'cancelled',
            'cancelled_at'  => now(),
            'cancel_reason' => $reason,
            'paused_at'     => null,
            'updated_at'    => now(),
        ]);
        self::event($tenant, $row->id, 'cancelled', "Cancelada: {$reason}", $user);

        return DB::table('memberships')->where('id', $row->id)->first();
    }

    public static function anchor(object $membership, ?\DateTimeZone $zone = null): Carbon
    {
        return Carbon::parse($membership->starts_at, $zone)->startOfDay()->addDays((int) ($membership->paused_days ?? 0));
    }

    public static function benefits(object $membership): array
    {
        $b = is_string($membership->benefits ?? null) ? (json_decode($membership->benefits, true) ?: []) : (array) ($membership->benefits ?? []);

        return $b + ['period' => 'monthly', 'months' => 1, 'discount_percent' => 0, 'discount_scope' => 'services', 'items' => []];
    }

    public static function periodStart(object $membership, Carbon $today): Carbon
    {
        $months = max(1, (int) self::benefits($membership)['months']);
        $last = Carbon::parse($membership->ends_at, $today->getTimezone())->startOfDay();
        $ref = $today->lt($last) ? $today->copy() : $last;
        $start = self::anchor($membership, $today->getTimezone());

        if ($start->gt($ref)) {
            return Carbon::parse($membership->starts_at, $today->getTimezone())->startOfDay();
        }

        while ($start->copy()->addMonthsNoOverflow($months)->lte($ref)) {
            $start->addMonthsNoOverflow($months);
        }

        return $start;
    }

    public static function periodEnd(object $membership, Carbon $start): Carbon
    {
        $months = max(1, (int) self::benefits($membership)['months']);
        $end = $start->copy()->addMonthsNoOverflow($months)->subDay();
        $last = Carbon::parse($membership->ends_at, $start->getTimezone());

        return $end->gt($last) ? $last : $end;
    }

    public static function used(object $membership, Carbon $periodStart, ?string $exceptSaleId = null): array
    {
        return DB::table('membership_usages as u')
            ->join('sales as s', 's.id', '=', 'u.sale_id')
            ->where('u.membership_id', $membership->id)
            ->where('u.period_start', $periodStart->toDateString())
            ->whereNotIn('s.status', ['void', 'refunded'])
            ->when($exceptSaleId, fn ($q, $id) => $q->where('u.sale_id', '!=', $id))
            ->groupBy('u.service_id')
            ->selectRaw('u.service_id, sum(u.quantity) AS total')
            ->pluck('total', 'service_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    public static function summary(Tenant $tenant, object $membership, ?string $exceptSaleId = null): array
    {
        $today = self::today($tenant);
        $b = self::benefits($membership);
        $start = self::periodStart($membership, $today);
        $used = self::used($membership, $start, $exceptSaleId);
        $currency = $membership->currency ?? Currency::of($tenant);

        return [
            'id'               => $membership->id,
            'code'             => $membership->code,
            'name'             => $membership->name,
            'status'           => self::inGrace($tenant, $membership) ? 'grace' : $membership->status,
            'ends_at'          => Carbon::parse($membership->ends_at)->toDateString(),
            'grace_until'      => self::inGrace($tenant, $membership) ? self::graceUntil($tenant, $membership) : null,
            'days_left'        => (int) $today->diffInDays(Carbon::parse($membership->ends_at, $today->getTimezone()), false),
            'discount_percent' => (float) $b['discount_percent'],
            'discount_scope'   => $b['discount_scope'],
            'period_start'     => $start->toDateString(),
            'period_end'       => self::periodEnd($membership, $start)->toDateString(),
            'benefits_text'    => self::benefitText($b, $currency),
            'included'         => array_map(fn ($i) => $i + [
                'used' => $used[$i['service_id']] ?? 0,
                'left' => max(0, $i['sessions'] - ($used[$i['service_id']] ?? 0)),
            ], $b['items']),
        ];
    }

    public static function apply(Tenant $tenant, ?Customer $customer, array &$lines, ?string $exceptSaleId = null): ?array
    {
        $membership = self::current($tenant, $customer?->id);

        if (!$membership) {
            return null;
        }

        $summary = self::summary($tenant, $membership, $exceptSaleId);
        $left = collect($summary['included'])->pluck('left', 'service_id')->all();
        $percent = (float) $summary['discount_percent'];

        foreach ($lines as &$line) {
            $kind = $line['item_type'] ?? null;

            if (!in_array($kind, ['service', 'product'], true) || !empty($line['no_membership'])) {
                continue;
            }

            if ($kind === 'product' && $summary['discount_scope'] !== 'all') {
                continue;
            }

            $quantity = (int) $line['quantity'];
            $included = $kind === 'service' ? min($quantity, (int) ($left[$line['reference_id']] ?? 0)) : 0;
            $gross = round($line['unit_price'] * $quantity, 2);
            $discount = round($included * $line['unit_price'] + ($quantity - $included) * $line['unit_price'] * $percent / 100, 2);

            if ($discount <= 0) {
                continue;
            }

            if ($included > 0) {
                $left[$line['reference_id']] -= $included;
            }

            $line['discount'] = min($gross, $discount);
            $line['total'] = round($gross - $line['discount'], 2);
            $line['membership'] = [
                'id'       => $membership->id,
                'code'     => $membership->code,
                'included' => $included,
                'percent'  => $quantity > $included ? $percent : 0,
                'label'    => self::lineLabel($included, $quantity, $percent),
                'period_start' => $summary['period_start'],
            ];
        }
        unset($line);

        return $summary;
    }

    private static function lineLabel(int $included, int $quantity, float $percent): string
    {
        $number = rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');

        if ($included >= $quantity) {
            return 'Incluido en la membresía';
        }

        return $included > 0 ? "{$included} incluido" . ($included > 1 ? 's' : '') . " en la membresía y {$number} % en el resto" : "{$number} % por membresía";
    }

    public static function covered(array $lines): bool
    {
        return collect($lines)->contains(fn ($l) => !empty($l['membership']));
    }

    public static function line(Tenant $tenant, ?Customer $customer, array $item, string $field, string $currency): array
    {
        if (!self::enabled($tenant)) {
            self::fail($field, self::ready() ? 'Tu plan no incluye membresías.' : self::NOT_READY);
        }

        $plan = DB::table('membership_plans')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->where('id', $item['id'])->first();

        if (!$plan || $plan->status !== 'active') {
            self::fail($field, 'Una de las membresías del carrito ya no se vende. Quítala del carrito.');
        }

        if (($plan->currency ?? $currency) !== $currency) {
            self::fail($field, "El precio de la membresía {$plan->name} está en otra moneda. Edítala en Membresías → Planes.");
        }

        if ((int) ($item['quantity'] ?? 1) > 1) {
            self::fail($field, 'Una membresía se vende de a una.');
        }

        $current = self::holder($tenant, $customer?->id);
        $renews = null;
        $replaces = null;

        if ($current) {
            if ($current->status === 'paused') {
                self::fail($field, Person::name($customer) . " tiene la membresía {$current->name} pausada. Reactívala en Membresías antes de renovarla o cambiarla de plan.");
            }

            if ($current->membership_plan_id === $plan->id) {
                $renews = $current->id;
            } else {
                $replaces = $current;
            }
        }

        $items = self::planItems([$plan->id])[$plan->id] ?? collect();
        $details = $renews ? ['renews' => $renews] : ($replaces ? ['replaces' => $replaces->id] : null);

        return [
            'type'            => 'membership',
            'id'              => $plan->id,
            'item_type'       => 'membership',
            'reference_id'    => $plan->id,
            'description'     => ($renews ? 'Renovación: ' : ($replaces ? 'Cambio de plan: ' : 'Membresía ')) . $plan->name,
            'quantity'        => 1,
            'unit_price'      => round((float) $plan->price, 2),
            'professional_id' => null,
            'professional'    => null,
            'appointment_id'  => null,
            'appointment'     => null,
            'details'         => $details,
            'snapshot'        => ['name' => $plan->name] + self::snapshot($plan, $items),
            'membership_plan' => [
                'period_label'  => self::PERIODS[$plan->billing_period] ?? '',
                'benefits_text' => self::benefitText(self::snapshot($plan, $items), $currency),
                'renews'        => (bool) $renews,
                'replaces'      => $replaces ? ['code' => $replaces->code, 'name' => $replaces->name, 'ends_at' => Carbon::parse($replaces->ends_at)->toDateString()] : null,
            ],
        ];
    }

    public static function recordUsages(Tenant $tenant, string $saleId, array $lines): void
    {
        if (!self::ready()) {
            return;
        }

        DB::table('membership_usages')->where('sale_id', $saleId)->delete();

        $rows = [];

        foreach ($lines as $line) {
            if (empty($line['membership']['included'])) {
                continue;
            }

            $rows[] = [
                'id'             => (string) Str::uuid(),
                'tenant_id'      => $tenant->id,
                'membership_id'  => $line['membership']['id'],
                'sale_id'        => $saleId,
                'service_id'     => $line['reference_id'],
                'appointment_id' => $line['appointment_id'] ?? null,
                'quantity'       => $line['membership']['included'],
                'period_start'   => $line['membership']['period_start'],
                'created_at'     => now(),
            ];
        }

        if ($rows) {
            DB::table('membership_usages')->insert($rows);
        }
    }

    public static function issueForSale(Tenant $tenant, object $sale, ?User $user): array
    {
        if (!self::ready() || !$sale->customer_id) {
            return [];
        }

        $lines = DB::table('sale_items')->where('sale_id', $sale->id)->where('item_type', 'membership')->get();
        $results = [];

        foreach ($lines as $line) {
            $details = isset($line->details) && $line->details ? (json_decode($line->details, true) ?: []) : [];

            if (!empty($details['issued'])) {
                continue;
            }

            $kept = $details['plan'] ?? null;
            $plan = DB::table('membership_plans')->where('tenant_id', $tenant->id)->where('id', $line->reference_id)->first();

            if (!$plan && !$kept) {
                continue;
            }

            $benefits = $kept ? collect($kept)->except('name')->all() : self::snapshot($plan, self::planItems([$plan->id])[$plan->id] ?? collect());
            $months = max(1, (int) ($benefits['months'] ?? 1));
            $renews = !empty($details['renews']) ? DB::table('memberships')->where('tenant_id', $tenant->id)->where('id', $details['renews'])->lockForUpdate()->first() : null;

            if ($renews && in_array($renews->status, ['active', 'grace', 'expired'], true)) {
                $from = Carbon::parse($renews->ends_at)->addDay();
                $today = self::today($tenant);
                $from = $from->lt($today) && Carbon::parse($renews->ends_at)->toDateString() < self::graceFloor($tenant) ? $today : $from;
                $ends = $from->copy()->addMonthsNoOverflow($months)->subDay();

                DB::table('memberships')->where('id', $renews->id)->update([
                    'ends_at'    => $ends->toDateString(),
                    'status'     => 'active',
                    'updated_at' => now(),
                ]);

                $details['issued'] = $renews->id;
                $details['extended_from'] = Carbon::parse($renews->ends_at)->toDateString();
                $details['extended_status'] = $renews->status;
                self::event($tenant, $renews->id, 'renewed', "Renovada con la venta {$sale->code}: ahora vence el " . self::day($ends) . '.', $user, $sale->id);
                $results[] = ['id' => $renews->id, 'code' => $renews->code, 'name' => $renews->name, 'renewed' => true, 'ends_at' => $ends->toDateString()];
            } else {
                $start = self::today($tenant);
                $ends = $start->copy()->addMonthsNoOverflow($months)->subDay();
                $id = (string) Str::uuid();
                $code = Codes::next($tenant->id, 'membership');

                DB::table('memberships')->insert([
                    'id'                 => $id,
                    'tenant_id'          => $tenant->id,
                    'code'               => $code,
                    'membership_plan_id' => $line->reference_id,
                    'customer_id'        => $sale->customer_id,
                    'branch_id'          => $sale->branch_id,
                    'sale_id'            => $sale->id,
                    'name'               => $kept['name'] ?? $plan->name,
                    'price'              => round((float) $line->total, 2),
                    'currency'           => $sale->currency ?? Currency::of($tenant),
                    'benefits'           => json_encode($benefits),
                    'status'             => 'active',
                    'starts_at'          => $start->toDateString(),
                    'ends_at'            => $ends->toDateString(),
                    'auto_renew'         => false,
                    'created_by'         => $user?->id ?? $sale->created_by,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);

                $details['issued'] = $id;
                $newName = $kept['name'] ?? $plan->name;
                $old = !empty($details['replaces']) ? DB::table('memberships')->where('tenant_id', $tenant->id)->where('id', $details['replaces'])->lockForUpdate()->first() : null;

                if ($old && in_array($old->status, self::OPEN, true)) {
                    DB::table('memberships')->where('id', $old->id)->update([
                        'status'        => 'cancelled',
                        'cancelled_at'  => now(),
                        'cancel_reason' => "Cambió al plan {$newName} ({$code}).",
                        'paused_at'     => null,
                        'updated_at'    => now(),
                    ]);
                    $details['replaced'] = $old->id;
                    $details['replaced_status'] = $old->status;
                    self::event($tenant, $old->id, 'replaced', "Cambió al plan {$newName} ({$code}) con la venta {$sale->code}. Deja de valer desde hoy.", $user, $sale->id);
                }

                self::event($tenant, $id, 'sold', "Vendida con la venta {$sale->code}" . ($old ? ", en reemplazo de {$old->code} ({$old->name})" : '') . '. Vale hasta el ' . self::day($ends) . '.', $user, $sale->id);
                $results[] = ['id' => $id, 'code' => $code, 'name' => $newName, 'renewed' => false, 'replaced' => $old ? $old->code : null, 'ends_at' => $ends->toDateString()];
            }

            DB::table('sale_items')->where('id', $line->id)->update(['details' => json_encode($details)]);
        }

        return $results;
    }

    public static function afterSaleRefunded(Tenant $tenant, object $sale, ?User $user): void
    {
        if (!self::ready() || $sale->status !== 'refunded') {
            return;
        }

        foreach (DB::table('sale_items')->where('sale_id', $sale->id)->where('item_type', 'membership')->get() as $line) {
            $details = $line->details ? (json_decode($line->details, true) ?: []) : [];
            $membership = !empty($details['issued']) ? DB::table('memberships')->where('id', $details['issued'])->first() : null;

            if (!$membership || $membership->status === 'cancelled') {
                continue;
            }

            if (!empty($details['extended_from'])) {
                $back = Carbon::parse($details['extended_from']);
                $back = $back->lt(Carbon::parse($membership->starts_at)) ? Carbon::parse($membership->starts_at) : $back;
                DB::table('memberships')->where('id', $membership->id)->update([
                    'ends_at'    => $back->toDateString(),
                    'status'     => $membership->status === 'paused' ? 'paused' : 'active',
                    'updated_at' => now(),
                ]);
                self::expireDue($tenant);
                self::event($tenant, $membership->id, 'refunded', "Se devolvió la renovación (venta {$sale->code}): vuelve a vencer el " . self::day($back) . '.', $user, $sale->id);

                continue;
            }

            DB::table('memberships')->where('id', $membership->id)->update([
                'status'        => 'cancelled',
                'cancelled_at'  => now(),
                'cancel_reason' => "Se devolvió la venta {$sale->code}.",
                'paused_at'     => null,
                'updated_at'    => now(),
            ]);
            self::event($tenant, $membership->id, 'refunded', "Cancelada: se devolvió la venta {$sale->code}.", $user, $sale->id);

            $old = !empty($details['replaced']) ? DB::table('memberships')->where('id', $details['replaced'])->first() : null;

            if ($old && $old->status === 'cancelled') {
                DB::table('memberships')->where('id', $old->id)->update([
                    'status'        => 'active',
                    'cancelled_at'  => null,
                    'cancel_reason' => null,
                    'updated_at'    => now(),
                ]);
                self::expireDue($tenant);
                self::event($tenant, $old->id, 'restored', "Vuelve a valer: se devolvió el cambio al plan {$membership->name} (venta {$sale->code}).", $user, $sale->id);
            }
        }
    }

    public static function notices(Tenant $tenant): void
    {
        if (!self::enabled($tenant)) {
            return;
        }

        self::expireDue($tenant);
        $zone = $tenant->zone();
        $today = self::today($tenant);
        $long = fn ($date) => Carbon::parse($date, $zone)->locale('es')->isoFormat('D [de] MMMM');
        $rows = DB::table('memberships as m')
            ->join('customers as c', 'c.id', '=', 'm.customer_id')
            ->where('m.tenant_id', $tenant->id)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('m.status', 'active')->whereBetween('m.ends_at', [$today->toDateString(), $today->copy()->addDays(self::NOTICE_DAYS)->toDateString()]))->orWhere('m.status', 'grace'))
            ->orderBy('m.ends_at')
            ->limit(200)
            ->get(['m.*', 'c.first_name', 'c.last_name']);

        foreach ($rows as $m) {
            $ends = Carbon::parse($m->ends_at)->toDateString();
            $who = Person::name($m);

            if ($m->status === 'grace') {
                $title = "Membresía vencida: {$who}";
                $body = "{$m->name} ({$m->code}) venció el {$long($ends)}. Sigue con sus beneficios hasta el {$long(self::graceUntil($tenant, $m))}; después deja de darlos. Renuévala en el punto de venta.";
                $key = "membership-grace:{$m->id}:{$ends}";
                $type = 'membership.grace';
            } else {
                $days = (int) $today->diffInDays(Carbon::parse($ends, $zone), false);
                $when = $days === 0 ? 'hoy' : ($days === 1 ? 'mañana' : "el {$long($ends)}");
                $title = "Membresía por vencer: {$who}";
                $body = "{$m->name} ({$m->code}) vence {$when}. Ofrécele renovarla en el punto de venta.";
                $key = "membership-due:{$m->id}:{$ends}";
                $type = 'membership.due';
            }

            foreach (Notifier::staffWith($tenant->id, 'memberships.edit', $m->branch_id ?? null) as $userId) {
                Notifier::send($tenant->id, $userId, $type, $title, $body, "/memberships/{$m->id}", 'membership', $m->id, $key);
            }
        }
    }

    public static function presentMany(Tenant $tenant, Collection $rows): array
    {
        $customers = DB::table('customers')->whereIn('id', $rows->pluck('customer_id')->unique())->get(['id', 'first_name', 'last_name'])->keyBy('id');
        $today = self::today($tenant);
        $uses = DB::table('membership_usages as u')->join('sales as s', 's.id', '=', 'u.sale_id')->whereIn('u.membership_id', $rows->pluck('id'))->whereNotIn('s.status', ['void', 'refunded'])->groupBy('u.membership_id')->selectRaw('u.membership_id, sum(u.quantity) AS total')->pluck('total', 'membership_id');

        return $rows->map(function ($m) use ($customers, $today, $tenant, $uses) {
            $customer = $customers[$m->customer_id] ?? null;
            $b = self::benefits($m);
            $alive = in_array($m->status, self::ALIVE, true) && Carbon::parse($m->starts_at, $today->getTimezone())->lte($today);
            $summary = $alive ? self::summary($tenant, $m) : null;

            return [
                'id'               => $m->id,
                'code'             => $m->code,
                'name'             => $m->name,
                'status'           => $m->status,
                'status_label'     => self::STATUS_LABELS[$m->status] ?? $m->status,
                'customer'         => $customer ? ['id' => $customer->id, 'name' => Person::name($customer)] : null,
                'price'            => round((float) $m->price, 2),
                'currency'         => $m->currency ?? Currency::of($tenant),
                'period_label'     => self::PERIODS[$b['period']] ?? '',
                'months'           => max(1, (int) $b['months']),
                'starts_at'        => Carbon::parse($m->starts_at)->toDateString(),
                'ends_at'          => Carbon::parse($m->ends_at)->toDateString(),
                'days_left'        => (int) $today->diffInDays(Carbon::parse($m->ends_at, $today->getTimezone()), false),
                'benefits_text'    => self::benefitText($b, $m->currency ?? Currency::of($tenant)),
                'included'         => $summary['included'] ?? [],
                'period_end'       => $summary['period_end'] ?? null,
                'uses'             => (int) ($uses[$m->id] ?? 0),
                'grace_until'      => $m->status === 'grace' ? self::graceUntil($tenant, $m) : null,
                'paused_at'        => $m->status === 'paused' && $m->paused_at ? Carbon::parse($m->paused_at)->setTimezone($tenant->zone())->toDateString() : null,
                'paused_days'      => (int) ($m->paused_days ?? 0),
                'auto_renew'       => (bool) ($m->auto_renew ?? false),
                'cancel_reason'    => $m->status === 'cancelled' ? ($m->cancel_reason ?? null) : null,
                'cancelled_at'     => $m->cancelled_at ? Carbon::parse($m->cancelled_at)->setTimezone($tenant->zone())->toDateString() : null,
            ];
        })->values()->all();
    }
}
