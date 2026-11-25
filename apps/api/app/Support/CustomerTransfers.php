<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerTransfers
{
    public const METHODS = ['transfer' => 'Transferencia', 'yape' => 'Yape', 'plin' => 'Plin'];

    public const MAX_VOUCHER = 5 * 1024 * 1024;

    public const DEFAULT_HOLD_HOURS = 24;

    public const NOT_READY = 'Falta actualizar la base de datos para recibir comprobantes: ejecuta php artisan migrate.';

    private static array $loaded = [];

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= PaymentLinks::ready() && Schema::hasTable('payment_vouchers') && Schema::hasColumn('payment_links', 'held_until');
    }

    public static function held(string $tenantId, string $branchId, Carbon $from, Carbon $to, ?string $professionalId = null): \Illuminate\Support\Collection
    {
        if (!PaymentLinks::holdReady()) {
            return collect();
        }

        $links = DB::table('payment_links')
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('purpose', 'deposit')
            ->where(fn ($q) => $q->where('status', 'review')
                ->orWhere(fn ($q) => $q->where('status', 'pending')->where('expires_at', '>', now())))
            ->where('held_until', '>', now())
            ->whereNotNull('professional_id')
            ->when($professionalId, fn ($q, $id) => $q->where('professional_id', $id))
            ->where('starts_at', '<', $to->toIso8601String())
            ->where('ends_at', '>', $from->toIso8601String())
            ->get(['professional_id', 'customer_id', 'starts_at', 'ends_at']);

        $customers = DB::table('customers')->whereIn('id', $links->pluck('customer_id')->filter()->unique())->get(['id', 'first_name', 'last_name'])->keyBy('id');

        return $links->map(fn ($link) => (object) [
            'professional_id' => $link->professional_id,
            'starts_at'       => $link->starts_at,
            'ends_at'         => $link->ends_at,
            'reason'          => isset($customers[$link->customer_id]) ? Person::name($customers[$link->customer_id]) : null,
        ]);
    }

    public static function forget(): void
    {
        self::$loaded = [];
    }

    private static function row(string $tenantId): ?object
    {
        if (!array_key_exists($tenantId, self::$loaded)) {
            self::$loaded[$tenantId] = Schema::hasTable('tenant_integrations')
                ? DB::table('tenant_integrations')->where('tenant_id', $tenantId)->where('kind', 'transfer')->first()
                : null;
        }

        return self::$loaded[$tenantId];
    }

    public static function config(string $tenantId): array
    {
        $row = self::row($tenantId);
        $settings = $row ? (json_decode($row->settings ?? '{}', true) ?: []) : [];

        return [
            'enabled'     => (bool) ($settings['enabled'] ?? false),
            'accounts'    => array_values($settings['accounts'] ?? []),
            'yape_phone'  => $settings['yape_phone'] ?? null,
            'plin_phone'  => $settings['plin_phone'] ?? null,
            'hold_hours'  => (int) ($settings['hold_hours'] ?? self::DEFAULT_HOLD_HOURS),
            'review_text' => $settings['review_text'] ?? 'en unas horas',
        ];
    }

    public static function methods(string $tenantId): array
    {
        $config = self::config($tenantId);
        $qr = Schema::hasTable('payment_qr_codes') ? DB::table('payment_qr_codes')->where('tenant_id', $tenantId)->get()->keyBy('method') : collect();
        $methods = [];

        foreach ($config['accounts'] as $account) {
            $methods[] = ['id' => $account['id'], 'method' => 'transfer'] + $account;
        }

        foreach (['yape', 'plin'] as $method) {
            $phone = $config["{$method}_phone"];
            $code = $qr->get($method);

            if ($phone || $code) {
                $methods[] = array_filter([
                    'id'     => $method,
                    'method' => $method,
                    'phone'  => $phone,
                    'holder' => $code?->holder,
                    'qr'     => $code?->image,
                ], fn ($value) => $value !== null);
            }
        }

        return $methods;
    }

    public static function enabled(string $tenantId): bool
    {
        return self::ready() && self::config($tenantId)['enabled'] && count(self::methods($tenantId)) > 0;
    }

    public static function label(array $method): string
    {
        return match ($method['method']) {
            'transfer' => trim(($method['bank'] ?? 'Banco') . (!empty($method['account_number']) ? ' · ' . $method['account_number'] : '')),
            default    => self::METHODS[$method['method']] . (!empty($method['phone']) ? ' · ' . $method['phone'] : ''),
        };
    }

    public static function save(string $tenantId, array $values): array
    {
        $accounts = collect($values['accounts'] ?? [])->map(function ($account) {
            if (empty($account['bank']) || empty($account['account_number']) || empty($account['holder'])) {
                throw ValidationException::withMessages(['accounts' => 'Cada cuenta bancaria necesita banco, titular y número de cuenta.']);
            }

            return array_filter([
                'id'             => !empty($account['id']) && preg_match('/^[a-z0-9]{6,20}$/', $account['id']) ? $account['id'] : Str::lower(Str::random(10)),
                'bank'           => mb_substr(trim((string) $account['bank']), 0, 60),
                'holder'         => mb_substr(trim((string) $account['holder']), 0, 120),
                'account_number' => mb_substr(trim((string) $account['account_number']), 0, 40),
                'cci'            => mb_substr(trim((string) ($account['cci'] ?? '')), 0, 40) ?: null,
            ], fn ($value) => $value !== null);
        })->values()->all();

        $settings = [
            'enabled'     => (bool) ($values['enabled'] ?? false),
            'accounts'    => $accounts,
            'yape_phone'  => trim((string) ($values['yape_phone'] ?? '')) ?: null,
            'plin_phone'  => trim((string) ($values['plin_phone'] ?? '')) ?: null,
            'hold_hours'  => max(1, min(168, (int) ($values['hold_hours'] ?? self::DEFAULT_HOLD_HOURS))),
            'review_text' => mb_substr(trim((string) ($values['review_text'] ?? '')), 0, 60) ?: 'en unas horas',
        ];

        $row = self::row($tenantId);
        $data = ['provider' => 'manual', 'mode' => 'production', 'settings' => json_encode($settings), 'status' => 'active', 'updated_at' => now()];
        $row
            ? DB::table('tenant_integrations')->where('id', $row->id)->update($data)
            : DB::table('tenant_integrations')->insert($data + ['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'kind' => 'transfer', 'created_at' => now()]);

        self::forget();

        if ($settings['enabled'] && !self::methods($tenantId)) {
            throw ValidationException::withMessages(['accounts' => 'Agrega al menos una cuenta bancaria, o el número o QR de Yape o Plin, antes de encender los comprobantes.']);
        }

        return self::config($tenantId);
    }

    public static function latest(string $linkId): ?object
    {
        return self::ready() ? DB::table('payment_vouchers')->where('payment_link_id', $linkId)->orderByDesc('created_at')->first() : null;
    }

    public static function publicOptions(object $link): ?array
    {
        if (!self::enabled($link->tenant_id) || !in_array($link->status, ['pending', 'review'], true)) {
            return null;
        }

        $config = self::config($link->tenant_id);

        return [
            'methods'     => self::methods($link->tenant_id),
            'review_text' => $config['review_text'],
            'hold_hours'  => $link->purpose === 'deposit' ? $config['hold_hours'] : null,
        ];
    }

    public static function voucherView(object $link): ?array
    {
        $voucher = self::latest($link->id);

        if (!$voucher) {
            return null;
        }

        return [
            'status'     => $voucher->status,
            'reason'     => $voucher->reason,
            'method'     => $voucher->account_label,
            'sent_at'    => Carbon::parse($voucher->created_at)->toIso8601String(),
            'held_until' => $link->held_until ? Carbon::parse($link->held_until)->toIso8601String() : null,
        ];
    }

    public static function submit(string $token, array $values): object
    {
        abort_unless(self::ready(), 503, self::NOT_READY);

        return DB::transaction(function () use ($token, $values) {
            $link = DB::table('payment_links')->where('token', $token)->lockForUpdate()->first();
            abort_unless($link, 404, 'Este link de pago no existe.');

            if ($link->status === 'review') {
                throw ValidationException::withMessages(['voucher' => 'Ya enviaste un comprobante y la empresa lo está revisando.']);
            }

            if ($link->status !== 'pending' || Carbon::parse($link->expires_at)->isPast()) {
                throw ValidationException::withMessages(['voucher' => 'Este link ya no está esperando pago.']);
            }

            abort_unless(self::enabled($link->tenant_id), 422, 'Esta empresa no recibe comprobantes de transferencia.');

            $tenant = Tenant::with('settings')->findOrFail($link->tenant_id);

            if ($link->purpose === 'deposit') {
                if (Subscriptions::lockedFor($link->tenant_id)) {
                    throw ValidationException::withMessages(['voucher' => Subscriptions::PORTAL_LOCKED]);
                }

                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['appointments:' . $tenant->id]);

                if ($taken = PaymentLinks::slotTaken($tenant, $link)) {
                    PaymentLinks::cancel($link, $taken);
                    throw ValidationException::withMessages(['voucher' => $taken . ' No transfieras: tu reserva se anuló.']);
                }
            }

            $method = collect(self::methods($link->tenant_id))->firstWhere('id', $values['method_id'] ?? null);

            if (!$method) {
                throw ValidationException::withMessages(['method_id' => 'Elige a dónde pagaste.']);
            }

            if (!preg_match('#^data:(image/(png|jpe?g|webp)|application/pdf);base64,([A-Za-z0-9+/=]+)$#', (string) ($values['voucher'] ?? ''), $match)) {
                throw ValidationException::withMessages(['voucher' => 'Sube una foto (PNG, JPG o WEBP) o un PDF del comprobante.']);
            }

            $bytes = base64_decode($match[3], true);

            if ($bytes === false || strlen($bytes) > self::MAX_VOUCHER) {
                throw ValidationException::withMessages(['voucher' => 'El comprobante pesa demasiado: usa uno de menos de 5 MB (una captura basta).']);
            }

            $id = (string) Str::uuid();
            $extension = $match[1] === 'application/pdf' ? 'pdf' : ($match[2] === 'jpeg' ? 'jpg' : $match[2]);
            $path = "payment-vouchers/{$link->tenant_id}/{$id}.{$extension}";
            Storage::disk('local')->put($path, $bytes);

            DB::table('payment_vouchers')->insert([
                'id'               => $id,
                'tenant_id'        => $link->tenant_id,
                'payment_link_id'  => $link->id,
                'branch_id'        => $link->branch_id,
                'customer_id'      => $link->customer_id,
                'method'           => $method['method'],
                'account_label'    => mb_substr(self::label($method), 0, 120),
                'operation_number' => mb_substr(trim((string) $values['operation_number']), 0, 60),
                'paid_on'          => $values['paid_on'],
                'voucher_path'     => $path,
                'amount'           => $link->amount,
                'currency'         => $link->currency,
                'status'           => 'review',
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            $hold = $link->purpose === 'deposit' ? now()->addHours(self::config($link->tenant_id)['hold_hours']) : null;
            if ($hold && $link->starts_at && Carbon::parse($link->starts_at)->lt($hold)) {
                $hold = Carbon::parse($link->starts_at);
            }
            DB::table('payment_links')->where('id', $link->id)->update(['status' => 'review', 'held_until' => $hold, 'updated_at' => now()]);

            self::notifyStaff($link, $id);

            return DB::table('payment_vouchers')->where('id', $id)->first();
        });
    }

    private static function notifyStaff(object $link, string $voucherId): void
    {
        try {
            $customer = $link->customer_id ? DB::table('customers')->where('id', $link->customer_id)->first(['first_name', 'last_name']) : null;

            foreach (Notifier::staffWith($link->tenant_id, 'payments.review', $link->branch_id) as $userId) {
                Notifier::send(
                    $link->tenant_id,
                    $userId,
                    'payment.voucher_sent',
                    'Comprobante por revisar',
                    implode(' · ', array_filter([$customer ? Person::name($customer) : null, Currency::format((float) $link->amount, $link->currency), $link->description])),
                    "/payments/vouchers?id={$voucherId}",
                    'payment_voucher',
                    $voucherId,
                    "voucher:{$voucherId}",
                );
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function scoped(User $user)
    {
        $query = DB::table('payment_vouchers as v')->where('v.tenant_id', $user->tenant_id);

        if ($branch = Access::branchLimit($user)) {
            $query->where('v.branch_id', $branch);
        }

        return $query;
    }

    public static function pendingCount(User $user): int
    {
        if (!self::ready() || !$user->tenant_id || !$user->allows('payments.review')) {
            return 0;
        }

        return self::scoped($user)->where('v.status', 'review')
            ->whereExists(fn ($q) => $q->from('payment_links')->whereColumn('payment_links.id', 'v.payment_link_id')->where('payment_links.status', 'review'))
            ->count();
    }

    public static function approve(string $id, User $reviewer): object
    {
        return DB::transaction(function () use ($id, $reviewer) {
            $voucher = self::scoped($reviewer)->where('v.id', $id)->lockForUpdate()->first(['v.*']);
            abort_unless($voucher, 404);
            abort_unless($voucher->status === 'review', 422, 'Este comprobante ya fue revisado.');

            $link = DB::table('payment_links')->where('id', $voucher->payment_link_id)->lockForUpdate()->first();

            if (!$link || $link->status !== 'review') {
                throw ValidationException::withMessages(['voucher' => 'El pago de este comprobante ya no está esperando revisión (se anuló o se pagó por otro medio). Recházalo y, si el cliente transfirió, devuélvele el dinero.']);
            }

            $tenant = Tenant::with(['settings', 'activeSubscription.plan'])->findOrFail($link->tenant_id);
            $result = PaymentLinks::confirmVoucher($tenant, $link, $voucher);

            if ($result['status'] !== 'paid') {
                throw ValidationException::withMessages(['voucher' => $result['message']]);
            }

            DB::table('payment_vouchers')->where('id', $voucher->id)->update([
                'status' => 'approved', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'reason' => null, 'updated_at' => now(),
            ]);

            return DB::table('payment_vouchers')->where('id', $voucher->id)->first();
        });
    }

    public static function reject(string $id, User $reviewer, string $reason): object
    {
        return DB::transaction(function () use ($id, $reviewer, $reason) {
            $voucher = self::scoped($reviewer)->where('v.id', $id)->lockForUpdate()->first(['v.*']);
            abort_unless($voucher, 404);
            abort_unless($voucher->status === 'review', 422, 'Este comprobante ya fue revisado.');

            DB::table('payment_vouchers')->where('id', $voucher->id)->update([
                'status' => 'rejected', 'reason' => mb_substr($reason, 0, 255), 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'updated_at' => now(),
            ]);

            $link = DB::table('payment_links')->where('id', $voucher->payment_link_id)->first();

            if ($link && $link->status === 'review') {
                $tenant = Tenant::with('settings')->find($link->tenant_id);
                $expires = $link->purpose === 'deposit'
                    ? now()->addMinutes(max(30, (int) AppointmentFlow::rule($tenant, 'booking.deposit_minutes')))
                    : now()->addHours(max(1, (int) AppointmentFlow::rule($tenant, 'payments.link_hours')));
                DB::table('payment_links')->where('id', $link->id)->update(['status' => 'pending', 'held_until' => null, 'expires_at' => $expires, 'updated_at' => now()]);
            }

            return DB::table('payment_vouchers')->where('id', $voucher->id)->first();
        });
    }

    public static function present(object $row): array
    {
        $link = DB::table('payment_links')->where('id', $row->payment_link_id)->first();
        $customer = $row->customer_id ? DB::table('customers')->where('id', $row->customer_id)->first(['id', 'first_name', 'last_name', 'phone', 'email']) : null;
        $reviewer = $row->reviewed_by ? DB::table('users')->where('id', $row->reviewed_by)->first(['first_name', 'last_name']) : null;
        $branch = $row->branch_id ? DB::table('branches')->where('id', $row->branch_id)->value('name') : null;
        $sale = $link?->sale_id ? DB::table('sales')->where('id', $link->sale_id)->first(['id', 'code']) : null;

        return [
            'id'               => $row->id,
            'status'           => $row->status,
            'amount'           => round((float) $row->amount, 2),
            'currency'         => $row->currency,
            'method'           => $row->method,
            'method_label'     => self::METHODS[$row->method] ?? $row->method,
            'account'          => $row->account_label,
            'operation_number' => $row->operation_number,
            'paid_on'          => Carbon::parse($row->paid_on)->format('Y-m-d'),
            'reason'           => $row->reason,
            'sent_at'          => Carbon::parse($row->created_at)->toIso8601String(),
            'reviewed_at'      => $row->reviewed_at ? Carbon::parse($row->reviewed_at)->toIso8601String() : null,
            'reviewer'         => $reviewer ? trim("{$reviewer->first_name} {$reviewer->last_name}") : null,
            'branch'           => $branch,
            'customer'         => $customer ? ['id' => $customer->id, 'name' => Person::name($customer), 'phone' => $customer->phone, 'email' => $customer->email] : null,
            'purpose'          => $link?->purpose,
            'purpose_label'    => match (true) {
                $link?->purpose === 'deposit' => 'Adelanto de una reserva',
                $link && $link->sale_id && DB::table('sales')->where('id', $link->sale_id)->value('notes') === Packages::ONLINE_NOTE => 'Paquete comprado en línea',
                default => 'Link de pago',
            },
            'description'      => $link?->description,
            'link_status'      => $link?->status,
            'held_until'       => $link?->held_until ? Carbon::parse($link->held_until)->toIso8601String() : null,
            'sale'             => $sale ? ['id' => $sale->id, 'code' => $sale->code] : null,
            'appointment'      => $link?->appointment_id ? DB::table('appointments')->where('id', $link->appointment_id)->value('code') : null,
            'voucher_type'     => str_ends_with($row->voucher_path, '.pdf') ? 'pdf' : 'image',
        ];
    }

    public static function file(object $row)
    {
        abort_unless($row->voucher_path && Storage::disk('local')->exists($row->voucher_path), 404, 'No encontramos el comprobante.');

        return Storage::disk('local')->response($row->voucher_path, null, ['Cache-Control' => 'private, no-store']);
    }
}
