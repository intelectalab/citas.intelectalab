<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Audit;
use App\Support\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CurrencyController extends Controller
{
    private function tenant(Request $request): Tenant
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 403, 'Esta cuenta no pertenece a una empresa.');
        abort_unless(Currency::ready(), 503, Currency::NOT_READY);

        return $tenant->loadMissing('settings');
    }

    private function payload(Request $request, Tenant $tenant): array
    {
        $current = Currency::of($tenant);

        return [
            'current'    => Currency::present($current),
            'currencies' => collect(Currency::catalog())->filter(fn ($c) => $c['is_active'] || $c['code'] === $current)->values(),
            'blockers'   => Currency::blockers($tenant),
            'records'    => collect(Currency::recordsByCurrency($tenant))->sortByDesc('sales')->values(),
            'history'    => Currency::history($tenant),
            'can_edit'   => $request->user()->allows('settings.edit'),
        ];
    }

    public function show(Request $request)
    {
        return response()->json($this->payload($request, $this->tenant($request)));
    }

    public function update(Request $request)
    {
        $tenant = $this->tenant($request);
        $active = Currency::all()->where('is_active', true)->pluck('code')->all();

        $validated = $request->validate(['code' => ['required', Rule::in($active)]], [
            'code.required' => 'Elige la moneda.',
            'code.in'       => 'Esa moneda no está disponible.',
        ]);

        if ($validated['code'] !== Currency::of($tenant) && ($blockers = Currency::blockers($tenant))) {
            return response()->json(['message' => implode(' ', $blockers), 'code' => 'CURRENCY_BLOCKED', 'blockers' => $blockers], 422);
        }

        $from = Currency::of($tenant);
        Currency::setTenantCurrency($tenant, $validated['code'], $request->user());

        if ($from !== $validated['code']) {
            Audit::log($request, 'settings.changed', [
                'tenant'       => $tenant->id,
                'entity_type'  => 'settings',
                'entity_label' => 'Moneda',
                'before'       => ['currency' => Currency::present($from)['name']],
                'after'        => ['currency' => Currency::present($validated['code'])['name']],
            ]);
        }

        return response()->json($this->payload($request, $tenant->fresh('settings')));
    }

    private function validatedRecords(Request $request, Tenant $tenant, bool $applying): array
    {
        $current = Currency::of($tenant);
        $codes = Currency::all()->pluck('code')->all();

        $validated = $request->validate([
            'kind'      => ['required', Rule::in(['relabel', 'convert'])],
            'from'      => ['required', Rule::in($codes), Rule::notIn([$current])],
            'starts_on' => 'nullable|date_format:Y-m-d',
            'ends_on'   => 'nullable|date_format:Y-m-d|after_or_equal:starts_on',
            'rate'      => [$applying ? 'required_if:kind,convert' : 'nullable', 'nullable', 'numeric', 'gt:0', 'max:1000000'],
            'direction' => ['nullable', Rule::in(['from_to', 'to_from'])],
        ], [
            'kind.required'         => 'Elige qué pasó.',
            'from.required'         => 'Elige la moneda de los registros que quieres cambiar.',
            'from.not_in'           => 'Esos registros ya están en la moneda de tu empresa.',
            'ends_on.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
            'rate.required_if'      => 'Escribe el tipo de cambio.',
            'rate.gt'               => 'El tipo de cambio debe ser mayor que cero.',
            'rate.numeric'          => 'Escribe el tipo de cambio como un número, por ejemplo 3.70.',
        ]);

        $factor = null;

        if ($validated['kind'] === 'convert' && !empty($validated['rate'])) {
            $rate = (float) $validated['rate'];
            $factor = ($validated['direction'] ?? 'to_from') === 'from_to' ? $rate : 1 / $rate;
        }

        return $validated + ['to' => $current, 'factor' => $factor];
    }

    public function preview(Request $request)
    {
        $tenant = $this->tenant($request);
        $data = $this->validatedRecords($request, $tenant, false);
        $scope = Currency::scope($tenant, $data['from'], $data['starts_on'] ?? null, $data['ends_on'] ?? null);
        $sample = $scope['sales']
            ? (float) DB::table('sales')->whereIn('id', $scope['sales'])->orderByDesc('created_at')->value('total')
            : ($scope['appointments'] ? (float) DB::table('appointments')->whereIn('id', array_slice($scope['appointments'], 0, 200))->where('price', '>', 0)->value('price') : null);

        return response()->json([
            'from'   => Currency::present($data['from']),
            'to'     => Currency::present($data['to']),
            'counts' => Currency::counts($scope),
            'sample' => $sample !== null ? [
                'before' => Currency::format($sample, $data['from']),
                'after'  => Currency::format($data['kind'] === 'convert' && $data['factor'] ? round($sample * $data['factor'], Currency::present($data['to'])['decimals']) : $sample, $data['to']),
            ] : null,
        ]);
    }

    public function apply(Request $request)
    {
        $tenant = $this->tenant($request);
        $data = $this->validatedRecords($request, $tenant, true);
        $scope = Currency::counts(Currency::scope($tenant, $data['from'], $data['starts_on'] ?? null, $data['ends_on'] ?? null));

        abort_if($scope['sales'] + $scope['appointments'] + $scope['sessions'] === 0, 422, 'No hay registros que cambiar con lo que elegiste.');

        $counts = Currency::apply($tenant, $data['kind'], $data['from'], $data['to'], $data['factor'], $data['starts_on'] ?? null, $data['ends_on'] ?? null, $request->user());

        Audit::log($request, 'currency.records_changed', [
            'tenant'       => $tenant->id,
            'entity_type'  => 'settings',
            'entity_label' => 'Moneda',
            'after'        => array_filter([
                'currency_kind'     => $data['kind'] === 'convert' ? 'Convertir con tipo de cambio' : 'Solo cambiar la moneda',
                'currency_from'     => Currency::present($data['from'])['name'],
                'currency_to'       => Currency::present($data['to'])['name'],
                'exchange_rate'     => $data['kind'] === 'convert' ? (float) $data['rate'] : null,
                'starts_on'         => $data['starts_on'] ?? null,
                'ends_on'           => $data['ends_on'] ?? null,
                'sales_count'       => $counts['sales'] ?? null,
                'appointments_count' => $counts['appointments'] ?? null,
            ], fn ($value) => $value !== null),
        ]);

        return response()->json($this->payload($request, $tenant) + ['applied' => $counts]);
    }

    public function platform()
    {
        abort_unless(Currency::ready(), 503, Currency::NOT_READY);

        $tenants = DB::table('tenant_settings')->whereNotNull('rules')->get(['rules'])
            ->map(fn ($s) => json_decode($s->rules, true)['currency.code'] ?? Currency::DEFAULT)
            ->countBy();
        $withoutSetting = DB::table('tenants')->count() - $tenants->sum();
        $plans = DB::table('saas_plans')->groupBy('currency')->selectRaw('currency, count(*) as total')->pluck('total', 'currency');

        return response()->json([
            'currencies' => collect(Currency::catalog())->map(fn ($c) => $c + [
                'tenants' => (int) ($tenants[$c['code']] ?? 0) + ($c['code'] === Currency::DEFAULT ? max(0, $withoutSetting) : 0),
                'plans'   => (int) ($plans[$c['code']] ?? 0),
            ])->values(),
        ]);
    }

    public function toggle(Request $request, string $code)
    {
        abort_unless(Currency::ready(), 503, Currency::NOT_READY);
        abort_unless(Currency::find($code), 404);

        $validated = $request->validate(['is_active' => 'required|boolean']);

        if (!$validated['is_active']) {
            abort_if($code === Currency::DEFAULT, 422, 'El sol peruano es la moneda de las empresas que no eligieron otra: no se apaga.');

            $usage = collect($this->platform()->getData(true)['currencies'])->firstWhere('code', $code);
            abort_if($usage['tenants'] > 0, 422, ($usage['tenants'] === 1 ? 'Una empresa cobra' : "{$usage['tenants']} empresas cobran") . ' en esta moneda: no se puede apagar.');
            abort_if($usage['plans'] > 0, 422, ($usage['plans'] === 1 ? 'Un plan tiene' : "{$usage['plans']} planes tienen") . ' su precio en esta moneda: cámbialos antes de apagarla.');
        }

        DB::table('currencies')->where('code', $code)->update(['is_active' => $validated['is_active'], 'updated_at' => now()]);
        Currency::forget();

        return $this->platform();
    }
}
