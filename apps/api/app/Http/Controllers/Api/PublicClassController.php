<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Audit;
use App\Support\ClassSessions;
use App\Support\OnlineBooking;
use App\Support\Person;
use App\Support\PublicBooking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PublicClassController extends Controller
{
    private function tenant(Request $request): Tenant
    {
        $tenant = PublicBooking::tenant((string) $request->route('slug'));
        abort_unless($tenant, 404, 'No encontramos esta página de reservas. Revisa el enlace.');
        abort_unless(PublicBooking::open($tenant), 403, PublicBooking::UNAVAILABLE);
        abort_unless(ClassSessions::enabled($tenant), 404, 'Esta empresa no ofrece clases en línea.');

        return $tenant;
    }

    private function bookable(Tenant $tenant)
    {
        return DB::table('class_sessions as c')
            ->join('services as s', 's.id', '=', 'c.service_id')
            ->join('branches as b', 'b.id', '=', 'c.branch_id')
            ->where('c.tenant_id', $tenant->id)
            ->where('c.status', 'scheduled')
            ->where('c.online_booking', true)
            ->where('s.online_booking', true)
            ->where('b.status', 'active')
            ->where('c.starts_at', '>', now()->toIso8601String())
            ->select('c.*');
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);
        $rows = $this->bookable($tenant)
            ->where('c.starts_at', '<', now()->addDays(PortalClassController::DAYS_AHEAD)->toIso8601String())
            ->orderBy('c.starts_at')
            ->limit(300)
            ->get();

        return response()->json([
            'classes' => array_map(fn ($c) => array_diff_key($c, array_flip(['notes', 'cancel_reason', 'cancelled_at', 'series_id', 'attended', 'no_show'])), ClassSessions::presentMany($tenant, $rows)),
        ]);
    }

    public function enroll(Request $request, string $slug, string $class)
    {
        $tenant = $this->tenant($request);
        $row = $this->bookable($tenant)->where('c.id', $class)->first();
        abort_unless($row, 404, 'Esa clase ya no está disponible en línea.');

        $validated = $request->validate([
            'first_name'       => 'required|string|max:100',
            'last_name'        => 'required|string|max:100',
            'type_document_id' => 'nullable|uuid|exists:type_documents,id',
            'document_number'  => 'required|string|min:6|max:20|regex:/^[A-Za-z0-9-]+$/',
            'phone'            => 'required|string|min:6|max:30|regex:/^[0-9+\s()-]+$/',
            'email'            => 'required|email|max:190',
            'accept_privacy'   => 'accepted',
            'request_key'      => 'required|uuid',
            'website'          => 'nullable|string|max:0',
        ], [
            'accept_privacy.accepted' => 'Para inscribirte, acepta la política de privacidad.',
            'website.max'             => 'No se pudo completar la inscripción.',
            'document_number.regex'   => 'Escribe el documento solo con letras y números.',
            'phone.regex'             => 'Escribe el celular solo con números.',
        ]);

        $cacheKey = "public-class:{$tenant->id}:{$validated['request_key']}";

        if ($previous = Cache::get($cacheKey)) {
            return response()->json($previous, 201);
        }

        $existing = PublicBooking::findCustomer($tenant, $validated);

        if ($existing) {
            $strikes = OnlineBooking::state($tenant, $existing->id);

            if ($strikes && $strikes['blocked']) {
                return response()->json(['message' => OnlineBooking::blockedMessage($tenant, $strikes), 'code' => 'BOOKING_BLOCKED'], 403);
            }
        }

        $body = DB::transaction(function () use ($request, $tenant, $validated, $existing, $row) {
            $customer = $existing ?? PublicBooking::createCustomer($tenant, $validated);

            if (!$existing || !PortalController::privacyAccepted($customer->id)) {
                PortalController::acceptPrivacy($tenant->id, $customer->id, 'public_booking');
            }

            $result = ClassSessions::enroll($tenant, $row->id, $customer, 'online', null);
            $request->setUserResolver(fn () => $customer);

            Audit::log($request, $result['enrollment']->status === 'waitlist' ? 'class.waitlisted' : 'class.enrolled', [
                'tenant'       => $tenant->id,
                'entity_type'  => 'class_session',
                'entity_id'    => $row->id,
                'entity_label' => "{$row->title} · " . ClassSessions::local($tenant, $row, 'starts_at')->format('d/m/Y H:i'),
                'branch_id'    => $row->branch_id,
                'after'        => ['customer' => Person::name($customer), 'origen' => 'Página pública de reservas'],
            ]);

            return [
                'status'  => $result['enrollment']->status,
                'message' => $result['enrollment']->status === 'waitlist'
                    ? 'La clase está llena: quedaste en la lista de espera. Te avisamos si se libera un cupo.'
                    : 'Listo, quedaste inscrito en la clase.',
                'class'   => ClassSessions::presentMany($tenant, collect([$row]))[0]['title'],
            ];
        });

        Cache::put($cacheKey, $body, now()->addMinutes(30));

        return response()->json($body, 201);
    }
}
