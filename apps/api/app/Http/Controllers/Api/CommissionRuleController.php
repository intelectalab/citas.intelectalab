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
    private const COLUMNS = ['r.*', 'p.display_name as professional_name', 's.name as service_name', 'pr.name as product_name', 'c.name as category_name'];

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
            ->leftJoin('products as pr', 'pr.id', '=', 'r.product_id')
            ->leftJoin('service_categories as c', 'c.id', '=', 'r.category_id');
    }

    private static function typeOf(object $row): string
    {
        return match (true) {
            $row->kind === 'product'     => 'product',
            (bool) $row->professional_id => 'exception',
            (bool) ($row->category_id ?? null) => 'category',
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
            'category_id'     => $row->category_id ?? null,
            'category'        => $row->category_name,
            'percent'         => round((float) $row->percent, 2),
            'fixed'           => round((float) $row->fixed, 2),
            'active'          => (bool) $row->active,
        ];
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);
        $products = Cart::productsEnabled($tenant);
        $own = \App\Support\Access::ownProfessionalId($request->user());
        $rules = $this->rows($tenant->id)
            ->when($own !== null, fn ($q) => $q->where(fn ($w) => $own === 'none' ? $w->whereNull('r.professional_id') : $w->whereNull('r.professional_id')->orWhere('r.professional_id', $own)))
            ->orderBy('c.name')->orderBy('s.name')->orderBy('p.display_name')->orderBy('pr.name')->get(self::COLUMNS)->map(fn ($row) => $this->present($row));

        return response()->json([
            'rules'         => $rules->values(),
            'can_edit'      => $request->user()->allows('commissions.rules'),
            'products'      => $products,
            'base'          => Commissions::rules($tenant)['base'],
            'on_products'   => Commissions::rules($tenant)['on_products'],
            'professionals' => DB::table('professionals')->where('tenant_id', $tenant->id)
                ->where(fn ($q) => $q->whereNull('status_id')->orWhereNotIn('status_id', DB::table('statuses')->where('slug', 'inactive')->select('id')))
                ->when($own !== null, fn ($q) => $own === 'none' ? $q->whereRaw('false') : $q->where('id', $own))
                ->orderBy('display_name')->get(['id', 'display_name as name', 'commission_percent']),
            'services'      => DB::table('services')->where('tenant_id', $tenant->id)->orderBy('name')->get(['id', 'name', 'price', 'category_id']),
            'categories'    => DB::table('service_categories')->where('tenant_id', $tenant->id)->orderBy('name')->get(\App\Support\CatalogExtras::categoryColor() ? ['id', 'name', 'color'] : ['id', 'name']),
            'product_list'  => $products ? DB::table('products')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->where('status', 'active')->whereIn('kind', Cart::SELLABLE)->orderBy('name')->get(['id', 'name', 'price']) : [],
        ]);
    }

    public function professional(Request $request, string $professional)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();
        $pro = DB::table('professionals')->where('tenant_id', $tenant->id)->where('id', $professional)->first();
        abort_unless($pro, 404);

        $scope = \App\Support\Access::scopeOf($user);
        $self = $pro->user_id === $user->id;
        abort_unless($self || $user->allows('commissions.view'), 403, 'No tienes permiso para ver comisiones.');
        abort_if($scope === 'own' && !$self, 403, 'Con tu rol solo puedes ver tus propias comisiones.');

        $range = \App\Support\Reports::range($tenant, 'month');
        $production = (new \App\Support\Reports($tenant, null, $range['from'], $range['to']))->production($pro->id);
        $earned = Commissions::byProfessional($tenant->id, $range['from']->copy()->utc()->toIso8601String(), $range['to']->copy()->utc()->toIso8601String(), null, $pro->id)[$pro->id] ?? 0.0;
        $goal = property_exists($pro, 'goal_month') && $pro->goal_month !== null ? round((float) $pro->goal_month, 2) : null;
        $rules = $this->rows($tenant->id)
            ->where('r.active', true)
            ->where(fn ($q) => $q->whereNull('r.professional_id')->orWhere('r.professional_id', $pro->id))
            ->orderBy('r.kind')->orderBy('c.name')->orderBy('s.name')->orderBy('pr.name')
            ->get(self::COLUMNS)
            ->map(fn ($row) => $this->present($row))
            ->filter(fn ($rule) => $rule['type'] !== 'product' || Cart::productsEnabled($tenant))
            ->values();

        return response()->json([
            'month'       => \Illuminate\Support\Str::ucfirst($range['from']->locale('es')->translatedFormat('F Y')),
            'currency'    => \App\Support\Currency::of($tenant),
            'goal_ready'  => property_exists($pro, 'goal_month'),
            'goal'        => $goal,
            'production'  => $production,
            'with_products' => Cart::productsEnabled($tenant),
            'progress'    => $goal ? min(100, (int) round($production * 100 / $goal)) : null,
            'earned'      => round((float) $earned, 2),
            'own_percent' => $pro->commission_percent !== null ? round((float) $pro->commission_percent, 2) : null,
            'base'        => Commissions::rules($tenant)['base'],
            'on_products' => Commissions::rules($tenant)['on_products'] && Cart::productsEnabled($tenant),
            'rules'       => $rules,
            'can_edit'    => $user->allows('commissions.rules'),
        ]);
    }

    private function validated(Request $request, Tenant $tenant, ?string $ignoreId = null): array
    {
        $exists = fn (string $table) => Rule::exists($table, 'id')->where('tenant_id', $tenant->id);

        $data = $request->validate([
            'type'            => ['required', Rule::in(['service', 'exception', 'category', 'product'])],
            'professional_id' => ['nullable', 'uuid', $exists('professionals'), 'required_if:type,exception'],
            'service_id'      => ['nullable', 'uuid', $exists('services'), 'required_if:type,service', 'required_if:type,exception'],
            'product_id'      => ['nullable', 'uuid', $exists('products')],
            'category_id'     => ['nullable', 'uuid', $exists('service_categories'), 'required_if:type,category'],
            'percent'         => 'nullable|numeric|min:0|max:100',
            'fixed'           => 'nullable|numeric|min:0|max:999999',
            'active'          => 'boolean',
        ], [
            'professional_id.required_if' => 'Elige a quién es la excepción.',
            'service_id.required_if'      => 'Elige el servicio.',
            'category_id.required_if'     => 'Elige la categoría.',
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
            'service'   => ['kind' => 'service', 'professional_id' => null, 'service_id' => $data['service_id'], 'product_id' => null, 'category_id' => null],
            'exception' => ['kind' => 'service', 'professional_id' => $data['professional_id'], 'service_id' => $data['service_id'], 'product_id' => null, 'category_id' => null],
            'category'  => ['kind' => 'service', 'professional_id' => null, 'service_id' => null, 'product_id' => null, 'category_id' => $data['category_id']],
            default     => ['kind' => 'product', 'professional_id' => null, 'service_id' => null, 'product_id' => $data['product_id'] ?? null, 'category_id' => null],
        };

        $same = DB::table('commission_rules')->where('tenant_id', $tenant->id)->where('kind', $values['kind'])
            ->when($ignoreId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->where(fn ($q) => $values['professional_id'] ? $q->where('professional_id', $values['professional_id']) : $q->whereNull('professional_id'))
            ->where(fn ($q) => $values['service_id'] ? $q->where('service_id', $values['service_id']) : $q->whereNull('service_id'))
            ->where(fn ($q) => $values['product_id'] ? $q->where('product_id', $values['product_id']) : $q->whereNull('product_id'))
            ->where(fn ($q) => $values['category_id'] ? $q->where('category_id', $values['category_id']) : $q->whereNull('category_id'))
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
        $category = $name('service_categories', 'name', $values['category_id'] ?? null);

        return mb_substr(match (true) {
            $values['kind'] === 'product' => $product ? "el producto {$product}" : 'todos los productos',
            (bool) $professional          => "{$professional} en {$service}",
            (bool) $category              => "la categoría {$category}, para todos",
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

        $own = \App\Support\Access::ownProfessionalId($request->user());
        abort_if($own !== null && $own !== $data['professional_id'], 403, 'Con tu rol solo puedes ver tus propias comisiones.');

        Commissions::flush();

        return response()->json(Commissions::preview($tenant, $data['kind'], $data['professional_id'], $data['reference_id']));
    }
}
