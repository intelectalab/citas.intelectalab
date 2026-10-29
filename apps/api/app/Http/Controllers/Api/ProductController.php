<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Cart;
use App\Support\Codes;
use App\Support\Currency;
use App\Support\Inventory;
use App\Support\Sales;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public const KINDS = [
        'retail' => 'Se vende al público',
        'supply' => 'Solo se usa en las atenciones',
        'both'   => 'Se vende y se usa en las atenciones',
    ];

    private function tenantId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user->tenant_id, 403);
        abort_unless(Cart::productsReady(), 503, Sales::NOT_READY);

        return $user->tenant_id;
    }

    private function present(object $product, array $categories, array $sold, array $stocks, string $currency): array
    {
        $stockRow = $stocks[$product->id] ?? null;

        return [
            'id'              => $product->id,
            'name'            => $product->name,
            'sku'             => $product->sku,
            'price'           => round((float) $product->price, 2),
            'cost'            => round((float) ($product->cost ?? 0), 2),
            'min_stock'       => Inventory::qty($product->min_stock ?? 0),
            'track_stock'     => (bool) ($product->track_stock ?? true),
            'stock'           => Inventory::qty($stockRow?->quantity ?? 0),
            'in_transit'      => Inventory::qty($stockRow?->in_transit ?? 0),
            'currency'        => $currency,
            'kind'            => $product->kind,
            'kind_label'      => self::KINDS[$product->kind] ?? $product->kind,
            'category_id'     => $product->category_id,
            'category'        => $categories[$product->category_id] ?? null,
            'status'          => $product->status,
            'sold'            => (int) round((float) ($sold[$product->id] ?? 0)),
            'created_at'      => Carbon::parse($product->created_at)->toIso8601String(),
        ];
    }

    private function auditable(object $product): array
    {
        return [
            'name'         => $product->name,
            'sku'          => $product->sku,
            'category'     => $product->category_id ? DB::table('product_categories')->where('id', $product->category_id)->value('name') : null,
            'product_kind' => self::KINDS[$product->kind] ?? $product->kind,
            'price'        => round((float) $product->price, 2),
            'cost'         => round((float) ($product->cost ?? 0), 2),
            'min_stock'    => Inventory::qty($product->min_stock ?? 0),
            'track_stock'  => (bool) ($product->track_stock ?? true),
            'is_active'    => $product->status === 'active',
        ];
    }

    private function audit(Request $request, string $action, object $product, array $before, array $after): void
    {
        Audit::log($request, $action, [
            'tenant'       => $request->user()->tenant_id,
            'entity_type'  => 'product',
            'entity_id'    => $product->id,
            'entity_label' => $product->name,
            'before'       => $before ?: null,
            'after'        => $after ?: null,
        ]);
    }

    private function categories(string $tenantId): array
    {
        return DB::table('product_categories')->where('tenant_id', $tenantId)->pluck('name', 'id')->all();
    }

    private function stocks(string $tenantId, array $ids): array
    {
        if (!$ids || !Inventory::ready()) {
            return [];
        }

        return DB::table('stock_balances')
            ->where('tenant_id', $tenantId)
            ->whereIn('product_id', $ids)
            ->groupBy('product_id')
            ->selectRaw('product_id, COALESCE(SUM(quantity), 0) as quantity, COALESCE(SUM(in_transit), 0) as in_transit')
            ->get()
            ->keyBy('product_id')
            ->all();
    }

    private function sold(string $tenantId, array $ids): array
    {
        return DB::table('sale_items as i')
            ->join('sales as s', 's.id', '=', 'i.sale_id')
            ->where('i.tenant_id', $tenantId)
            ->where('i.item_type', 'product')
            ->whereIn('i.reference_id', $ids)
            ->whereIn('s.status', ['paid', 'partially_refunded'])
            ->groupBy('i.reference_id')
            ->selectRaw('i.reference_id, sum(i.quantity) as total')
            ->pluck('total', 'reference_id')
            ->all();
    }

    private function one(Request $request, string $tenantId, string $id): array
    {
        $product = DB::table('products')->where('id', $id)->first();

        return $this->present(
            $product,
            $this->categories($tenantId),
            $this->sold($tenantId, [$id]),
            $this->stocks($tenantId, [$id]),
            Currency::of($request->user()->tenant),
        );
    }

    private function validated(Request $request, string $tenantId, ?string $ignoreId = null): array
    {
        $request->merge(['sku' => mb_strtoupper(trim((string) $request->input('sku'))) ?: null]);

        $validated = $request->validate([
            'name'        => 'required|string|max:200',
            'sku'         => ['nullable', 'string', 'max:60', Rule::unique('products', 'sku')->where('tenant_id', $tenantId)->ignore($ignoreId)],
            'price'       => 'required|numeric|min:0|max:9999999',
            'cost'        => 'nullable|numeric|min:0|max:9999999',
            'min_stock'   => 'nullable|numeric|min:0|max:999999',
            'track_stock' => 'nullable|boolean',
            'kind'        => ['required', Rule::in(array_keys(self::KINDS))],
            'category_id' => ['nullable', 'uuid', Rule::exists('product_categories', 'id')->where('tenant_id', $tenantId)],
            'status'      => ['nullable', Rule::in(['active', 'inactive'])],
        ], [
            'name.required'  => 'Escribe el nombre del producto.',
            'sku.unique'     => 'Ya tienes un producto con ese código.',
            'price.required' => 'Escribe el precio.',
            'price.numeric'  => 'Escribe el precio como un número.',
            'price.min'      => 'El precio no puede ser negativo.',
            'cost.numeric'   => 'Escribe el costo como un número.',
            'cost.min'       => 'El costo no puede ser negativo.',
            'min_stock.numeric' => 'Escribe el stock mínimo como un número.',
            'min_stock.min'  => 'El stock mínimo no puede ser negativo.',
            'category_id.exists' => 'Esa categoría ya no existe.',
        ]);

        return [
            'name'        => trim($validated['name']),
            'price'       => round((float) $validated['price'], 2),
            'kind'        => $validated['kind'],
            'category_id' => $validated['category_id'] ?? null,
            'status'      => $validated['status'] ?? 'active',
            'updated_at'  => now(),
        ] + ($validated['sku'] ? ['sku' => $validated['sku']] : [])
          + (array_key_exists('cost', $validated) && $validated['cost'] !== null ? ['cost' => round((float) $validated['cost'], 2)] : [])
          + (array_key_exists('min_stock', $validated) && $validated['min_stock'] !== null ? ['min_stock' => round((float) $validated['min_stock'], 4)] : [])
          + (array_key_exists('track_stock', $validated) && $validated['track_stock'] !== null ? ['track_stock' => (bool) $validated['track_stock']] : []);
    }

    public function index(Request $request)
    {
        $tenantId = $this->tenantId($request);
        $tenant = $request->user()->tenant;
        $products = DB::table('products')->where('tenant_id', $tenantId)->whereNull('deleted_at')->orderBy('name')->get();
        $categories = $this->categories($tenantId);
        $ids = $products->pluck('id')->all();
        $sold = $this->sold($tenantId, $ids);
        $stocks = $this->stocks($tenantId, $ids);
        $currency = Currency::of($tenant);

        return response()->json([
            'inventory_enabled' => Inventory::enabled($tenant),
            'kinds'      => collect(self::KINDS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'categories' => collect($categories)->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->sortBy('name')->values(),
            'products'   => $products->map(fn ($p) => $this->present($p, $categories, $sold, $stocks, $currency))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = $this->tenantId($request);
        $data = $this->validated($request, $tenantId);
        $id = (string) Str::uuid();

        DB::transaction(function () use ($tenantId, $data, $id) {
            $data['sku'] ??= $this->nextSku($tenantId);
            $data['track_stock'] ??= false;
            DB::table('products')->insert($data + ['id' => $id, 'tenant_id' => $tenantId, 'created_at' => now()]);
        });

        $created = DB::table('products')->where('id', $id)->first();
        $this->audit($request, 'product.created', $created, [], array_filter($this->auditable($created), fn ($value) => $value !== null && $value !== false));

        return response()->json($this->one($request, $tenantId, $id), 201);
    }

    private function nextSku(string $tenantId): string
    {
        do {
            $sku = Codes::next($tenantId, 'product');
        } while (DB::table('products')->where('tenant_id', $tenantId)->where('sku', $sku)->exists());

        return $sku;
    }

    public function update(Request $request, string $product)
    {
        $tenantId = $this->tenantId($request);
        $row = DB::table('products')->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('id', $product)->first();
        abort_unless($row, 404);

        DB::table('products')->where('id', $row->id)->update($this->validated($request, $tenantId, $row->id));
        $fresh = DB::table('products')->where('id', $row->id)->first();
        [$before, $after] = Audit::changed($this->auditable($row), $this->auditable($fresh));

        if ($after) {
            $this->audit($request, 'product.updated', $fresh, $before, $after);
        }

        return response()->json($this->one($request, $tenantId, $row->id));
    }

    public function destroy(Request $request, string $product)
    {
        $tenantId = $this->tenantId($request);
        $row = DB::table('products')->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('id', $product)->first();
        abort_unless($row, 404);

        $used = DB::table('sale_items')->where('tenant_id', $tenantId)->where('item_type', 'product')->where('reference_id', $row->id)->exists()
            || DB::table('inventory_movements')->where('product_id', $row->id)->exists()
            || DB::table('service_supplies')->where('product_id', $row->id)->exists();

        if ($used) {
            DB::table('products')->where('id', $row->id)->update(['deleted_at' => now(), 'status' => 'inactive', 'updated_at' => now()]);
        } else {
            DB::table('stock_balances')->where('product_id', $row->id)->delete();
            DB::table('products')->where('id', $row->id)->delete();
        }

        Audit::log($request, 'product.deleted', [
            'tenant'       => $tenantId,
            'entity_type'  => 'product',
            'entity_id'    => $row->id,
            'entity_label' => $row->name,
            'before'       => array_filter(['sku' => $row->sku, 'barcode' => $row->barcode]),
            'after'        => $used ? ['kept_history' => true] : null,
        ]);

        return response()->json(['kept' => $used]);
    }

    public function storeCategory(Request $request)
    {
        $tenantId = $this->tenantId($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('product_categories', 'name')->where('tenant_id', $tenantId)],
        ], [
            'name.required' => 'Escribe el nombre de la categoría.',
            'name.unique'   => 'Ya tienes una categoría con ese nombre.',
        ]);

        $id = (string) Str::uuid();
        DB::table('product_categories')->insert(['id' => $id, 'tenant_id' => $tenantId, 'name' => trim($validated['name']), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['id' => $id, 'name' => trim($validated['name'])], 201);
    }
}
