<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Professional;
use App\Models\Service;
use App\Models\Tenant;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Cart;
use App\Support\Cash;
use App\Support\Currency;
use App\Support\Packages;
use App\Support\Person;
use App\Support\Quotes;
use App\Support\Sales;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuoteController extends Controller
{
    private function tenant(Request $request): Tenant
    {
        abort_unless(Quotes::ready(), 503, Quotes::NOT_READY);
        Quotes::ensurePermissions();

        return $request->user()->tenant;
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();
        Quotes::expireDue($tenant->id);

        $rules = Quotes::rules($tenant);
        $salesRules = Sales::rules($tenant);
        $branchLimit = Access::branchLimit($user);
        $iso = fn ($value) => $value ? Carbon::parse($value)->toIso8601String() : null;

        $branches = Branch::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->when($branchLimit, fn ($q, $id) => $q->where('id', $id))
            ->orderBy('name')
            ->get(['id', 'name']);

        $customers = DB::table('customers')
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(600)
            ->get(['id', 'first_name', 'last_name', 'document_number', 'phone', 'email'])
            ->map(fn ($c) => [
                'id'              => $c->id,
                'name'            => Person::name($c),
                'document_number' => $c->document_number,
                'phone'           => $c->phone,
                'email'           => $c->email,
            ])
            ->values();

        $professionals = Professional::where('tenant_id', $tenant->id)
            ->with('status')
            ->orderBy('display_name')
            ->get()
            ->reject(fn (Professional $p) => $p->status?->slug === 'inactive')
            ->map(fn (Professional $p) => [
                'id'           => $p->id,
                'display_name' => $p->display_name,
            ])
            ->values();

        $services = Service::where('tenant_id', $tenant->id)
            ->with('status')
            ->orderBy('name')
            ->get()
            ->reject(fn (Service $s) => $s->status?->slug === 'inactive')
            ->map(fn (Service $s) => [
                'id'               => $s->id,
                'name'             => $s->name,
                'price'            => round((float) $s->price, 2),
                'duration_minutes' => (int) $s->duration_minutes,
            ])
            ->values();

        $products = Cart::productsEnabled($tenant)
            ? DB::table('products')
                ->where('tenant_id', $tenant->id)
                ->whereNull('deleted_at')
                ->where('status', 'active')
                ->whereIn('kind', Cart::SELLABLE)
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'price'])
                ->map(fn ($p) => [
                    'id'    => $p->id,
                    'name'  => $p->name,
                    'sku'   => $p->sku,
                    'price' => round((float) $p->price, 2),
                ])
                ->values()
            : collect();

        $packageSessions = Packages::enabled($tenant)
            ? DB::table('package_plan_items')
                ->where('tenant_id', $tenant->id)
                ->groupBy('package_plan_id')
                ->selectRaw('package_plan_id, COALESCE(SUM(sessions), 0) as total_sessions')
                ->pluck('total_sessions', 'package_plan_id')
            : collect();

        $packages = Packages::enabled($tenant)
            ? DB::table('package_plans')
                ->where('tenant_id', $tenant->id)
                ->whereNull('deleted_at')
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'price'])
                ->map(fn ($p) => [
                    'id'             => $p->id,
                    'name'           => $p->name,
                    'price'          => round((float) $p->price, 2),
                    'sessions_total' => max(1, (int) ($packageSessions[$p->id] ?? 1)),
                ])
                ->values()
            : collect();

        $quoteRows = DB::table('quotes as q')
            ->join('customers as c', 'c.id', '=', 'q.customer_id')
            ->leftJoin('branches as b', 'b.id', '=', 'q.branch_id')
            ->leftJoin('professionals as p', 'p.id', '=', 'q.professional_id')
            ->leftJoin('sales as s', 's.id', '=', 'q.sale_id')
            ->where('q.tenant_id', $tenant->id)
            ->when($branchLimit, fn ($query, $id) => $query->where(fn ($sub) => $sub->where('q.branch_id', $id)->orWhereNull('q.branch_id')))
            ->orderByDesc('q.created_at')
            ->limit(250)
            ->get([
                'q.*',
                'c.first_name as customer_first_name',
                'c.last_name as customer_last_name',
                'c.document_number as customer_document',
                'c.phone as customer_phone',
                'c.email as customer_email',
                'b.name as branch_name',
                'p.display_name as professional_name',
                's.code as sale_code',
                's.status as sale_status',
                's.paid as sale_paid',
                's.balance as sale_balance',
            ]);

        foreach ($quoteRows as $qRow) {
            if ($qRow->sale_id) {
                Quotes::syncInstallmentsWithSale($tenant->id, $qRow);
            }
        }

        $quoteIds = $quoteRows->pluck('id')->all();

        $itemsByQuote = $quoteIds
            ? DB::table('quote_items')
                ->where('tenant_id', $tenant->id)
                ->whereIn('quote_id', $quoteIds)
                ->orderBy('sort_order')
                ->get()
                ->groupBy('quote_id')
            : collect();

        $installmentsByQuote = $quoteIds
            ? DB::table('quote_installments')
                ->where('tenant_id', $tenant->id)
                ->whereIn('quote_id', $quoteIds)
                ->orderBy('number')
                ->get()
                ->groupBy('quote_id')
            : collect();

        $historyByQuote = $quoteIds
            ? DB::table('quote_status_history as h')
                ->leftJoin('users as u', 'u.id', '=', 'h.actor_user_id')
                ->where('h.tenant_id', $tenant->id)
                ->whereIn('h.quote_id', $quoteIds)
                ->orderByDesc('h.created_at')
                ->get([
                    'h.*',
                    'u.first_name as user_first_name',
                    'u.last_name as user_last_name',
                ])
                ->groupBy('quote_id')
            : collect();

        $plansByQuote = $quoteIds && Quotes::plansReady()
            ? DB::table('customer_packages')->where('tenant_id', $tenant->id)->whereIn('quote_id', $quoteIds)->orderBy('created_at')->get()->keyBy('quote_id')
            : collect();


        $quotes = $quoteRows->map(function ($q) use ($itemsByQuote, $installmentsByQuote, $historyByQuote, $plansByQuote, $iso) {
            $rawItems = $itemsByQuote[$q->id] ?? collect();
            $rawInstallments = $installmentsByQuote[$q->id] ?? collect();
            $rawHistory = $historyByQuote[$q->id] ?? collect();

            $items = $rawItems->map(function ($item) {
                $meta = is_string($item->metadata) ? (json_decode($item->metadata, true) ?: []) : ((array) ($item->metadata ?? []));

                return [
                    'id'              => $item->id,
                    'item_type'       => $item->item_type,
                    'item_type_label' => Quotes::ITEM_TYPES[$item->item_type] ?? $item->item_type,
                    'reference_id'    => $item->reference_id,
                    'description'     => $item->description,
                    'quantity'        => round((float) $item->quantity, 2),
                    'unit_price'      => round((float) $item->unit_price, 2),
                    'discount'        => round((float) $item->discount, 2),
                    'total'           => round((float) $item->total, 2),
                    'sessions_total'  => (int) $item->sessions_total,
                    'sessions_used'   => (int) $item->sessions_used,
                    'zone'            => $meta['zone'] ?? null,
                    'phase'           => $meta['phase'] ?? null,
                    'notes'           => $meta['notes'] ?? null,
                ];
            })->values();

            $customerName = trim(($q->customer_first_name ?? '') . ' ' . ($q->customer_last_name ?? ''));

            $installments = $rawInstallments->map(fn ($inst) => [
                'id'           => $inst->id,
                'number'       => (int) $inst->number,
                'due_date'     => $inst->due_date,
                'amount'       => round((float) $inst->amount, 2),
                'status'       => $inst->status,
                'status_label' => Quotes::INSTALLMENT_STATUSES[$inst->status] ?? $inst->status,
                'paid_at'      => $iso($inst->paid_at),
            ])->values();

            $history = $rawHistory->map(fn ($h) => [
                'id'              => $h->id,
                'from_status'     => $h->from_status,
                'to_status'       => $h->to_status,
                'to_status_label' => Quotes::STATUSES[$h->to_status] ?? $h->to_status,
                'actor'           => trim(($h->user_first_name ?? '') . ' ' . ($h->user_last_name ?? '')) ?: null,
                'note'            => $h->note,
                'created_at'      => $iso($h->created_at),
            ])->values();

            $sessionsTotal = (int) $items->sum('sessions_total');
            $sessionsUsed = (int) $items->sum('sessions_used');
            $progressPercent = $sessionsTotal > 0 ? min(100, (int) round(($sessionsUsed / $sessionsTotal) * 100)) : 0;

            $plan = $plansByQuote[$q->id] ?? null;
            $overdue = $rawInstallments->where('status', 'overdue')->count();

            $validUntil = $q->valid_until ? Carbon::parse($q->valid_until) : null;
            $daysLeft = $validUntil ? (int) now()->startOfDay()->diffInDays($validUntil->copy()->startOfDay(), false) : null;

            return [
                'id'                 => $q->id,
                'code'               => $q->code,
                'title'              => $q->title,
                'status'             => $q->status,
                'status_label'       => Quotes::STATUSES[$q->status] ?? $q->status,
                'valid_until'        => $q->valid_until,
                'days_left'          => $daysLeft,
                'branch_id'          => $q->branch_id,
                'branch_name'        => $q->branch_name,
                'customer_id'        => $q->customer_id,
                'customer_name'      => $customerName,
                'customer_document'  => $q->customer_document,
                'customer_phone'     => $q->customer_phone,
                'customer_email'     => $q->customer_email,
                'professional_id'    => $q->professional_id,
                'professional_name'  => $q->professional_name,
                'subtotal'           => round((float) $q->subtotal, 2),
                'discount'           => round((float) $q->discount, 2),
                'tax'                => round((float) $q->tax, 2),
                'total'              => round((float) $q->total, 2),
                'notes'              => $q->notes,
                'accepted_at'        => $iso($q->accepted_at),
                'accepted_via'       => $q->accepted_via,
                'sale_id'            => $q->sale_id,
                'sale_code'          => $q->sale_code,
                'sale_status'        => $q->sale_status,
                'sale_status_label'  => $q->sale_status ? (Sales::STATUS_LABELS[$q->sale_status] ?? $q->sale_status) : null,
                'sale_paid'          => round((float) ($q->sale_paid ?? 0), 2),
                'sale_balance'       => round((float) ($q->sale_balance ?? 0), 2),
                'sessions_total'     => $sessionsTotal,
                'sessions_used'      => $sessionsUsed,
                'progress_percent'   => $progressPercent,
                'items_count'        => $items->count(),
                'items'              => $items,
                'installments_count' => $installments->count(),
                'installments'       => $installments,
                'overdue_installments' => $overdue,
                'plan'               => $plan ? [
                    'id'           => $plan->id,
                    'code'         => $plan->code,
                    'status'       => $plan->status,
                    'status_label' => Packages::STATUS_LABELS[$plan->status] ?? $plan->status,
                    'expires_at'   => $iso($plan->expires_at),
                ] : null,
                'history'            => $history,
                'created_at'         => $iso($q->created_at),
            ];
        })->values();


        return response()->json([
            'currency'         => Currency::of($tenant),
            'rules'            => $rules + ['cancel_policy_label' => Quotes::CANCEL_POLICIES[$rules['cancel_policy']]],
            'tax'              => [
                'rate'     => $salesRules['tax_rate'],
                'included' => $salesRules['prices_include_tax'],
            ],
            'can'              => [
                'create' => $user->allows('quotes.create'),
                'edit'   => $user->allows('quotes.edit'),
                'delete' => $user->allows('quotes.delete'),
                'charge' => $user->allows('sales.create'),
            ],
            'branches'         => $branches,
            'customers'        => $customers,
            'professionals'    => $professionals,
            'services'         => $services,
            'products'         => $products,
            'packages'         => $packages,
            'quotes'           => $quotes,
        ]);
    }

    public function pdf(Request $request, string $quote)
    {
        $tenant = $this->tenant($request);
        $branchLimit = Access::branchLimit($request->user());

        $q = DB::table('quotes as q')
            ->join('customers as c', 'c.id', '=', 'q.customer_id')
            ->leftJoin('branches as b', 'b.id', '=', 'q.branch_id')
            ->leftJoin('professionals as p', 'p.id', '=', 'q.professional_id')
            ->where('q.tenant_id', $tenant->id)
            ->where('q.id', $quote)
            ->when($branchLimit, fn ($query, $id) => $query->where(fn ($sub) => $sub->where('q.branch_id', $id)->orWhereNull('q.branch_id')))
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

    private function validateQuotePayload(Request $request, ?string $quoteId = null): array
    {
        $validated = $this->validateQuoteFields($request);

        if (!Cart::productsEnabled($request->user()->tenant)) {
            $kept = $quoteId
                ? DB::table('quote_items')->where('quote_id', $quoteId)->where('item_type', 'product')->pluck('reference_id')->filter()->all()
                : [];
            $added = collect($validated['items'])->contains(fn ($item) => $item['item_type'] === 'product' && !in_array($item['reference_id'] ?? null, $kept, true));

            if ($added) {
                throw ValidationException::withMessages(['items' => Cart::NO_PRODUCTS]);
            }
        }

        return $validated;
    }

    private function validateQuoteFields(Request $request): array
    {
        return $request->validate([
            'customer_id'                 => 'required|uuid',
            'branch_id'                   => 'nullable|uuid',
            'professional_id'             => 'nullable|uuid',
            'title'                       => 'required|string|max:200',
            'valid_until'                 => 'nullable|date_format:Y-m-d',
            'notes'                       => 'nullable|string|max:2000',
            'status'                      => ['nullable', Rule::in(['draft', 'sent'])],
            'items'                       => 'required|array|min:1|max:50',
            'items.*.item_type'           => ['required', Rule::in(array_keys(Quotes::ITEM_TYPES))],
            'items.*.reference_id'        => 'nullable|uuid',
            'items.*.description'         => 'required|string|max:255',
            'items.*.quantity'            => 'required|numeric|min:0.01|max:9999',
            'items.*.unit_price'          => 'required|numeric|min:0|max:9999999',
            'items.*.discount'            => 'nullable|numeric|min:0|max:9999999',
            'items.*.sessions_total'      => 'nullable|integer|min:0|max:365',
            'items.*.sessions_used'       => 'nullable|integer|min:0|max:365',
            'items.*.zone'                => 'nullable|string|max:120',
            'items.*.phase'               => 'nullable|string|max:120',
            'items.*.notes'               => 'nullable|string|max:255',
            'installments'                => 'nullable|array|max:36',
            'installments.*.due_date'     => 'required_with:installments|date_format:Y-m-d',
            'installments.*.amount'       => 'required_with:installments|numeric|min:0.01|max:9999999',
        ], [
            'customer_id.required'         => 'Elige al cliente o paciente.',
            'title.required'               => 'Escribe un título para el presupuesto o plan de tratamiento.',
            'items.required'               => 'Agrega al menos una línea al presupuesto.',
            'items.min'                    => 'Agrega al menos una línea al presupuesto.',
            'items.*.description.required' => 'Escribe la descripción de cada línea.',
        ]);
    }

    private function persistItemsAndInstallments(Tenant $tenant, string $quoteId, array $validated): array
    {
        $salesRules = Sales::rules($tenant);
        $gross = 0.0;
        $totalDiscount = 0.0;

        DB::table('quote_items')->where('tenant_id', $tenant->id)->where('quote_id', $quoteId)->delete();

        foreach (array_values($validated['items']) as $idx => $item) {
            $qty = round((float) $item['quantity'], 2);
            $unitPrice = round((float) $item['unit_price'], 2);
            $lineGross = round($qty * $unitPrice, 2);
            $lineDiscount = min($lineGross, round((float) ($item['discount'] ?? 0), 2));
            $lineTotal = max(0, round($lineGross - $lineDiscount, 2));

            $gross = round($gross + $lineGross, 2);
            $totalDiscount = round($totalDiscount + $lineDiscount, 2);

            $sessionsTotal = isset($item['sessions_total'])
                ? (int) $item['sessions_total']
                : ($item['item_type'] === 'service' ? max(1, (int) round($qty)) : 0);

            $sessionsUsed = min($sessionsTotal, max(0, (int) ($item['sessions_used'] ?? 0)));

            $meta = array_filter([
                'zone'  => isset($item['zone']) && trim($item['zone']) !== '' ? trim($item['zone']) : null,
                'phase' => isset($item['phase']) && trim($item['phase']) !== '' ? trim($item['phase']) : null,
                'notes' => isset($item['notes']) && trim($item['notes']) !== '' ? trim($item['notes']) : null,
            ], fn ($v) => $v !== null);

            DB::table('quote_items')->insert([
                'id'             => (string) Str::uuid(),
                'tenant_id'      => $tenant->id,
                'quote_id'       => $quoteId,
                'item_type'      => $item['item_type'],
                'reference_id'   => $item['reference_id'] ?? null,
                'description'    => trim($item['description']),
                'quantity'       => $qty,
                'unit_price'     => $unitPrice,
                'discount'       => $lineDiscount,
                'total'          => $lineTotal,
                'sessions_total' => $sessionsTotal,
                'sessions_used'  => $sessionsUsed,
                'metadata'       => json_encode($meta ?: (object) []),
                'sort_order'     => $idx,
            ]);
        }

        $totals = Sales::totals($salesRules, $gross, $totalDiscount);

        DB::table('quote_installments')->where('tenant_id', $tenant->id)->where('quote_id', $quoteId)->delete();

        $installments = $validated['installments'] ?? [];
        if ($installments) {
            foreach (array_values($installments) as $idx => $inst) {
                $dueDate = $inst['due_date'];
                $status = Carbon::parse($dueDate)->lt(now()->startOfDay()) ? 'overdue' : 'pending';

                DB::table('quote_installments')->insert([
                    'id'         => (string) Str::uuid(),
                    'tenant_id'  => $tenant->id,
                    'quote_id'   => $quoteId,
                    'number'     => $idx + 1,
                    'due_date'   => $dueDate,
                    'amount'     => round((float) $inst['amount'], 2),
                    'status'     => $status,
                    'payment_id' => null,
                    'paid_at'    => null,
                ]);
            }
        }

        return $totals;
    }

    public function store(Request $request)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();
        $rules = Quotes::rules($tenant);
        $validated = $this->validateQuotePayload($request);

        if (!empty($validated['branch_id'])) {
            Access::assertBranch($user, $validated['branch_id'], true);
        }

        $validUntil = $validated['valid_until']
            ?? ($rules['validity_days'] > 0 ? now()->addDays($rules['validity_days'])->toDateString() : null);

        $initialStatus = $validated['status'] ?? 'draft';

        DB::transaction(function () use ($tenant, $user, $validated, $validUntil, $initialStatus) {
            $quoteId = (string) Str::uuid();
            $code = Quotes::nextCode($tenant->id);

            DB::table('quotes')->insert([
                'id'                    => $quoteId,
                'tenant_id'             => $tenant->id,
                'branch_id'             => $validated['branch_id'] ?? $user->branch_id,
                'customer_id'           => $validated['customer_id'],
                'customer_dependent_id' => null,
                'professional_id'       => $validated['professional_id'] ?? null,
                'code'                  => $code,
                'title'                 => trim($validated['title']),
                'status'                => $initialStatus,
                'valid_until'           => $validUntil,
                'subtotal'              => 0,
                'discount'              => 0,
                'tax'                   => 0,
                'total'                 => 0,
                'notes'                 => isset($validated['notes']) && trim($validated['notes']) !== '' ? trim($validated['notes']) : null,
                'created_by'            => $user->id,
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);

            $totals = $this->persistItemsAndInstallments($tenant, $quoteId, $validated);

            DB::table('quotes')->where('id', $quoteId)->update([
                'subtotal'   => $totals['subtotal'],
                'discount'   => $totals['discount'],
                'tax'        => $totals['tax'],
                'total'      => $totals['total'],
                'updated_at' => now(),
            ]);

            DB::table('quote_status_history')->insert([
                'id'            => (string) Str::uuid(),
                'tenant_id'     => $tenant->id,
                'quote_id'      => $quoteId,
                'from_status'   => null,
                'to_status'     => $initialStatus,
                'actor_user_id' => $user->id,
                'note'          => $initialStatus === 'sent' ? 'Presupuesto creado y enviado al cliente.' : 'Presupuesto creado como borrador.',
                'created_at'    => now(),
            ]);
        });

        return $this->index($request);
    }

    public function update(Request $request, string $quote)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();
        $validated = $this->validateQuotePayload($request, $quote);

        DB::transaction(function () use ($tenant, $user, $quote, $validated) {
            $row = DB::table('quotes')
                ->where('tenant_id', $tenant->id)
                ->where('id', $quote)
                ->lockForUpdate()
                ->first();

            abort_unless($row, 404);
            Access::assertBranch($user, $row->branch_id, true);

            if (!in_array($row->status, ['draft', 'sent'], true)) {
                throw ValidationException::withMessages([
                    'title' => 'Solo se pueden modificar las líneas de un presupuesto en borrador o enviado.',
                ]);
            }

            $totals = $this->persistItemsAndInstallments($tenant, $row->id, $validated);

            DB::table('quotes')->where('id', $row->id)->update([
                'branch_id'       => $validated['branch_id'] ?? $row->branch_id,
                'customer_id'     => $validated['customer_id'],
                'professional_id' => $validated['professional_id'] ?? null,
                'title'           => trim($validated['title']),
                'valid_until'     => $validated['valid_until'] ?? null,
                'subtotal'        => $totals['subtotal'],
                'discount'        => $totals['discount'],
                'tax'             => $totals['tax'],
                'total'           => $totals['total'],
                'notes'           => isset($validated['notes']) && trim($validated['notes']) !== '' ? trim($validated['notes']) : null,
                'updated_at'      => now(),
            ]);
        });

        return $this->index($request);
    }

    private function auditQuote(Request $request, Tenant $tenant, string $action, string $quoteId, array $after, array $before = []): void
    {
        $quote = DB::table('quotes')->where('id', $quoteId)->first();

        if (!$quote) {
            return;
        }

        $customer = DB::table('customers')->where('id', $quote->customer_id)->first(['first_name', 'last_name']);

        Audit::log($request, $action, [
            'tenant'       => $tenant->id,
            'entity_type'  => 'quote',
            'entity_id'    => $quote->id,
            'entity_label' => $quote->code,
            'branch_id'    => $quote->branch_id,
            'before'       => $before ?: null,
            'after'        => array_filter($after + ['total' => round((float) $quote->total, 2), 'customer' => Person::name($customer) ?: null], fn ($value) => $value !== null && $value !== []),
        ]);
    }

    public function changeStatus(Request $request, string $quote)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();
        $rules = Quotes::rules($tenant);

        $validated = $request->validate([
            'status'      => ['required', Rule::in(['draft', 'sent', 'accepted', 'rejected', 'cancelled'])],
            'note'        => 'nullable|string|max:255',
            'create_sale' => 'nullable|boolean',
        ]);

        $change = DB::transaction(function () use ($tenant, $user, $rules, $quote, $validated) {
            $row = DB::table('quotes')
                ->where('tenant_id', $tenant->id)
                ->where('id', $quote)
                ->lockForUpdate()
                ->first();

            abort_unless($row, 404);
            Access::assertBranch($user, $row->branch_id, true);

            $from = $row->status;
            $to = $validated['status'];

            if ($from === $to) {
                return null;
            }

            if ($from === 'accepted') {
                throw ValidationException::withMessages([
                    'status' => 'Este presupuesto ya está aprobado y tiene su plan de tratamiento: para dejarlo sin efecto usa "Anular", que calcula qué se devuelve o se cobra.',
                ]);
            }

            $updates = [
                'status'     => $to,
                'updated_at' => now(),
            ];

            if ($to === 'accepted') {
                $updates['accepted_at'] = now();
                $updates['accepted_via'] = 'staff';
            }

            DB::table('quotes')->where('id', $row->id)->update($updates);
            $fresh = DB::table('quotes')->where('id', $row->id)->first();

            if ($to === 'accepted') {
                $shouldCreateSale = array_key_exists('create_sale', $validated) && $validated['create_sale'] !== null
                    ? (bool) $validated['create_sale']
                    : $rules['auto_create_sale'];

                if ($shouldCreateSale && Sales::ready()) {
                    Quotes::ensureSale($tenant, $fresh, $user);
                    $fresh = DB::table('quotes')->where('id', $row->id)->first();
                }

                Quotes::createPlan($tenant, $fresh, $user);
            }

            if (in_array($to, ['rejected', 'cancelled'], true)) {
                DB::table('quote_installments')
                    ->where('tenant_id', $tenant->id)
                    ->where('quote_id', $row->id)
                    ->whereIn('status', ['pending', 'overdue'])
                    ->update(['status' => 'cancelled']);
            }

            $defaultNote = match ($to) {
                'sent'      => 'Presupuesto enviado al cliente.',
                'accepted'  => 'Presupuesto aprobado por el cliente.',
                'rejected'  => 'Presupuesto rechazado.',
                'cancelled' => 'Presupuesto anulado.',
                'draft'     => 'Reabierto como borrador.',
                default     => null,
            };

            DB::table('quote_status_history')->insert([
                'id'            => (string) Str::uuid(),
                'tenant_id'     => $tenant->id,
                'quote_id'      => $row->id,
                'from_status'   => $from,
                'to_status'     => $to,
                'actor_user_id' => $user->id,
                'note'          => isset($validated['note']) && trim($validated['note']) !== '' ? trim($validated['note']) : $defaultNote,
                'created_at'    => now(),
            ]);

            return ['id' => $row->id, 'from' => $from, 'to' => $to, 'sale_id' => $fresh->sale_id ?? null];
        });

        $action = ['accepted' => 'quote.approved', 'rejected' => 'quote.rejected', 'cancelled' => 'quote.cancelled'][$change['to'] ?? ''] ?? null;

        if ($action) {
            $this->auditQuote($request, $tenant, $action, $change['id'], [
                'status' => Quotes::STATUSES[$change['to']],
                'note'   => isset($validated['note']) && trim($validated['note']) !== '' ? trim($validated['note']) : null,
                'sale'   => $change['sale_id'] ? DB::table('sales')->where('id', $change['sale_id'])->value('code') : null,
            ], ['status' => Quotes::STATUSES[$change['from']] ?? $change['from']]);
        }

        return $this->index($request);
    }

    public function recordSession(Request $request, string $quote, string $item)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();

        $validated = $request->validate([
            'note' => 'required|string|max:255',
        ], [
            'note.required' => 'Escribe por qué se descuenta la sesión sin cita.',
        ]);

        $deducted = DB::transaction(function () use ($tenant, $user, $quote, $item, $validated) {
            $quoteRow = DB::table('quotes')->where('tenant_id', $tenant->id)->where('id', $quote)->lockForUpdate()->first();
            abort_unless($quoteRow, 404);
            Access::assertBranch($user, $quoteRow->branch_id, true);

            $plan = Quotes::planOf($quoteRow->id);

            if ($quoteRow->status !== 'accepted' || !$plan) {
                throw ValidationException::withMessages(['note' => 'Aprueba el presupuesto para tener su plan de tratamiento y descontar sesiones.']);
            }

            $itemRow = DB::table('quote_items')->where('tenant_id', $tenant->id)->where('quote_id', $quoteRow->id)->where('id', $item)->first();
            abort_unless($itemRow, 404);

            $services = $itemRow->item_type === 'package'
                ? (Packages::planItems([$itemRow->reference_id])[$itemRow->reference_id] ?? collect())->pluck('service_id')->all()
                : [$itemRow->reference_id];

            $left = DB::table('customer_package_items')
                ->where('customer_package_id', $plan->id)
                ->whereIn('service_id', array_filter($services))
                ->whereColumn('sessions_used', '<', 'sessions_total')
                ->orderBy('position')
                ->value('service_id');

            if (!$left) {
                throw ValidationException::withMessages(['note' => 'A esta línea del plan ya no le quedan sesiones.']);
            }

            Packages::manualUse($tenant, $plan->id, $left, trim($validated['note']), $user);

            return $left;
        });

        $this->auditQuote($request, $tenant, 'quote.session_deducted', $quote, [
            'service' => DB::table('services')->where('id', $deducted)->value('name'),
            'reason'  => trim($validated['note']),
        ]);

        return $this->index($request);
    }

    public function createSale(Request $request, string $quote)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();

        $approvedFrom = null;

        $sale = DB::transaction(function () use ($tenant, $user, $quote, &$approvedFrom) {
            $row = DB::table('quotes')
                ->where('tenant_id', $tenant->id)
                ->where('id', $quote)
                ->lockForUpdate()
                ->first();

            abort_unless($row, 404);
            Access::assertBranch($user, $row->branch_id, true);

            if ($row->status !== 'accepted') {
                $approvedFrom = $row->status;
                DB::table('quotes')->where('id', $row->id)->update([
                    'status'       => 'accepted',
                    'accepted_at'  => now(),
                    'accepted_via' => 'staff',
                    'updated_at'   => now(),
                ]);

                DB::table('quote_status_history')->insert([
                    'id'            => (string) Str::uuid(),
                    'tenant_id'     => $tenant->id,
                    'quote_id'      => $row->id,
                    'from_status'   => $row->status,
                    'to_status'     => 'accepted',
                    'actor_user_id' => $user->id,
                    'note'          => 'Presupuesto aprobado al pasar a cobro.',
                    'created_at'    => now(),
                ]);

                $row = DB::table('quotes')->where('id', $row->id)->first();
            }

            $sale = Quotes::ensureSale($tenant, $row, $user);
            Quotes::createPlan($tenant, DB::table('quotes')->where('id', $row->id)->first(), $user);

            return $sale;
        });

        if ($approvedFrom) {
            $this->auditQuote($request, $tenant, 'quote.approved', $quote, [
                'status' => Quotes::STATUSES['accepted'], 'note' => 'Presupuesto aprobado al pasar a cobro.', 'sale' => $sale->code ?? null,
            ], ['status' => Quotes::STATUSES[$approvedFrom] ?? $approvedFrom]);
        }

        $response = $this->index($request)->getData(true);
        $response['created_sale_id'] = $sale->id;

        return response()->json($response);
    }

    private function acceptedQuote(Request $request, Tenant $tenant, string $quote, bool $lock = false): object
    {
        $row = DB::table('quotes')->where('tenant_id', $tenant->id)->where('id', $quote)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        abort_unless($row, 404);
        Access::assertBranch($request->user(), $row->branch_id, true);

        if ($row->status !== 'accepted') {
            throw ValidationException::withMessages(['reason' => 'Solo se anula así un presupuesto aprobado.']);
        }

        return $row;
    }

    public function cancelPreview(Request $request, string $quote)
    {
        $tenant = $this->tenant($request);
        $row = $this->acceptedQuote($request, $tenant, $quote);
        $preview = Quotes::cancelPreview($tenant, $row);
        $branchId = $preview['sale']['branch_id'] ?? $row->branch_id;

        return response()->json($preview + [
            'can_refund' => $request->user()->allows('sales.delete'),
            'methods'    => collect(Sales::METHODS)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'cash'       => $branchId ? Cash::openSessions($tenant->id, $branchId)->map(fn ($s) => ['id' => $s->id, 'register_name' => $s->register_name])->values() : [],
        ]);
    }

    public function cancelAccepted(Request $request, string $quote)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();

        $validated = $request->validate([
            'reason'          => 'required|string|max:200',
            'refund_amount'   => 'nullable|numeric|min:0|max:9999999',
            'method'          => ['required_with:refund_amount', 'nullable', Rule::in(array_keys(Sales::METHODS))],
            'cash_session_id' => 'nullable|uuid',
            'debt_action'     => ['nullable', Rule::in(['charge', 'forgive'])],
        ], [
            'reason.required' => 'Escribe por qué se anula el presupuesto.',
        ]);

        if ((float) ($validated['refund_amount'] ?? 0) > 0 && !$user->allows('sales.delete')) {
            abort(403, Access::denial('sales.delete', $tenant));
        }

        $result = DB::transaction(function () use ($request, $tenant, $user, $quote, $validated) {
            $row = $this->acceptedQuote($request, $tenant, $quote, true);

            return Quotes::cancelAccepted($tenant, $row, $validated, $user);
        });

        $this->auditQuote($request, $tenant, 'quote.cancelled', $quote, [
            'status'       => Quotes::STATUSES['cancelled'],
            'reason'       => trim($validated['reason']),
            'refund'       => $result['refunded'] > 0 ? $result['refunded'] : null,
            'method'       => $result['refunded'] > 0 ? (Sales::METHODS[$validated['method']] ?? $validated['method']) : null,
            'debt_action'  => isset($validated['debt_action']) ? ($validated['debt_action'] === 'charge' ? 'Se cobra lo usado' : 'No se cobra lo que faltaba') : null,
            'balance'      => $result['balance'] > 0.001 ? $result['balance'] : null,
            'appointments' => $result['cancelled_appointments'] ?: null,
        ], ['status' => Quotes::STATUSES['accepted']]);

        $response = $this->index($request)->getData(true);
        $response['cancel_result'] = $result;

        return response()->json($response);
    }
}
