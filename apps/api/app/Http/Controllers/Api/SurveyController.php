<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Reports;
use App\Support\Surveys;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SurveyController extends Controller
{
    private function tenant(Request $request, string $permission = 'surveys.view'): Tenant
    {
        $user = $request->user();
        abort_unless($user?->tenant_id, 403);
        abort_unless($user->allows($permission), 403, Access::denial($permission, $user->tenant));
        abort_unless(Surveys::ready(), 503, Surveys::NOT_READY);

        return $user->tenant->loadMissing('settings');
    }

    private function filters(Request $request, Tenant $tenant): array
    {
        $validated = $request->validate([
            'period'          => ['sometimes', Rule::in(array_keys(Reports::PERIODS))],
            'from'            => 'required_if:period,custom|nullable|date_format:Y-m-d',
            'to'              => 'required_if:period,custom|nullable|date_format:Y-m-d|after_or_equal:from',
            'branch_id'       => 'nullable|uuid',
            'professional_id' => 'nullable|uuid',
            'service_id'      => 'nullable|uuid',
        ], [
            'to.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
        ]);

        $range = Reports::range($tenant, $validated['period'] ?? '90', $validated['from'] ?? null, $validated['to'] ?? null);
        $limit = Access::branchLimit($request->user());

        return [
            'period'          => $validated['period'] ?? '90',
            'from'            => $range['from']->copy()->utc()->toIso8601String(),
            'to'              => $range['to']->copy()->utc()->toIso8601String(),
            'from_day'        => $range['from']->toDateString(),
            'to_day'          => $range['to']->copy()->subDay()->toDateString(),
            'branch_id'       => $limit ?: ($validated['branch_id'] ?? null),
            'professional_id' => $validated['professional_id'] ?? null,
            'service_id'      => $validated['service_id'] ?? null,
        ];
    }

    private function find(Request $request, Tenant $tenant, string $id): object
    {
        $row = Surveys::listQuery($tenant, ['branch_id' => Access::branchLimit($request->user())])->where('s.id', $id)->first();
        abort_unless($row, 404, 'No encontramos esa encuesta.');

        return $row;
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);
        $filters = $this->filters($request, $tenant);
        $rows = Surveys::listQuery($tenant, $filters)->orderByDesc('s.created_at')->limit(1000)->get();

        return response()->json([
            'config'          => Surveys::config($tenant),
            'can_edit'        => $request->user()->allows('surveys.edit'),
            'summary'         => Surveys::summary($tenant, $filters),
            'by_professional' => Surveys::breakdown($tenant, $filters, 'professional'),
            'by_service'      => Surveys::breakdown($tenant, $filters, 'service'),
            'by_branch'       => Surveys::breakdown($tenant, $filters, 'branch'),
            'surveys'         => $rows->map(fn ($row) => Surveys::present($row, $tenant))->values(),
            'range'           => ['period' => $filters['period'], 'from' => $filters['from_day'], 'to' => $filters['to_day']],
            'periods'         => collect(Reports::PERIODS)->map(fn ($label, $key) => ['value' => (string) $key, 'label' => $label])->values(),
            'options'         => [
                'branches'      => DB::table('branches')->where('tenant_id', $tenant->id)->where('status', 'active')->when(Access::branchLimit($request->user()), fn ($q, $id) => $q->where('id', $id))->orderBy('name')->get(['id', 'name']),
                'professionals' => DB::table('professionals')->where('tenant_id', $tenant->id)->orderBy('display_name')->get(['id', 'display_name as name']),
                'services'      => DB::table('services')->where('tenant_id', $tenant->id)->orderBy('name')->get(['id', 'name']),
            ],
            'branch_locked'   => (bool) Access::branchLimit($request->user()),
        ]);
    }

    public function show(Request $request, string $survey)
    {
        $tenant = $this->tenant($request);

        return response()->json([
            'survey'   => Surveys::present($this->find($request, $tenant, $survey), $tenant),
            'can_edit' => $request->user()->allows('surveys.edit'),
            'config'   => Surveys::config($tenant),
        ]);
    }

    public function followUp(Request $request, string $survey)
    {
        $tenant = $this->tenant($request, 'surveys.edit');
        $row = $this->find($request, $tenant, $survey);
        $validated = $request->validate([
            'follow_up' => ['required', Rule::in(['contacted', 'resolved'])],
            'note'      => 'required|string|min:5|max:1000',
        ], [
            'note.required' => 'Cuenta qué se habló con el cliente o qué se le ofreció.',
            'note.min'      => 'Cuenta qué se habló con el cliente o qué se le ofreció.',
        ]);

        if (!$row->follow_up) {
            throw ValidationException::withMessages(['follow_up' => 'Esta respuesta no necesita seguimiento: la nota no fue baja.']);
        }

        DB::table('surveys')->where('id', $row->id)->update([
            'follow_up'      => $validated['follow_up'],
            'follow_up_note' => trim($validated['note']),
            'follow_up_by'   => $request->user()->id,
            'follow_up_at'   => now(),
            'updated_at'     => now(),
        ]);

        $this->audit($request, $tenant, $row, 'survey.follow_up', ['seguimiento' => Surveys::FOLLOW_UPS[$row->follow_up]], ['seguimiento' => Surveys::FOLLOW_UPS[$validated['follow_up']], 'nota' => trim($validated['note'])]);

        return $this->show($request, $row->id);
    }

    public function publish(Request $request, string $survey)
    {
        $tenant = $this->tenant($request, 'surveys.edit');
        $row = $this->find($request, $tenant, $survey);
        $validated = $request->validate(['published' => 'required|boolean']);

        if ($validated['published']) {
            if (!$row->publish_consent || !$row->comment) {
                throw ValidationException::withMessages(['published' => 'Solo se publican las reseñas con comentario de clientes que aceptaron que se muestre.']);
            }

            DB::table('surveys')->where('id', $row->id)->update(['published' => true, 'published_at' => now(), 'published_by' => $request->user()->id, 'hidden_at' => null, 'hidden_by' => null, 'updated_at' => now()]);
        } else {
            DB::table('surveys')->where('id', $row->id)->update(['published' => false, 'hidden_at' => now(), 'hidden_by' => $request->user()->id, 'updated_at' => now()]);
        }

        $this->audit($request, $tenant, $row, $validated['published'] ? 'review.published' : 'review.hidden', ['publicada' => (bool) $row->published], ['publicada' => (bool) $validated['published']]);

        return $this->show($request, $row->id);
    }

    public function reply(Request $request, string $survey)
    {
        $tenant = $this->tenant($request, 'surveys.edit');
        $row = $this->find($request, $tenant, $survey);
        $validated = $request->validate(['reply' => 'nullable|string|max:600']);

        if ($row->score === null) {
            throw ValidationException::withMessages(['reply' => 'La encuesta aún no tiene respuesta.']);
        }

        $reply = trim((string) ($validated['reply'] ?? '')) ?: null;
        DB::table('surveys')->where('id', $row->id)->update(['reply' => $reply, 'replied_at' => $reply ? now() : null, 'replied_by' => $reply ? $request->user()->id : null, 'updated_at' => now()]);
        $this->audit($request, $tenant, $row, 'review.replied', ['respuesta' => $row->reply], ['respuesta' => $reply]);

        return $this->show($request, $row->id);
    }

    public function settings(Request $request)
    {
        $tenant = $this->tenant($request);

        return response()->json(['config' => Surveys::config($tenant), 'can_edit' => $request->user()->allows('surveys.edit')]);
    }

    public function saveSettings(Request $request)
    {
        $tenant = $this->tenant($request, 'surveys.edit');
        $validated = $request->validate([
            'enabled'         => 'required|boolean',
            'delay_hours'     => 'required|integer|min:0|max:48',
            'google_min'      => 'required|integer|min:6|max:10',
            'publish_reviews' => 'required|boolean',
            'valid_days'      => 'required|integer|min:1|max:30',
            'google_url'      => ['nullable', 'string', 'max:500', 'regex:#^https://#i'],
        ], [
            'google_url.regex' => 'Pega el enlace completo que te da Google para pedir reseñas; empieza con https://',
        ]);

        $before = Surveys::config($tenant);
        $after = Surveys::saveConfig($tenant, $validated);

        Audit::log($request, 'settings.changed', [
            'tenant'       => $tenant->id,
            'entity_type'  => 'tenant_settings',
            'entity_id'    => $tenant->id,
            'entity_label' => 'Encuestas y reseñas',
            'before'       => $before,
            'after'        => $after,
        ]);

        return response()->json(['config' => $after, 'can_edit' => true]);
    }

    private function audit(Request $request, Tenant $tenant, object $row, string $action, array $before, array $after): void
    {
        Audit::log($request, $action, [
            'tenant'       => $tenant->id,
            'entity_type'  => 'survey',
            'entity_id'    => $row->id,
            'entity_label' => 'Encuesta de ' . trim(($row->customer_first ?? '') . ' ' . ($row->customer_last ?? '')),
            'before'       => $before,
            'after'        => $after,
        ]);
    }
}
