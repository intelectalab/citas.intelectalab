<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\SupportDesk;
use App\Support\TenantHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PlatformSupportController extends Controller
{
    private function ticket(string $id): object
    {
        abort_unless(SupportDesk::ready(), 503, SupportDesk::NOT_READY);

        return SupportDesk::find($id) ?? abort(404, 'No encontramos ese pedido de ayuda.');
    }

    private function agents(): array
    {
        return User::whereNull('tenant_id')->where('status', 'active')->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'email'])
            ->map(fn ($u) => ['value' => $u->id, 'label' => trim("{$u->first_name} {$u->last_name}") ?: $u->email])->all();
    }

    private function options(): array
    {
        $pairs = fn (array $list) => collect($list)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values();

        return [
            'statuses'   => $pairs(SupportDesk::STATUSES),
            'priorities' => $pairs(SupportDesk::PRIORITIES),
            'topics'     => $pairs(SupportDesk::TOPICS),
            'agents'     => $this->agents(),
        ];
    }

    public function index(Request $request)
    {
        if (!SupportDesk::ready()) {
            return response()->json(['ready' => false, 'tickets' => [], 'message' => SupportDesk::NOT_READY] + $this->options());
        }

        $tickets = DB::table('saas_support_tickets')
            ->orderByRaw("case when status in ('open', 'in_progress') and due_at < now() then 0 when status in ('open', 'in_progress') then 1 when status = 'waiting' then 2 else 3 end")
            ->orderBy('due_at')->orderByDesc('last_message_at')
            ->limit(1000)->get();

        return response()->json([
            'ready'    => true,
            'me'       => $request->user()->id,
            'tickets'  => SupportDesk::presentMany($tickets, true),
            'settings' => SupportDesk::settings(),
        ] + $this->options());
    }

    public function show(string $ticket)
    {
        $row = $this->ticket($ticket);

        return response()->json([
            'ticket'   => SupportDesk::presentMany(collect([$row]), true)[0],
            'messages' => SupportDesk::messages($row, true),
            'settings' => SupportDesk::settings(),
        ] + $this->options());
    }

    public function reply(Request $request, string $ticket)
    {
        $row = $this->ticket($ticket);
        $validated = $request->validate([
            'body'       => 'required|string|min:2|max:5000',
            'status'     => ['nullable', Rule::in(array_keys(SupportDesk::STATUSES))],
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,webp,gif,pdf|max:5120',
        ], ['body.required' => 'Escribe la respuesta.']);

        SupportDesk::reply($row, $request->user(), 'platform', $validated['body'], $request->file('attachment'), $validated['status'] ?? null);

        Audit::log($request, 'support.ticket_replied', [
            'tenant'       => $row->tenant_id,
            'entity_type'  => 'support_ticket',
            'entity_id'    => $row->id,
            'entity_label' => "{$row->code} · {$row->subject}",
        ]);

        return $this->show($ticket);
    }

    public function update(Request $request, string $ticket)
    {
        $row = $this->ticket($ticket);
        $validated = $request->validate([
            'status'      => ['sometimes', Rule::in(array_keys(SupportDesk::STATUSES))],
            'priority'    => ['sometimes', Rule::in(array_keys(SupportDesk::PRIORITIES))],
            'assigned_to' => ['sometimes', 'nullable', 'uuid', Rule::exists('users', 'id')->whereNull('tenant_id')],
        ]);

        $update = $validated + ['updated_at' => now()];

        if (isset($validated['status'])) {
            $update['resolved_at'] = $validated['status'] === 'resolved' ? now() : null;
        }

        if (isset($validated['priority']) && $validated['priority'] !== $row->priority && !$row->first_response_at) {
            $update['due_at'] = \Illuminate\Support\Carbon::parse($row->created_at)->addHours(SupportDesk::responseHours($row->tenant_id, $validated['priority']));
        }

        DB::table('saas_support_tickets')->where('id', $row->id)->update($update);

        [$before, $after] = Audit::changed(
            array_intersect_key((array) $row, $validated),
            $validated,
        );

        Audit::log($request, 'support.ticket_updated', [
            'tenant'       => $row->tenant_id,
            'entity_type'  => 'support_ticket',
            'entity_id'    => $row->id,
            'entity_label' => "{$row->code} · {$row->subject}",
            'before'       => $before,
            'after'        => $after,
        ]);

        return $this->show($ticket);
    }

    public function file(string $ticket, string $message)
    {
        return SupportDesk::file($this->ticket($ticket), $message);
    }

    public function settings(Request $request)
    {
        $validated = $request->validate(['consent_required' => 'required|boolean']);
        $before = SupportDesk::settings();
        $after = SupportDesk::saveSettings($validated);

        Audit::log($request, 'platform.support_settings_changed', ['before' => $before, 'after' => $after]);

        return response()->json(['settings' => $after]);
    }

    public function health()
    {
        return response()->json(TenantHealth::report());
    }
}
