<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Access;
use App\Support\Currency;
use App\Support\Reports;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    private const MONEY = [
        'sales'     => ['income', 'ticket', 'discount', 'tips', 'refunds'],
        'customers' => [],
    ];

    public function show(Request $request)
    {
        $user = $request->user();
        $tenant = $user->tenant;
        abort_unless($tenant, 403);

        $validated = $request->validate([
            'tab'       => ['sometimes', Rule::in(array_keys(Reports::TABS))],
            'period'    => ['sometimes', Rule::in(array_keys(Reports::PERIODS))],
            'from'      => 'required_if:period,custom|nullable|date_format:Y-m-d',
            'to'        => 'required_if:period,custom|nullable|date_format:Y-m-d',
            'branch_id' => 'nullable|uuid',
        ], [
            'from.required_if' => 'Elige desde qué día.',
            'to.required_if'   => 'Elige hasta qué día.',
        ]);

        $ownPro = \App\Models\Professional::where('tenant_id', $tenant->id)->where('user_id', $user->id)->value('id');
        $ownOnly = !$user->allows('reports.view') || Access::scopeOf($user) === 'own';
        abort_if($ownOnly && !$ownPro, 403, 'Tu cuenta no está ligada a un profesional: no hay números propios que mostrar.');
        abort_if($ownOnly && !$user->allows('reports.own') && !$user->allows('reports.view'), 403);

        $tab = $ownOnly ? 'mine' : ($validated['tab'] ?? 'sales');
        $tab = $tab === 'mine' && !$ownPro ? 'sales' : $tab;
        $period = $validated['period'] ?? '30';

        if ($period === 'custom') {
            if ($validated['to'] < $validated['from']) {
                throw ValidationException::withMessages(['to' => 'La fecha final no puede ser anterior a la inicial.']);
            }
            if (Carbon::parse($validated['from'])->diffInDays(Carbon::parse($validated['to'])) >= Reports::MAX_DAYS) {
                throw ValidationException::withMessages(['to' => 'Elige un periodo de hasta un año.']);
            }
        }

        $limit = $ownOnly ? null : Access::branchLimit($user);
        $branches = DB::table('branches')->where('tenant_id', $tenant->id)->where('status', 'active')
            ->when($limit, fn ($q, $id) => $q->where('id', $id))
            ->when($tab === 'mine', fn ($q) => $q->whereIn('id', DB::table('professional_schedules')->where('professional_id', $ownPro)->select('branch_id')))
            ->orderBy('name')->get(['id', 'name']);
        $branchId = $limit ?: (($validated['branch_id'] ?? null) && $branches->contains('id', $validated['branch_id']) ? $validated['branch_id'] : null);

        $advanced = $tenant->hasModule('reports_adv');
        $money = $tab === 'mine' ? ($user->allows('reports.own') || $user->allows('reports.money')) : $user->allows('reports.money');
        $range = Reports::range($tenant, $period, $validated['from'] ?? null, $validated['to'] ?? null);
        $locked = Reports::TABS[$tab]['advanced'] && !$advanced;

        $mineId = $tab === 'mine' ? $ownPro : null;
        $current = new Reports($tenant, $branchId, $range['from'], $range['to'], $mineId);
        $previous = new Reports($tenant, $branchId, $range['prev_from'], $range['prev_to'], $mineId);

        $data = $locked ? null : match ($tab) {
            'sales' => [
                'summary'  => $this->hide($current->salesSummary(), $money),
                'previous' => $advanced ? $this->hide($previous->salesSummary(), $money) : null,
                'detail'   => $money ? $current->salesDetail() : null,
            ],
            'appointments' => [
                'summary'  => $current->appointmentsSummary(),
                'previous' => $advanced ? $previous->appointmentsSummary() : null,
                'detail'   => $current->appointmentsDetail($advanced),
            ],
            'customers' => [
                'summary'  => $current->customersSummary(),
                'previous' => $previous->customersSummary(),
                'top'      => $money ? $current->topCustomers() : null,
            ],
            'pros' => [
                'rows' => collect($current->professionals())->map(fn ($row) => $money ? $row : array_merge($row, ['income' => null, 'collected' => null]))->all(),
            ],
            'catalog' => collect($current->catalog($tenant->hasModule('inventory')))
                ->map(fn ($rows) => $rows === null ? null : array_map(fn ($row) => $money ? $row : array_merge($row, ['income' => null, 'collected' => null]), $rows))->all(),
            'mine' => [
                'summary'   => $money ? $current->mine() : array_merge($current->mine(), ['income' => null]),
                'previous'  => $money ? $previous->mine() : array_merge($previous->mine(), ['income' => null]),
                'by_day'    => $current->appointmentsDetail(false)['occupancy_by_day'],
                'services'  => array_map(fn ($row) => $money ? $row : array_merge($row, ['income' => null, 'collected' => null]), $current->catalog(false)['services']),
                'customers' => array_map(fn ($row) => $money ? $row : array_merge($row, ['income' => null, 'collected' => null]), $current->myCustomers()),
            ],
        };

        return response()->json([
            'tab'      => $tab,
            'tabs'     => collect(Reports::TABS)
                ->filter(fn ($t, $key) => $ownOnly ? $key === 'mine' : ($key !== 'mine' || $ownPro))
                ->map(fn ($t, $key) => ['key' => $key, 'label' => $t['label'], 'locked' => $t['advanced'] && !$advanced])->values(),
            'own_only' => $ownOnly,
            'periods'  => collect(Reports::PERIODS)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'branches' => $branches,
            'branch_id' => $branchId,
            'branch_locked' => (bool) $limit,
            'range'    => [
                'period'    => $period,
                'from'      => $range['from']->toDateString(),
                'to'        => $range['to']->copy()->subDay()->toDateString(),
                'prev_from' => $range['prev_from']->toDateString(),
                'prev_to'   => $range['prev_to']->copy()->subDay()->toDateString(),
                'days'      => $range['days'],
            ],
            'advanced' => $advanced,
            'money'    => $money,
            'currency' => Currency::of($tenant),
            'locked'   => $locked,
            'data'     => $data,
        ]);
    }

    private function hide(array $summary, bool $money): array
    {
        return $money ? $summary : array_merge($summary, array_fill_keys(self::MONEY['sales'], null));
    }
}
