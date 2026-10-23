<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Payments\PaymentProvider;
use App\Support\Payments\SimulatedPaymentProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentLinks
{
    public const PROVIDERS = [
        'simulated'   => ['label' => 'Simulación', 'class' => SimulatedPaymentProvider::class],
        'culqi'       => ['label' => 'Culqi', 'class' => null],
        'niubiz'      => ['label' => 'Niubiz', 'class' => null],
        'izipay'      => ['label' => 'Izipay', 'class' => null],
        'mercadopago' => ['label' => 'Mercado Pago', 'class' => null],
    ];

    public const STATUS_LABELS = [
        'pending'   => 'Esperando pago',
        'paid'      => 'Pagado',
        'expired'   => 'Vencido',
        'cancelled' => 'Anulado',
        'failed'    => 'Rechazado',
    ];

    public const GATEWAY_METHODS = ['link', 'deposit'];

    public const SLOT_TAKEN = 'Ese horario se acaba de ocupar. Elige otro.';

    public const NOT_READY = 'Falta actualizar la base de datos para usar los links de pago: ejecuta php artisan migrate.';

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('payment_links') && Schema::hasTable('payment_events')
            && Schema::hasColumn('sales', 'deposit_outcome') && Schema::hasColumn('services', 'deposit_type');
    }

    public static function integration(string $tenantId): ?object
    {
        return DB::table('tenant_integrations')->where('tenant_id', $tenantId)->where('kind', 'payments')->first();
    }

    public static function provider(Tenant|string $tenant): PaymentProvider
    {
        $integration = self::integration($tenant instanceof Tenant ? $tenant->id : $tenant);
        $class = $integration && $integration->status === 'active' && $integration->mode !== 'simulated'
            ? (self::PROVIDERS[$integration->provider]['class'] ?? null)
            : null;

        return $class ? new $class($integration) : new SimulatedPaymentProvider();
    }

    public static function providerByKey(string $key): ?PaymentProvider
    {
        $class = self::PROVIDERS[$key]['class'] ?? null;

        return $class ? new $class(null) : null;
    }

    public static function url(string $token): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . '/pay/' . $token;
    }

    public static function depositAmount(object $service): float
    {
        if (!$service->requires_deposit) {
            return 0.0;
        }

        $price = (float) $service->price;
        $value = (float) ($service->deposit_value ?? 30);

        $amount = ($service->deposit_type ?? 'percent') === 'amount'
            ? min($value, $price)
            : $price * min(100, $value) / 100;

        return max(0, round($amount, 2));
    }

    public static function depositLabel(object $service): string
    {
        return ($service->deposit_type ?? 'percent') === 'amount'
            ? Currency::format((float) $service->deposit_value, $service->currency ?? null)
            : rtrim(rtrim(number_format((float) $service->deposit_value, 2), '0'), '.') . ' %';
    }

    public static function expireDue(?string $tenantId = null): void
    {
        if (!self::ready()) {
            return;
        }

        DB::table('payment_links')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('status', 'pending')
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired', 'updated_at' => now()]);
    }

    public static function latestForSale(string $saleId): ?object
    {
        if (!self::ready()) {
            return null;
        }

        $link = DB::table('payment_links')->where('sale_id', $saleId)->orderByRaw("status = 'pending' desc")->orderByDesc('created_at')->first();

        if ($link && $link->status === 'pending' && Carbon::parse($link->expires_at)->isPast()) {
            DB::table('payment_links')->where('id', $link->id)->update(['status' => 'expired', 'updated_at' => now()]);
            $link->status = 'expired';
        }

        return $link;
    }

    private static function token(): string
    {
        return Str::lower(Str::random(40));
    }

    public static function forSale(Tenant $tenant, object $sale, User $user): object
    {
        if (!in_array($sale->status, ['pending', 'partial'], true) || (float) $sale->balance <= 0) {
            throw ValidationException::withMessages(['link' => 'Esta venta no tiene saldo por cobrar.']);
        }

        $current = self::latestForSale($sale->id);

        if ($current && $current->status === 'pending') {
            throw ValidationException::withMessages(['link' => 'Esta venta ya tiene un link esperando pago. Anúlalo si necesitas uno nuevo.']);
        }

        $items = DB::table('sale_items')->where('sale_id', $sale->id)->pluck('description')->implode(', ');
        $hours = max(1, (int) AppointmentFlow::rule($tenant, 'payments.link_hours'));

        return self::create($tenant, [
            'branch_id'   => $sale->branch_id,
            'purpose'     => 'sale',
            'amount'      => round((float) $sale->balance, 2),
            'currency'    => $sale->currency ?? Currency::of($tenant),
            'description' => mb_substr("Venta {$sale->code}" . ($items ? " · {$items}" : ''), 0, 255),
            'sale_id'     => $sale->id,
            'customer_id' => $sale->customer_id,
            'expires_at'  => now()->addHours($hours),
            'created_by'  => $user->id,
        ]);
    }

    public static function forDeposit(Tenant $tenant, Customer $customer, Service $service, Branch $branch, Professional $professional, Carbon $start, Carbon $end, ?string $dependentId, ?string $notes): object
    {
        $minutes = max(5, (int) AppointmentFlow::rule($tenant, 'booking.deposit_minutes'));
        $when = $start->copy()->locale('es')->isoFormat('ddd D [de] MMM, HH:mm');

        DB::table('payment_links')
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->where('purpose', 'deposit')
            ->where('status', 'pending')
            ->update(['status' => 'cancelled', 'cancel_reason' => 'Lo reemplazó una reserva nueva.', 'updated_at' => now()]);

        return self::create($tenant, [
            'branch_id'             => $branch->id,
            'purpose'               => 'deposit',
            'amount'                => self::depositAmount($service),
            'currency'              => Currency::of($tenant),
            'description'           => mb_substr("Adelanto: {$service->name} · {$when}", 0, 255),
            'customer_id'           => $customer->id,
            'service_id'            => $service->id,
            'professional_id'       => $professional->id,
            'customer_dependent_id' => $dependentId,
            'starts_at'             => $start->copy()->utc(),
            'ends_at'               => $end->copy()->utc(),
            'notes'                 => $notes,
            'expires_at'            => now()->addMinutes($minutes),
        ]);
    }

    private static function create(Tenant $tenant, array $values): object
    {
        $id = (string) Str::uuid();
        $provider = self::provider($tenant);
        $values += ['id' => $id, 'tenant_id' => $tenant->id, 'token' => self::token(), 'provider' => $provider->key(), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()];
        $values['external_id'] = $provider->checkout((object) $values)['external_id'] ?? null;

        DB::table('payment_links')->insert($values);

        return DB::table('payment_links')->where('id', $id)->first();
    }

    public static function present(?object $link): ?array
    {
        if (!$link) {
            return null;
        }

        $iso = fn ($value) => $value ? Carbon::parse($value)->toIso8601String() : null;

        return [
            'id'            => $link->id,
            'url'           => self::url($link->token),
            'purpose'       => $link->purpose,
            'status'        => $link->status,
            'status_label'  => self::STATUS_LABELS[$link->status] ?? $link->status,
            'amount'        => round((float) $link->amount, 2),
            'currency'      => $link->currency,
            'description'   => $link->description,
            'expires_at'    => $iso($link->expires_at),
            'paid_at'       => $iso($link->paid_at),
            'cancel_reason' => $link->cancel_reason,
            'provider'      => self::PROVIDERS[$link->provider]['label'] ?? $link->provider,
            'simulated'     => $link->provider === 'simulated',
        ];
    }

    public static function cancel(object $link, string $reason): void
    {
        DB::table('payment_links')->where('id', $link->id)->where('status', 'pending')
            ->update(['status' => 'cancelled', 'cancel_reason' => mb_substr($reason, 0, 255), 'updated_at' => now()]);
    }

    public static function cancelForSale(string $saleId, string $reason): void
    {
        if (!self::ready()) {
            return;
        }

        DB::table('payment_links')->where('sale_id', $saleId)->where('status', 'pending')
            ->update(['status' => 'cancelled', 'cancel_reason' => mb_substr($reason, 0, 255), 'updated_at' => now()]);
    }

    public static function slotTaken(Tenant $tenant, object $link): ?string
    {
        $service = Service::where('tenant_id', $tenant->id)->with('status')->find($link->service_id);
        $professional = Professional::where('tenant_id', $tenant->id)->with('status')->find($link->professional_id);
        $branch = Branch::where('tenant_id', $tenant->id)->find($link->branch_id);

        if (!$service || !$professional || !$branch) {
            return 'Ese servicio u horario ya no está disponible. Elige otro.';
        }

        $timezone = AppointmentScheduler::timezone($branch, $tenant);
        $start = Carbon::parse($link->starts_at)->setTimezone($timezone);
        $duration = (int) Carbon::parse($link->starts_at)->diffInMinutes(Carbon::parse($link->ends_at));
        $check = (new AppointmentScheduler($tenant))->check($service, $professional, $branch, $start, $duration);

        return $check['ok'] ? null : self::SLOT_TAKEN;
    }

    public static function publicView(string $token): ?array
    {
        if (!self::ready()) {
            return null;
        }

        $link = DB::table('payment_links')->where('token', $token)->first();

        if (!$link) {
            return null;
        }

        $tenant = Tenant::with('settings')->find($link->tenant_id);
        self::expireDue($link->tenant_id);
        $link = DB::table('payment_links')->where('id', $link->id)->first();

        if ($link->status === 'pending' && $link->purpose === 'deposit' && ($taken = self::slotTaken($tenant, $link))) {
            self::cancel($link, $taken);
            $link = DB::table('payment_links')->where('id', $link->id)->first();
        }

        $company = DB::table('companies')->where('tenant_id', $tenant->id)->orderBy('created_at')->first();
        $branch = $link->branch_id ? Branch::find($link->branch_id) : null;
        $timezone = $branch ? AppointmentScheduler::timezone($branch, $tenant) : ($tenant->zone());
        $lastEvent = DB::table('payment_events')->where('payment_link_id', $link->id)->orderByDesc('created_at')->first();

        $booking = null;

        if ($link->purpose === 'deposit') {
            $service = Service::find($link->service_id);
            $booking = [
                'service'      => $service?->name,
                'professional' => Professional::find($link->professional_id)?->display_name,
                'branch'       => $branch?->name,
                'starts_at'    => $link->starts_at ? Carbon::parse($link->starts_at)->setTimezone($timezone)->format('Y-m-d\TH:i') : null,
                'price'        => $service ? round((float) $service->price, 2) : null,
            ];
        }

        return [
            'status'       => $link->status,
            'status_label' => self::STATUS_LABELS[$link->status] ?? $link->status,
            'purpose'      => $link->purpose,
            'amount'       => round((float) $link->amount, 2),
            'currency'     => $link->currency,
            'currency_info' => Currency::present($link->currency),
            'description'  => $link->description,
            'expires_at'   => Carbon::parse($link->expires_at)->toIso8601String(),
            'paid_at'      => $link->paid_at ? Carbon::parse($link->paid_at)->toIso8601String() : null,
            'reason'       => $link->cancel_reason,
            'rejected'     => $link->status === 'pending' && $lastEvent?->event === 'payment.rejected',
            'simulated'    => $link->provider === 'simulated',
            'provider'     => self::PROVIDERS[$link->provider]['label'] ?? $link->provider,
            'company'      => [
                'name'  => $company->trade_name ?? $tenant->name,
                'color' => $tenant->settings?->brand_color ?? null,
                'logo'  => $tenant->settings?->logo_url ?? null,
            ],
            'booking'      => $booking,
            'appointment'  => $link->appointment_id ? DB::table('appointments')->where('id', $link->appointment_id)->value('code') : null,
        ];
    }

    public static function simulate(string $token, string $result): array
    {
        $link = DB::table('payment_links')->where('token', $token)->first();
        abort_unless($link && $link->provider === 'simulated', 404);

        return self::handle('simulated', [
            'event_id'  => 'evt_' . Str::lower(Str::random(16)),
            'type'      => $result === 'approved' ? 'payment.approved' : 'payment.rejected',
            'reference' => $link->external_id ?? $link->token,
            'amount'    => (float) $link->amount,
            'payload'   => ['simulated' => true, 'result' => $result],
        ]);
    }

    private static function log(?object $link, string $provider, array $event, string $status, ?string $message): void
    {
        DB::table('payment_events')->insertOrIgnore([
            'id'              => (string) Str::uuid(),
            'tenant_id'       => $link?->tenant_id,
            'payment_link_id' => $link?->id,
            'provider'        => $provider,
            'event_id'        => mb_substr($event['event_id'], 0, 190),
            'event'           => mb_substr($event['type'], 0, 60),
            'status'          => $status,
            'message'         => $message ? mb_substr($message, 0, 255) : null,
            'payload'         => json_encode($event['payload'] ?? $event),
            'created_at'      => now(),
        ]);
    }

    public static function handle(string $provider, array $event): array
    {
        return DB::transaction(function () use ($provider, $event) {
            if (DB::table('payment_events')->where('provider', $provider)->where('event_id', $event['event_id'])->exists()) {
                return ['status' => 'duplicate', 'message' => 'Este aviso ya se había recibido: no se registra dos veces.'];
            }

            $link = DB::table('payment_links')
                ->where('provider', $provider)
                ->where(fn ($q) => $q->where('external_id', $event['reference'])->orWhere('token', $event['reference']))
                ->lockForUpdate()
                ->first();

            if (!$link) {
                self::log(null, $provider, $event, 'ignored', 'No hay un link con esa referencia.');

                return ['status' => 'ignored', 'message' => 'No hay un link con esa referencia.'];
            }

            if ($link->status === 'pending' && Carbon::parse($link->expires_at)->isPast()) {
                DB::table('payment_links')->where('id', $link->id)->update(['status' => 'expired', 'updated_at' => now()]);
                $link->status = 'expired';
            }

            if ($event['type'] !== 'payment.approved') {
                self::log($link, $provider, $event, 'processed', 'Pago rechazado: el cliente puede intentarlo de nuevo.');

                return ['status' => 'rejected', 'message' => 'El pago fue rechazado. Puedes intentarlo con otra tarjeta u otro medio.'];
            }

            $tenant = Tenant::with(['settings', 'activeSubscription.plan'])->find($link->tenant_id);

            if ($link->status !== 'pending') {
                return self::refundUnexpected($tenant, $link, $provider, $event, $link->status === 'paid'
                    ? 'Este link ya estaba pagado'
                    : 'Este link ya no estaba vigente (' . mb_strtolower(self::STATUS_LABELS[$link->status] ?? $link->status) . ')');
            }

            return $link->purpose === 'deposit'
                ? self::confirmDeposit($tenant, $link, $provider, $event)
                : self::confirmSale($tenant, $link, $provider, $event);
        });
    }

    private static function refundUnexpected(Tenant $tenant, object $link, string $provider, array $event, string $why): array
    {
        $refund = self::provider($tenant)->refund((object) ['external_reference' => $event['reference']], (float) ($event['amount'] ?? $link->amount));
        $message = "{$why}: se devolvió el pago automáticamente.";
        self::log($link, $provider, $event, 'refunded', $message . ($refund['ok'] ? '' : ' La pasarela no confirmó la devolución.'));

        return ['status' => 'refunded', 'message' => $message];
    }

    private static function gatewayPayment(Tenant $tenant, object $sale, object $link, string $method, array $event): string
    {
        $paymentId = (string) Str::uuid();

        DB::table('payments')->insert([
            'id'                 => $paymentId,
            'tenant_id'          => $tenant->id,
            'branch_id'          => $sale->branch_id,
            'sale_id'            => $sale->id,
            'appointment_id'     => $sale->appointment_id,
            'customer_id'        => $sale->customer_id,
            'method'             => $method,
            'status'             => 'confirmed',
            'amount'             => round((float) $link->amount, 2),
            'change_amount'      => 0,
            'external_reference' => mb_substr((string) $event['reference'], 0, 190),
            'provider'           => $link->provider,
            'payment_url'        => self::url($link->token),
            'confirmed_at'       => now(),
            'idempotency_key'    => 'link:' . $link->id,
            'created_by'         => $link->created_by,
            'created_at'         => now(),
        ] + Currency::column($link->currency));

        return $paymentId;
    }

    private static function applyPayment(Tenant $tenant, object $sale, float $amount): object
    {
        $paid = round((float) $sale->paid + $amount, 2);
        $balance = round((float) $sale->total - $paid, 2);
        $isPaid = $balance <= 0.001;

        DB::table('sales')->where('id', $sale->id)->update([
            'paid'       => $paid,
            'balance'    => max(0, $balance),
            'status'     => $isPaid ? 'paid' : 'partial',
            'paid_at'    => $isPaid ? now() : null,
            'updated_at' => now(),
        ]);

        $sale = DB::table('sales')->where('id', $sale->id)->first();

        if ($isPaid) {
            Invoicing::issue($tenant, $sale, Invoicing::validated($tenant, ['doc_type' => Invoicing::FACTURA === Invoicing::defaultType($tenant) ? Invoicing::types($tenant)[0] : Invoicing::defaultType($tenant)]), null);
            Packages::issueForSale($tenant, $sale, null);
        }

        return $sale;
    }

    private static function confirmSale(Tenant $tenant, object $link, string $provider, array $event): array
    {
        $sale = DB::table('sales')->where('id', $link->sale_id)->lockForUpdate()->first();

        if (!$sale || !in_array($sale->status, ['pending', 'partial'], true) || (float) $sale->balance + 0.001 < (float) $link->amount) {
            self::cancel($link, 'La venta cambió antes del pago.');

            return self::refundUnexpected($tenant, $link, $provider, $event, 'La venta ya no tenía ese saldo por cobrar');
        }

        $paymentId = self::gatewayPayment($tenant, $sale, $link, 'link', $event);
        $sale = self::applyPayment($tenant, $sale, (float) $link->amount);

        DB::table('payment_links')->where('id', $link->id)->update(['status' => 'paid', 'paid_at' => now(), 'payment_id' => $paymentId, 'updated_at' => now()]);
        self::log($link, $provider, $event, 'processed', "Pago confirmado de la venta {$sale->code}.");

        if ($link->created_by) {
            $customer = $sale->customer_id ? DB::table('customers')->where('id', $sale->customer_id)->first(['first_name', 'last_name']) : null;
            Notifier::send(
                $tenant->id,
                $link->created_by,
                'payment.link_paid',
                'Pago recibido por link',
                implode(' · ', array_filter(["Venta {$sale->code}", Currency::format((float) $link->amount, $link->currency), $customer ? \App\Support\Person::name($customer) : null])),
                '/sales',
                'sale',
                $sale->id,
                "link-paid:{$link->id}",
            );
        }

        return ['status' => 'paid', 'message' => 'Pago confirmado.'];
    }

    private static function confirmDeposit(Tenant $tenant, object $link, string $provider, array $event): array
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['appointments:' . $tenant->id]);

        if ($taken = self::slotTaken($tenant, $link)) {
            self::cancel($link, $taken);

            return self::refundUnexpected($tenant, $link, $provider, $event, 'El horario se ocupó antes del pago');
        }

        $customer = Customer::find($link->customer_id);
        $service = Service::find($link->service_id);

        $appointment = Appointment::create([
            'tenant_id'             => $tenant->id,
            'code'                  => Appointment::nextCode($tenant->id),
            'branch_id'             => $link->branch_id,
            'customer_id'           => $link->customer_id,
            'customer_dependent_id' => $link->customer_dependent_id,
            'service_id'            => $link->service_id,
            'professional_id'       => $link->professional_id,
            'starts_at'             => Carbon::parse($link->starts_at),
            'ends_at'               => Carbon::parse($link->ends_at),
            'status'                => AppointmentFlow::CONFIRMED,
            'source'                => 'online',
            'price'                 => $service->price,
        ] + Currency::column($tenant) + [
            'notes'                 => $link->notes,
            'confirmed_at'          => now(),
            'created_by'            => null,
        ]);

        AppointmentEvents::record($appointment, null, AppointmentFlow::CONFIRMED, $customer, 'Reserva en línea con adelanto pagado', ['payment_links', 'packages']);

        $sale = Sales::createForAppointment($tenant, $appointment->load('service'), null);
        $paymentId = self::gatewayPayment($tenant, $sale, $link, 'deposit', $event);
        self::applyPayment($tenant, $sale, (float) $link->amount);

        DB::table('payment_links')->where('id', $link->id)->update([
            'status' => 'paid', 'paid_at' => now(), 'payment_id' => $paymentId, 'sale_id' => $sale->id, 'appointment_id' => $appointment->id, 'updated_at' => now(),
        ]);
        self::log($link, $provider, $event, 'processed', "Adelanto pagado: se reservó la cita {$appointment->code}.");
        self::invalidateClashing($tenant, $appointment, $link->id);

        return ['status' => 'paid', 'message' => 'Pago confirmado.', 'appointment' => $appointment->code];
    }

    public static function invalidateClashing(Tenant $tenant, Appointment $appointment, ?string $exceptId = null): void
    {
        DB::table('payment_links')
            ->where('tenant_id', $tenant->id)
            ->where('purpose', 'deposit')
            ->where('status', 'pending')
            ->where('professional_id', $appointment->professional_id)
            ->where('starts_at', '<', Carbon::parse($appointment->ends_at)->toIso8601String())
            ->where('ends_at', '>', Carbon::parse($appointment->starts_at)->toIso8601String())
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->update(['status' => 'cancelled', 'cancel_reason' => self::SLOT_TAKEN, 'updated_at' => now()]);
    }

    public static function depositPaid(string $saleId): float
    {
        return round((float) DB::table('payments')->where('sale_id', $saleId)->where('method', 'deposit')->where('status', 'confirmed')->sum('amount'), 2);
    }

    public static function gatewayPaid(string $saleId): float
    {
        $in = (float) DB::table('payments')->where('sale_id', $saleId)->whereIn('method', self::GATEWAY_METHODS)->where('status', 'confirmed')->sum('amount');
        $out = (float) DB::table('payments')->where('sale_id', $saleId)->where('method', 'gateway')->sum('amount');

        return round($in + $out, 2);
    }

    public static function appointmentChanged(Tenant $tenant, Appointment $appointment, ?string $from, string $to): void
    {
        try {
            if (!self::ready()) {
                return;
            }

            if (!in_array($to, AppointmentFlow::RELEASED, true)) {
                self::invalidateClashing($tenant, $appointment);

                return;
            }

            $sale = Sales::ofAppointment($tenant->id, $appointment->id);

            if (!$sale) {
                return;
            }

            self::cancelForSale($sale->id, 'La cita se ' . ($to === AppointmentFlow::CANCELLED ? 'canceló' : 'marcó como no asistida') . '.');

            $deposit = self::depositPaid($sale->id);

            if ($deposit <= 0 || $sale->deposit_outcome || !in_array($sale->status, ['partial', 'paid'], true)) {
                return;
            }

            $hours = max(0, (int) AppointmentFlow::rule($tenant, 'booking.deposit_refund_hours'));
            $onTime = $to === AppointmentFlow::CANCELLED && now()->lte($appointment->starts_at->copy()->subHours($hours));

            if ($onTime) {
                DB::table('sales')->where('id', $sale->id)->update(['deposit_outcome' => 'refund_due', 'updated_at' => now()]);
                $appointment->loadMissing('customer');
                $who = $appointment->customer ? \App\Support\Person::name($appointment->customer) : 'El cliente';

                foreach (Notifier::adminIds($tenant->id) as $userId) {
                    Notifier::send(
                        $tenant->id,
                        $userId,
                        'payment.deposit_refund_due',
                        'Adelanto por devolver',
                        "{$who} canceló a tiempo la cita {$appointment->code}. Devuélvele " . Currency::format($deposit, $sale->currency ?? null) . " desde la venta {$sale->code}.",
                        '/sales',
                        'sale',
                        $sale->id,
                        "deposit-refund:{$sale->id}",
                    );
                }

                return;
            }

            self::retain($tenant, $sale, $appointment, $deposit, $to);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private static function retain(Tenant $tenant, object $sale, Appointment $appointment, float $deposit, string $to): void
    {
        $rules = Sales::rules($tenant);
        $totals = Sales::totals($rules, $deposit);
        $why = $to === AppointmentFlow::NO_SHOW ? 'no asistió' : 'canceló tarde';
        $appointment->loadMissing('service');

        DB::table('coupon_redemptions')->where('sale_id', $sale->id)->get()->each(function ($redemption) {
            DB::table('coupons')->where('id', $redemption->coupon_id)->where('used_count', '>', 0)->decrement('used_count');
            DB::table('coupon_redemptions')->where('id', $redemption->id)->delete();
        });

        DB::table('sale_items')->where('sale_id', $sale->id)->delete();
        DB::table('sale_items')->insert([
            'id'              => (string) Str::uuid(),
            'tenant_id'       => $tenant->id,
            'sale_id'         => $sale->id,
            'item_type'       => 'deposit',
            'reference_id'    => $appointment->service_id,
            'description'     => mb_substr('Adelanto retenido: ' . ($appointment->service?->name ?? 'servicio') . " (cita {$appointment->code}, {$why})", 0, 255),
            'quantity'        => 1,
            'unit_price'      => $deposit,
            'discount'        => 0,
            'total'           => $deposit,
            'professional_id' => $appointment->professional_id,
            'appointment_id'  => $appointment->id,
        ]);

        DB::table('sales')->where('id', $sale->id)->update([
            'subtotal'        => $totals['subtotal'],
            'discount'        => 0,
            'tax'             => $totals['tax'],
            'total'           => $deposit,
            'paid'            => $deposit,
            'balance'         => 0,
            'status'          => 'paid',
            'paid_at'         => now(),
            'deposit_outcome' => 'retained',
            'updated_at'      => now(),
        ]);

        $sale = DB::table('sales')->where('id', $sale->id)->first();
        Invoicing::issue($tenant, $sale, Invoicing::validated($tenant, ['doc_type' => Invoicing::FACTURA === Invoicing::defaultType($tenant) ? Invoicing::types($tenant)[0] : Invoicing::defaultType($tenant)]), null);
    }

    public static function refundThroughGateway(Tenant $tenant, object $sale, float $amount): array
    {
        $available = self::gatewayPaid($sale->id);

        if ($available + 0.001 < $amount) {
            throw ValidationException::withMessages(['amount' => 'Por la pasarela solo se puede devolver ' . Currency::format($available, $sale->currency ?? null) . ', que es lo que se pagó por ella.']);
        }

        $payment = DB::table('payments')->where('sale_id', $sale->id)->whereIn('method', self::GATEWAY_METHODS)->where('status', 'confirmed')->orderByDesc('created_at')->first();
        $result = self::provider($tenant)->refund($payment, $amount);

        if (!$result['ok']) {
            throw ValidationException::withMessages(['amount' => 'La pasarela no aceptó la devolución: ' . ($result['message'] ?? 'inténtalo de nuevo.')]);
        }

        return $result;
    }

    public static function afterRefund(object $sale): void
    {
        if (!self::ready()) {
            return;
        }

        $fresh = DB::table('sales')->where('id', $sale->id)->first();

        if ($fresh && $fresh->deposit_outcome === 'refund_due' && (float) $fresh->paid <= 0.001) {
            DB::table('sales')->where('id', $sale->id)->update([
                'status' => 'void', 'balance' => 0, 'deposit_outcome' => 'refunded',
                'void_reason' => 'La cita se canceló a tiempo: se devolvió el adelanto.', 'updated_at' => now(),
            ]);
        }
    }

    public static function forCustomer(string $tenantId, string $customerId): array
    {
        if (!self::ready()) {
            return [];
        }

        self::expireDue($tenantId);

        return DB::table('payment_links')
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($link) => self::present($link) + ['token' => $link->token])
            ->values()
            ->all();
    }
}
