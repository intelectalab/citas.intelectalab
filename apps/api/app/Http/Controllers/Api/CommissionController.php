<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Cart;
use App\Support\Commissions;
use App\Support\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommissionController extends Controller
{
    private function tenant(Request $request): Tenant
    {
        $user = $request->user();
        abort_unless($user->tenant, 403);
        abort_unless(Commissions::ready(), 503, Commissions::NOT_READY);

        return $user->tenant->loadMissing(['settings', 'activeSubscription.plan']);
    }

    private function periods(Tenant $tenant): array
    {
        $now = now($tenant->zone())->startOfMonth();
        $months = collect(range(0, 11))->map(fn ($i) => $now->copy()->subMonths($i)->format('Y-m'));
        $used = DB::table('commission_entries')->where('tenant_id', $tenant->id)->distinct()->pluck('period');

        return $months->merge($used)->unique()->sortDesc()->values()
            ->map(fn ($p) => ['value' => $p, 'label' => Str::ucfirst(Carbon::createFromFormat('Y-m-d', "{$p}-01")->locale('es')->translatedFormat('F Y'))])
            ->all();
    }

    private function present(object $row): array
    {
        return [
            'id'              => $row->id,
            'earned_at'       => Carbon::parse($row->earned_at)->toIso8601String(),
            'professional'    => ['id' => $row->professional_id, 'name' => $row->professional_name],
            'branch_name'     => $row->branch_name,
            'source'          => $row->source,
            'source_label'    => Commissions::SOURCES[$row->source] ?? $row->source,
            'kind'            => $row->kind,
            'sale'            => $row->sale_id ? ['id' => $row->sale_id, 'code' => $row->sale_code] : null,
            'description'     => $row->description,
            'base'            => round((float) $row->base, 2),
            'percent'         => round((float) $row->percent, 2),
            'fixed'           => round((float) $row->fixed, 2),
            'rule_name'       => $row->rule_name,
            'amount'          => round((float) $row->amount, 2),
            'currency'        => $row->currency,
            'status'          => $row->status,
            'status_label'    => Commissions::STATUSES[$row->status] ?? $row->status,
            'approved_at'     => $row->approved_at ? Carbon::parse($row->approved_at)->toIso8601String() : null,
            'paid_at'         => $row->paid_at ? Carbon::parse($row->paid_at)->toIso8601String() : null,
        ];
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();
        $validated = $request->validate(['period' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $period = $validated['period'] ?? Commissions::period($tenant);

        $rows = Commissions::scoped($user)
            ->where('e.period', $period)
            ->join('professionals as p', 'p.id', '=', 'e.professional_id')
            ->leftJoin('branches as b', 'b.id', '=', 'e.branch_id')
            ->leftJoin('sales as s', 's.id', '=', 'e.sale_id')
            ->orderByDesc('e.earned_at')
            ->get(['e.*', 'p.display_name as professional_name', 'b.name as branch_name', 's.code as sale_code']);

        $sum = fn ($items, ?string $status = null) => round((float) ($status ? $items->where('status', $status) : $items)->sum('amount'), 2);

        $byProfessional = $rows->groupBy('professional_id')->map(fn ($items, $id) => [
            'id'         => $id,
            'name'       => $items->first()->professional_name,
            'calculated' => $sum($items, 'calculated'),
            'approved'   => $sum($items, 'approved'),
            'paid'       => $sum($items, 'paid'),
            'total'      => $sum($items),
            'count'      => $items->count(),
        ])->sortBy('name')->values();

        $scope = Access::scopeOf($user);

        return response()->json([
            'period'    => $period,
            'periods'   => $this->periods($tenant),
            'currency'  => Currency::of($tenant),
            'scope'     => $scope,
            'rules'     => Commissions::rules($tenant),
            'products'  => Cart::productsEnabled($tenant),
            'can'       => [
                'rules'   => $user->allows('commissions.rules'),
                'approve' => $user->allows('commissions.approve'),
                'pay'     => $user->allows('commissions.pay'),
            ],
            'summary'   => [
                'calculated'       => $sum($rows, 'calculated'),
                'approved'         => $sum($rows, 'approved'),
                'paid'             => $sum($rows, 'paid'),
                'total'            => $sum($rows),
                'calculated_count' => $rows->where('status', 'calculated')->count(),
                'approved_count'   => $rows->where('status', 'approved')->count(),
                'paid_count'       => $rows->where('status', 'paid')->count(),
                'professionals'    => $byProfessional->count(),
            ],
            'by_professional' => $byProfessional,
            'entries'   => $rows->map(fn ($row) => $this->present($row))->values(),
        ]);
    }

    public function transition(Request $request)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();
        $validated = $request->validate([
            'ids'   => 'required|array|min:1|max:1000',
            'ids.*' => 'uuid',
            'to'    => ['required', Rule::in(['approved', 'paid'])],
        ], ['ids.required' => 'Elige al menos una comisión en la lista.']);

        abort_unless($user->allows($validated['to'] === 'paid' ? 'commissions.pay' : 'commissions.approve'), 403);

        $from = $validated['to'] === 'paid' ? ['approved'] : ['calculated'];
        $ids = Commissions::scoped($user)->whereIn('e.id', $validated['ids'])->whereIn('e.status', $from)->pluck('e.id');

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages(['ids' => $validated['to'] === 'paid'
                ? 'Ninguna de las elegidas está aprobada: primero apruébalas y después márcalas como pagadas.'
                : 'Ninguna de las elegidas está por aprobar.']);
        }

        $column = $validated['to'] === 'paid' ? 'paid' : 'approved';
        $total = (float) DB::table('commission_entries')->whereIn('id', $ids)->sum('amount');

        DB::table('commission_entries')->whereIn('id', $ids)->update([
            'status' => $validated['to'], "{$column}_at" => now(), "{$column}_by" => $user->id, 'updated_at' => now(),
        ]);

        Audit::log($request, $validated['to'] === 'paid' ? 'commission.paid' : 'commission.approved', [
            'tenant' => $tenant->id, 'entity_type' => 'commission',
            'entity_label' => $ids->count() === 1 ? '1 comisión' : "{$ids->count()} comisiones",
            'after' => ['count' => $ids->count(), 'total' => round($total, 2)],
        ]);

        $skipped = count($validated['ids']) - $ids->count();

        return response()->json([
            'updated' => $ids->count(),
            'skipped' => $skipped,
            'message' => ($validated['to'] === 'paid'
                ? ($ids->count() === 1 ? '1 comisión marcada como pagada' : "{$ids->count()} comisiones marcadas como pagadas")
                : ($ids->count() === 1 ? '1 comisión aprobada' : "{$ids->count()} comisiones aprobadas"))
                . ($skipped > 0 ? ". {$skipped} no se cambiaron porque " . ($validated['to'] === 'paid' ? 'no estaban aprobadas.' : 'ya estaban aprobadas o pagadas.') : '.'),
        ]);
    }

    public function recalculate(Request $request)
    {
        $tenant = $this->tenant($request);
        abort_unless(Commissions::enabled($tenant), 403, 'El módulo Comisiones está apagado.');
        $validated = $request->validate(['period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);

        abort_if($validated['period'] > Commissions::period($tenant), 422, 'No se puede calcular un mes que aún no empieza.');

        Commissions::flush();
        $result = Commissions::recalculate($tenant, $validated['period']);

        Audit::log($request, 'commission.recalculated', [
            'tenant' => $tenant->id, 'entity_type' => 'commission', 'entity_label' => $validated['period'],
            'after' => ['period' => $validated['period'], 'created' => $result['created'], 'removed' => $result['removed']],
        ]);

        $count = DB::table('commission_entries')->where('tenant_id', $tenant->id)->where('period', $validated['period'])->count();

        return response()->json($result + ['message' => "Mes recalculado: quedan {$count} " . ($count === 1 ? 'comisión' : 'comisiones') . '. Las aprobadas y pagadas no se tocaron.']);
    }
}
