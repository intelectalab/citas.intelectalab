<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Cart;
use App\Support\Currency;
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

    private function present(object $product, array $categories, array $sold, string $currency): array
    {
        return [
            'id'          => $product->id,
            'name'        => $product->name,
            'sku'         => $product->sku,
            'price'       => round((float) $product->price, 2),
            'currency'    => $currency,
            'kind'        => $product->kind,
            'kind_label'  => self::KINDS[$product->kind] ?? $product->kind,
            'category_id' => $product->category_id,
            'category'    => $categories[$product->category_id] ?? null,
            'status'      => $product->status,
            'sold'        => (int) round((float) ($sold[$product->id] ?? 0)),
            'created_at'  => Carbon::parse($product->created_at)->toIso8601String(),
        ];
    }

    private function categories(string $tenantId): array
    {
        return DB::table('product_categories')->where('tenant_id', $tenantId)->pluck('name', 'id')->all();
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

        return $this->present($product, $this->categories($tenantId), $this->sold($tenantId, [$id]), Currency::of($request->user()->tenant));
    }

    private function validated(Request $request, string $tenantId, ?string $ignoreId = null): array
    {
        $request->merge(['sku' => mb_strtoupper(trim((string) $request->input('sku'))) ?: null]);

        $validated = $request->validate([
            'name'        => 'required|string|max:200',
            'sku'         => ['nullable', 'string', 'max:60', Rule::unique('products', 'sku')->where('tenant_id', $tenantId)->ignore($ignoreId)],
            'price'       => 'required|numeric|min:0|max:9999999',
            'kind'        => ['required', Rule::in(array_keys(self::KINDS))],
            'category_id' => ['nullable', 'uuid', Rule::exists('product_categories', 'id')->where('tenant_id', $tenantId)],
            'status'      => ['nullable', Rule::in(['active', 'inactive'])],
        ], [
            'name.required'  => 'Escribe el nombre del producto.',
            'sku.unique'     => 'Ya tienes un producto con ese código.',
            'price.required' => 'Escribe el precio.',
            'price.numeric'  => 'Escribe el precio como un número.',
            'price.min'      => 'El precio no puede ser negativo.',
            'category_id.exists' => 'Esa categoría ya no existe.',
        ]);

        return [
            'name'        => trim($validated['name']),
            'price'       => round((float) $validated['price'], 2),
            'kind'        => $validated['kind'],
            'category_id' => $validated['category_id'] ?? null,
            'status'      => $validated['status'] ?? 'active',
            'updated_at'  => now(),
        ] + ($validated['sku'] ? ['sku' => $validated['sku']] : []);
    }

    public function index(Request $request)
    {
        $tenantId = $this->tenantId($request);
        $products = DB::table('products')->where('tenant_id', $tenantId)->whereNull('deleted_at')->orderBy('name')->get();
        $categories = $this->categories($tenantId);
        $sold = $this->sold($tenantId, $products->pluck('id')->all());
        $currency = Currency::of($request->user()->tenant);

        return response()->json([
            'kinds'      => collect(self::KINDS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'categories' => collect($categories)->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->sortBy('name')->values(),
            'products'   => $products->map(fn ($p) => $this->present($p, $categories, $sold, $currency))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = $this->tenantId($request);
        $data = $this->validated($request, $tenantId);
        $id = (string) Str::uuid();

        DB::transaction(function () use ($tenantId, $data, $id) {
            $data['sku'] ??= $this->nextSku($tenantId);
            DB::table('products')->insert($data + ['id' => $id, 'tenant_id' => $tenantId, 'track_stock' => false, 'created_at' => now()]);
        });

        return response()->json($this->one($request, $tenantId, $id), 201);
    }

    private function nextSku(string $tenantId): string
    {
        do {
            $sku = sprintf('P-%04d', Sales::nextNumber($tenantId, 'product'));
        } while (DB::table('products')->where('tenant_id', $tenantId)->where('sku', $sku)->exists());

        return $sku;
    }

    public function update(Request $request, string $product)
    {
        $tenantId = $this->tenantId($request);
        $row = DB::table('products')->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('id', $product)->first();
        abort_unless($row, 404);

        DB::table('products')->where('id', $row->id)->update($this->validated($request, $tenantId, $row->id));

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
