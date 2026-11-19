<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Announcements;
use App\Support\Audit;
use App\Support\Referrals;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AnnouncementController extends Controller
{
    public function feed(Request $request)
    {
        return response()->json(['ready' => Announcements::ready(), 'items' => Announcements::forUser($request->user())]);
    }

    public function dismiss(Request $request, string $announcement)
    {
        abort_unless(Announcements::ready(), 503, Announcements::NOT_READY);
        abort_unless(DB::table('saas_announcements')->where('id', $announcement)->exists(), 404);

        Announcements::dismiss($request->user(), $announcement);

        return response()->json(['items' => Announcements::forUser($request->user())]);
    }

    private function options(): array
    {
        $pairs = fn (array $list) => collect($list)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values();

        return [
            'kinds'          => $pairs(Announcements::KINDS),
            'audiences'      => $pairs(Announcements::AUDIENCES),
            'plans'          => DB::table('saas_plans')->orderBy('presentation_order')->get(['id', 'name'])->map(fn ($p) => ['value' => $p->id, 'label' => $p->name]),
            'business_types' => DB::table('business_types')->orderBy('sort_order')->get(['id', 'name'])->map(fn ($b) => ['value' => $b->id, 'label' => $b->name]),
        ];
    }

    public function index()
    {
        if (!Announcements::ready()) {
            return response()->json(['ready' => false, 'items' => [], 'message' => Announcements::NOT_READY] + $this->options());
        }

        return response()->json([
            'ready' => true,
            'items' => DB::table('saas_announcements')->orderByDesc('starts_at')->limit(300)->get()->map(fn ($row) => Announcements::present($row))->values(),
        ] + $this->options());
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title'               => 'required|string|min:3|max:150',
            'body'                => 'required|string|max:10000',
            'kind'                => ['required', Rule::in(array_keys(Announcements::KINDS))],
            'audience'            => ['required', Rule::in(array_keys(Announcements::AUDIENCES))],
            'plan_ids'            => 'required_if:audience,plans|array',
            'plan_ids.*'          => 'uuid|exists:saas_plans,id',
            'business_type_ids'   => 'required_if:audience,business_types|array',
            'business_type_ids.*' => 'uuid|exists:business_types,id',
            'starts_on'           => 'required|date_format:Y-m-d',
            'ends_on'             => 'nullable|date_format:Y-m-d|after_or_equal:starts_on',
        ], [
            'title.required'             => 'Escribe el título del anuncio.',
            'body.required'              => 'Escribe el texto del anuncio.',
            'plan_ids.required_if'       => 'Elige al menos un plan.',
            'business_type_ids.required_if' => 'Elige al menos un rubro.',
            'starts_on.required'         => 'Elige desde qué día se ve.',
            'ends_on.after_or_equal'     => 'El último día no puede ser antes del primero.',
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(Announcements::ready(), 503, Announcements::NOT_READY);
        $row = Announcements::save(null, $this->validated($request), $request->user());

        Audit::log($request, 'announcement.created', ['entity_type' => 'announcement', 'entity_id' => $row->id, 'entity_label' => $row->title, 'after' => ['title' => $row->title]]);

        return response()->json(Announcements::present($row), 201);
    }

    public function update(Request $request, string $announcement)
    {
        abort_unless(Announcements::ready(), 503, Announcements::NOT_READY);
        $before = DB::table('saas_announcements')->where('id', $announcement)->first() ?? abort(404);
        $row = Announcements::save($announcement, $this->validated($request), $request->user());

        Audit::log($request, 'announcement.updated', ['entity_type' => 'announcement', 'entity_id' => $row->id, 'entity_label' => $row->title, 'before' => ['title' => $before->title], 'after' => ['title' => $row->title]]);

        return response()->json(Announcements::present($row));
    }

    public function destroy(Request $request, string $announcement)
    {
        abort_unless(Announcements::ready(), 503, Announcements::NOT_READY);
        $row = DB::table('saas_announcements')->where('id', $announcement)->first() ?? abort(404);
        DB::table('saas_announcements')->where('id', $announcement)->delete();
        DB::table('user_notifications')->where('entity_type', 'announcement')->where('entity_id', $announcement)->delete();

        Audit::log($request, 'announcement.deleted', ['entity_type' => 'announcement', 'entity_id' => $row->id, 'entity_label' => $row->title, 'before' => ['title' => $row->title]]);

        return response()->noContent();
    }

    public function referrals()
    {
        return response()->json(Referrals::forPlatform());
    }

    public function referralSettings(Request $request)
    {
        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'kind'    => ['required', Rule::in(array_keys(Referrals::KINDS))],
            'days'    => 'required|integer|min:1|max:365',
            'percent' => 'required|integer|min:5|max:100',
            'referred_percent' => 'nullable|integer|min:0|max:100',
            'referred_months'  => 'nullable|integer|min:0|max:24',
        ]);

        $before = Referrals::config();
        $after = Referrals::saveConfig($validated);

        Audit::log($request, 'referral.settings_changed', ['before' => $before, 'after' => $after]);

        return response()->json(Referrals::forPlatform());
    }
}
