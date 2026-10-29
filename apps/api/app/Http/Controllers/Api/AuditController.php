<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Tenant;
use App\Support\Access;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AuditController extends Controller
{
    private const MAX_ROWS = 2000;

    public function tenant(Request $request)
    {
        $user = $request->user();
        abort_unless($user->tenant_id, 403);
        abort_unless($user->allows('audit.view'), 403, 'Tu cuenta no tiene permiso para ver la auditoría.');

        $validated = $request->validate([
            'from'      => 'nullable|date',
            'to'        => 'nullable|date',
            'actor_id'  => 'nullable|uuid',
            'limit'     => 'nullable|integer|min:1|max:' . self::MAX_ROWS,
        ]);

        $zone = $user->tenant?->zone() ?? 'America/Lima';
        $from = isset($validated['from']) ? Carbon::parse($validated['from'], $zone)->startOfDay() : now($zone)->subDays(29)->startOfDay();
        $to = isset($validated['to']) ? Carbon::parse($validated['to'], $zone)->endOfDay() : now($zone)->endOfDay();
        $limit = (int) ($validated['limit'] ?? self::MAX_ROWS);

        $query = AuditEvent::query()
            ->where('tenant_id', $user->tenant_id)
            ->whereBetween('created_at', [$from->utc(), $to->utc()])
            ->whereRaw("not (action = 'access.change' and coalesce(after_data->>'text', '') ~ '^(Creó |Le cambió el rol|Activó la cuenta|Desactivó la cuenta|Le cambió el nombre|Le dio una contraseña|Le cambió la sede|Le quitó la sede)')")
            ->orderByDesc('created_at');

        $scope = Access::scopeOf($user);
        $branch = Access::branchLimit($user);

        if ($scope === 'own') {
            $query->where('actor_user_id', $user->id);
        } elseif ($branch !== null) {
            Audit::hasColumn('branch_id') ? $query->where('branch_id', $branch) : $query->whereRaw('false');
        }

        if (!empty($validated['actor_id'])) {
            $query->where('actor_user_id', $validated['actor_id']);
        }

        $events = $query->limit($limit + 1)->get();
        $rows = Audit::present($events->take($limit));
        $branches = Branch::where('tenant_id', $user->tenant_id)->pluck('name', 'id');

        foreach ($rows as $index => $row) {
            $branchId = $events[$index]->branch_id ?? null;
            $rows[$index]['branch'] = $branchId ? ['id' => $branchId, 'name' => $branches[$branchId] ?? 'Sede'] : null;
        }

        return response()->json([
            'events'    => $rows,
            'truncated' => $events->count() > $limit,
            'ready'     => Audit::hasColumn('actor_type'),
            'scope'     => $scope === 'own' ? 'own' : ($branch !== null ? 'branch' : 'tenant'),
            'areas'     => Audit::AREAS,
        ]);
    }

    public function platform(Request $request)
    {
        $validated = $request->validate([
            'from'      => 'nullable|date',
            'to'        => 'nullable|date',
            'tenant_id' => 'nullable|uuid',
            'limit'     => 'nullable|integer|min:1|max:' . self::MAX_ROWS,
        ]);

        $from = isset($validated['from']) ? Carbon::parse($validated['from'], 'America/Lima')->startOfDay() : now('America/Lima')->subDays(29)->startOfDay();
        $to = isset($validated['to']) ? Carbon::parse($validated['to'], 'America/Lima')->endOfDay() : now('America/Lima')->endOfDay();
        $limit = (int) ($validated['limit'] ?? self::MAX_ROWS);

        $query = AuditEvent::query()
            ->select('audit_events.*')
            ->leftJoin('users as actor', 'actor.id', '=', 'audit_events.actor_user_id')
            ->where(function ($scope) {
                $scope->where(fn ($q) => $q->whereNotNull('audit_events.actor_user_id')->whereNull('actor.tenant_id'));

                if (Audit::hasColumn('impersonator_user_id')) {
                    $scope->orWhereNotNull('audit_events.impersonator_user_id');
                }

                if (Audit::hasColumn('actor_type')) {
                    $scope->orWhere('audit_events.actor_type', 'platform');
                }
            })
            ->whereBetween('audit_events.created_at', [$from->utc(), $to->utc()])
            ->orderByDesc('audit_events.created_at');

        if (!empty($validated['tenant_id'])) {
            $query->where('audit_events.tenant_id', $validated['tenant_id']);
        }

        $events = $query->limit($limit + 1)->get();

        return response()->json([
            'events'    => Audit::present($events->take($limit)),
            'truncated' => $events->count() > $limit,
            'ready'     => Audit::hasColumn('actor_type'),
            'tenants'   => Tenant::orderBy('name')->get(['id', 'name']),
            'areas'     => Audit::AREAS,
        ]);
    }
}
