<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\LinkPage;
use App\Support\PublicBooking;
use App\Support\Rls;
use Illuminate\Http\Request;

class PublicLinkController extends Controller
{
    public function show(string $slug)
    {
        $tenant = PublicBooking::tenant($slug);
        abort_unless($tenant, 404, 'No encontramos esta página. Revisa el enlace.');
        Rls::tenant($tenant->id);
        abort_unless(LinkPage::get($tenant)['enabled'], 404, 'Esta empresa aún no publica su enlace para redes.');

        return response()->json(LinkPage::publicPayload($tenant))->header('Cache-Control', 'public, max-age=60');
    }

    public function visit(Request $request, string $slug)
    {
        $tenant = PublicBooking::tenant($slug);
        abort_unless($tenant, 404);
        Rls::tenant($tenant->id);
        abort_unless(LinkPage::get($tenant)['enabled'], 404);

        $kind = $request->input('kind') === 'booking' ? 'bookings' : 'visits';
        LinkPage::recordVisit($tenant, $kind);

        return response()->noContent();
    }
}
