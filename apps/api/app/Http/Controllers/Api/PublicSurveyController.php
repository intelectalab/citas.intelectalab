<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Branding;
use App\Support\Rls;
use App\Support\Surveys;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PublicSurveyController extends Controller
{
    private function resolve(string $token): array
    {
        $survey = Surveys::findByToken($token);
        abort_unless($survey, 404, 'No encontramos esta encuesta. Revisa que hayas copiado el enlace completo.');

        Rls::tenant($survey->tenant_id);
        $tenant = Tenant::with('settings')->find($survey->tenant_id);
        abort_unless($tenant && $tenant->status === 'active', 404, 'Esta encuesta ya no está disponible.');

        return [$survey, $tenant];
    }

    private function payload(object $survey, Tenant $tenant): array
    {
        $brand = Branding::present($tenant);
        $config = Surveys::config($tenant);
        $state = $survey->status === 'answered' ? 'answered' : (Surveys::answerable($survey) ? 'open' : 'expired');

        return [
            'state'        => $state,
            'company'      => ['name' => $tenant->name, 'slug' => $tenant->slug, 'primary' => $brand['primary'], 'accent' => $brand['accent'], 'logo' => $brand['logo_url']],
            'service'      => DB::table('services')->where('id', $survey->service_id)->value('name'),
            'professional' => DB::table('professionals')->where('id', $survey->professional_id)->value('display_name'),
            'date'         => Carbon::parse(DB::table('appointments')->where('id', $survey->appointment_id)->value('starts_at'))->setTimezone($tenant->zone())->toDateString(),
            'score'        => $survey->score === null ? null : (int) $survey->score,
            'google'       => $state === 'answered' && $survey->google_invited && $config['google_url'] ? true : false,
            'detractor'    => $survey->score !== null && (int) $survey->score <= Surveys::DETRACTOR_MAX,
            'can_publish'  => $config['publish_reviews'],
        ];
    }

    public function show(string $token)
    {
        [$survey, $tenant] = $this->resolve($token);

        return response()->json($this->payload($survey, $tenant));
    }

    public function answer(Request $request, string $token)
    {
        [$survey, $tenant] = $this->resolve($token);
        $validated = $request->validate([
            'score'           => 'required|integer|min:0|max:10',
            'comment'         => 'nullable|string|max:1000',
            'publish_consent' => 'nullable|boolean',
        ], [
            'score.required' => 'Elige una nota del 0 al 10.',
        ]);

        if ($survey->status === 'answered') {
            return response()->json(['message' => 'Ya respondiste esta encuesta. ¡Gracias!', 'code' => 'ALREADY_ANSWERED'] + $this->payload($survey, $tenant), 409);
        }

        abort_unless(Surveys::answerable($survey), 410, 'Esta encuesta venció. Si quieres contarnos algo, escríbenos directamente.');

        $fresh = Surveys::answer($tenant, $survey, (int) $validated['score'], $validated['comment'] ?? null, (bool) ($validated['publish_consent'] ?? false) && Surveys::config($tenant)['publish_reviews']);

        if (!$fresh) {
            $current = DB::table('surveys')->where('id', $survey->id)->first();
            abort_unless($current->status === 'answered', 410, 'Esta encuesta venció. Si quieres contarnos algo, escríbenos directamente.');

            return response()->json(['message' => 'Ya respondiste esta encuesta. ¡Gracias!', 'code' => 'ALREADY_ANSWERED'] + $this->payload($current, $tenant), 409);
        }

        return response()->json($this->payload($fresh, $tenant));
    }

    public function google(string $token)
    {
        [$survey, $tenant] = $this->resolve($token);
        $url = Surveys::googleUrl($tenant);
        abort_unless($survey->google_invited && $url, 404, 'Esta empresa no tiene un enlace de reseñas en Google.');

        if (!$survey->google_clicked_at) {
            DB::table('surveys')->where('id', $survey->id)->update(['google_clicked_at' => now(), 'updated_at' => now()]);
        }

        return response()->json(['url' => $url]);
    }
}
