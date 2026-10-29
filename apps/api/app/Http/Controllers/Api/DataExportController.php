<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DataExportController extends Controller
{
    public const ACTION = 'data.export';

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user->tenant_id, 403);

        $validated = $this->validated($request);

        AuditEvent::create([
            'tenant_id'     => $user->tenant_id,
            'actor_user_id' => $user->id,
            'action'        => self::ACTION,
            'entity_type'   => mb_substr($validated['screen'], 0, 100),
            'ip_address'    => $request->ip(),
            'user_agent'    => mb_substr((string) $request->userAgent(), 0, 500),
            'after_data'    => [
                'title'   => $validated['title'],
                'format'  => $validated['format'],
                'scope'   => $validated['scope'],
                'rows'    => (int) $validated['rows'],
                'limit'   => $validated['limit'] ?? null,
                'columns' => array_values($validated['columns'] ?? []),
                'filters' => array_values($validated['filters'] ?? []),
                'branch'  => $user->branch_id ? DB::table('branches')->where('id', $user->branch_id)->value('name') : null,
            ],
        ]);

        return response()->json(['ok' => true], 201);
    }

    public function platform(Request $request)
    {
        $validated = $this->validated($request);

        Audit::log($request, self::ACTION, [
            'entity_type'  => mb_substr($validated['screen'], 0, 100),
            'entity_label' => $validated['title'],
            'after'        => [
                'title'   => $validated['title'],
                'format'  => $validated['format'],
                'scope'   => $validated['scope'],
                'rows'    => (int) $validated['rows'],
                'limit'   => $validated['limit'] ?? null,
                'columns' => array_values($validated['columns'] ?? []),
                'filters' => array_values($validated['filters'] ?? []),
            ],
        ]);

        return response()->json(['ok' => true], 201);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'screen'    => 'required|string|max:100',
            'title'     => 'required|string|max:150',
            'format'    => 'required|in:xlsx,csv',
            'scope'     => 'required|in:filtered,selected',
            'rows'      => 'required|integer|min:0|max:1000000',
            'limit'     => 'nullable|integer|min:1|max:1000000',
            'columns'   => 'nullable|array|max:80',
            'columns.*' => 'string|max:150',
            'filters'   => 'nullable|array|max:30',
            'filters.*' => 'string|max:300',
        ]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->tenant_id, 403);
        abort_unless($user->allows('users.view'), 403, 'Tu cuenta no tiene permiso para ver las descargas de datos.');

        $rows = DB::table('audit_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.actor_user_id')
            ->where('e.tenant_id', $user->tenant_id)
            ->where('e.action', self::ACTION)
            ->orderByDesc('e.created_at')
            ->limit(100)
            ->get(['e.id', 'e.created_at', 'e.after_data', 'u.first_name', 'u.last_name', 'u.email']);

        return response()->json([
            'exports' => $rows->map(function ($row) {
                $data = json_decode((string) $row->after_data, true) ?: [];

                return [
                    'id'      => $row->id,
                    'at'      => Carbon::parse($row->created_at)->toIso8601String(),
                    'by'      => trim("{$row->first_name} {$row->last_name}") ?: ($row->email ?? '—'),
                    'title'   => $data['title'] ?? '—',
                    'format'  => $data['format'] ?? '',
                    'scope'   => $data['scope'] ?? 'filtered',
                    'rows'    => (int) ($data['rows'] ?? 0),
                    'limit'   => $data['limit'] ?? null,
                    'columns' => $data['columns'] ?? [],
                    'filters' => $data['filters'] ?? [],
                ];
            })->values(),
        ]);
    }
}
