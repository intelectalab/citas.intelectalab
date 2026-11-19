<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Penalties
{
    public const NO_SHOW = 'no_show';
    public const LATE_CANCEL = 'late_cancel';

    public const LABELS = [
        self::NO_SHOW     => 'Penalidad por no asistir',
        self::LATE_CANCEL => 'Penalidad por cancelar a última hora',
    ];

    public static function rules(Tenant $tenant): array
    {
        return [
            'enabled'             => (bool) AppointmentFlow::rule($tenant, 'penalties.enabled'),
            'no_show_percent'     => max(0, min(100, (float) AppointmentFlow::rule($tenant, 'noshow.fee_percent'))),
            'late_cancel_percent' => max(0, min(100, (float) AppointmentFlow::rule($tenant, 'cancel.late_fee_percent'))),
            'window_hours'        => (int) AppointmentFlow::rule($tenant, 'cancel.window_hours'),
        ];
    }

    public static function enabled(Tenant $tenant): bool
    {
        return Sales::ready() && $tenant->hasModule('pos') && self::rules($tenant)['enabled'];
    }

    public static function percent(Tenant $tenant, string $kind): float
    {
        $rules = self::rules($tenant);

        return $kind === self::NO_SHOW ? $rules['no_show_percent'] : $rules['late_cancel_percent'];
    }

    public static function retainedDeposit(Tenant $tenant, Appointment $appointment): float
    {
        $sale = Sales::ofAppointment($tenant->id, $appointment->id);

        if (!$sale || !PaymentLinks::ready()) {
            return 0.0;
        }

        return ($sale->deposit_outcome ?? null) === 'refund_due' ? 0.0 : PaymentLinks::depositPaid($sale->id);
    }

    public static function amount(Tenant $tenant, Appointment $appointment, string $kind): float
    {
        if (!self::enabled($tenant) || $appointment->customer_package_id || !$appointment->customer_id) {
            return 0.0;
        }

        $percent = self::percent($tenant, $kind);
        $price = (float) $appointment->price;

        if ($percent <= 0 || $price <= 0) {
            return 0.0;
        }

        return max(0, round($price * $percent / 100 - self::retainedDeposit($tenant, $appointment), 2));
    }

    public static function insideWindow(Tenant $tenant, Appointment $appointment, ?Carbon $now = null): bool
    {
        $now ??= now();

        return $now->lt($appointment->starts_at)
            && $now->diffInMinutes($appointment->starts_at) < 60 * self::rules($tenant)['window_hours'];
    }

    public static function preview(Tenant $tenant, Appointment $appointment): ?array
    {
        if (!self::enabled($tenant) || !in_array($appointment->status, AppointmentFlow::RESCHEDULABLE, true)) {
            return null;
        }

        $late = self::insideWindow($tenant, $appointment) ? self::amount($tenant, $appointment, self::LATE_CANCEL) : 0.0;
        $noShow = self::amount($tenant, $appointment, self::NO_SHOW);

        if ($late <= 0 && $noShow <= 0) {
            return null;
        }

        return [
            'late_cancel'         => $late > 0 ? $late : null,
            'late_cancel_percent' => self::percent($tenant, self::LATE_CANCEL),
            'no_show'             => $noShow > 0 ? $noShow : null,
            'no_show_percent'     => self::percent($tenant, self::NO_SHOW),
            'window_hours'        => self::rules($tenant)['window_hours'],
            'currency'            => $appointment->currency ?? Currency::of($tenant),
        ];
    }

    public static function find(string $tenantId, string $appointmentId): ?object
    {
        if (!Sales::ready()) {
            return null;
        }

        return DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', DB::table('sale_items')->where('tenant_id', $tenantId)->where('item_type', 'penalty')->where('appointment_id', $appointmentId)->select('sale_id'))
            ->orderByDesc('created_at')
            ->first();
    }

    public static function apply(Tenant $tenant, Appointment $appointment, string $kind, ?User $user): ?object
    {
        $amount = self::amount($tenant, $appointment, $kind);

        if ($amount <= 0 || self::find($tenant->id, $appointment->id)) {
            return null;
        }

        $rules = Sales::rules($tenant);
        $totals = Sales::totals($rules, $amount);
        $id = (string) Str::uuid();
        $percent = self::percent($tenant, $kind);

        DB::table('sales')->insert([
            'id'                 => $id,
            'tenant_id'          => $tenant->id,
            'branch_id'          => $appointment->branch_id,
            'customer_id'        => $appointment->customer_id,
            'code'               => Codes::next($tenant->id, 'sale'),
            'status'             => 'pending',
            'subtotal'           => $totals['subtotal'],
            'discount'           => 0,
            'tax'                => $totals['tax'],
            'total'              => $totals['total'],
            'paid'               => 0,
            'balance'            => $totals['total'],
            'tax_rate'           => $rules['tax_rate'],
            'prices_include_tax' => $rules['prices_include_tax'],
            'notes'              => self::LABELS[$kind] . ' de la cita ' . $appointment->code,
            'created_by'         => $user?->id,
            'created_at'         => now(),
            'updated_at'         => now(),
        ] + Currency::column($tenant));

        DB::table('sale_items')->insert([
            'id'             => (string) Str::uuid(),
            'tenant_id'      => $tenant->id,
            'sale_id'        => $id,
            'item_type'      => 'penalty',
            'reference_id'   => $appointment->service_id,
            'description'    => self::LABELS[$kind] . " ({$appointment->code}, " . rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.') . ' %)',
            'quantity'       => 1,
            'unit_price'     => $amount,
            'discount'       => 0,
            'total'          => $amount,
            'appointment_id' => $appointment->id,
            'details'        => json_encode(['penalty' => $kind, 'percent' => $percent, 'deposit_retained' => self::retainedDeposit($tenant, $appointment)]),
        ]);

        Audit::log(request(), 'sale.penalty', [
            'tenant'       => $tenant->id,
            'entity_type'  => 'sale',
            'entity_id'    => $id,
            'entity_label' => $appointment->code,
            'branch_id'    => $appointment->branch_id,
            'after'        => ['kind' => self::LABELS[$kind], 'amount' => $totals['total'], 'appointment' => $appointment->code],
        ]);

        return DB::table('sales')->where('id', $id)->first();
    }

    public static function present(?object $sale): ?array
    {
        if (!$sale) {
            return null;
        }

        $item = DB::table('sale_items')->where('sale_id', $sale->id)->where('item_type', 'penalty')->first();
        $details = $item?->details ? json_decode($item->details, true) : [];

        return [
            'sale_id'  => $sale->id,
            'code'     => $sale->code,
            'kind'     => $details['penalty'] ?? null,
            'label'    => self::LABELS[$details['penalty'] ?? ''] ?? 'Penalidad',
            'total'    => round((float) $sale->total, 2),
            'balance'  => round((float) $sale->balance, 2),
            'status'   => $sale->status,
            'currency' => $sale->currency ?? null,
        ];
    }
}
