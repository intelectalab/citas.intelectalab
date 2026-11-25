<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Surveys;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PortalSurveyController extends Controller
{
    public function index(Request $request)
    {
        $customer = $request->user();

        if (!Surveys::ready()) {
            return response()->json(['surveys' => []]);
        }

        $rows = DB::table('surveys as s')
            ->leftJoin('services as v', 'v.id', '=', 's.service_id')
            ->leftJoin('professionals as p', 'p.id', '=', 's.professional_id')
            ->where('s.tenant_id', $customer->tenant_id)->where('s.customer_id', $customer->id)
            ->where('s.status', 'sent')->where('s.expires_at', '>', now()->toIso8601String())
            ->orderByDesc('s.sent_at')->limit(3)
            ->get(['s.token', 'v.name as service', 'p.display_name as professional']);

        return response()->json(['surveys' => $rows->map(fn ($row) => [
            'url'          => '/encuesta/' . $row->token,
            'service'      => $row->service,
            'professional' => $row->professional,
        ])->values()]);
    }
}
