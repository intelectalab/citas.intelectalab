<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Inventory
{
    public const NOT_READY = 'Falta actualizar la base de datos para usar el inventario: ejecuta php artisan migrate.';

    public const MOVEMENT_LABELS = [
        'initial'             => 'Carga inicial',
        'purchase'            => 'Compra / ingreso',
        'sale'                => 'Venta',
        'service_consumption' => 'Consumo en atención',
        'adjustment'          => 'Ajuste / merma',
        'transfer_out'        => 'Salida por traslado',
        'transfer_in'         => 'Entrada por traslado',
        'return'              => 'Devolución',
    ];

    public const TRANSFER_STATUS_LABELS = [
        'draft'      => 'Borrador',
        'in_transit' => 'En tránsito',
        'received'   => 'Recibido',
        'cancelled'  => 'Cancelado',
    ];

    public const PURCHASE_STATUS_LABELS = [
        'draft'     => 'Borrador',
        'ordered'   => 'Pendiente de recibir',
        'partial'   => 'Recepción parcial',
        'received'  => 'Recibida',
        'cancelled' => 'Cancelada',
    ];

    public const COST_METHODS = [
        'last'    => 'Último costo de compra',
        'average' => 'Costo promedio ponderado',
        'none'    => 'No actualizar automáticamente',
    ];

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('warehouses')
            && Schema::hasTable('stock_balances')
            && Schema::hasTable('inventory_movements');
    }

    public static function enabled(Tenant $tenant): bool
    {
        return $tenant->hasModule('inventory') && self::ready();
    }

    public static function rules(Tenant $tenant): array
    {
        $method = (string) AppointmentFlow::rule($tenant, 'inventory.cost_method');

        return [
            'allow_negative'     => (bool) AppointmentFlow::rule($tenant, 'inventory.allow_negative'),
            'consume_on_service' => (bool) AppointmentFlow::rule($tenant, 'inventory.consume_on_service'),
            'transfer_two_step'  => (bool) AppointmentFlow::rule($tenant, 'inventory.transfer_two_step'),
            'cost_method'        => isset(self::COST_METHODS[$method]) ? $method : 'last',
            'alert_min_stock'    => (bool) AppointmentFlow::rule($tenant, 'inventory.alert_min_stock'),
        ];
    }

    public static function qty(mixed $value): float
    {
        return round((float) $value, 2);
    }

    public static function formatQty(mixed $value, ?string $unitAbbr = null): string
    {
        $n = self::qty($value);
        $formatted = floor($n) == $n ? (string) (int) $n : rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');

        return $unitAbbr ? "{$formatted} {$unitAbbr}" : $formatted;
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public static function ensureWarehouses(Tenant $tenant, ?string $onlyBranchId = null): Collection
    {
        if (!self::ready()) {
            return collect();
        }

        self::ensurePermissions();

        $branches = DB::table('branches')
            ->where('tenant_id', $tenant->id)
            ->orderByRaw("status = 'active' desc")
            ->orderBy('created_at')
            ->orderBy('name')
            ->get(['id', 'name', 'status', 'created_at']);

        if ($branches->isEmpty()) {
            return collect();
        }

        $mainBranchId = $branches->first()->id;

        $existing = DB::table('warehouses')
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->get()
            ->groupBy('branch_id');

        foreach ($branches as $branch) {
            $group = $existing->get($branch->id);
            $default = $group?->firstWhere('is_default', true) ?? $group?->first();
            $expectedStatus = $branch->status === 'active' ? 'active' : 'inactive';
            $expectedName = 'Almacén ' . $branch->name;

            if (!$default) {
                DB::table('warehouses')->insert([
                    'id'         => (string) Str::uuid(),
                    'tenant_id'  => $tenant->id,
                    'branch_id'  => $branch->id,
                    'name'       => mb_substr($expectedName, 0, 120),
                    'is_default' => true,
                    'status'     => $expectedStatus,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } elseif (!$default->is_default || $default->status !== $expectedStatus) {
                DB::table('warehouses')->where('id', $default->id)->update([
                    'is_default' => true,
                    'status'     => $expectedStatus,
                    'updated_at' => now(),
                ]);
            }
        }

        return DB::table('warehouses as w')
            ->join('branches as b', 'b.id', '=', 'w.branch_id')
            ->where('w.tenant_id', $tenant->id)
            ->where('w.is_default', true)
            ->when($onlyBranchId, fn ($q) => $q->where('w.branch_id', $onlyBranchId))
            ->orderByRaw("b.status = 'active' desc")
            ->orderBy('b.created_at')
            ->orderBy('b.name')
            ->get([
                'w.id',
                'w.branch_id',
                'w.name',
                'w.is_default',
                'w.status',
                'b.name as branch_name',
                'b.status as branch_status',
            ])
            ->map(function ($w) use ($mainBranchId) {
                $w->branch_code = null;
                $w->is_main = $w->branch_id === $mainBranchId;

                return $w;
            });
    }

    public static function warehouseForBranch(Tenant $tenant, string $branchId): object
    {
        $warehouse = DB::table('warehouses as w')
            ->join('branches as b', 'b.id', '=', 'w.branch_id')
            ->where('w.tenant_id', $tenant->id)
            ->where('w.branch_id', $branchId)
            ->orderByDesc('w.is_default')
            ->first(['w.*', 'b.name as branch_name']);

        if ($warehouse) {
            return $warehouse;
        }

        self::ensureWarehouses($tenant);

        $warehouse = DB::table('warehouses as w')
            ->join('branches as b', 'b.id', '=', 'w.branch_id')
            ->where('w.tenant_id', $tenant->id)
            ->where('w.branch_id', $branchId)
            ->orderByDesc('w.is_default')
            ->first(['w.*', 'b.name as branch_name']);

        abort_unless((bool) $warehouse, 422, 'La sede seleccionada no tiene un almacén disponible.');

        return $warehouse;
    }

    public static function branchStocks(Tenant $tenant, string $branchId, array $productIds = []): array
    {
        if (!self::ready()) {
            return [];
        }

        $warehouse = DB::table('warehouses')
            ->where('tenant_id', $tenant->id)
            ->where('branch_id', $branchId)
            ->orderByDesc('is_default')
            ->first(['id']);

        if (!$warehouse) {
            return [];
        }

        return DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouse->id)
            ->when($productIds, fn ($q) => $q->whereIn('product_id', $productIds))
            ->pluck('quantity', 'product_id')
            ->map(fn ($q) => self::qty($q))
            ->all();
    }

    public static function assertStockForItems(Tenant $tenant, string $branchId, iterable $items, string $errorKey = 'items'): void
    {
        if (!self::enabled($tenant) || AppointmentFlow::rule($tenant, 'inventory.allow_negative')) {
            return;
        }

        $requested = [];
        foreach ($items as $item) {
            $type = is_array($item) ? ($item['type'] ?? $item['item_type'] ?? null) : ($item->item_type ?? $item->type ?? null);
            $id = is_array($item) ? ($item['id'] ?? $item['reference_id'] ?? $item['item_id'] ?? null) : ($item->reference_id ?? $item->item_id ?? $item->id ?? null);
            $qty = is_array($item) ? ($item['quantity'] ?? 1) : ($item->quantity ?? 1);

            if ($type === 'product' && $id) {
                $requested[$id] = ($requested[$id] ?? 0.0) + (float) $qty;
            }
        }

        if (!$requested) {
            return;
        }

        $products = DB::table('products')
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', array_keys($requested))
            ->where('track_stock', true)
            ->get(['id', 'name'])
            ->keyBy('id');

        if ($products->isEmpty()) {
            return;
        }

        $stocks = self::branchStocks($tenant, $branchId, $products->keys()->all());

        foreach ($products as $productId => $product) {
            $needed = self::qty($requested[$productId] ?? 0);
            $available = self::qty($stocks[$productId] ?? 0);

            if ($needed > $available + 0.0001) {
                $availText = $available <= 0 ? 'no hay unidades disponibles' : 'solo quedan ' . self::formatQty($available);
                self::fail($errorKey, "No alcanza el stock de {$product->name} en esta sede: {$availText} e intentas vender " . self::formatQty($needed) . '.');
            }
        }
    }

    public static function recordMovement(
        Tenant $tenant,
        string $warehouseId,
        string $productId,
        string $type,
        float $quantity,
        ?User $actor = null,
        ?string $reason = null,
        ?float $unitCost = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        bool $checkNegative = true,
        string $errorField = 'quantity',
    ): object {
        $delta = round($quantity, 4);
        if (abs($delta) < 0.0001) {
            self::fail($errorField, 'La cantidad del movimiento debe ser distinta de cero.');
        }

        $product = DB::table('products')
            ->where('tenant_id', $tenant->id)
            ->where('id', $productId)
            ->whereNull('deleted_at')
            ->first(['id', 'name', 'cost', 'min_stock', 'track_stock']);

        if (!$product) {
            self::fail('product_id', 'El producto seleccionado no existe.');
        }

        $warehouse = DB::table('warehouses as w')
            ->join('branches as b', 'b.id', '=', 'w.branch_id')
            ->where('w.tenant_id', $tenant->id)
            ->where('w.id', $warehouseId)
            ->first(['w.id', 'w.branch_id', 'w.name', 'b.name as branch_name']);

        if (!$warehouse) {
            self::fail('warehouse_id', 'El almacén seleccionado no existe.');
        }

        $balance = DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        $current = $balance ? (float) $balance->quantity : 0.0;
        $after = round($current + $delta, 4);

        if (
            !$product->track_stock
            && $delta > 0
            && !DB::table('inventory_movements')->where('tenant_id', $tenant->id)->where('product_id', $productId)->exists()
        ) {
            DB::table('products')->where('id', $productId)->update(['track_stock' => true, 'updated_at' => now()]);
            $product->track_stock = true;
        }

        if ($delta < 0 && $checkNegative && !AppointmentFlow::rule($tenant, 'inventory.allow_negative') && $after < -0.0001) {
            $availText = $current <= 0 ? 'no hay stock disponible' : 'solo hay ' . self::formatQty($current);
            self::fail($errorField, "No hay suficiente stock de {$product->name} en {$warehouse->branch_name} ({$availText}).");
        }

        if ($balance) {
            DB::table('stock_balances')->where('id', $balance->id)->update([
                'quantity'   => $after,
                'updated_at' => now(),
            ]);
        } else {
            DB::table('stock_balances')->insert([
                'id'           => (string) Str::uuid(),
                'tenant_id'    => $tenant->id,
                'warehouse_id' => $warehouseId,
                'product_id'   => $productId,
                'quantity'     => $after,
                'in_transit'   => 0,
                'updated_at'   => now(),
            ]);
        }

        $cost = max(0, round($unitCost !== null ? $unitCost : (float) $product->cost, 2));
        $movementId = (string) Str::uuid();

        DB::table('inventory_movements')->insert([
            'id'             => $movementId,
            'tenant_id'      => $tenant->id,
            'warehouse_id'   => $warehouseId,
            'product_id'     => $productId,
            'type'           => $type,
            'quantity'       => $delta,
            'unit_cost'      => $cost,
            'balance_after'  => $after,
            'reference_type' => $referenceType,
            'reference_id'   => $referenceId,
            'reason'         => $reason ? mb_substr(trim($reason), 0, 255) : null,
            'actor_user_id'  => $actor?->id,
            'created_at'     => now(),
        ]);

        if (
            $delta < 0
            && $product->track_stock
            && (float) $product->min_stock > 0
            && $after <= (float) $product->min_stock
            && AppointmentFlow::rule($tenant, 'inventory.alert_min_stock')
        ) {
            self::notifyLowStock($tenant, $warehouse, $product, $after);
        }

        return (object) [
            'id'             => $movementId,
            'warehouse_id'   => $warehouseId,
            'product_id'     => $productId,
            'type'           => $type,
            'quantity'       => self::qty($delta),
            'unit_cost'      => $cost,
            'balance_after'  => self::qty($after),
            'balance_before' => self::qty($current),
        ];
    }

    public static function adjustCount(
        Tenant $tenant,
        string $warehouseId,
        string $productId,
        float $countedQuantity,
        ?User $actor = null,
        ?string $reason = null,
    ): ?object {
        $balance = DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        $current = $balance ? (float) $balance->quantity : 0.0;
        $delta = round($countedQuantity - $current, 4);

        if (abs($delta) < 0.0001) {
            return null;
        }

        $note = trim($reason ?: 'Conteo físico de inventario');

        return self::recordMovement(
            tenant: $tenant,
            warehouseId: $warehouseId,
            productId: $productId,
            type: 'adjustment',
            quantity: $delta,
            actor: $actor,
            reason: $note . ' (contado: ' . self::formatQty($countedQuantity) . ', antes: ' . self::formatQty($current) . ')',
            checkNegative: false,
        );
    }

    public static function deductForSale(Tenant $tenant, object $sale, ?User $actor = null): void
    {
        if (!self::enabled($tenant) || ($sale->status ?? null) !== 'paid' || empty($sale->branch_id)) {
            return;
        }

        $already = DB::table('inventory_movements')
            ->where('tenant_id', $tenant->id)
            ->where('reference_type', 'sale')
            ->where('reference_id', $sale->id)
            ->where('type', 'sale')
            ->exists();

        if ($already) {
            return;
        }

        $items = DB::table('sale_items as si')
            ->join('products as p', 'p.id', '=', 'si.reference_id')
            ->where('si.tenant_id', $tenant->id)
            ->where('si.sale_id', $sale->id)
            ->where('si.item_type', 'product')
            ->where('p.track_stock', true)
            ->get(['si.reference_id as product_id', 'si.quantity', 'p.cost']);

        if ($items->isEmpty()) {
            return;
        }

        $warehouse = self::warehouseForBranch($tenant, $sale->branch_id);

        foreach ($items->groupBy('product_id') as $productId => $rows) {
            $qty = round((float) $rows->sum('quantity'), 4);
            if ($qty <= 0) {
                continue;
            }

            $cost = (float) ($rows->first()->cost ?? 0);

            self::recordMovement(
                tenant: $tenant,
                warehouseId: $warehouse->id,
                productId: (string) $productId,
                type: 'sale',
                quantity: -$qty,
                actor: $actor,
                reason: "Venta {$sale->code}",
                unitCost: $cost,
                referenceType: 'sale',
                referenceId: $sale->id,
                checkNegative: false,
            );
        }
    }

    public static function returnForSale(Tenant $tenant, object $sale, ?User $actor = null, ?string $reason = null): void
    {
        if (!self::ready()) {
            return;
        }

        $alreadyReturned = DB::table('inventory_movements')
            ->where('tenant_id', $tenant->id)
            ->where('reference_type', 'sale')
            ->where('reference_id', $sale->id)
            ->where('type', 'return')
            ->exists();

        if ($alreadyReturned) {
            return;
        }

        $deductions = DB::table('inventory_movements')
            ->where('tenant_id', $tenant->id)
            ->where('reference_type', 'sale')
            ->where('reference_id', $sale->id)
            ->where('type', 'sale')
            ->get();

        if ($deductions->isEmpty()) {
            return;
        }

        $label = $reason ? "Devolución {$sale->code}: {$reason}" : "Devolución de venta {$sale->code}";

        foreach ($deductions as $m) {
            $qty = abs((float) $m->quantity);
            if ($qty <= 0) {
                continue;
            }

            self::recordMovement(
                tenant: $tenant,
                warehouseId: $m->warehouse_id,
                productId: $m->product_id,
                type: 'return',
                quantity: $qty,
                actor: $actor,
                reason: $label,
                unitCost: (float) $m->unit_cost,
                referenceType: 'sale',
                referenceId: $sale->id,
                checkNegative: false,
            );
        }
    }

    public static function consumeForAppointment(Tenant $tenant, Appointment $appointment, ?User $actor = null): void
    {
        if (
            !self::enabled($tenant)
            || !AppointmentFlow::rule($tenant, 'inventory.consume_on_service')
            || !$appointment->service_id
            || !$appointment->branch_id
        ) {
            return;
        }

        $already = DB::table('inventory_movements')
            ->where('tenant_id', $tenant->id)
            ->where('reference_type', 'appointment')
            ->where('reference_id', $appointment->id)
            ->where('type', 'service_consumption')
            ->exists();

        if ($already) {
            return;
        }

        $supplies = DB::table('service_supplies as ss')
            ->join('products as p', 'p.id', '=', 'ss.product_id')
            ->leftJoin('services as s', 's.id', '=', 'ss.service_id')
            ->where('ss.tenant_id', $tenant->id)
            ->where('ss.service_id', $appointment->service_id)
            ->whereNull('p.deleted_at')
            ->where('p.track_stock', true)
            ->get(['ss.product_id', 'ss.quantity', 'p.cost', 's.name as service_name']);

        if ($supplies->isEmpty()) {
            return;
        }

        $warehouse = self::warehouseForBranch($tenant, $appointment->branch_id);

        foreach ($supplies as $supply) {
            $qty = round((float) $supply->quantity, 4);
            if ($qty <= 0) {
                continue;
            }

            $serviceLabel = $supply->service_name ? " ({$supply->service_name})" : '';

            self::recordMovement(
                tenant: $tenant,
                warehouseId: $warehouse->id,
                productId: $supply->product_id,
                type: 'service_consumption',
                quantity: -$qty,
                actor: $actor,
                reason: "Cita {$appointment->code}{$serviceLabel}",
                unitCost: (float) $supply->cost,
                referenceType: 'appointment',
                referenceId: $appointment->id,
                checkNegative: false,
            );
        }
    }

    public static function updateProductCostOnPurchase(
        Tenant $tenant,
        string $productId,
        float $receivedQty,
        float $unitCost,
        float $previousTotalStock,
    ): void {
        if ($receivedQty <= 0 || $unitCost < 0) {
            return;
        }

        $method = self::rules($tenant)['cost_method'];
        if ($method === 'none') {
            return;
        }

        $product = DB::table('products')
            ->where('tenant_id', $tenant->id)
            ->where('id', $productId)
            ->first(['id', 'cost']);

        if (!$product) {
            return;
        }

        $currentCost = max(0, (float) $product->cost);
        $newCost = $unitCost;

        if ($method === 'average' && $previousTotalStock > 0 && $currentCost > 0) {
            $newCost = (($previousTotalStock * $currentCost) + ($receivedQty * $unitCost)) / ($previousTotalStock + $receivedQty);
        }

        DB::table('products')
            ->where('tenant_id', $tenant->id)
            ->where('id', $productId)
            ->update([
                'cost'       => round(max(0, $newCost), 2),
                'updated_at' => now(),
            ]);
    }

    public static function nextTransferCode(string $tenantId): string
    {
        return Codes::next($tenantId, 'transfer');
    }

    public static function nextPurchaseCode(string $tenantId): string
    {
        return Codes::next($tenantId, 'purchase');
    }

    private static function ensurePermissions(): void
    {
        static $synced = false;
        if ($synced || !Access::ready()) {
            return;
        }
        $synced = true;

        $already = DB::table('permissions')->where('name', 'inventory.view')->exists();
        if ($already) {
            return;
        }

        $ids = Access::permissionIds();

        foreach (['manager', 'reception'] as $slug) {
            $names = array_values(array_intersect(
                Access::factoryPermissions($slug),
                array_filter(array_keys($ids), fn ($name) => str_starts_with($name, 'inventory.')),
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

    private static function notifyLowStock(Tenant $tenant, object $warehouse, object $product, float $after): void
    {
        try {
            if (!Notifier::available()) {
                return;
            }

            $recipients = Notifier::adminIds($tenant->id);

            $branchManagers = User::where('tenant_id', $tenant->id)
                ->where('branch_id', $warehouse->branch_id)
                ->whereHas('roles', fn ($r) => $r->where('slug', 'manager'))
                ->pluck('id')
                ->all();

            $userIds = array_unique(array_filter([...$recipients, ...$branchManagers]));
            $title = $after <= 0 ? 'Producto agotado' : 'Stock bajo el mínimo';
            $qtyLabel = $after <= 0 ? 'Sin existencias (0)' : 'Quedan ' . self::formatQty($after) . ' (mínimo ' . self::formatQty($product->min_stock) . ')';
            $body = "{$product->name} · {$warehouse->branch_name} · {$qtyLabel}";
            $dedupe = "low-stock:{$warehouse->id}:{$product->id}:" . now($tenant->zone())->toDateString();

            foreach ($userIds as $userId) {
                Notifier::send(
                    $tenant->id,
                    $userId,
                    'inventory.low_stock',
                    $title,
                    $body,
                    '/products',
                    'product',
                    $product->id,
                    "{$dedupe}:{$userId}",
                );
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
