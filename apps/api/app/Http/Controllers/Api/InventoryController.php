<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\Tenant;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Currency;
use App\Support\Inventory;
use App\Support\Person;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    private function context(Request $request, string $permission = 'inventory.view'): array
    {
        $user = $request->user();
        abort_unless($user && $user->tenant_id, 403);
        abort_unless(Inventory::ready(), 503, Inventory::NOT_READY);
        abort_unless($user->allows($permission), 403, Access::denial($permission, $user->tenant));

        return [$user->tenant, $user];
    }

    public function index(Request $request)
    {
        [$tenant, $user] = $this->context($request, 'inventory.view');

        return response()->json($this->boardPayload($tenant, $user));
    }

    private function boardPayload(Tenant $tenant, $user): array
    {
        $warehouses = Inventory::ensureWarehouses($tenant);
        $branchLimit = Access::branchLimit($user);
        $currency = Currency::of($tenant);
        $rules = Inventory::rules($tenant);

        $categories = DB::table('product_categories')
            ->where('tenant_id', $tenant->id)
            ->pluck('name', 'id')
            ->all();

        $productsRaw = DB::table('products')
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        $balances = DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)
            ->get()
            ->groupBy('product_id');

        $visibleWarehouseIds = $branchLimit
            ? $warehouses->where('branch_id', $branchLimit)->pluck('id')->all()
            : $warehouses->pluck('id')->all();

        $products = $productsRaw->map(function ($p) use ($balances, $warehouses, $visibleWarehouseIds, $categories, $currency) {
            $rows = ($balances[$p->id] ?? collect())->keyBy('warehouse_id');
            $stocks = [];
            $totalStock = 0.0;
            $totalTransit = 0.0;

            foreach ($warehouses as $w) {
                $b = $rows->get($w->id);
                $qty = Inventory::qty($b?->quantity ?? 0);
                $tr = Inventory::qty($b?->in_transit ?? 0);
                $stocks[$w->id] = [
                    'warehouse_id' => $w->id,
                    'branch_id'    => $w->branch_id,
                    'branch_name'  => $w->branch_name,
                    'quantity'     => $qty,
                    'in_transit'   => $tr,
                ];

                if (in_array($w->id, $visibleWarehouseIds, true)) {
                    $totalStock += $qty;
                    $totalTransit += $tr;
                }
            }

            $totalStock = Inventory::qty($totalStock);
            $totalTransit = Inventory::qty($totalTransit);
            $cost = round((float) ($p->cost ?? 0), 2);
            $price = round((float) ($p->price ?? 0), 2);
            $minStock = Inventory::qty($p->min_stock ?? 0);
            $track = (bool) ($p->track_stock ?? true);

            return [
                'id'               => $p->id,
                'name'             => $p->name,
                'sku'              => $p->sku,
                'kind'             => $p->kind,
                'kind_label'       => ProductController::KINDS[$p->kind] ?? $p->kind,
                'category_id'      => $p->category_id,
                'category'         => $categories[$p->category_id] ?? null,
                'price'            => $price,
                'cost'             => $cost,
                'min_stock'        => $minStock,
                'track_stock'      => $track,
                'status'           => $p->status,
                'currency'         => $currency,
                'stocks'           => $stocks,
                'total_stock'      => $totalStock,
                'total_in_transit' => $totalTransit,
                'stock_value'      => round(max(0, $totalStock) * $cost, 2),
                'out_of_stock'     => $track && $totalStock <= 0,
                'low_stock'        => $track && $totalStock > 0 && $minStock > 0 && $totalStock <= $minStock,
            ];
        })->values();

        $movements = DB::table('inventory_movements as m')
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->join('branches as b', 'b.id', '=', 'w.branch_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.actor_user_id')
            ->where('m.tenant_id', $tenant->id)
            ->when($branchLimit, fn ($q) => $q->where('w.branch_id', $branchLimit))
            ->orderByDesc('m.created_at')
            ->limit(300)
            ->get([
                'm.id',
                'm.warehouse_id',
                'm.product_id',
                'm.type',
                'm.quantity',
                'm.unit_cost',
                'm.balance_after',
                'm.reference_type',
                'm.reference_id',
                'm.reason',
                'm.created_at',
                'p.name as product_name',
                'p.sku as product_sku',
                'w.branch_id',
                'b.name as branch_name',
                'u.first_name',
                'u.last_name',
            ])
            ->map(fn ($m) => [
                'id'             => $m->id,
                'warehouse_id'   => $m->warehouse_id,
                'branch_id'      => $m->branch_id,
                'branch_name'    => $m->branch_name,
                'product_id'     => $m->product_id,
                'product_name'   => $m->product_name,
                'product_sku'    => $m->product_sku,
                'type'           => $m->type,
                'type_label'     => Inventory::MOVEMENT_LABELS[$m->type] ?? $m->type,
                'quantity'       => Inventory::qty($m->quantity),
                'unit_cost'      => round((float) $m->unit_cost, 2),
                'balance_after'  => Inventory::qty($m->balance_after),
                'reference_type' => $m->reference_type,
                'reference_id'   => $m->reference_id,
                'reason'         => $m->reason,
                'by'             => Person::name($m) ?: 'Sistema',
                'created_at'     => Carbon::parse($m->created_at)->toIso8601String(),
            ])->values();

        $transfersRaw = DB::table('stock_transfers as t')
            ->join('warehouses as wf', 'wf.id', '=', 't.from_warehouse_id')
            ->join('branches as bf', 'bf.id', '=', 'wf.branch_id')
            ->join('warehouses as wt', 'wt.id', '=', 't.to_warehouse_id')
            ->join('branches as bt', 'bt.id', '=', 'wt.branch_id')
            ->leftJoin('users as uc', 'uc.id', '=', 't.created_by')
            ->leftJoin('users as ur', 'ur.id', '=', 't.received_by')
            ->where('t.tenant_id', $tenant->id)
            ->when($branchLimit, fn ($q) => $q->where(fn ($sub) => $sub->where('wf.branch_id', $branchLimit)->orWhere('wt.branch_id', $branchLimit)))
            ->orderByDesc('t.created_at')
            ->limit(150)
            ->get([
                't.*',
                'wf.branch_id as from_branch_id',
                'bf.name as from_branch_name',
                'wt.branch_id as to_branch_id',
                'bt.name as to_branch_name',
                'uc.first_name as created_first_name',
                'uc.last_name as created_last_name',
                'ur.first_name as received_first_name',
                'ur.last_name as received_last_name',
            ]);

        $transferItems = $transfersRaw->isEmpty()
            ? collect()
            : DB::table('stock_transfer_items as ti')
                ->join('products as p', 'p.id', '=', 'ti.product_id')
                ->whereIn('ti.stock_transfer_id', $transfersRaw->pluck('id')->all())
                ->get(['ti.*', 'p.name as product_name', 'p.sku as product_sku'])
                ->groupBy('stock_transfer_id');

        $transfers = $transfersRaw->map(function ($t) use ($transferItems) {
            $items = ($transferItems[$t->id] ?? collect())->map(fn ($i) => [
                'id'                => $i->id,
                'product_id'        => $i->product_id,
                'product_name'      => $i->product_name,
                'product_sku'       => $i->product_sku,
                'quantity'          => Inventory::qty($i->quantity),
                'received_quantity' => Inventory::qty($i->received_quantity),
            ])->values()->all();

            return [
                'id'                => $t->id,
                'code'              => $t->code,
                'from_warehouse_id' => $t->from_warehouse_id,
                'from_branch_id'    => $t->from_branch_id,
                'from_branch_name'  => $t->from_branch_name,
                'to_warehouse_id'   => $t->to_warehouse_id,
                'to_branch_id'      => $t->to_branch_id,
                'to_branch_name'    => $t->to_branch_name,
                'status'            => $t->status,
                'status_label'      => Inventory::TRANSFER_STATUS_LABELS[$t->status] ?? $t->status,
                'notes'             => $t->notes,
                'created_by_name'   => trim("{$t->created_first_name} {$t->created_last_name}") ?: 'Sistema',
                'received_by_name'  => trim("{$t->received_first_name} {$t->received_last_name}") ?: null,
                'sent_at'           => $t->sent_at ? Carbon::parse($t->sent_at)->toIso8601String() : null,
                'received_at'       => $t->received_at ? Carbon::parse($t->received_at)->toIso8601String() : null,
                'created_at'        => Carbon::parse($t->created_at)->toIso8601String(),
                'items'             => $items,
                'total_units'       => Inventory::qty(array_sum(array_column($items, 'quantity'))),
            ];
        })->values();

        $suppliersRaw = DB::table('suppliers')
            ->where('tenant_id', $tenant->id)
            ->orderBy('name')
            ->get();

        $supplierStats = DB::table('purchase_orders')
            ->where('tenant_id', $tenant->id)
            ->whereNotIn('status', ['cancelled'])
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id, COUNT(*) as orders_count, COALESCE(SUM(total), 0) as total_bought')
            ->get()
            ->keyBy('supplier_id');

        $suppliers = $suppliersRaw->map(function ($s) use ($supplierStats) {
            $st = $supplierStats->get($s->id);

            return [
                'id'              => $s->id,
                'name'            => $s->name,
                'document_number' => $s->document_number,
                'contact_name'    => $s->contact_name,
                'email'           => $s->email,
                'phone'           => $s->phone,
                'address'         => $s->address,
                'status'          => $s->status,
                'orders_count'    => (int) ($st?->orders_count ?? 0),
                'total_bought'    => round((float) ($st?->total_bought ?? 0), 2),
                'created_at'      => Carbon::parse($s->created_at)->toIso8601String(),
            ];
        })->values();

        $purchasesRaw = DB::table('purchase_orders as po')
            ->join('suppliers as s', 's.id', '=', 'po.supplier_id')
            ->join('warehouses as w', 'w.id', '=', 'po.warehouse_id')
            ->join('branches as b', 'b.id', '=', 'w.branch_id')
            ->leftJoin('users as u', 'u.id', '=', 'po.created_by')
            ->where('po.tenant_id', $tenant->id)
            ->when($branchLimit, fn ($q) => $q->where('w.branch_id', $branchLimit))
            ->orderByDesc('po.created_at')
            ->limit(150)
            ->get([
                'po.*',
                's.name as supplier_name',
                'w.branch_id',
                'b.name as branch_name',
                'u.first_name',
                'u.last_name',
            ]);

        $purchaseItems = $purchasesRaw->isEmpty()
            ? collect()
            : DB::table('purchase_order_items as pi')
                ->join('products as p', 'p.id', '=', 'pi.product_id')
                ->whereIn('pi.purchase_order_id', $purchasesRaw->pluck('id')->all())
                ->get(['pi.*', 'p.name as product_name', 'p.sku as product_sku'])
                ->groupBy('purchase_order_id');

        $purchases = $purchasesRaw->map(function ($po) use ($purchaseItems, $currency) {
            $items = ($purchaseItems[$po->id] ?? collect())->map(fn ($i) => [
                'id'                => $i->id,
                'product_id'        => $i->product_id,
                'product_name'      => $i->product_name,
                'product_sku'       => $i->product_sku,
                'quantity'          => Inventory::qty($i->quantity),
                'received_quantity' => Inventory::qty($i->received_quantity),
                'pending_quantity'  => Inventory::qty(max(0, (float) $i->quantity - (float) $i->received_quantity)),
                'unit_cost'         => round((float) $i->unit_cost, 2),
                'total'             => round((float) $i->total, 2),
            ])->values()->all();

            return [
                'id'              => $po->id,
                'code'            => $po->code,
                'supplier_id'     => $po->supplier_id,
                'supplier_name'   => $po->supplier_name,
                'warehouse_id'    => $po->warehouse_id,
                'branch_id'       => $po->branch_id,
                'branch_name'     => $po->branch_name,
                'status'          => $po->status,
                'status_label'    => Inventory::PURCHASE_STATUS_LABELS[$po->status] ?? $po->status,
                'subtotal'        => round((float) $po->subtotal, 2),
                'tax'             => round((float) $po->tax, 2),
                'total'           => round((float) $po->total, 2),
                'currency'        => $currency,
                'expected_at'     => $po->expected_at,
                'received_at'     => $po->received_at ? Carbon::parse($po->received_at)->toIso8601String() : null,
                'notes'           => $po->notes,
                'created_by_name' => Person::name($po) ?: 'Sistema',
                'created_at'      => Carbon::parse($po->created_at)->toIso8601String(),
                'items'           => $items,
            ];
        })->values();

        return [
            'currency'     => $currency,
            'rules'        => $rules,
            'branch_limit' => $branchLimit,
            'can'          => [
                'create'   => $user->allows('inventory.create'),
                'edit'     => $user->allows('inventory.edit'),
                'delete'   => $user->allows('inventory.delete'),
                'products' => $user->allows('products.create'),
                'export'   => $user->allows('data.export'),
            ],
            'warehouses' => $warehouses->map(fn ($w) => [
                'id'           => $w->id,
                'branch_id'    => $w->branch_id,
                'branch_name'  => $w->branch_name,
                'branch_code'  => $w->branch_code,
                'name'         => $w->name,
                'is_main'      => (bool) $w->is_main,
                'status'       => $w->status,
                'can_operate'  => !$branchLimit || $w->branch_id === $branchLimit,
            ])->values(),
            'categories' => collect($categories)->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->sortBy('name')->values(),
            'kinds'      => collect(ProductController::KINDS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'products'   => $products,
            'movements'  => $movements,
            'transfers'  => $transfers,
            'suppliers'  => $suppliers,
            'purchases'  => $purchases,
        ];
    }

    public function storeMovement(Request $request)
    {
        $mode = (string) $request->input('mode', 'entry');
        $perm = $mode === 'count' ? 'inventory.edit' : 'inventory.create';
        [$tenant, $user] = $this->context($request, $perm);

        $validated = $request->validate([
            'mode'         => ['required', Rule::in(['entry', 'exit', 'count'])],
            'warehouse_id' => ['required', 'uuid', Rule::exists('warehouses', 'id')->where('tenant_id', $tenant->id)],
            'product_id'   => ['required', 'uuid', Rule::exists('products', 'id')->where('tenant_id', $tenant->id)],
            'type'         => ['nullable', Rule::in(['initial', 'purchase', 'adjustment', 'service_consumption', 'return'])],
            'quantity'     => 'required|numeric|min:0|max:999999',
            'unit_cost'    => 'nullable|numeric|min:0|max:9999999',
            'reason'       => 'nullable|string|max:255',
        ], [
            'warehouse_id.required' => 'Elige la sede o almacén.',
            'product_id.required'   => 'Elige el producto.',
            'quantity.required'     => 'Escribe la cantidad.',
            'quantity.numeric'      => 'Escribe la cantidad como un número.',
            'quantity.min'          => 'La cantidad no puede ser negativa.',
        ]);

        $warehouse = DB::table('warehouses')->where('tenant_id', $tenant->id)->where('id', $validated['warehouse_id'])->first();
        abort_unless($warehouse, 404);
        Access::assertBranch($user, $warehouse->branch_id);

        if ($validated['mode'] !== 'count' && (float) $validated['quantity'] <= 0) {
            throw ValidationException::withMessages(['quantity' => 'La cantidad debe ser mayor que cero.']);
        }

        if ($validated['mode'] === 'exit' && empty(trim((string) ($validated['reason'] ?? '')))) {
            throw ValidationException::withMessages(['reason' => 'Indica el motivo de la salida o ajuste.']);
        }

        $movement = DB::transaction(function () use ($tenant, $user, $validated) {
            $qty = round((float) $validated['quantity'], 4);
            $reason = trim((string) ($validated['reason'] ?? '')) ?: null;

            if ($validated['mode'] === 'count') {
                return Inventory::adjustCount(
                    tenant: $tenant,
                    warehouseId: $validated['warehouse_id'],
                    productId: $validated['product_id'],
                    countedQuantity: $qty,
                    actor: $user,
                    reason: $reason ?: 'Conteo físico de inventario',
                );
            }

            if ($validated['mode'] === 'entry') {
                $type = in_array($validated['type'] ?? null, ['initial', 'purchase', 'return', 'adjustment'], true)
                    ? $validated['type']
                    : 'purchase';

                $prevTotalStock = (float) DB::table('stock_balances')
                    ->where('tenant_id', $tenant->id)
                    ->where('product_id', $validated['product_id'])
                    ->sum('quantity');

                $unitCost = isset($validated['unit_cost']) && $validated['unit_cost'] !== '' && $validated['unit_cost'] !== null
                    ? round((float) $validated['unit_cost'], 2)
                    : null;

                $movement = Inventory::recordMovement(
                    tenant: $tenant,
                    warehouseId: $validated['warehouse_id'],
                    productId: $validated['product_id'],
                    type: $type,
                    quantity: $qty,
                    actor: $user,
                    reason: $reason ?: (Inventory::MOVEMENT_LABELS[$type] ?? 'Ingreso de stock'),
                    unitCost: $unitCost,
                    checkNegative: false,
                );

                if ($unitCost !== null && in_array($type, ['purchase', 'initial'], true)) {
                    Inventory::updateProductCostOnPurchase($tenant, $validated['product_id'], $qty, $unitCost, $prevTotalStock);
                }

                return $movement;
            }

            $type = in_array($validated['type'] ?? null, ['adjustment', 'service_consumption'], true)
                ? $validated['type']
                : 'adjustment';

            return Inventory::recordMovement(
                tenant: $tenant,
                warehouseId: $validated['warehouse_id'],
                productId: $validated['product_id'],
                type: $type,
                quantity: -$qty,
                actor: $user,
                reason: $reason,
                checkNegative: true,
            );
        });

        $this->auditMovement($request, $validated, $warehouse, $movement);

        return response()->json($this->boardPayload($tenant, $user), 201);
    }

    private function audit(Request $request, string $action, array $options): void
    {
        Audit::log($request, $action, ['tenant' => $request->user()->tenant_id] + $options);
    }

    private function branchName(?string $branchId): ?string
    {
        return $branchId ? DB::table('branches')->where('id', $branchId)->value('name') : null;
    }

    private function auditMovement(Request $request, array $validated, object $warehouse, ?object $movement): void
    {
        $counted = Inventory::qty($validated['quantity']);
        $reason = trim((string) ($validated['reason'] ?? '')) ?: null;
        $options = [
            'entity_type'  => 'product',
            'entity_id'    => $validated['product_id'],
            'entity_label' => DB::table('products')->where('id', $validated['product_id'])->value('name'),
            'branch_id'    => $warehouse->branch_id,
        ];

        if ($validated['mode'] === 'count') {
            $this->audit($request, 'inventory.count', $options + [
                'before' => ['stock' => $movement ? $movement->balance_before : $counted],
                'after'  => array_filter([
                    'branch'     => $this->branchName($warehouse->branch_id),
                    'counted'    => $counted,
                    'difference' => $movement ? $movement->quantity : 0,
                    'stock'      => $counted,
                    'reason'     => $reason,
                ], fn ($value) => $value !== null),
            ]);

            return;
        }

        $this->audit($request, 'inventory.movement', $options + [
            'before' => ['stock' => $movement->balance_before],
            'after'  => array_filter([
                'movement_type' => Inventory::MOVEMENT_LABELS[$movement->type] ?? $movement->type,
                'branch'        => $this->branchName($warehouse->branch_id),
                'quantity'      => $movement->quantity,
                'unit_cost'     => $validated['mode'] === 'entry' && isset($validated['unit_cost']) && $validated['unit_cost'] !== null ? round((float) $validated['unit_cost'], 2) : null,
                'reason'        => $reason,
                'stock'         => $movement->balance_after,
            ], fn ($value) => $value !== null),
        ]);
    }

    private function suppliesSummary(string $tenantId, string $serviceId): array
    {
        return DB::table('service_supplies as s')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->where('s.tenant_id', $tenantId)
            ->where('s.service_id', $serviceId)
            ->orderBy('p.name')
            ->get(['p.name', 's.quantity'])
            ->map(fn ($row) => $row->name . ' × ' . Inventory::formatQty($row->quantity))
            ->all();
    }

    private function suppliesPayload(Tenant $tenant, $user, ?Service $service): array
    {
        $supplies = $service
            ? DB::table('service_supplies as ss')
                ->join('products as p', 'p.id', '=', 'ss.product_id')
                ->where('ss.tenant_id', $tenant->id)
                ->where('ss.service_id', $service->id)
                ->whereNull('p.deleted_at')
                ->orderBy('p.name')
                ->get(['ss.product_id', 'ss.quantity', 'p.name as product_name', 'p.sku as product_sku', 'p.cost as unit_cost'])
                ->map(fn ($row) => [
                    'product_id'   => $row->product_id,
                    'product_name' => $row->product_name,
                    'product_sku'  => $row->product_sku,
                    'quantity'     => Inventory::qty($row->quantity),
                    'unit_cost'    => round((float) $row->unit_cost, 2),
                ])->values()
            : collect();

        $assigned = $supplies->pluck('product_id')->all();

        $products = DB::table('products')
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where(fn ($sub) => $sub->whereIn('kind', ['supply', 'both'])->where('status', 'active'))
                ->orWhereIn('id', $assigned ?: ['00000000-0000-0000-0000-000000000000']))
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'cost'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'cost' => round((float) $p->cost, 2)])
            ->values();

        return [
            'currency'           => Currency::of($tenant),
            'consume_on_service' => (bool) Inventory::rules($tenant)['consume_on_service'],
            'can_edit'           => $user->allows('inventory.edit'),
            'products'           => $products,
            'supplies'           => $supplies,
        ];
    }

    public function serviceSupplies(Request $request, string $service)
    {
        $user = $request->user();
        abort_unless($user && $user->tenant_id, 403);
        abort_unless(Inventory::ready(), 503, Inventory::NOT_READY);

        $tenant = $user->tenant;
        $svc = $service === 'new' ? null : Service::where('tenant_id', $tenant->id)->findOrFail($service);

        return response()->json($this->suppliesPayload($tenant, $user, $svc));
    }

    public function updateServiceSupplies(Request $request, string $service)
    {
        [$tenant, $user] = $this->context($request, 'inventory.edit');

        $svc = Service::where('tenant_id', $tenant->id)->findOrFail($service);

        $validated = $request->validate([
            'supplies'              => 'present|array|max:40',
            'supplies.*.product_id' => ['required', 'uuid', 'distinct', Rule::exists('products', 'id')->where('tenant_id', $tenant->id)],
            'supplies.*.quantity'   => 'required|numeric|gt:0|max:99999',
        ], [
            'supplies.*.product_id.required' => 'Elige el insumo.',
            'supplies.*.product_id.distinct' => 'No repitas el mismo insumo en el mismo servicio.',
            'supplies.*.quantity.required'   => 'Escribe la cantidad que se consume.',
            'supplies.*.quantity.gt'         => 'La cantidad por servicio debe ser mayor que cero.',
        ]);

        $previous = $this->suppliesSummary($tenant->id, $svc->id);

        DB::transaction(function () use ($tenant, $svc, $validated) {
            DB::table('service_supplies')
                ->where('tenant_id', $tenant->id)
                ->where('service_id', $svc->id)
                ->delete();

            if (!empty($validated['supplies'])) {
                DB::table('service_supplies')->insert(array_map(fn (array $item) => [
                    'tenant_id'  => $tenant->id,
                    'service_id' => $svc->id,
                    'product_id' => $item['product_id'],
                    'quantity'   => round((float) $item['quantity'], 4),
                ], $validated['supplies']));
            }
        });

        [$before, $after] = Audit::changed(['supplies' => $previous], ['supplies' => $this->suppliesSummary($tenant->id, $svc->id)]);

        if ($after) {
            $this->audit($request, 'service.updated', [
                'entity_type'  => 'service',
                'entity_id'    => $svc->id,
                'entity_label' => $svc->name,
                'before'       => $before,
                'after'        => $after,
            ]);
        }

        return response()->json($this->suppliesPayload($tenant, $user, $svc));
    }

    public function storeTransfer(Request $request)
    {
        [$tenant, $user] = $this->context($request, 'inventory.create');

        $validated = $request->validate([
            'from_warehouse_id'  => ['required', 'uuid', Rule::exists('warehouses', 'id')->where('tenant_id', $tenant->id)],
            'to_warehouse_id'    => ['required', 'uuid', 'different:from_warehouse_id', Rule::exists('warehouses', 'id')->where('tenant_id', $tenant->id)],
            'notes'              => 'nullable|string|max:500',
            'items'              => 'required|array|min:1|max:50',
            'items.*.product_id' => ['required', 'uuid', 'distinct', Rule::exists('products', 'id')->where('tenant_id', $tenant->id)],
            'items.*.quantity'   => 'required|numeric|gt:0|max:99999',
        ], [
            'from_warehouse_id.required'  => 'Elige la sede de origen.',
            'to_warehouse_id.required'    => 'Elige la sede de destino.',
            'to_warehouse_id.different'   => 'La sede de destino debe ser distinta a la de origen.',
            'items.required'              => 'Agrega al menos un producto para trasladar.',
            'items.min'                   => 'Agrega al menos un producto para trasladar.',
            'items.*.product_id.distinct' => 'Cada producto debe aparecer una sola vez en el traslado.',
            'items.*.quantity.gt'         => 'La cantidad a trasladar debe ser mayor que cero.',
        ]);

        $fromWarehouse = DB::table('warehouses as w')
            ->join('branches as b', 'b.id', '=', 'w.branch_id')
            ->where('w.tenant_id', $tenant->id)
            ->where('w.id', $validated['from_warehouse_id'])
            ->first(['w.*', 'b.name as branch_name']);

        $toWarehouse = DB::table('warehouses as w')
            ->join('branches as b', 'b.id', '=', 'w.branch_id')
            ->where('w.tenant_id', $tenant->id)
            ->where('w.id', $validated['to_warehouse_id'])
            ->first(['w.*', 'b.name as branch_name']);

        abort_unless($fromWarehouse && $toWarehouse, 404);
        Access::assertBranch($user, $fromWarehouse->branch_id);

        $twoStep = Inventory::rules($tenant)['transfer_two_step'];

        $created = DB::transaction(function () use ($tenant, $user, $validated, $fromWarehouse, $toWarehouse, $twoStep) {
            $transferId = (string) Str::uuid();
            $code = Inventory::nextTransferCode($tenant->id);
            $now = now();

            DB::table('stock_transfers')->insert([
                'id'                => $transferId,
                'tenant_id'         => $tenant->id,
                'code'              => $code,
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id'   => $toWarehouse->id,
                'status'            => $twoStep ? 'in_transit' : 'received',
                'notes'             => isset($validated['notes']) ? trim((string) $validated['notes']) ?: null : null,
                'created_by'        => $user->id,
                'received_by'       => $twoStep ? null : $user->id,
                'sent_at'           => $now,
                'received_at'       => $twoStep ? null : $now,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);

            foreach ($validated['items'] as $idx => $item) {
                $qty = round((float) $item['quantity'], 4);
                $productId = $item['product_id'];

                Inventory::recordMovement(
                    tenant: $tenant,
                    warehouseId: $fromWarehouse->id,
                    productId: $productId,
                    type: 'transfer_out',
                    quantity: -$qty,
                    actor: $user,
                    reason: "Traslado {$code} hacia {$toWarehouse->branch_name}",
                    referenceType: 'transfer',
                    referenceId: $transferId,
                    checkNegative: true,
                    errorField: "items.{$idx}.quantity",
                );

                DB::table('stock_transfer_items')->insert([
                    'id'                => (string) Str::uuid(),
                    'tenant_id'         => $tenant->id,
                    'stock_transfer_id' => $transferId,
                    'product_id'        => $productId,
                    'quantity'          => $qty,
                    'received_quantity' => $twoStep ? 0 : $qty,
                ]);

                if ($twoStep) {
                    $destBalance = DB::table('stock_balances')
                        ->where('tenant_id', $tenant->id)
                        ->where('warehouse_id', $toWarehouse->id)
                        ->where('product_id', $productId)
                        ->lockForUpdate()
                        ->first();

                    if ($destBalance) {
                        DB::table('stock_balances')->where('id', $destBalance->id)->update([
                            'in_transit' => round((float) $destBalance->in_transit + $qty, 4),
                            'updated_at' => $now,
                        ]);
                    } else {
                        DB::table('stock_balances')->insert([
                            'id'           => (string) Str::uuid(),
                            'tenant_id'    => $tenant->id,
                            'warehouse_id' => $toWarehouse->id,
                            'product_id'   => $productId,
                            'quantity'     => 0,
                            'in_transit'   => $qty,
                            'updated_at'   => $now,
                        ]);
                    }
                } else {
                    Inventory::recordMovement(
                        tenant: $tenant,
                        warehouseId: $toWarehouse->id,
                        productId: $productId,
                        type: 'transfer_in',
                        quantity: $qty,
                        actor: $user,
                        reason: "Traslado {$code} desde {$fromWarehouse->branch_name}",
                        referenceType: 'transfer',
                        referenceId: $transferId,
                        checkNegative: false,
                    );
                }
            }

            return ['id' => $transferId, 'code' => $code];
        });

        $this->audit($request, 'transfer.created', [
            'entity_type'  => 'transfer',
            'entity_id'    => $created['id'],
            'entity_label' => $created['code'],
            'branch_id'    => $fromWarehouse->branch_id,
            'after'        => [
                'from_branch'     => $fromWarehouse->branch_name,
                'to_branch'       => $toWarehouse->branch_name,
                'items_count'     => count($validated['items']),
                'transfer_status' => Inventory::TRANSFER_STATUS_LABELS[$twoStep ? 'in_transit' : 'received'],
            ],
        ]);

        return response()->json($this->boardPayload($tenant, $user), 201);
    }

    public function receiveTransfer(Request $request, string $transfer)
    {
        [$tenant, $user] = $this->context($request, 'inventory.create');

        $validated = $request->validate([
            'notes'                     => 'nullable|string|max:500',
            'items'                     => 'nullable|array',
            'items.*.product_id'        => 'required_with:items|uuid',
            'items.*.received_quantity' => 'required_with:items|numeric|min:0|max:99999',
        ]);

        $received = DB::transaction(function () use ($tenant, $user, $transfer, $validated) {
            $row = DB::table('stock_transfers')
                ->where('tenant_id', $tenant->id)
                ->where('id', $transfer)
                ->lockForUpdate()
                ->first();

            abort_unless($row, 404);

            if ($row->status !== 'in_transit') {
                throw ValidationException::withMessages(['transfer' => 'Este traslado ya fue procesado.']);
            }

            $fromWarehouse = DB::table('warehouses as w')
                ->join('branches as b', 'b.id', '=', 'w.branch_id')
                ->where('w.id', $row->from_warehouse_id)
                ->first(['w.*', 'b.name as branch_name']);

            $toWarehouse = DB::table('warehouses as w')
                ->join('branches as b', 'b.id', '=', 'w.branch_id')
                ->where('w.id', $row->to_warehouse_id)
                ->first(['w.*', 'b.name as branch_name']);

            Access::assertBranch($user, $toWarehouse->branch_id);

            $items = DB::table('stock_transfer_items')
                ->where('tenant_id', $tenant->id)
                ->where('stock_transfer_id', $row->id)
                ->get();

            $receivedMap = collect($validated['items'] ?? [])
                ->mapWithKeys(fn ($i) => [$i['product_id'] => round((float) $i['received_quantity'], 4)])
                ->all();
            $short = 0;

            foreach ($items as $item) {
                $sentQty = round((float) $item->quantity, 4);
                $receivedQty = array_key_exists($item->product_id, $receivedMap)
                    ? min($sentQty, max(0, $receivedMap[$item->product_id]))
                    : $sentQty;

                $destBalance = DB::table('stock_balances')
                    ->where('tenant_id', $tenant->id)
                    ->where('warehouse_id', $toWarehouse->id)
                    ->where('product_id', $item->product_id)
                    ->lockForUpdate()
                    ->first();

                if ($destBalance) {
                    DB::table('stock_balances')->where('id', $destBalance->id)->update([
                        'in_transit' => max(0, round((float) $destBalance->in_transit - $sentQty, 4)),
                        'updated_at' => now(),
                    ]);
                }

                if ($receivedQty > 0) {
                    Inventory::recordMovement(
                        tenant: $tenant,
                        warehouseId: $toWarehouse->id,
                        productId: $item->product_id,
                        type: 'transfer_in',
                        quantity: $receivedQty,
                        actor: $user,
                        reason: "Recepción de traslado {$row->code} desde {$fromWarehouse->branch_name}",
                        referenceType: 'transfer',
                        referenceId: $row->id,
                        checkNegative: false,
                    );
                }

                $diff = round($sentQty - $receivedQty, 4);
                if ($diff > 0.0001) {
                    $short++;
                    Inventory::recordMovement(
                        tenant: $tenant,
                        warehouseId: $fromWarehouse->id,
                        productId: $item->product_id,
                        type: 'transfer_in',
                        quantity: $diff,
                        actor: $user,
                        reason: "Diferencia no recibida en traslado {$row->code} ({$toWarehouse->branch_name})",
                        referenceType: 'transfer',
                        referenceId: $row->id,
                        checkNegative: false,
                    );
                }

                DB::table('stock_transfer_items')->where('id', $item->id)->update([
                    'received_quantity' => $receivedQty,
                ]);
            }

            $notes = $row->notes;
            if (!empty($validated['notes'])) {
                $extra = trim((string) $validated['notes']);
                $notes = $notes ? "{$notes} · Recepción: {$extra}" : "Recepción: {$extra}";
            }

            DB::table('stock_transfers')->where('id', $row->id)->update([
                'status'      => 'received',
                'received_by' => $user->id,
                'received_at' => now(),
                'notes'       => $notes ? mb_substr($notes, 0, 500) : null,
                'updated_at'  => now(),
            ]);

            return [$row, $fromWarehouse, $toWarehouse, $items->count(), $short];
        });

        [$row, $fromWarehouse, $toWarehouse, $count, $short] = $received;

        $this->audit($request, 'transfer.received', [
            'entity_type'  => 'transfer',
            'entity_id'    => $row->id,
            'entity_label' => $row->code,
            'branch_id'    => $toWarehouse->branch_id,
            'before'       => ['transfer_status' => Inventory::TRANSFER_STATUS_LABELS[$row->status] ?? $row->status],
            'after'        => array_filter([
                'transfer_status' => Inventory::TRANSFER_STATUS_LABELS['received'],
                'from_branch'     => $fromWarehouse->branch_name,
                'to_branch'       => $toWarehouse->branch_name,
                'items_count'     => $count,
                'short_items'     => $short ?: null,
            ], fn ($value) => $value !== null),
        ]);

        return response()->json($this->boardPayload($tenant, $user));
    }

    public function cancelTransfer(Request $request, string $transfer)
    {
        [$tenant, $user] = $this->context($request, 'inventory.delete');

        $cancelled = DB::transaction(function () use ($tenant, $user, $transfer) {
            $row = DB::table('stock_transfers')
                ->where('tenant_id', $tenant->id)
                ->where('id', $transfer)
                ->lockForUpdate()
                ->first();

            abort_unless($row, 404);

            if ($row->status !== 'in_transit') {
                throw ValidationException::withMessages(['transfer' => 'Solo se pueden cancelar traslados que estén en tránsito.']);
            }

            $fromWarehouse = DB::table('warehouses')->where('id', $row->from_warehouse_id)->first();
            $toWarehouse = DB::table('warehouses')->where('id', $row->to_warehouse_id)->first();

            $branchLimit = Access::branchLimit($user);
            if ($branchLimit && $fromWarehouse->branch_id !== $branchLimit && $toWarehouse->branch_id !== $branchLimit) {
                Access::assertBranch($user, $fromWarehouse->branch_id);
            }

            $items = DB::table('stock_transfer_items')
                ->where('tenant_id', $tenant->id)
                ->where('stock_transfer_id', $row->id)
                ->get();

            foreach ($items as $item) {
                $qty = round((float) $item->quantity, 4);

                $destBalance = DB::table('stock_balances')
                    ->where('tenant_id', $tenant->id)
                    ->where('warehouse_id', $row->to_warehouse_id)
                    ->where('product_id', $item->product_id)
                    ->lockForUpdate()
                    ->first();

                if ($destBalance) {
                    DB::table('stock_balances')->where('id', $destBalance->id)->update([
                        'in_transit' => max(0, round((float) $destBalance->in_transit - $qty, 4)),
                        'updated_at' => now(),
                    ]);
                }

                Inventory::recordMovement(
                    tenant: $tenant,
                    warehouseId: $row->from_warehouse_id,
                    productId: $item->product_id,
                    type: 'transfer_in',
                    quantity: $qty,
                    actor: $user,
                    reason: "Cancelación de traslado {$row->code}",
                    referenceType: 'transfer',
                    referenceId: $row->id,
                    checkNegative: false,
                );
            }

            DB::table('stock_transfers')->where('id', $row->id)->update([
                'status'     => 'cancelled',
                'updated_at' => now(),
            ]);

            return [$row, $fromWarehouse, $toWarehouse, $items->count()];
        });

        [$row, $fromWarehouse, $toWarehouse, $count] = $cancelled;

        $this->audit($request, 'transfer.cancelled', [
            'entity_type'  => 'transfer',
            'entity_id'    => $row->id,
            'entity_label' => $row->code,
            'branch_id'    => $fromWarehouse->branch_id,
            'before'       => ['transfer_status' => Inventory::TRANSFER_STATUS_LABELS[$row->status] ?? $row->status],
            'after'        => [
                'transfer_status' => Inventory::TRANSFER_STATUS_LABELS['cancelled'],
                'from_branch'     => $this->branchName($fromWarehouse->branch_id),
                'to_branch'       => $this->branchName($toWarehouse->branch_id),
                'items_count'     => $count,
            ],
        ]);

        return response()->json($this->boardPayload($tenant, $user));
    }

    public function storeSupplier(Request $request)
    {
        [$tenant, $user] = $this->context($request, 'inventory.create');
        $data = $this->validatedSupplier($request, $tenant->id);
        $id = (string) Str::uuid();

        DB::table('suppliers')->insert($data + [
            'id'         => $id,
            'tenant_id'  => $tenant->id,
            'created_at' => now(),
        ]);

        $created = DB::table('suppliers')->where('id', $id)->first();
        $this->audit($request, 'supplier.created', [
            'entity_type'  => 'supplier',
            'entity_id'    => $id,
            'entity_label' => $created->name,
            'after'        => array_filter($this->supplierAuditable($created), fn ($value) => $value !== null),
        ]);

        return response()->json($this->boardPayload($tenant, $user), 201);
    }

    public function updateSupplier(Request $request, string $supplier)
    {
        [$tenant, $user] = $this->context($request, 'inventory.edit');
        $row = DB::table('suppliers')->where('tenant_id', $tenant->id)->where('id', $supplier)->first();
        abort_unless($row, 404);

        DB::table('suppliers')->where('id', $row->id)->update($this->validatedSupplier($request, $tenant->id, $row->id));
        $fresh = DB::table('suppliers')->where('id', $row->id)->first();
        [$before, $after] = Audit::changed($this->supplierAuditable($row), $this->supplierAuditable($fresh));

        if ($after) {
            $this->audit($request, 'supplier.updated', [
                'entity_type'  => 'supplier',
                'entity_id'    => $row->id,
                'entity_label' => $fresh->name,
                'before'       => $before,
                'after'        => $after,
            ]);
        }

        return response()->json($this->boardPayload($tenant, $user));
    }

    private function supplierAuditable(object $supplier): array
    {
        return [
            'name'            => $supplier->name,
            'document_number' => $supplier->document_number,
            'contact_name'    => $supplier->contact_name,
            'email'           => $supplier->email,
            'phone'           => $supplier->phone,
            'address'         => $supplier->address,
            'is_active'       => $supplier->status === 'active',
        ];
    }

    public function destroySupplier(Request $request, string $supplier)
    {
        [$tenant, $user] = $this->context($request, 'inventory.delete');
        $row = DB::table('suppliers')->where('tenant_id', $tenant->id)->where('id', $supplier)->first();
        abort_unless($row, 404);

        $hasOrders = DB::table('purchase_orders')->where('tenant_id', $tenant->id)->where('supplier_id', $row->id)->exists();

        if ($hasOrders) {
            DB::table('suppliers')->where('id', $row->id)->update(['status' => 'inactive', 'updated_at' => now()]);
        } else {
            DB::table('suppliers')->where('id', $row->id)->delete();
        }

        Audit::log($request, $hasOrders ? 'supplier.deactivated' : 'supplier.deleted', [
            'tenant'       => $tenant->id,
            'entity_type'  => 'supplier',
            'entity_id'    => $row->id,
            'entity_label' => $row->name,
        ]);

        return response()->json($this->boardPayload($tenant, $user));
    }

    private function validatedSupplier(Request $request, string $tenantId, ?string $ignoreId = null): array
    {
        $validated = $request->validate([
            'name'            => ['required', 'string', 'max:200'],
            'document_number' => 'nullable|string|max:30',
            'contact_name'    => 'nullable|string|max:150',
            'email'           => 'nullable|email|max:190',
            'phone'           => 'nullable|string|max:40',
            'address'         => 'nullable|string|max:255',
            'status'          => ['nullable', Rule::in(['active', 'inactive'])],
        ], [
            'name.required' => 'Escribe el nombre o razón social del proveedor.',
            'email.email'   => 'Escribe un correo electrónico válido.',
        ]);

        return [
            'name'            => trim($validated['name']),
            'document_number' => isset($validated['document_number']) ? trim((string) $validated['document_number']) ?: null : null,
            'contact_name'    => isset($validated['contact_name']) ? trim((string) $validated['contact_name']) ?: null : null,
            'email'           => isset($validated['email']) ? trim((string) $validated['email']) ?: null : null,
            'phone'           => isset($validated['phone']) ? trim((string) $validated['phone']) ?: null : null,
            'address'         => isset($validated['address']) ? trim((string) $validated['address']) ?: null : null,
            'status'          => $validated['status'] ?? 'active',
            'updated_at'      => now(),
        ];
    }

    public function storePurchase(Request $request)
    {
        [$tenant, $user] = $this->context($request, 'inventory.create');

        $validated = $request->validate([
            'supplier_id'        => ['required', 'uuid', Rule::exists('suppliers', 'id')->where('tenant_id', $tenant->id)],
            'warehouse_id'       => ['required', 'uuid', Rule::exists('warehouses', 'id')->where('tenant_id', $tenant->id)],
            'expected_at'        => 'nullable|date_format:Y-m-d',
            'notes'              => 'nullable|string|max:500',
            'receive_now'        => 'nullable|boolean',
            'items'              => 'required|array|min:1|max:60',
            'items.*.product_id' => ['required', 'uuid', 'distinct', Rule::exists('products', 'id')->where('tenant_id', $tenant->id)],
            'items.*.quantity'   => 'required|numeric|gt:0|max:99999',
            'items.*.unit_cost'  => 'required|numeric|min:0|max:9999999',
        ], [
            'supplier_id.required'        => 'Elige el proveedor.',
            'warehouse_id.required'       => 'Elige la sede donde ingresará la compra.',
            'items.required'              => 'Agrega al menos un producto a la orden de compra.',
            'items.*.product_id.distinct' => 'Cada producto debe ir en una sola línea.',
            'items.*.quantity.gt'         => 'La cantidad debe ser mayor que cero.',
            'items.*.unit_cost.min'       => 'El costo unitario no puede ser negativo.',
        ]);

        $warehouse = DB::table('warehouses')->where('tenant_id', $tenant->id)->where('id', $validated['warehouse_id'])->first();
        abort_unless($warehouse, 404);
        Access::assertBranch($user, $warehouse->branch_id);

        $receiveNow = (bool) ($validated['receive_now'] ?? false);

        $created = DB::transaction(function () use ($tenant, $user, $validated, $warehouse, $receiveNow) {
            $orderId = (string) Str::uuid();
            $code = Inventory::nextPurchaseCode($tenant->id);
            $now = now();

            $lines = [];
            $total = 0.0;

            foreach ($validated['items'] as $item) {
                $qty = round((float) $item['quantity'], 4);
                $cost = round((float) $item['unit_cost'], 2);
                $lineTotal = round($qty * $cost, 2);
                $total += $lineTotal;

                $lines[] = [
                    'id'                => (string) Str::uuid(),
                    'tenant_id'         => $tenant->id,
                    'purchase_order_id' => $orderId,
                    'product_id'        => $item['product_id'],
                    'quantity'          => $qty,
                    'received_quantity' => $receiveNow ? $qty : 0,
                    'unit_cost'         => $cost,
                    'total'             => $lineTotal,
                ];
            }

            $total = round($total, 2);

            DB::table('purchase_orders')->insert([
                'id'           => $orderId,
                'tenant_id'    => $tenant->id,
                'code'         => $code,
                'supplier_id'  => $validated['supplier_id'],
                'warehouse_id' => $warehouse->id,
                'status'       => $receiveNow ? 'received' : 'ordered',
                'subtotal'     => $total,
                'tax'          => 0,
                'total'        => $total,
                'expected_at'  => $validated['expected_at'] ?? null,
                'received_at'  => $receiveNow ? $now : null,
                'notes'        => isset($validated['notes']) ? trim((string) $validated['notes']) ?: null : null,
                'created_by'   => $user->id,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);

            DB::table('purchase_order_items')->insert($lines);

            if ($receiveNow) {
                foreach ($lines as $line) {
                    $prevTotalStock = (float) DB::table('stock_balances')
                        ->where('tenant_id', $tenant->id)
                        ->where('product_id', $line['product_id'])
                        ->sum('quantity');

                    Inventory::recordMovement(
                        tenant: $tenant,
                        warehouseId: $warehouse->id,
                        productId: $line['product_id'],
                        type: 'purchase',
                        quantity: $line['quantity'],
                        actor: $user,
                        reason: "Orden de compra {$code}",
                        unitCost: $line['unit_cost'],
                        referenceType: 'purchase_order',
                        referenceId: $orderId,
                        checkNegative: false,
                    );

                    Inventory::updateProductCostOnPurchase(
                        $tenant,
                        $line['product_id'],
                        $line['quantity'],
                        $line['unit_cost'],
                        $prevTotalStock,
                    );
                }
            }

            return DB::table('purchase_orders')->where('id', $orderId)->first();
        });

        $this->auditPurchase($request, 'purchase.created', $created, $warehouse, [], [
            'purchase_status' => Inventory::PURCHASE_STATUS_LABELS[$created->status] ?? $created->status,
            'items_count'     => count($validated['items']),
            'expected_at'     => $created->expected_at ? Carbon::parse($created->expected_at)->toDateString() : null,
        ]);

        return response()->json($this->boardPayload($tenant, $user), 201);
    }

    public function receivePurchase(Request $request, string $order)
    {
        [$tenant, $user] = $this->context($request, 'inventory.edit');

        $validated = $request->validate([
            'notes'                    => 'nullable|string|max:500',
            'items'                    => 'required|array|min:1',
            'items.*.id'               => 'required|uuid',
            'items.*.receive_quantity' => 'required|numeric|min:0|max:99999',
        ]);

        $received = DB::transaction(function () use ($tenant, $user, $order, $validated) {
            $po = DB::table('purchase_orders')
                ->where('tenant_id', $tenant->id)
                ->where('id', $order)
                ->lockForUpdate()
                ->first();

            abort_unless($po, 404);

            if (!in_array($po->status, ['ordered', 'partial'], true)) {
                throw ValidationException::withMessages(['order' => 'Esta orden de compra ya no tiene unidades pendientes por recibir.']);
            }

            $warehouse = DB::table('warehouses')->where('id', $po->warehouse_id)->first();
            Access::assertBranch($user, $warehouse->branch_id);

            $items = DB::table('purchase_order_items')
                ->where('tenant_id', $tenant->id)
                ->where('purchase_order_id', $po->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $anyReceived = false;
            $lines = 0;

            foreach ($validated['items'] as $inputItem) {
                $row = $items->get($inputItem['id']);
                if (!$row) {
                    continue;
                }

                $receiveQty = round((float) $inputItem['receive_quantity'], 4);
                if ($receiveQty <= 0) {
                    continue;
                }

                $pending = round((float) $row->quantity - (float) $row->received_quantity, 4);
                if ($receiveQty > $pending + 0.0001) {
                    throw ValidationException::withMessages([
                        'items' => 'La cantidad a recibir supera lo pendiente en una de las líneas.',
                    ]);
                }

                $anyReceived = true;
                $lines++;

                $prevTotalStock = (float) DB::table('stock_balances')
                    ->where('tenant_id', $tenant->id)
                    ->where('product_id', $row->product_id)
                    ->sum('quantity');

                Inventory::recordMovement(
                    tenant: $tenant,
                    warehouseId: $po->warehouse_id,
                    productId: $row->product_id,
                    type: 'purchase',
                    quantity: $receiveQty,
                    actor: $user,
                    reason: "Recepción de compra {$po->code}",
                    unitCost: (float) $row->unit_cost,
                    referenceType: 'purchase_order',
                    referenceId: $po->id,
                    checkNegative: false,
                );

                Inventory::updateProductCostOnPurchase(
                    $tenant,
                    $row->product_id,
                    $receiveQty,
                    (float) $row->unit_cost,
                    $prevTotalStock,
                );

                DB::table('purchase_order_items')->where('id', $row->id)->update([
                    'received_quantity' => round((float) $row->received_quantity + $receiveQty, 4),
                ]);
            }

            if (!$anyReceived) {
                throw ValidationException::withMessages([
                    'items' => 'Indica una cantidad mayor que cero en al menos un producto para registrar la recepción.',
                ]);
            }

            $freshItems = DB::table('purchase_order_items')
                ->where('purchase_order_id', $po->id)
                ->get();

            $allComplete = $freshItems->every(fn ($i) => (float) $i->received_quantity >= (float) $i->quantity - 0.0001);

            $notes = $po->notes;
            if (!empty($validated['notes'])) {
                $extra = trim((string) $validated['notes']);
                $notes = $notes ? "{$notes} · Recepción: {$extra}" : "Recepción: {$extra}";
            }

            DB::table('purchase_orders')->where('id', $po->id)->update([
                'status'      => $allComplete ? 'received' : 'partial',
                'received_at' => $allComplete ? now() : $po->received_at,
                'notes'       => $notes ? mb_substr($notes, 0, 500) : null,
                'updated_at'  => now(),
            ]);

            return [$po, $warehouse, $allComplete ? 'received' : 'partial', $lines];
        });

        [$po, $warehouse, $status, $lines] = $received;

        $this->auditPurchase($request, 'purchase.received', $po, $warehouse, [
            'purchase_status' => Inventory::PURCHASE_STATUS_LABELS[$po->status] ?? $po->status,
        ], [
            'purchase_status' => Inventory::PURCHASE_STATUS_LABELS[$status],
            'received_lines'  => $lines,
        ]);

        return response()->json($this->boardPayload($tenant, $user));
    }

    public function cancelPurchase(Request $request, string $order)
    {
        [$tenant, $user] = $this->context($request, 'inventory.delete');

        [$po, $warehouse] = DB::transaction(function () use ($tenant, $user, $order) {
            $po = DB::table('purchase_orders')
                ->where('tenant_id', $tenant->id)
                ->where('id', $order)
                ->lockForUpdate()
                ->first();

            abort_unless($po, 404);

            $warehouse = DB::table('warehouses')->where('id', $po->warehouse_id)->first();
            Access::assertBranch($user, $warehouse->branch_id);

            $alreadyReceived = DB::table('purchase_order_items')
                ->where('purchase_order_id', $po->id)
                ->where('received_quantity', '>', 0)
                ->exists();

            if ($po->status !== 'ordered' || $alreadyReceived) {
                throw ValidationException::withMessages([
                    'order' => 'Solo se pueden cancelar órdenes de compra que aún no tengan ingresos registrados.',
                ]);
            }

            DB::table('purchase_orders')->where('id', $po->id)->update([
                'status'     => 'cancelled',
                'updated_at' => now(),
            ]);

            return [$po, $warehouse];
        });

        $this->auditPurchase($request, 'purchase.cancelled', $po, $warehouse, [
            'purchase_status' => Inventory::PURCHASE_STATUS_LABELS[$po->status] ?? $po->status,
        ], [
            'purchase_status' => Inventory::PURCHASE_STATUS_LABELS['cancelled'],
        ]);

        return response()->json($this->boardPayload($tenant, $user));
    }

    private function auditPurchase(Request $request, string $action, object $order, object $warehouse, array $before, array $after): void
    {
        $this->audit($request, $action, [
            'entity_type'  => 'purchase_order',
            'entity_id'    => $order->id,
            'entity_label' => $order->code,
            'branch_id'    => $warehouse->branch_id,
            'before'       => $before ?: null,
            'after'        => array_filter($after + [
                'supplier' => DB::table('suppliers')->where('id', $order->supplier_id)->value('name'),
                'total'    => round((float) $order->total, 2),
                'branch'   => $this->branchName($warehouse->branch_id),
            ], fn ($value) => $value !== null),
        ]);
    }
}
