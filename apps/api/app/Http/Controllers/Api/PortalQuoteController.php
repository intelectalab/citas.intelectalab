<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Audit;
use App\Support\Currency;
use App\Support\Notifier;
use App\Support\Packages;
use App\Support\Quotes;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PortalQuoteController extends Controller
{
    public const REASONS = ['Precio', 'Lo pensaré', 'Me atenderé en otro lugar'];

    private function tenant(Request $request): Tenant
    {
        abort_unless(Quotes::ready(), 503, Quotes::NOT_READY);
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 403);

        return $tenant;
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);
        $customer = $request->user();
        Quotes::expireDue($tenant->id);
        $rules = Quotes::rules($tenant);

        $rows = DB::table('quotes as q')
            ->leftJoin('branches as b', 'b.id', '=', 'q.branch_id')
            ->leftJoin('professionals as p', 'p.id', '=', 'q.professional_id')
            ->leftJoin('sales as s', 's.id', '=', 'q.sale_id')
            ->where('q.tenant_id', $tenant->id)
            ->where('q.customer_id', $customer->id)
            ->whereIn('q.status', ['sent', 'accepted', 'rejected', 'expired'])
            ->orderByRaw("CASE q.status WHEN 'sent' THEN 0 WHEN 'accepted' THEN 1 ELSE 2 END")
            ->orderByDesc('q.created_at')
            ->limit(60)
            ->get(['q.*', 'b.name as branch_name', 'p.display_name as professional_name', 's.paid as sale_paid', 's.balance as sale_balance', 's.status as sale_status']);

        $ids = $rows->pluck('id')->all();
        $items = $ids ? DB::table('quote_items')->whereIn('quote_id', $ids)->orderBy('sort_order')->get()->groupBy('quote_id') : collect();
        $installments = $ids ? DB::table('quote_installments')->whereIn('quote_id', $ids)->orderBy('number')->get()->groupBy('quote_id') : collect();
        $plans = $ids && Quotes::plansReady() ? DB::table('customer_packages')->whereIn('quote_id', $ids)->get()->keyBy('quote_id') : collect();
        $rejections = $ids ? DB::table('quote_status_history')->whereIn('quote_id', $ids)->where('to_status', 'rejected')->orderByDesc('created_at')->get()->groupBy('quote_id') : collect();
        $currency = Currency::of($tenant);
        $today = Quotes::today($tenant->id);

        $quotes = $rows->map(function ($q) use ($items, $installments, $plans, $rejections, $currency, $rules, $today) {
            $plan = $plans[$q->id] ?? null;
            $lines = $items[$q->id] ?? collect();
            $expired = $q->status === 'expired' || ($q->status === 'sent' && $q->valid_until && (string) $q->valid_until < $today);
            $planItems = $plan ? DB::table('customer_package_items')->where('customer_package_id', $plan->id)->get() : collect();

            return [
                'id'           => $q->id,
                'code'         => $q->code,
                'title'        => $q->title,
                'status'       => $expired ? 'expired' : $q->status,
                'status_label' => ['sent' => 'Por responder', 'accepted' => 'Aceptado'][$expired ? 'expired' : $q->status] ?? Quotes::STATUSES[$expired ? 'expired' : $q->status] ?? $q->status,
                'valid_until'  => $q->valid_until,
                'created_at'   => Carbon::parse($q->created_at)->toIso8601String(),
                'accepted_at'  => $q->accepted_at ? Carbon::parse($q->accepted_at)->toIso8601String() : null,
                'accepted_via' => $q->accepted_via,
                'branch'       => $q->branch_name,
                'professional' => $q->professional_name,
                'notes'        => $q->notes,
                'subtotal'     => round((float) $q->subtotal, 2),
                'discount'     => round((float) $q->discount, 2),
                'total'        => round((float) $q->total, 2),
                'currency'     => $currency,
                'items'        => $lines->map(fn ($i) => [
                    'description' => $i->description,
                    'quantity'    => round((float) $i->quantity, 2),
                    'total'       => round((float) $i->total, 2),
                    'sessions'    => (int) $i->sessions_total,
                ])->values(),
                'installments' => ($installments[$q->id] ?? collect())->map(fn ($i) => [
                    'number'       => (int) $i->number,
                    'due_date'     => $i->due_date,
                    'amount'       => round((float) $i->amount, 2),
                    'status'       => $i->status,
                    'status_label' => Quotes::INSTALLMENT_STATUSES[$i->status] ?? $i->status,
                ])->values(),
                'paid'         => $q->sale_id ? round((float) $q->sale_paid, 2) : null,
                'balance'      => $q->sale_id && $q->sale_status !== 'void' ? round((float) $q->sale_balance, 2) : null,
                'plan'         => $plan ? [
                    'code'           => $plan->code,
                    'status'         => $plan->status,
                    'status_label'   => Packages::STATUS_LABELS[$plan->status] ?? $plan->status,
                    'expires_at'     => Carbon::parse($plan->expires_at)->toIso8601String(),
                    'sessions_total' => (int) $planItems->sum('sessions_total'),
                    'sessions_used'  => (int) $planItems->sum('sessions_used'),
                ] : null,
                'rejection'    => $q->status === 'rejected' ? ($rejections[$q->id] ?? collect())->first()?->note : null,
                'can_answer'   => $rules['portal_accept'] && $q->status === 'sent' && !$expired,
            ];
        })->values();

        return response()->json([
            'enabled' => $rules['portal_accept'],
            'reasons' => self::REASONS,
            'quotes'  => $quotes,
        ]);
    }

    public function pdf(Request $request, string $quote)
    {
        $tenant = $this->tenant($request);
        $customer = $request->user();
        abort_unless(DB::table('quotes')->where('tenant_id', $tenant->id)->where('id', $quote)->where('customer_id', $customer->id)->whereIn('status', ['sent', 'accepted', 'rejected', 'expired'])->exists(), 404, 'No se encontró el presupuesto.');

        return Quotes::pdf($tenant, $quote, null, $customer->id);
    }

    private function answerable(Tenant $tenant, string $quote, string $customerId): object
    {
        if (!Quotes::rules($tenant)['portal_accept']) {
            throw ValidationException::withMessages(['quote' => 'Para responder este presupuesto, comunícate con nosotros.']);
        }

        $row = DB::table('quotes')->where('tenant_id', $tenant->id)->where('id', $quote)->where('customer_id', $customerId)->lockForUpdate()->first();
        abort_unless($row && in_array($row->status, ['sent', 'accepted', 'rejected', 'expired'], true), 404, 'No se encontró el presupuesto.');

        if ($row->status !== 'sent') {
            throw ValidationException::withMessages(['quote' => match ($row->status) {
                'accepted' => 'Ya aceptaste este presupuesto.',
                'rejected' => 'Ya respondiste este presupuesto.',
                default    => 'Este presupuesto venció. Pídenos uno nuevo.',
            }]);
        }

        if ($row->valid_until && (string) $row->valid_until < Quotes::today($tenant->id)) {
            throw ValidationException::withMessages(['quote' => 'Este presupuesto venció el ' . Carbon::parse($row->valid_until)->format('d/m/Y') . '. Pídenos uno nuevo.']);
        }

        return $row;
    }

    public function accept(Request $request, string $quote)
    {
        $tenant = $this->tenant($request);
        $customer = $request->user();
        $rules = Quotes::rules($tenant);

        $row = DB::transaction(function () use ($tenant, $customer, $quote, $rules) {
            $row = $this->answerable($tenant, $quote, $customer->id);

            return Quotes::approve($tenant, $row, null, 'portal', $rules['auto_create_sale']);
        });

        Audit::log($request, 'quote.approved', [
            'tenant' => $tenant->id, 'entity_type' => 'quote', 'entity_id' => $row->id, 'entity_label' => $row->code, 'branch_id' => $row->branch_id,
            'before' => ['status' => Quotes::STATUSES['sent']], 'after' => ['status' => Quotes::STATUSES['accepted'], 'note' => 'Desde el portal'],
        ]);
        Notifier::quoteAnswered($tenant, $row, $customer, true, null);

        return $this->index($request);
    }

    public function reject(Request $request, string $quote)
    {
        $tenant = $this->tenant($request);
        $customer = $request->user();
        $validated = $request->validate(['reason' => 'required|string|max:255'], ['reason.required' => 'Cuéntanos el motivo.']);
        $reason = trim($validated['reason']);

        $row = DB::transaction(function () use ($tenant, $customer, $quote, $reason) {
            $row = $this->answerable($tenant, $quote, $customer->id);

            DB::table('quotes')->where('id', $row->id)->update(['status' => 'rejected', 'updated_at' => now()]);
            DB::table('quote_installments')->where('quote_id', $row->id)->whereIn('status', ['pending', 'overdue'])->update(['status' => 'cancelled']);
            DB::table('quote_status_history')->insert([
                'id'            => (string) Str::uuid(),
                'tenant_id'     => $tenant->id,
                'quote_id'      => $row->id,
                'from_status'   => 'sent',
                'to_status'     => 'rejected',
                'actor_user_id' => null,
                'note'          => mb_substr("El cliente lo rechazó desde el portal: {$reason}", 0, 255),
                'created_at'    => now(),
            ]);

            return DB::table('quotes')->where('id', $row->id)->first();
        });

        Audit::log($request, 'quote.rejected', [
            'tenant' => $tenant->id, 'entity_type' => 'quote', 'entity_id' => $row->id, 'entity_label' => $row->code, 'branch_id' => $row->branch_id,
            'before' => ['status' => Quotes::STATUSES['sent']], 'after' => ['status' => Quotes::STATUSES['rejected'], 'note' => $reason],
        ]);
        Notifier::quoteAnswered($tenant, $row, $customer, false, $reason);

        return $this->index($request);
    }
}
