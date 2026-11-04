<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Quotes
{
    public const NOT_READY = 'Falta actualizar la base de datos para usar presupuestos: ejecuta php artisan migrate.';

    public const STATUSES = [
        'draft'     => 'Borrador',
        'sent'      => 'Enviado',
        'accepted'  => 'Aprobado',
        'rejected'  => 'Rechazado',
        'expired'   => 'Vencido',
        'cancelled' => 'Anulado',
    ];

    public const ITEM_TYPES = [
        'service' => 'Servicio',
        'product' => 'Producto',
        'package' => 'Paquete',
        'other'   => 'Concepto libre',
    ];

    public const CANCEL_POLICIES = [
        'unused_quote'  => 'Se cobra lo usado al precio del presupuesto y se devuelve el resto',
        'unused_normal' => 'Se cobra lo usado a precio normal y se devuelve el resto',
        'none'          => 'No se devuelve dinero: lo pagado se queda y se anulan las cuotas que faltan',
    ];

    public const PACKAGE_REFUND = ['unused_quote' => 'unused_package', 'unused_normal' => 'unused_normal', 'none' => 'none'];

    public const INSTALLMENT_STATUSES = [
        'pending'   => 'Pendiente',
        'paid'      => 'Pagada',
        'overdue'   => 'Vencida',
        'cancelled' => 'Anulada',
    ];

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('quotes')
            && Schema::hasTable('quote_items')
            && Schema::hasTable('quote_installments')
            && Schema::hasTable('quote_status_history');
    }

    public static function enabled(Tenant $tenant): bool
    {
        return $tenant->hasModule('quotes') && self::ready();
    }

    public static function rules(Tenant $tenant): array
    {
        return [
            'validity_days'        => max(0, min(3650, (int) AppointmentFlow::rule($tenant, 'quotes.validity_days'))),
            'allow_installments'   => (bool) AppointmentFlow::rule($tenant, 'quotes.allow_installments'),
            'auto_create_sale'     => (bool) AppointmentFlow::rule($tenant, 'quotes.auto_create_sale'),
            'track_clinical_zones' => (bool) AppointmentFlow::rule($tenant, 'quotes.track_clinical_zones'),
            'plan_validity_days'   => max(1, min(3650, (int) AppointmentFlow::rule($tenant, 'quotes.plan_validity_days'))),
            'portal_accept'        => (bool) AppointmentFlow::rule($tenant, 'quotes.portal_accept'),
            'cancel_policy'        => in_array($policy = (string) AppointmentFlow::rule($tenant, 'quotes.cancel_policy'), array_keys(self::CANCEL_POLICIES), true) ? $policy : 'unused_quote',
        ];
    }

    public static function nextCode(string $tenantId): string
    {
        return Codes::next($tenantId, 'quote');
    }

    public static function expireDue(string $tenantId): void
    {
        if (!self::ready()) {
            return;
        }

        $today = self::today($tenantId);

        $dueQuotes = DB::table('quotes')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['draft', 'sent'])
            ->whereNotNull('valid_until')
            ->where('valid_until', '<', $today)
            ->get(['id', 'status']);

        foreach ($dueQuotes as $q) {
            DB::table('quotes')->where('id', $q->id)->update([
                'status'     => 'expired',
                'updated_at' => now(),
            ]);

            DB::table('quote_status_history')->insert([
                'id'            => (string) Str::uuid(),
                'tenant_id'     => $tenantId,
                'quote_id'      => $q->id,
                'from_status'   => $q->status,
                'to_status'     => 'expired',
                'actor_user_id' => null,
                'note'          => 'Venció el plazo de validez del presupuesto.',
                'created_at'    => now(),
            ]);
        }

        DB::table('quote_installments')
            ->where('tenant_id', $tenantId)
            ->where('status', 'pending')
            ->where('due_date', '<', $today)
            ->update(['status' => 'overdue']);
    }

    public static function today(string $tenantId): string
    {
        $zone = DB::table('tenants')->where('id', $tenantId)->value('timezone') ?: 'America/Lima';

        return Carbon::now($zone)->toDateString();
    }

    public static function approvalBlocker(object $quote): ?string
    {
        if (!in_array($quote->status, ['draft', 'sent'], true)) {
            return match ($quote->status) {
                'accepted'  => "El presupuesto {$quote->code} ya está aprobado.",
                'expired'   => "El presupuesto {$quote->code} venció: vuelve a emitirlo con una nueva fecha de validez.",
                'rejected'  => "El presupuesto {$quote->code} fue rechazado: reábrelo como borrador para aprobarlo.",
                default     => "El presupuesto {$quote->code} está anulado: crea uno nuevo.",
            };
        }

        if ($quote->valid_until && (string) $quote->valid_until < self::today($quote->tenant_id)) {
            return "El presupuesto {$quote->code} venció el " . Carbon::parse($quote->valid_until)->format('d/m/Y') . ': vuelve a emitirlo con una nueva fecha de validez.';
        }

        return null;
    }

    public static function approve(Tenant $tenant, object $quote, ?User $user, string $via, bool $createSale, ?string $note = null, ?string $customerId = null): object
    {
        if ($blocker = self::approvalBlocker($quote)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['status' => $blocker]);
        }

        DB::table('quotes')->where('id', $quote->id)->update([
            'status'       => 'accepted',
            'accepted_at'  => now(),
            'accepted_via' => $via,
            'updated_at'   => now(),
        ]);

        DB::table('quote_status_history')->insert([
            'id'            => (string) Str::uuid(),
            'tenant_id'     => $tenant->id,
            'quote_id'      => $quote->id,
            'from_status'   => $quote->status,
            'to_status'     => 'accepted',
            'actor_user_id' => $user?->id,
            'note'          => $note ?: ($via === 'portal' ? 'El cliente aprobó el presupuesto desde el portal.' : 'Presupuesto aprobado por el cliente.'),
            'created_at'    => now(),
        ]);

        $fresh = DB::table('quotes')->where('id', $quote->id)->first();

        if ($createSale && Sales::ready()) {
            self::ensureSale($tenant, $fresh, $user);
            $fresh = DB::table('quotes')->where('id', $quote->id)->first();
        }

        if ($fresh->sale_id || !Sales::ready()) {
            self::createPlan($tenant, $fresh, $user);
        }

        return DB::table('quotes')->where('id', $quote->id)->first();
    }

    public static function syncInstallmentsWithSale(string $tenantId, object $quote): void
    {
        if (!$quote->sale_id) {
            return;
        }

        $sale = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->where('id', $quote->sale_id)
            ->first(['id', 'paid', 'total', 'status']);

        if (!$sale || $sale->status === 'void') {
            return;
        }

        $installments = DB::table('quote_installments')
            ->where('tenant_id', $tenantId)
            ->where('quote_id', $quote->id)
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('number')
            ->get();

        $outside = max(0, round((float) $sale->total - (float) $installments->sum('amount'), 2));
        $paidAmount = round((float) $sale->paid - $outside, 2);

        $today = self::today($tenantId);
        $accumulated = 0.0;

        foreach ($installments as $inst) {
            $accumulated = round($accumulated + (float) $inst->amount, 2);
            $covered = $paidAmount > 0 && $paidAmount + 0.009 >= $accumulated;

            if ($covered && $inst->status !== 'paid') {
                DB::table('quote_installments')->where('id', $inst->id)->update([
                    'status'  => 'paid',
                    'paid_at' => now(),
                ]);
            }

            if (!$covered && $inst->status === 'paid') {
                DB::table('quote_installments')->where('id', $inst->id)->update([
                    'status'  => (string) $inst->due_date < $today ? 'overdue' : 'pending',
                    'paid_at' => null,
                ]);
            }
        }
    }

    public static function ensureSale(Tenant $tenant, object $quote, ?User $user = null): object
    {
        if ($quote->sale_id) {
            $existing = DB::table('sales')
                ->where('tenant_id', $tenant->id)
                ->where('id', $quote->sale_id)
                ->where('status', '!=', 'void')
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $branchId = $quote->branch_id
            ?: DB::table('branches')->where('tenant_id', $tenant->id)->where('status', 'active')->orderBy('created_at')->value('id');

        $salesRules = Sales::rules($tenant);
        $saleId = (string) Str::uuid();
        $total = round((float) $quote->total, 2);

        DB::table('sales')->insert([
            'id'                 => $saleId,
            'tenant_id'          => $tenant->id,
            'branch_id'          => $branchId,
            'customer_id'        => $quote->customer_id,
            'appointment_id'     => null,
            'code'               => Codes::next($tenant->id, 'sale'),
            'status'             => 'pending',
            'subtotal'           => round((float) $quote->subtotal, 2),
            'discount'           => round((float) $quote->discount, 2),
            'tax'                => round((float) $quote->tax, 2),
            'total'              => $total,
            'paid'               => 0,
            'balance'            => $total,
            'tax_rate'           => $salesRules['tax_rate'],
            'prices_include_tax' => $salesRules['prices_include_tax'],
            'created_by'         => $user?->id,
            'created_at'         => now(),
            'updated_at'         => now(),
        ] + Currency::column($tenant));

        $items = DB::table('quote_items')
            ->where('tenant_id', $tenant->id)
            ->where('quote_id', $quote->id)
            ->orderBy('sort_order')
            ->get();

        $hasPosition = Schema::hasColumn('sale_items', 'position');

        foreach ($items as $idx => $item) {
            $saleItemType = in_array($item->item_type, ['service', 'product', 'package'], true)
                ? $item->item_type
                : 'service';

            $row = [
                'id'              => (string) Str::uuid(),
                'tenant_id'       => $tenant->id,
                'sale_id'         => $saleId,
                'item_type'       => $saleItemType,
                'reference_id'    => $item->reference_id,
                'description'     => $item->description,
                'quantity'        => max(1, (int) round((float) $item->quantity)),
                'unit_price'      => round((float) $item->unit_price, 2),
                'discount'        => round((float) $item->discount, 2),
                'total'           => round((float) $item->total, 2),
                'professional_id' => $quote->professional_id,
                'appointment_id'  => null,
            ];

            if ($hasPosition) {
                $row['position'] = $idx;
            }

            DB::table('sale_items')->insert($row);
        }

        DB::table('quotes')->where('id', $quote->id)->update([
            'sale_id'    => $saleId,
            'updated_at' => now(),
        ]);

        $sale = DB::table('sales')->where('id', $saleId)->first();
        self::attachSale($quote, $sale);

        return $sale;
    }

    public static function plansReady(): bool
    {
        static $ready = null;

        return $ready ??= self::ready() && Packages::ready() && Schema::hasColumn('customer_packages', 'quote_id');
    }

    public static function planOf(string $quoteId): ?object
    {
        if (!self::plansReady()) {
            return null;
        }

        return DB::table('customer_packages')->where('quote_id', $quoteId)->orderByDesc('created_at')->first();
    }

    private static function sessionLines(Tenant $tenant, object $quote): array
    {
        $items = DB::table('quote_items')->where('tenant_id', $tenant->id)->where('quote_id', $quote->id)->orderBy('sort_order')->get();
        $lines = [];

        foreach ($items as $item) {
            if (!$item->reference_id || (int) $item->sessions_total <= 0) {
                continue;
            }

            if ($item->item_type === 'service') {
                $lines[] = ['item' => $item, 'parts' => [['service_id' => $item->reference_id, 'sessions' => (int) $item->sessions_total]]];
            }

            $meta = is_string($item->metadata ?? null) ? (json_decode($item->metadata, true) ?: []) : (array) ($item->metadata ?? []);

            if ($item->item_type === 'package' && !empty($meta['parts'])) {
                $lines[] = ['item' => $item, 'parts' => $meta['parts']];
                continue;
            }

            if ($item->item_type === 'package') {
                $times = max(1, (int) round((float) $item->quantity));
                $parts = (Packages::planItems([$item->reference_id])[$item->reference_id] ?? collect())
                    ->map(fn ($p) => ['service_id' => $p->service_id, 'sessions' => (int) $p->sessions * $times])
                    ->filter(fn ($p) => $p['sessions'] > 0)
                    ->values()->all();

                if ($parts) {
                    $lines[] = ['item' => $item, 'parts' => $parts];
                }
            }
        }

        return $lines;
    }

    public static function createPlan(Tenant $tenant, object $quote, ?User $user): ?object
    {
        if (!self::plansReady() || ($existing = self::planOf($quote->id))) {
            if ($existing && !$existing->sale_id && $quote->sale_id) {
                DB::table('customer_packages')->where('id', $existing->id)->update(['sale_id' => $quote->sale_id, 'updated_at' => now()]);
            }

            return $existing ?? null;
        }

        $lines = self::sessionLines($tenant, $quote);

        if (!$lines) {
            return null;
        }

        foreach ($lines as $line) {
            if ($line['item']->item_type === 'package') {
                $meta = is_string($line['item']->metadata ?? null) ? (json_decode($line['item']->metadata, true) ?: []) : (array) ($line['item']->metadata ?? []);
                DB::table('quote_items')->where('id', $line['item']->id)->update(['metadata' => json_encode(['parts' => $line['parts']] + $meta)]);
            }
        }

        $allLines = (float) DB::table('quote_items')->where('quote_id', $quote->id)->sum('total');
        $sessionsValue = (float) collect($lines)->sum(fn ($l) => (float) $l['item']->total);
        $planPrice = $allLines > 0 ? round((float) $quote->total * $sessionsValue / $allLines, 2) : round((float) $quote->total, 2);

        $byService = [];

        foreach ($lines as $line) {
            $lineSessions = array_sum(array_column($line['parts'], 'sessions'));
            $perSession = $lineSessions > 0 ? (float) $line['item']->total / $lineSessions : 0.0;
            $usedLeft = min((int) $line['item']->sessions_used, $lineSessions);

            foreach ($line['parts'] as $part) {
                $service = $part['service_id'];
                $used = min($usedLeft, $part['sessions']);
                $usedLeft -= $used;
                $byService[$service] ??= ['sessions' => 0, 'value' => 0.0, 'used' => 0];
                $byService[$service]['sessions'] += $part['sessions'];
                $byService[$service]['value'] += $perSession * $part['sessions'];
                $byService[$service]['used'] += $used;
            }
        }

        $rules = self::rules($tenant);
        $currency = Currency::of($tenant);
        $purchased = $quote->accepted_at ? Carbon::parse($quote->accepted_at) : now();
        $names = DB::table('services')->whereIn('id', array_keys($byService))->pluck('name', 'id');
        $conditions = [
            'validity_days'     => $rules['plan_validity_days'],
            'booking_mode'      => 'free',
            'frequency'         => 'weekly',
            'custom_days'       => [],
            'repeat_weeks'      => 1,
            'professional_mode' => 'chosen',
            'late_policy'       => 'reschedule',
            'refund_policy'     => self::PACKAGE_REFUND[$rules['cancel_policy']],
            'terms'             => null,
        ];
        $summary = collect($byService)->map(fn ($s, $id) => Packages::sessions($s['sessions']) . ' de ' . ($names[$id] ?? 'servicio'))->implode(', ');
        $conditions['terms_text'] = "Plan del presupuesto {$quote->code}: {$summary}, válido {$rules['plan_validity_days']} días desde que se aprobó. Si no puedes venir, la sesión se reprograma. Si se anula, se aplica la regla de anulación de presupuestos de la empresa.";

        $id = (string) Str::uuid();

        DB::table('customer_packages')->insert([
            'id'              => $id,
            'tenant_id'       => $tenant->id,
            'code'            => Codes::next($tenant->id, 'treatment_plan'),
            'package_plan_id' => null,
            'customer_id'     => $quote->customer_id,
            'branch_id'       => $quote->branch_id,
            'sale_id'         => $quote->sale_id,
            'quote_id'        => $quote->id,
            'name'            => "Plan de tratamiento {$quote->code}",
            'price'           => $planPrice,
            'currency'        => $currency,
            'conditions'      => json_encode($conditions),
            'professional_id' => $quote->professional_id,
            'status'          => 'active',
            'purchased_at'    => $purchased,
            'expires_at'      => Packages::expiresFrom($tenant, $purchased, $rules['plan_validity_days']),
            'created_by'      => $user?->id ?? $quote->created_by,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $position = 0;

        foreach ($byService as $serviceId => $s) {
            DB::table('customer_package_items')->insert([
                'id'                  => (string) Str::uuid(),
                'tenant_id'           => $tenant->id,
                'customer_package_id' => $id,
                'service_id'          => $serviceId,
                'sessions_total'      => $s['sessions'],
                'sessions_used'       => min($s['used'], $s['sessions']),
                'unit_price'          => $s['sessions'] > 0 ? round($s['value'] / $s['sessions'], 2) : 0,
                'position'            => $position++,
            ]);
        }

        if (collect($byService)->every(fn ($s) => $s['used'] >= $s['sessions'])) {
            DB::table('customer_packages')->where('id', $id)->update(['status' => 'consumed']);
        }

        DB::table('customer_package_events')->insert([
            'id'                  => (string) Str::uuid(),
            'tenant_id'           => $tenant->id,
            'customer_package_id' => $id,
            'kind'                => 'sold',
            'detail'              => "Se creó al aprobar el presupuesto {$quote->code}.",
            'amount'              => round((float) $quote->total, 2),
            'created_by'          => $user?->id,
            'created_at'          => now(),
        ]);

        return DB::table('customer_packages')->where('id', $id)->first();
    }

    public static function attachSale(object $quote, object $sale): void
    {
        if (self::plansReady()) {
            DB::table('customer_packages')->where('quote_id', $quote->id)->update(['sale_id' => $sale->id, 'updated_at' => now()]);
        }
    }

    public static function syncPlanUsage(Tenant $tenant, string $quoteId): void
    {
        $plan = self::planOf($quoteId);
        $quote = DB::table('quotes')->where('id', $quoteId)->first();

        if (!$plan || !$quote) {
            return;
        }

        $used = DB::table('customer_package_items')->where('customer_package_id', $plan->id)->pluck('sessions_used', 'service_id')->map(fn ($v) => (int) $v)->all();

        foreach (self::sessionLines($tenant, $quote) as $line) {
            $lineUsed = 0;

            foreach ($line['parts'] as $part) {
                $take = min($used[$part['service_id']] ?? 0, $part['sessions']);
                $used[$part['service_id']] = ($used[$part['service_id']] ?? 0) - $take;
                $lineUsed += $take;
            }

            DB::table('quote_items')->where('id', $line['item']->id)->update(['sessions_used' => min($lineUsed, (int) $line['item']->sessions_total)]);
        }
    }

    public static function isQuoteSale(object $sale): bool
    {
        return self::ready() && DB::table('quotes')->where('sale_id', $sale->id)->exists();
    }

    public static function cancelPreview(Tenant $tenant, object $quote): array
    {
        $policy = self::rules($tenant)['cancel_policy'];
        $plan = self::planOf($quote->id);
        $sale = $quote->sale_id ? DB::table('sales')->where('id', $quote->sale_id)->first() : null;
        $sale = $sale && $sale->status !== 'void' ? $sale : null;
        $currency = $sale->currency ?? Currency::of($tenant);
        $format = fn (float $v) => Currency::format($v, $currency);

        $planUsed = $plan ? DB::table('customer_package_items')->where('customer_package_id', $plan->id)->pluck('sessions_used', 'service_id')->map(fn ($v) => (int) $v)->all() : [];
        $prices = DB::table('services')->where('tenant_id', $tenant->id)->pluck('price', 'id')->map(fn ($v) => (float) $v)->all();
        $sessionLines = collect(self::sessionLines($tenant, $quote))->keyBy(fn ($l) => $l['item']->id);
        $items = DB::table('quote_items')->where('tenant_id', $tenant->id)->where('quote_id', $quote->id)->orderBy('sort_order')->get();

        $usedValue = 0.0;
        $usedSessions = 0;
        $totalSessions = 0;
        $chargedFull = 0.0;

        foreach ($items as $item) {
            $line = $sessionLines->get($item->id);

            if (!$line) {
                $chargedFull += (float) $item->total;
                continue;
            }

            $lineSessions = array_sum(array_column($line['parts'], 'sessions'));
            $perSession = $lineSessions > 0 ? (float) $item->total / $lineSessions : 0.0;
            $totalSessions += $lineSessions;

            foreach ($line['parts'] as $part) {
                $take = min($planUsed[$part['service_id']] ?? 0, $part['sessions']);
                $planUsed[$part['service_id']] = ($planUsed[$part['service_id']] ?? 0) - $take;
                $usedSessions += $take;
                $usedValue += $take * ($policy === 'unused_normal' ? ($prices[$part['service_id']] ?? $perSession) : $perSession);
            }
        }

        $paid = round((float) ($sale->paid ?? 0), 2);
        $used = min(round((float) $quote->total, 2), round($usedValue + $chargedFull, 2));

        if ($policy === 'none') {
            $used = $paid;
        }

        $refund = max(0, round($paid - $used, 2));
        $debt = max(0, round($used - $paid, 2));
        $state = $refund > 0 && $sale ? Refunds::availability($tenant, $sale) : ['available' => 0, 'blocked' => null];
        $sessionsText = "Usó {$usedSessions} de " . Packages::sessions($totalSessions);

        $explain = match (true) {
            $policy === 'none' => "{$sessionsText}. Tu empresa no devuelve dinero al anular: se queda lo pagado ({$format($paid)}) y se anulan las cuotas que faltaban.",
            default => $sessionsText . ($policy === 'unused_normal' ? ' a precio normal' : ' al precio del presupuesto') . ": {$format($usedValue)}."
                . ($chargedFull > 0 ? " Los productos y otros cargos se cobran completos: {$format($chargedFull)}." : '')
                . " Pagó {$format($paid)}."
                . ($refund > 0 ? " Se le devuelven {$format($refund)}." : ($debt > 0 ? " Faltan {$format($debt)} por cobrar." : ' No hay nada que devolver ni cobrar.')),
        };

        return [
            'policy'         => $policy,
            'policy_label'   => self::CANCEL_POLICIES[$policy],
            'used_sessions'  => $usedSessions,
            'total_sessions' => $totalSessions,
            'used_value'     => $used,
            'paid'           => $paid,
            'refund'         => $refund,
            'refund_max'     => $refund > 0 && !$state['blocked'] ? round(min($refund, $state['available']), 2) : 0.0,
            'refund_blocked' => $refund > 0 ? $state['blocked'] : null,
            'debt'           => $debt,
            'explain'        => $explain,
            'currency'       => $currency,
            'sale'           => $sale ? ['id' => $sale->id, 'code' => $sale->code, 'branch_id' => $sale->branch_id] : null,
        ];
    }

    public static function cancelAccepted(Tenant $tenant, object $quote, array $data, User $user): array
    {
        $preview = self::cancelPreview($tenant, $quote);
        $reason = trim($data['reason']);
        $refund = round((float) ($data['refund_amount'] ?? 0), 2);
        $sale = $preview['sale'] ? DB::table('sales')->where('id', $preview['sale']['id'])->lockForUpdate()->first() : null;

        if ($refund > 0) {
            if ($preview['refund_blocked']) {
                throw \Illuminate\Validation\ValidationException::withMessages(['refund_amount' => $preview['refund_blocked']]);
            }

            if ($refund > $preview['refund_max'] + 0.001) {
                throw \Illuminate\Validation\ValidationException::withMessages(['refund_amount' => 'La devolución no puede pasar de ' . Currency::format($preview['refund_max'], $preview['currency']) . '.']);
            }

            Refunds::refund($tenant, $sale->id, [
                'amount'          => $refund,
                'method'          => $data['method'],
                'reason'          => "Anulación del presupuesto {$quote->code}: {$reason}",
                'cash_session_id' => $data['cash_session_id'] ?? null,
                'from_package'    => true,
            ], $user);

            $sale = DB::table('sales')->where('id', $sale->id)->first();
        }

        $charge = $preview['debt'] > 0 && ($data['debt_action'] ?? 'charge') === 'charge';
        $final = $preview['debt'] > 0
            ? ($charge ? $preview['used_value'] : $preview['paid'])
            : round($preview['paid'] - $refund, 2);

        if (!$sale && $charge && Sales::ready()) {
            $sale = self::ensureSale($tenant, $quote, $user);
        }

        if ($sale && in_array($sale->status, ['pending', 'partial'], true)) {
            $sale = self::closeSale($tenant, $sale, $final, $reason, $user);
        }

        DB::table('quote_installments')->where('tenant_id', $tenant->id)->where('quote_id', $quote->id)->whereIn('status', ['pending', 'overdue'])->update(['status' => 'cancelled']);

        $balance = $sale && $sale->status !== 'void' ? round((float) $sale->balance, 2) : 0.0;

        if ($balance > 0.001) {
            DB::table('quote_installments')->insert([
                'id'         => (string) Str::uuid(),
                'tenant_id'  => $tenant->id,
                'quote_id'   => $quote->id,
                'number'     => (int) DB::table('quote_installments')->where('quote_id', $quote->id)->max('number') + 1,
                'due_date'   => now()->setTimezone($tenant->zone())->toDateString(),
                'amount'     => $balance,
                'status'     => 'pending',
                'payment_id' => null,
                'paid_at'    => null,
            ]);
        }

        $plan = self::planOf($quote->id);
        $codes = $plan && in_array($plan->status, ['active', 'expired', 'consumed'], true)
            ? Packages::cancelPlan($tenant, $plan, "Se anuló el presupuesto {$quote->code}: {$reason}", $user)
            : [];

        $format = fn (float $v) => Currency::format($v, $preview['currency']);
        $result = match (true) {
            $refund > 0       => "Se devolvieron {$format($refund)}.",
            $balance > 0.001  => "Quedan {$format($balance)} por cobrar.",
            $preview['debt'] > 0 => "No se le cobran los {$format($preview['debt'])} que faltaban.",
            default           => 'No había nada que devolver ni cobrar.',
        };

        DB::table('quotes')->where('id', $quote->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
        DB::table('quote_status_history')->insert([
            'id'            => (string) Str::uuid(),
            'tenant_id'     => $tenant->id,
            'quote_id'      => $quote->id,
            'from_status'   => $quote->status,
            'to_status'     => 'cancelled',
            'actor_user_id' => $user->id,
            'note'          => mb_substr("Anulado: {$reason}. {$preview['explain']} {$result}" . ($codes ? ' Se cancelaron las citas ' . implode(', ', $codes) . '.' : ''), 0, 255),
            'created_at'    => now(),
        ]);

        return ['refunded' => $refund, 'balance' => $balance, 'cancelled_appointments' => $codes, 'message' => "Presupuesto {$quote->code} anulado. {$result}"];
    }

    private static function closeSale(Tenant $tenant, object $sale, float $final, string $reason, User $user): object
    {
        $paid = round((float) $sale->paid, 2);
        $final = max($paid, round($final, 2));

        if ($final <= 0.001) {
            Refunds::void($sale, "Se anuló el presupuesto: {$reason}");

            return DB::table('sales')->where('id', $sale->id)->first();
        }

        $gross = round((float) $sale->subtotal, 2);
        $rate = (float) $sale->tax_rate / 100;
        $net = $sale->prices_include_tax ? $final : round($final / (1 + $rate), 2);
        $totals = Sales::totals(['tax_rate' => (float) $sale->tax_rate, 'prices_include_tax' => (bool) $sale->prices_include_tax], $gross, max(0, round($gross - $net, 2)));
        $balance = max(0, round($final - $paid, 2));
        $isPaid = $balance <= 0.001;

        DB::table('sales')->where('id', $sale->id)->update([
            'discount'   => $totals['discount'],
            'tax'        => $totals['tax'],
            'total'      => $final,
            'balance'    => $balance,
            'status'     => $isPaid ? 'paid' : ($paid > 0 ? 'partial' : 'pending'),
            'paid_at'    => $isPaid ? now() : null,
            'updated_at' => now(),
        ]);

        $sale = DB::table('sales')->where('id', $sale->id)->first();

        if ($isPaid) {
            Invoicing::issue($tenant, $sale, Invoicing::validated($tenant, ['doc_type' => Invoicing::FACTURA === Invoicing::defaultType($tenant) ? Invoicing::types($tenant)[0] : Invoicing::defaultType($tenant)]), $user);
            Inventory::deductForSale($tenant, $sale, $user);
            Loyalty::earnForSale($tenant, $sale, $user);
            Commissions::forSale($tenant, $sale);
        }

        return $sale;
    }

    public static function ensurePermissions(): void
    {
        static $synced = false;

        if ($synced || !Access::ready()) {
            return;
        }

        $synced = true;

        if (DB::table('permissions')->where('name', 'quotes.view')->exists()) {
            return;
        }

        $ids = Access::permissionIds();

        foreach (['manager', 'reception', 'professional', 'cashier'] as $slug) {
            $names = array_values(array_intersect(
                Access::factoryPermissions($slug),
                array_filter(array_keys($ids), fn ($name) => str_starts_with($name, 'quotes.')),
            ));

            if (!$names) {
                continue;
            }

            $roles = DB::table('roles')->where('slug', $slug)->whereNotNull('permissions_seeded_at')->pluck('id');
            foreach ($roles as $roleId) {
                DB::table('role_permissions')->insertOrIgnore(
                    array_map(fn ($name) => ['role_id' => $roleId, 'permission_id' => $ids[$name]], $names),
                );
            }
        }
    }

    public static function pdf(Tenant $tenant, string $quote, ?string $branchLimit = null, ?string $customerId = null)
    {
        $q = DB::table('quotes as q')
            ->join('customers as c', 'c.id', '=', 'q.customer_id')
            ->leftJoin('branches as b', 'b.id', '=', 'q.branch_id')
            ->leftJoin('professionals as p', 'p.id', '=', 'q.professional_id')
            ->where('q.tenant_id', $tenant->id)
            ->where('q.id', $quote)
            ->when($branchLimit, fn ($query, $id) => $query->where(fn ($sub) => $sub->where('q.branch_id', $id)->orWhereNull('q.branch_id')))
            ->when($customerId, fn ($query, $id) => $query->where('q.customer_id', $id))
            ->first(['q.*', 'c.first_name', 'c.last_name', 'c.document_number', 'c.phone as customer_phone', 'c.email as customer_email', 'b.name as branch_name', 'b.address as branch_address', 'p.display_name as professional_name', 'p.license_number']);
        abort_unless($q, 404, 'No se encontró el presupuesto.');

        $currency = Currency::of($tenant);
        $money = fn ($amount) => Currency::format((float) $amount, $currency);
        $salesRules = Sales::rules($tenant);
        $zone = $tenant->zone();

        $items = DB::table('quote_items')->where('tenant_id', $tenant->id)->where('quote_id', $q->id)->orderBy('sort_order')->get()
            ->map(function ($item) use ($money) {
                $meta = is_string($item->metadata) ? (json_decode($item->metadata, true) ?: []) : (array) ($item->metadata ?? []);

                return [
                    'description' => $item->description,
                    'detail'      => collect([$meta['zone'] ?? null, $meta['phase'] ?? null, $meta['notes'] ?? null])->filter()->implode(' · '),
                    'quantity'    => rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ''), '0'), '.'),
                    'unit_price'  => $money($item->unit_price),
                    'discount'    => (float) $item->discount > 0 ? $money($item->discount) : null,
                    'total'       => $money($item->total),
                ];
            });

        $installments = DB::table('quote_installments')->where('tenant_id', $tenant->id)->where('quote_id', $q->id)->orderBy('number')->get()
            ->map(fn ($i) => ['number' => (int) $i->number, 'due_date' => Carbon::parse($i->due_date)->format('d/m/Y'), 'amount' => $money($i->amount)]);

        $html = view('quotes.pdf', [
            'quote' => [
                'code'         => $q->code,
                'title'        => $q->title,
                'status'       => $q->status,
                'status_label' => Quotes::STATUSES[$q->status] ?? $q->status,
                'date'         => Carbon::parse($q->created_at)->setTimezone($zone)->format('d/m/Y'),
                'valid_until'  => $q->valid_until ? Carbon::parse($q->valid_until)->format('d/m/Y') : null,
                'customer'     => trim("{$q->first_name} {$q->last_name}"),
                'document'     => $q->document_number,
                'phone'        => $q->customer_phone,
                'email'        => $q->customer_email,
                'branch'       => $q->branch_name,
                'branch_address' => $q->branch_address,
                'professional' => $q->professional_name,
                'license'      => $q->license_number,
                'notes'        => $q->notes,
                'subtotal'     => $money($q->subtotal),
                'discount'     => (float) $q->discount > 0 ? $money($q->discount) : null,
                'tax'          => (float) $q->tax > 0 ? $money($q->tax) : null,
                'tax_label'    => 'IGV ' . rtrim(rtrim(number_format((float) $salesRules['tax_rate'], 2, '.', ''), '0'), '.') . '%' . ($salesRules['prices_include_tax'] ? ' (incluido)' : ''),
                'total'        => $money($q->total),
            ],
            'items'        => $items,
            'installments' => $installments,
            'company'      => $tenant->name,
            'client'       => $tenant->vocabulary()['client'],
            'pro'          => $tenant->vocabulary()['pro'],
            'document'     => \App\Support\Documents::forPdf($tenant),
            'printedAt'    => now($zone)->format('d/m/Y H:i'),
        ])->render();

        $name = trim(preg_replace('/[^\pL\pN \-]+/u', '', "Presupuesto {$q->code} {$q->first_name} {$q->last_name}")) ?: 'Presupuesto';

        return response(\App\Support\Documents::pdf($html), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . Str::ascii($name) . '.pdf"',
        ]);
    }
}
