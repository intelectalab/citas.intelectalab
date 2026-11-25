<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Professional;
use App\Models\Service;
use App\Models\Tenant;
use App\Support\Access;
use App\Support\AppointmentFlow;
use App\Support\Cart;
use App\Support\Currency;
use App\Support\Reports;
use App\Support\ServiceProfessionals;
use App\Support\WorkMinutes;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TeamOverviewController extends Controller
{
    private const VACATION_DAYS = 14;
    private const WINDOW_DAYS = 30;

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 403);

        return $tenant;
    }

    public function overview(Request $request)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();
        $limit = Access::branchLimit($user);
        $zone = $tenant->zone();
        $dayStart = Carbon::now($zone)->startOfDay();
        $dayEnd = $dayStart->copy()->addDay();

        $professionals = Professional::where('tenant_id', $tenant->id)
            ->when($limit, fn ($q, $id) => $q->where(fn ($w) => $w->whereIn('id', Access::professionalsAt($id))
                ->orWhereNotIn('id', DB::table('professional_schedules')->select('professional_id'))))
            ->get(['id', 'color_code']);
        $ids = $professionals->pluck('id')->all();

        $appointments = DB::table('appointments')
            ->where('tenant_id', $tenant->id)
            ->whereIn('professional_id', $ids ?: ['00000000-0000-0000-0000-000000000000'])
            ->whereNotIn('status', AppointmentFlow::RELEASED)
            ->where('starts_at', '>=', $dayStart->copy()->utc())
            ->where('starts_at', '<', $dayEnd->copy()->utc())
            ->get(['professional_id', 'starts_at', 'ends_at'])
            ->groupBy('professional_id');

        $scheduled = WorkMinutes::compute($tenant, $dayStart, $dayEnd)['by_pro'];

        $branches = DB::table('professional_schedules as ps')
            ->join('branches as b', 'b.id', '=', 'ps.branch_id')
            ->where('ps.tenant_id', $tenant->id)
            ->where('b.status', 'active')
            ->select('ps.professional_id', 'b.name')
            ->distinct()
            ->orderBy('b.name')
            ->get()
            ->groupBy('professional_id');

        $map = ServiceProfessionals::map($tenant->id);
        $serviceIds = Service::where('tenant_id', $tenant->id)
            ->whereDoesntHave('status', fn ($q) => $q->where('slug', 'inactive'))
            ->pluck('id');

        $vacations = DB::table('availability_blocks')
            ->where('tenant_id', $tenant->id)
            ->whereIn('professional_id', $ids ?: ['00000000-0000-0000-0000-000000000000'])
            ->where('kind', 'vacation')
            ->where('ends_at', '>', now())
            ->where('starts_at', '<', now()->addDays(self::VACATION_DAYS))
            ->orderBy('starts_at')
            ->get(['professional_id', 'starts_at', 'ends_at'])
            ->groupBy('professional_id');

        return response()->json($professionals->mapWithKeys(function (Professional $p) use ($appointments, $scheduled, $branches, $map, $serviceIds, $vacations, $zone) {
            $today = $appointments[$p->id] ?? collect();
            $booked = $today->sum(fn ($a) => max(0, intdiv(Carbon::parse($a->ends_at)->getTimestamp() - Carbon::parse($a->starts_at)->getTimestamp(), 60)));
            $available = (int) ($scheduled[$p->id] ?? 0);
            $vacation = ($vacations[$p->id] ?? collect())->first();

            return [$p->id => [
                'color'          => $p->color_code,
                'today_count'    => $today->count(),
                'occupancy'      => $available > 0 ? min(100, (int) round($booked * 100 / $available)) : null,
                'branches'       => ($branches[$p->id] ?? collect())->pluck('name')->values()->all(),
                'services_count' => $serviceIds->filter(fn ($id) => !isset($map[$id]) || in_array($p->id, $map[$id], true))->count(),
                'vacation'       => $vacation ? [
                    'starts' => Carbon::parse($vacation->starts_at)->setTimezone($zone)->toDateString(),
                    'ends'   => Carbon::parse($vacation->ends_at)->setTimezone($zone)->toDateString(),
                    'now'    => Carbon::parse($vacation->starts_at)->lte(now()),
                ] : null,
            ]];
        }));
    }

    public function performance(Request $request, string $professional)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();
        $pro = Professional::where('tenant_id', $tenant->id)->findOrFail($professional);
        $self = $pro->user_id === $user->id;

        abort_if(Access::scopeOf($user) === 'own' && !$self, 403, 'Con tu rol solo puedes ver tu propia página.');

        if (($limit = Access::branchLimit($user)) && !$self && DB::table('professional_schedules')->where('professional_id', $pro->id)->exists() && !Access::worksAt($pro->id, $limit)) {
            abort(403, 'Este profesional atiende en otra sede. ' . Access::onlyBranch($user));
        }

        $zone = str_replace("'", '', $tenant->zone());
        $to = Carbon::now($tenant->zone())->startOfDay()->addDay();
        $from = $to->copy()->subDays(self::WINDOW_DAYS);
        $day = fn (string $column) => "to_char({$column} AT TIME ZONE '{$zone}', 'YYYY-MM-DD')";
        $currency = Currency::of($tenant);

        $done = DB::table('appointments as a')
            ->leftJoin('services as s', 's.id', '=', 'a.service_id')
            ->where('a.tenant_id', $tenant->id)
            ->where('a.professional_id', $pro->id)
            ->where('a.status', AppointmentFlow::COMPLETED)
            ->where('a.starts_at', '>=', $from->copy()->utc())
            ->where('a.starts_at', '<', $to->copy()->utc());

        $services = (clone $done)
            ->selectRaw("coalesce(s.name, 'Sin servicio') as name, count(*) as total")
            ->groupBy('s.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['name' => $row->name, 'count' => (int) $row->total]);

        $seesMoney = $self || $user->allows('reports.view') || $user->allows('commissions.view');
        $days = null;

        if ($seesMoney) {
            $attended = (clone $done)
                ->whereRaw('coalesce(a.currency, ?) = ?', [$currency, $currency])
                ->selectRaw($day('a.starts_at') . ' as day, coalesce(sum(a.price), 0) as amount')
                ->groupBy('day')
                ->pluck('amount', 'day');

            $products = Cart::productsEnabled($tenant)
                ? DB::table('sale_items as i')
                    ->join('sales as v', 'v.id', '=', 'i.sale_id')
                    ->where('v.tenant_id', $tenant->id)
                    ->whereIn('v.status', Reports::PAID)
                    ->where('v.currency', $currency)
                    ->where('i.item_type', 'product')
                    ->where('i.professional_id', $pro->id)
                    ->whereRaw('coalesce(v.paid_at, v.created_at) >= ?', [$from->copy()->utc()->toIso8601String()])
                    ->whereRaw('coalesce(v.paid_at, v.created_at) < ?', [$to->copy()->utc()->toIso8601String()])
                    ->selectRaw($day('coalesce(v.paid_at, v.created_at)') . ' as day, coalesce(sum(i.total), 0) as amount')
                    ->groupBy('day')
                    ->pluck('amount', 'day')
                : collect();

            $days = [];
            for ($cursor = $from->copy(); $cursor->lt($to); $cursor->addDay()) {
                $key = $cursor->toDateString();
                $days[] = ['date' => $key, 'amount' => round((float) ($attended[$key] ?? 0) + (float) ($products[$key] ?? 0), 2)];
            }
        }

        return response()->json([
            'currency'      => $currency,
            'with_products' => Cart::productsEnabled($tenant),
            'days'          => $days,
            'total'         => $days === null ? null : round(array_sum(array_column($days, 'amount')), 2),
            'services'      => $services,
        ]);
    }
}
