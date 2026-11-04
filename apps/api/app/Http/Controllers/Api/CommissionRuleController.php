<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Audit;
use App\Support\Cart;
use App\Support\Commissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommissionRuleController extends Controller
{
    private const COLUMNS = ['r.*', 'p.display_name as professional_name', 's.name as service_name', 'pr.name as product_name'];

    private function tenant(Request $request): Tenant
    {
        $user = $request->user();
        abort_unless($user->tenant, 403);
        abort_unless(Commissions::ready(), 503, Commissions::NOT_READY);

        return $user->tenant;
    }

    private function rows(string $tenantId)
    {
        return DB::table('commission_rules as r')
            ->where('r.tenant_id', $tenantId)
            ->leftJoin('professionals as p', 'p.id', '=', 'r.professional_id')
            ->leftJoin('services as s', 's.id', '=', 'r.service_id')
            ->leftJoin('products as pr', 'pr.id', '=', 'r.product_id');
    }

    private static function typeOf(object $row): string
    {
        return match (true) {
            $row->kind === 'product'     => 'product',
            (bool) $row->professional_id => 'exception',
            default                      => 'service',
        };
    }

    private function present(object $row): array
    {
        return [
            'id'              => $row->id,
            'type'            => self::typeOf($row),
            'professional_id' => $row->professional_id,
            'professional'    => $row->professional_name,
            'service_id'      => $row->service_id,
            'service'         => $row->service_name,
            'product_id'      => $row->product_id,
            'product'         => $row->product_name,
            'percent'         => round((float) $row->percent, 2),
            'fixed'           => round((float) $row->fixed, 2),
            'active'          => (bool) $row->active,
        ];
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);
        $products = Cart::productsEnabled($tenant);
        $rules = $this->rows($tenant->id)->orderBy('s.name')->orderBy('p.display_name')->orderBy('pr.name')->get(self::COLUMNS)->map(fn ($row) => $this->present($row));

        return response()->json([
            'rules'         => $rules->values(),
            'can_edit'      => $request->user()->allows('commissions.rules'),
            'products'      => $products,
            'base'          => Commissions::rules($tenant)['base'],
            'on_products'   => Commissions::rules($tenant)['on_products'],
            'professionals' => DB::table('professionals')->where('tenant_id', $tenant->id)
                ->where(fn ($q) => $q->whereNull('status_id')->orWhereNotIn('status_id', DB::table('statuses')->where('slug', 'inactive')->select('id')))
                ->orderBy('display_name')->get(['id', 'display_name as name', 'commission_percent']),
            'services'      => DB::table('services')->where('tenant_id', $tenant->id)->orderBy('name')->get(['id', 'name', 'price']),
            'product_list'  => $products ? DB::table('products')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->where('status', 'active')->whereIn('kind', Cart::SELLABLE)->orderBy('name')->get(['id', 'name', 'price']) : [],
        ]);
    }

    private function validated(Request $request, Tenant $tenant, ?string $ignoreId = null): array
    {
        $exists = fn (string $table) => Rule::exists($table, 'id')->where('tenant_id', $tenant->id);

        $data = $request->validate([
            'type'            => ['required', Rule::in(['service', 'exception', 'product'])],
            'professional_id' => ['nullable', 'uuid', $exists('professionals'), 'required_if:type,exception'],
            'service_id'      => ['nullable', 'uuid', $exists('services'), 'required_if:type,service', 'required_if:type,exception'],
            'product_id'      => ['nullable', 'uuid', $exists('products')],
            'percent'         => 'nullable|numeric|min:0|max:100',
            'fixed'           => 'nullable|numeric|min:0|max:999999',
            'active'          => 'boolean',
        ], [
            'professional_id.required_if' => 'Elige a quién es la excepción.',
            'service_id.required_if'      => 'Elige el servicio.',
            'percent.max'                 => 'El porcentaje no puede pasar de 100.',
        ]);

        $percent = round((float) ($data['percent'] ?? 0), 2);
        $fixed = round((float) ($data['fixed'] ?? 0), 2);

        if ($percent <= 0 && $fixed <= 0) {
            throw ValidationException::withMessages(['percent' => 'Indica un porcentaje, un monto fijo o los dos.']);
        }

        if ($data['type'] === 'product' && !Cart::productsEnabled($tenant)) {
            throw ValidationException::withMessages(['type' => Cart::NO_PRODUCTS]);
        }

        $values = match ($data['type']) {
            'service'   => ['kind' => 'service', 'professional_id' => null, 'service_id' => $data['service_id'], 'product_id' => null],
            'exception' => ['kind' => 'service', 'professional_id' => $data['professional_id'], 'service_id' => $data['service_id'], 'product_id' => null],
            default     => ['kind' => 'product', 'professional_id' => null, 'service_id' => null, 'product_id' => $data['product_id'] ?? null],
        };

        $same = DB::table('commission_rules')->where('tenant_id', $tenant->id)->where('kind', $values['kind'])
            ->when($ignoreId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->where(fn ($q) => $values['professional_id'] ? $q->where('professional_id', $values['professional_id']) : $q->whereNull('professional_id'))
            ->where(fn ($q) => $values['service_id'] ? $q->where('service_id', $values['service_id']) : $q->whereNull('service_id'))
            ->where(fn ($q) => $values['product_id'] ? $q->where('product_id', $values['product_id']) : $q->whereNull('product_id'))
            ->value('id');

        if ($same) {
            $label = $this->label($tenant, $values);
            abort(response()->json([
                'message'     => "Ya hay una comisión para {$label}. Cámbiala en vez de crear otra.",
                'code'        => 'COMMISSION_RULE_EXISTS',
                'existing_id' => $same,
            ], 422));
        }

        return $values + ['name' => $this->label($tenant, $values), 'percent' => $percent, 'fixed' => $fixed, 'active' => (bool) ($data['active'] ?? true)];
    }

    private function label(Tenant $tenant, array $values): string
    {
        $name = fn (string $table, string $column, ?string $id) => $id ? DB::table($table)->where('tenant_id', $tenant->id)->where('id', $id)->value($column) : null;
        $service = $name('services', 'name', $values['service_id']);
        $professional = $name('professionals', 'display_name', $values['professional_id']);
        $product = $name('products', 'name', $values['product_id']);

        return mb_substr(match (true) {
            $values['kind'] === 'product' => $product ? "el producto {$product}" : 'todos los productos',
            (bool) $professional          => "{$professional} en {$service}",
            default                       => "{$service}, para todos",
        }, 0, 120);
    }

    private function audit(Request $request, string $action, string $tenantId, string $id, string $label, array $before = [], array $after = []): void
    {
        Audit::log($request, $action, [
            'tenant' => $tenantId, 'entity_type' => 'commission_rule', 'entity_id' => $id, 'entity_label' => $label,
            'before' => $before ?: null, 'after' => $after ?: null,
        ]);
    }

    private function auditData(array $values): array
    {
        return array_filter([
            'percent' => isset($values['percent']) ? (float) $values['percent'] : null,
            'fixed'   => isset($values['fixed']) ? (float) $values['fixed'] : null,
            'active'  => isset($values['active']) ? ((bool) $values['active'] ? 'Activa' : 'Inactiva') : null,
        ], fn ($v) => $v !== null);
    }

    public function store(Request $request)
    {
        $tenant = $this->tenant($request);
        $values = $this->validated($request, $tenant);
        $id = (string) Str::uuid();

        DB::table('commission_rules')->insert($values + [
            'id' => $id, 'tenant_id' => $tenant->id, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Commissions::flush();
        $this->audit($request, 'commission_rule.created', $tenant->id, $id, $values['name'], [], $this->auditData($values));

        return response()->json($this->present($this->rows($tenant->id)->where('r.id', $id)->first(self::COLUMNS)), 201);
    }

    public function update(Request $request, string $rule)
    {
        $tenant = $this->tenant($request);
        $row = DB::table('commission_rules')->where('tenant_id', $tenant->id)->where('id', $rule)->first();
        abort_unless($row, 404);
        $values = $this->validated($request, $tenant, $row->id);

        DB::table('commission_rules')->where('id', $row->id)->update($values + ['updated_at' => now()]);
        Commissions::flush();
        [$from, $to] = Audit::changed($this->auditData((array) $row), $this->auditData($values));
        if ($to) {
            $this->audit($request, 'commission_rule.updated', $tenant->id, $row->id, $values['name'], $from, $to);
        }

        return response()->json($this->present($this->rows($tenant->id)->where('r.id', $row->id)->first(self::COLUMNS)));
    }

    public function destroy(Request $request, string $rule)
    {
        $tenant = $this->tenant($request);
        $row = DB::table('commission_rules')->where('tenant_id', $tenant->id)->where('id', $rule)->first();
        abort_unless($row, 404);

        DB::table('commission_rules')->where('id', $row->id)->delete();
        Commissions::flush();
        $this->audit($request, 'commission_rule.deleted', $tenant->id, $row->id, $row->name, $this->auditData((array) $row));

        return response()->json(['message' => 'Comisión quitada. Las ya calculadas no cambian.']);
    }

    public function preview(Request $request)
    {
        $tenant = $this->tenant($request);
        $data = $request->validate([
            'professional_id' => 'required|uuid',
            'kind'            => ['required', Rule::in(['service', 'product'])],
            'reference_id'    => 'required|uuid',
        ]);

        Commissions::flush();

        return response()->json(Commissions::preview($tenant, $data['kind'], $data['professional_id'], $data['reference_id']));
    }
}
