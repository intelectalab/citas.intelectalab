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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Cart
{
    public const TYPES = ['appointment', 'service', 'product', 'package'];

    public const SELLABLE = ['retail', 'both'];

    public const MAX_ITEMS = 50;

    public const NO_PRODUCTS = 'Tu plan no incluye productos: se venden con el módulo Inventario.';

    public static function productsEnabled(Tenant $tenant): bool
    {
        return self::productsReady() && $tenant->hasModule('inventory');
    }

    public static function productsReady(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('products') && Schema::hasColumn('products', 'kind');
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private static function personName(?object $person): string
    {
        return $person ? \App\Support\Person::name($person) : '';
    }

    public static function resolve(Tenant $tenant, User $user, array $input, ?object $sale = null): array
    {
        $branch = Branch::where('tenant_id', $tenant->id)->find($sale->branch_id ?? $input['branch_id'] ?? null);

        if (!$branch) {
            self::fail('branch_id', 'Elige la sede donde se vende.');
        }

        Access::assertBranch($user, $branch->id, true);

        $timezone = AppointmentScheduler::timezone($branch, $tenant);
        $currency = Currency::of($tenant);
        $customer = !empty($input['customer_id']) ? Customer::where('tenant_id', $tenant->id)->find($input['customer_id']) : null;

        if (!empty($input['customer_id']) && !$customer) {
            self::fail('customer_id', 'No encontramos a ese cliente.');
        }

        $items = array_values($input['items'] ?? []);

        if ($items === []) {
            self::fail('items', 'Agrega al menos algo al carrito.');
        }

        if (count($items) > self::MAX_ITEMS) {
            self::fail('items', 'Una venta lleva como máximo ' . self::MAX_ITEMS . ' líneas.');
        }

        $lines = [];
        $seen = [];

        foreach ($items as $index => $item) {
            $type = $item['type'] ?? null;
            $id = $item['id'] ?? null;
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $field = "items.{$index}";

            if (!in_array($type, self::TYPES, true) || !$id) {
                self::fail($field, 'Hay una línea del carrito que no se reconoce. Quítala y agrégala de nuevo.');
            }

            if ($type === 'appointment') {
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;

                $appointment = Appointment::where('tenant_id', $tenant->id)->with(['service', 'professional', 'customer'])->find($id);

                if (!$appointment) {
                    self::fail($field, 'Una de las citas del carrito ya no existe. Quítala del carrito.');
                }

                $code = $appointment->code;

                if ($appointment->branch_id !== $branch->id) {
                    self::fail($field, "La cita {$code} es de otra sede: se cobra en su sede.");
                }

                if (in_array($appointment->status, AppointmentFlow::RELEASED, true)) {
                    self::fail($field, "La cita {$code} está " . mb_strtolower(AppointmentFlow::name($tenant, $appointment->status)) . ': no se cobra.');
                }

                if ((float) $appointment->price <= 0) {
                    self::fail($field, "La cita {$code} no tiene un monto por cobrar: su servicio se agendó con precio cero.");
                }

                if (Currency::ready() && ($appointment->currency ?? $currency) !== $currency) {
                    $from = Currency::present($appointment->currency)['symbol'];
                    $to = Currency::present($currency)['symbol'];
                    self::fail($field, "El precio de la cita {$code} está en {$from} y tu empresa ahora cobra en {$to}. Pásala a {$to} en Configuración → Moneda → \"Cambiar registros anteriores\".");
                }

                $other = DB::table('sales')
                    ->where('tenant_id', $tenant->id)
                    ->whereIn('id', Sales::holding($appointment->id))
                    ->where('status', '!=', 'void')
                    ->when($sale, fn ($q) => $q->where('id', '!=', $sale->id))
                    ->first(['code', 'status']);

                if ($other) {
                    self::fail($field, $other->status === 'pending'
                        ? "La cita {$code} ya está en la venta {$other->code}, que quedó por cobrar. Ábrela desde \"Por cobrar\"."
                        : "La cita {$code} ya se cobró en la venta {$other->code}.");
                }

                if (!$customer) {
                    $customer = $appointment->customer;
                } elseif ($customer->id !== $appointment->customer_id) {
                    self::fail($field, 'Una venta es de un solo cliente: la cita ' . $code . ' es de ' . self::personName($appointment->customer) . ' y el carrito es de ' . self::personName($customer) . '.');
                }

                $lines[] = [
                    'type'            => 'appointment',
                    'id'              => $appointment->id,
                    'item_type'       => 'service',
                    'reference_id'    => $appointment->service_id,
                    'description'     => $appointment->service?->name ?? 'Servicio',
                    'quantity'        => 1,
                    'unit_price'      => round((float) $appointment->price, 2),
                    'professional_id' => $appointment->professional_id,
                    'professional'    => $appointment->professional?->display_name,
                    'appointment_id'  => $appointment->id,
                    'appointment'     => [
                        'code'      => $code,
                        'starts_at' => $appointment->starts_at->copy()->setTimezone($timezone)->format('Y-m-d\TH:i'),
                        'status'    => AppointmentFlow::name($tenant, $appointment->status),
                    ],
                ];

                continue;
            }

            if ($type === 'service') {
                $service = Service::where('tenant_id', $tenant->id)->with('status')->find($id);

                if (!$service || $service->status?->slug === 'inactive') {
                    self::fail($field, 'Uno de los servicios del carrito ya no está activo. Quítalo del carrito.');
                }

                if (!$service->offeredAt($branch->id)) {
                    self::fail($field, "{$service->name} no se da en {$branch->name}. Quítalo del carrito o cambia de sede.");
                }

                $professional = null;

                if (!empty($item['professional_id'])) {
                    $professional = Professional::where('tenant_id', $tenant->id)->find($item['professional_id']);

                    if (!$professional || !Access::worksAt($professional->id, $branch->id)) {
                        self::fail($field, 'Elige a alguien que trabaje en esta sede, o deja el campo vacío.');
                    }
                }

                if ($quantity > 99) {
                    self::fail($field, 'Un servicio se vende como máximo 99 veces en una venta.');
                }

                $lines[] = [
                    'type'            => 'service',
                    'id'              => $service->id,
                    'item_type'       => 'service',
                    'reference_id'    => $service->id,
                    'description'     => $service->name,
                    'quantity'        => $quantity,
                    'unit_price'      => round((float) $service->price, 2),
                    'professional_id' => $professional?->id,
                    'professional'    => $professional?->display_name,
                    'appointment_id'  => null,
                    'appointment'     => null,
                ];

                continue;
            }

            if ($type === 'package') {
                $lines[] = self::packageLine($tenant, $item, $field, $currency);

                continue;
            }

            if (!self::productsReady()) {
                self::fail($field, Sales::NOT_READY);
            }

            if (!self::productsEnabled($tenant) && !($sale && DB::table('sale_items')->where('sale_id', $sale->id)->where('item_type', 'product')->where('reference_id', $id)->exists())) {
                self::fail($field, self::NO_PRODUCTS);
            }

            $product = DB::table('products')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->where('id', $id)->first();

            if (!$product || $product->status !== 'active' || !in_array($product->kind, self::SELLABLE, true)) {
                self::fail($field, 'Uno de los productos del carrito ya no se vende. Quítalo del carrito.');
            }

            if ($quantity > 999) {
                self::fail($field, 'Un producto se vende como máximo 999 unidades en una venta.');
            }

            $seller = null;

            if (!empty($item['professional_id'])) {
                $seller = Professional::where('tenant_id', $tenant->id)->find($item['professional_id']);

                if (!$seller || !Access::worksAt($seller->id, $branch->id)) {
                    self::fail($field, 'Elige a alguien que trabaje en esta sede, o deja el campo vacío.');
                }
            }

            $lines[] = [
                'type'            => 'product',
                'id'              => $product->id,
                'item_type'       => 'product',
                'reference_id'    => $product->id,
                'description'     => $product->name,
                'quantity'        => $quantity,
                'unit_price'      => round((float) $product->price, 2),
                'professional_id' => $seller?->id,
                'professional'    => $seller?->display_name,
                'appointment_id'  => null,
                'appointment'     => null,
            ];
        }

        foreach ($lines as &$line) {
            $line['total'] = round($line['unit_price'] * $line['quantity'], 2);
        }
        unset($line);

        if (!$customer && collect($lines)->contains('type', 'package')) {
            self::fail('customer_id', 'Elige al cliente: el paquete queda a su nombre.');
        }

        Inventory::assertStockForItems($tenant, $branch->id, $lines);

        $gross = round(array_sum(array_column($lines, 'total')), 2);

        if ($gross <= 0) {
            self::fail('items', 'El carrito no tiene nada por cobrar: todo lo que lleva tiene precio cero.');
        }

        $code = trim((string) ($input['coupon_code'] ?? ''));
        $applied = $code !== '' ? Coupons::check($tenant->id, $code, $gross, $currency) : null;
        $rules = Sales::rules($tenant);

        return [
            'branch'   => $branch,
            'customer' => $customer,
            'currency' => $currency,
            'lines'    => $lines,
            'gross'    => $gross,
            'applied'  => $applied,
            'totals'   => Sales::totals($rules, $gross, $applied['discount'] ?? 0) + [
                'tax_rate'           => $rules['tax_rate'],
                'prices_include_tax' => $rules['prices_include_tax'],
                'currency'           => $currency,
            ],
        ];
    }

    private static function packageLine(Tenant $tenant, array $item, string $field, string $currency): array
    {
        if (!Packages::enabled($tenant)) {
            self::fail($field, Packages::ready() ? 'Tu plan no incluye paquetes.' : Packages::NOT_READY);
        }

        $plan = DB::table('package_plans')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->where('id', $item['id'])->first();

        if (!$plan || $plan->status !== 'active') {
            self::fail($field, 'Uno de los paquetes del carrito ya no se vende. Quítalo del carrito.');
        }

        if (($plan->currency ?? $currency) !== $currency) {
            self::fail($field, "El precio del paquete {$plan->name} está en otra moneda. Edítalo en Paquetes → Catálogo.");
        }

        if ((int) ($item['quantity'] ?? 1) > 1) {
            self::fail($field, 'Un paquete se vende de a uno. Si el cliente quiere dos, agrégalo otra vez.');
        }

        $c = Packages::conditionsOf($plan);
        $items = Packages::planItems([$plan->id])[$plan->id] ?? collect();
        $details = null;

        if ($c['booking_mode'] === 'scheduled') {
            $raw = $item['details'] ?? [];
            $slots = array_values(array_filter($raw['slots'] ?? [], fn ($s) => !empty($s['date'])));
            $sessions = (int) $items->sum('sessions');

            if (count($slots) > $sessions) {
                self::fail($field, "El paquete {$plan->name} trae " . Packages::sessions($sessions) . ': no se pueden agendar más.');
            }

            $details = [
                'professional_id' => $raw['professional_id'] ?? null,
                'branch_id'       => $raw['branch_id'] ?? null,
                'slots'           => array_map(fn ($s) => [
                    'date'            => (string) $s['date'],
                    'time'            => isset($s['time']) ? (string) $s['time'] : null,
                    'professional_id' => $s['professional_id'] ?? null,
                ], $slots),
            ];
        }

        return [
            'type'            => 'package',
            'id'              => $plan->id,
            'item_type'       => 'package',
            'reference_id'    => $plan->id,
            'description'     => $plan->name,
            'quantity'        => 1,
            'unit_price'      => round((float) $plan->price, 2),
            'professional_id' => null,
            'professional'    => null,
            'appointment_id'  => null,
            'appointment'     => null,
            'details'         => $details,
            'snapshot'        => Packages::snapshot($plan),
            'package'         => [
                'sessions'       => (int) $items->sum('sessions'),
                'booking_mode'   => $c['booking_mode'],
                'schedule_label' => Packages::scheduleLabel($c),
                'scheduled'      => count($details['slots'] ?? []),
            ],
        ];
    }

    private static function storedDetails(array $line): ?string
    {
        $details = ($line['details'] ?? null) ?: [];

        if (!empty($line['snapshot'])) {
            $details['plan'] = $line['snapshot'];
        }

        return $details ? json_encode($details) : null;
    }

    private static function shownDetails(?string $raw): ?array
    {
        $details = $raw ? (json_decode($raw, true) ?: []) : [];
        unset($details['plan']);

        return $details ?: null;
    }

    public static function preview(array $resolved): array
    {
        $customer = $resolved['customer'];

        return [
            'branch_id' => $resolved['branch']->id,
            'customer'  => $customer ? self::customer($customer) : null,
            'lines'     => array_map(fn (array $line) => [
                'type'         => $line['type'],
                'id'           => $line['id'],
                'description'  => $line['description'],
                'quantity'     => $line['quantity'],
                'unit_price'   => $line['unit_price'],
                'total'        => $line['total'],
                'professional' => $line['professional_id'] ? ['id' => $line['professional_id'], 'name' => $line['professional']] : null,
                'appointment'  => $line['appointment'],
                'details'      => $line['details'] ?? null,
                'package'      => $line['package'] ?? null,
            ], $resolved['lines']),
            'totals'    => $resolved['totals'],
            'coupon'    => $resolved['applied'] ? Coupons::summary($resolved['applied']['coupon'], $resolved['applied']['discount'], $resolved['currency']) : null,
        ];
    }

    public static function customer(object $customer): array
    {
        return [
            'id'              => $customer->id,
            'code'            => $customer->code,
            'first_name'      => $customer->first_name,
            'last_name'       => $customer->last_name,
            'phone'           => $customer->phone,
            'document_number' => $customer->document_number,
        ];
    }

    public static function save(Tenant $tenant, User $user, array $input, ?string $saleId = null): object
    {
        return DB::transaction(function () use ($tenant, $user, $input, $saleId) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['sales-cart:' . $tenant->id]);

            $sale = null;

            if ($saleId) {
                $sale = DB::table('sales')->where('tenant_id', $tenant->id)->where('id', $saleId)->lockForUpdate()->first();
                abort_unless($sale, 404);
                Access::assertBranch($user, $sale->branch_id, true);

                if ($blocked = self::editBlocked($sale)) {
                    self::fail('items', $blocked);
                }
            }

            $resolved = self::resolve($tenant, $user, $input, $sale);
            $totals = $resolved['totals'];
            $appointments = array_values(array_filter(array_column($resolved['lines'], 'appointment_id')));

            $values = [
                'branch_id'          => $resolved['branch']->id,
                'customer_id'        => $resolved['customer']?->id,
                'appointment_id'     => count($appointments) === 1 ? $appointments[0] : null,
                'subtotal'           => $totals['subtotal'],
                'discount'           => $totals['discount'],
                'tax'                => $totals['tax'],
                'total'              => $totals['total'],
                'paid'               => 0,
                'balance'            => $totals['total'],
                'tax_rate'           => $totals['tax_rate'],
                'prices_include_tax' => $totals['prices_include_tax'],
                'updated_at'         => now(),
            ] + Currency::column($resolved['currency']);

            if ($sale) {
                PaymentLinks::cancelForSale($sale->id, 'La venta cambió: genera un link nuevo.');
                self::releaseCoupon($sale->id);
                DB::table('sale_items')->where('sale_id', $sale->id)->delete();
                DB::table('sales')->where('id', $sale->id)->update($values);
                $id = $sale->id;
            } else {
                $id = (string) Str::uuid();
                DB::table('sales')->insert($values + [
                    'id'         => $id,
                    'tenant_id'  => $tenant->id,
                    'code'       => Codes::next($tenant->id, 'sale'),
                    'status'     => 'pending',
                    'created_by' => $user->id,
                    'created_at' => now(),
                ]);
            }

            $ordered = Schema::hasColumn('sale_items', 'position');
            $detailed = Schema::hasColumn('sale_items', 'details');

            foreach ($resolved['lines'] as $index => $line) {
                if (($line['type'] ?? null) !== 'package' || empty($line['details']['slots'])) {
                    continue;
                }

                $plan = DB::table('package_plans')->where('id', $line['id'])->first();
                $service = Service::where('tenant_id', $tenant->id)->with('status')->find(Packages::planItems([$plan->id])[$plan->id]->first()->service_id ?? null);
                $branch = Branch::where('tenant_id', $tenant->id)->find($line['details']['branch_id'] ?? $resolved['branch']->id) ?? $resolved['branch'];
                $checked = Packages::check($tenant, $branch, $service, $resolved['customer']->id, $line['details']['slots'], $line['details']['professional_id'], Packages::expiresFrom($tenant, now(), (int) $plan->validity_days));
                $bad = collect($checked)->firstWhere('ok', false);

                if ($bad) {
                    self::fail("items.{$index}", "La sesión del {$bad['weekday']} " . Carbon::parse($bad['date'])->format('d/m') . ($bad['time'] ? ' a las ' . Packages::hour($bad['time']) : '') . ' no se puede agendar: ' . mb_strtolower(collect($bad['reasons'])->pluck('message')->implode(' · ')) . '. Cámbiala en "Agendar sesiones".');
                }
            }

            DB::table('sale_items')->insert(array_map(fn (array $line, int $position) => [
                'id'              => (string) Str::uuid(),
                'tenant_id'       => $tenant->id,
                'sale_id'         => $id,
                'item_type'       => $line['item_type'],
                'reference_id'    => $line['reference_id'],
                'description'     => $line['description'],
                'quantity'        => $line['quantity'],
                'unit_price'      => $line['unit_price'],
                'discount'        => 0,
                'total'           => $line['total'],
                'professional_id' => $line['professional_id'],
                'appointment_id'  => $line['appointment_id'],
            ] + ($ordered ? ['position' => $position] : []) + ($detailed ? ['details' => self::storedDetails($line)] : []), $resolved['lines'], array_keys($resolved['lines'])));

            if ($resolved['applied']) {
                Coupons::redeem($resolved['applied']['coupon'], $id, $resolved['customer']?->id, $resolved['applied']['discount']);
            }

            return DB::table('sales')->where('id', $id)->first();
        });
    }

    public static function editBlocked(object $sale): ?string
    {
        return match (true) {
            $sale->status === 'void' => 'Esta venta está anulada: ya no se cambia.',
            (float) $sale->paid > 0 || $sale->status !== 'pending' => 'Esta venta ya tiene pagos: no se cambia. Si hay un error, devuélvela desde Ventas.',
            default => null,
        };
    }

    private static function releaseCoupon(string $saleId): void
    {
        foreach (DB::table('coupon_redemptions')->where('sale_id', $saleId)->get() as $redemption) {
            DB::table('coupons')->where('id', $redemption->coupon_id)->where('used_count', '>', 0)->decrement('used_count');
            DB::table('coupon_redemptions')->where('id', $redemption->id)->delete();
        }
    }

    public static function load(Tenant $tenant, object $sale): array
    {
        $coupon = DB::table('coupon_redemptions as r')->join('coupons as c', 'c.id', '=', 'r.coupon_id')->where('r.sale_id', $sale->id)->value('c.code');
        $customer = $sale->customer_id ? Customer::where('tenant_id', $tenant->id)->find($sale->customer_id) : null;

        return [
            'sale_id'      => $sale->id,
            'code'         => $sale->code,
            'status'       => $sale->status,
            'branch_id'    => $sale->branch_id,
            'customer'     => $customer ? self::customer($customer) : null,
            'coupon_code'  => $coupon,
            'edit_blocked' => self::editBlocked($sale),
            'items'        => DB::table('sale_items')->where('sale_id', $sale->id)->when(Schema::hasColumn('sale_items', 'position'), fn ($q) => $q->orderBy('position'))->get()->map(fn ($i) => [
                'type'            => $i->appointment_id ? 'appointment' : $i->item_type,
                'id'              => $i->appointment_id ?? $i->reference_id,
                'quantity'        => (int) round((float) $i->quantity),
                'professional_id' => $i->appointment_id ? null : $i->professional_id,
                'details'         => self::shownDetails($i->details ?? null),
            ])->values(),
        ];
    }

    public static function pendingSales(Tenant $tenant, string $branchId): array
    {
        $iso = fn ($value) => $value ? Carbon::parse($value)->toIso8601String() : null;

        $sales = DB::table('sales as s')
            ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
            ->leftJoin('users as u', 'u.id', '=', 's.created_by')
            ->where('s.tenant_id', $tenant->id)
            ->where('s.branch_id', $branchId)
            ->whereIn('s.status', ['pending', 'partial'])
            ->when(Schema::hasColumn('sales', 'deposit_outcome'), fn ($q) => $q->whereNull('s.deposit_outcome'))
            ->orderByDesc('s.created_at')
            ->limit(200)
            ->get(['s.*', 'c.first_name', 'c.last_name', 'u.first_name as user_first', 'u.last_name as user_last']);

        $items = DB::table('sale_items')->whereIn('sale_id', $sales->pluck('id'))->get(['sale_id', 'description', 'quantity'])->groupBy('sale_id');

        return $sales->map(fn ($s) => [
            'id'         => $s->id,
            'code'       => $s->code,
            'status'     => $s->status,
            'created_at' => $iso($s->created_at),
            'customer'   => $s->customer_id ? ['id' => $s->customer_id, 'name' => \App\Support\Person::name($s)] : null,
            'by'         => trim("{$s->user_first} {$s->user_last}") ?: null,
            'detail'     => ($items[$s->id] ?? collect())->map(fn ($i) => ((float) $i->quantity > 1 ? (int) $i->quantity . ' × ' : '') . $i->description)->implode(', '),
            'total'      => round((float) $s->total, 2),
            'balance'    => round((float) $s->balance, 2),
            'paid'       => round((float) $s->paid, 2),
            'deposit'    => PaymentLinks::ready() ? PaymentLinks::depositPaid($s->id) : 0.0,
            'currency'   => $s->currency ?? Currency::DEFAULT,
        ])->values()->all();
    }

    public static function pendingAppointments(Tenant $tenant, Branch $branch, ?string $ownProfessionalId = null): array
    {
        $timezone = AppointmentScheduler::timezone($branch, $tenant);
        $today = now($timezone)->startOfDay();
        $currency = Currency::of($tenant);

        return Appointment::where('tenant_id', $tenant->id)
            ->where('branch_id', $branch->id)
            ->when($ownProfessionalId, fn ($q, $id) => $q->where('professional_id', $id))
            ->where('price', '>', 0)
            ->whereNotIn('status', AppointmentFlow::RELEASED)
            ->when(Currency::ready(), fn ($q) => $q->where('currency', $currency))
            ->when(Packages::ready(), fn ($q) => $q->whereNull('customer_package_id'))
            ->whereNotIn('id', DB::table('sale_items as i')->join('sales as s', 's.id', '=', 'i.sale_id')->where('s.tenant_id', $tenant->id)->where('s.status', '!=', 'void')->whereNotNull('i.appointment_id')->select('i.appointment_id'))
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('starts_at', '>=', $today->toIso8601String())->where('starts_at', '<', $today->copy()->addDay()->toIso8601String()))
                ->orWhere(fn ($q) => $q->where('status', AppointmentFlow::COMPLETED)->where('starts_at', '>=', $today->copy()->subDays(3)->toIso8601String())->where('starts_at', '<', $today->toIso8601String())))
            ->with(['customer', 'service', 'professional'])
            ->orderBy('starts_at')
            ->limit(200)
            ->get()
            ->map(fn (Appointment $a) => [
                'id'           => $a->id,
                'code'         => $a->code,
                'starts_at'    => $a->starts_at->copy()->setTimezone($timezone)->format('Y-m-d\TH:i'),
                'today'        => $a->starts_at->copy()->setTimezone($timezone)->isSameDay($today),
                'status'       => $a->status,
                'status_name'  => AppointmentFlow::name($tenant, $a->status),
                'customer'     => $a->customer ? ['id' => $a->customer->id, 'name' => self::personName($a->customer)] : null,
                'service'      => $a->service?->name,
                'professional' => $a->professional ? ['id' => $a->professional->id, 'name' => $a->professional->display_name] : null,
                'price'        => round((float) $a->price, 2),
                'currency'     => $a->currency ?? Currency::DEFAULT,
            ])
            ->sort(fn (array $a, array $b) => [$b['today'], $a['starts_at']] <=> [$a['today'], $b['starts_at']])
            ->values()
            ->all();
    }
}
