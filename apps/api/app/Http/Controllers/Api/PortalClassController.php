<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Tenant;
use App\Support\AppointmentFlow;
use App\Support\Audit;
use App\Support\ClassSessions;
use App\Support\OnlineBooking;
use App\Support\Person;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PortalClassController extends Controller
{
    public const DAYS_AHEAD = 21;

    private function context(Request $request): array
    {
        abort_unless(ClassSessions::ready(), 503, ClassSessions::NOT_READY);
        $customer = $request->user();
        abort_unless($customer instanceof Customer, 403);
        $tenant = Tenant::findOrFail($customer->tenant_id);

        return [$tenant, $customer];
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
            ->select('c.*');
    }

    public function index(Request $request)
    {
        [$tenant, $customer] = $this->context($request);

        $upcoming = $this->bookable($tenant)
            ->where('c.starts_at', '>', now()->toIso8601String())
            ->where('c.starts_at', '<', now()->addDays(self::DAYS_AHEAD)->toIso8601String())
            ->orderBy('c.starts_at')
            ->limit(300)
            ->get();

        $mine = DB::table('class_enrollments')
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->where('status', '!=', 'cancelled')
            ->get()
            ->keyBy('class_session_id');

        $mineSessions = DB::table('class_sessions')
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $mine->keys())
            ->where('starts_at', '>', now()->subDays(60)->toIso8601String())
            ->orderByDesc('starts_at')
            ->get();

        $leaveHours = (int) AppointmentFlow::rule($tenant, 'classes.portal_leave_hours');
        $decorate = function (array $class) use ($mine, $leaveHours, $tenant) {
            $own = $mine[$class['id']] ?? null;
            $start = Carbon::createFromFormat('Y-m-d\TH:i', $class['starts_at'], $tenant->zone());

            return $class + [
                'my_status'       => $own?->status,
                'my_status_label' => $own ? (ClassSessions::STATUS_LABELS[$own->status] ?? $own->status) : null,
                'can_leave'       => $own && in_array($own->status, ['enrolled', 'waitlist'], true) && $class['status'] === 'scheduled'
                    && ($own->status === 'waitlist' || now()->lt($start->copy()->subHours($leaveHours))),
            ];
        };

        $strikes = OnlineBooking::state($tenant, $customer->id);

        return response()->json([
            'classes'     => array_map($decorate, ClassSessions::presentMany($tenant, $upcoming)),
            'mine'        => array_map($decorate, ClassSessions::presentMany($tenant, $mineSessions)),
            'leave_hours' => $leaveHours,
            'waitlist'    => (bool) AppointmentFlow::rule($tenant, 'classes.waitlist'),
            'blocked'     => $strikes && $strikes['blocked'] ? OnlineBooking::blockedMessage($tenant, $strikes) : null,
        ]);
    }

    public function enroll(Request $request, string $class)
    {
        [$tenant, $customer] = $this->context($request);
        $row = $this->bookable($tenant)->where('c.id', $class)->first();
        abort_unless($row, 404, 'Esa clase ya no está disponible en línea.');

        $strikes = OnlineBooking::state($tenant, $customer->id);
        if ($strikes && $strikes['blocked']) {
            return response()->json(['message' => OnlineBooking::blockedMessage($tenant, $strikes), 'code' => 'BOOKING_BLOCKED'], 403);
        }

        $result = ClassSessions::enroll($tenant, $row->id, $customer, 'portal', null);
        $this->audit($request, $tenant, $result['enrollment']->status === 'waitlist' ? 'class.waitlisted' : 'class.enrolled', $row, $customer);

        return response()->json([
            'status'  => $result['enrollment']->status,
            'message' => $result['enrollment']->status === 'waitlist'
                ? 'La clase está llena: quedaste en la lista de espera. Te avisamos si se libera un cupo.'
                : 'Listo, quedaste inscrito en la clase.',
        ], 201);
    }

    public function leave(Request $request, string $class)
    {
        [$tenant, $customer] = $this->context($request);
        $row = DB::table('class_sessions')->where('tenant_id', $tenant->id)->where('id', $class)->first();
        abort_unless($row, 404, 'No encontramos esa clase.');

        $own = DB::table('class_enrollments')->where('class_session_id', $row->id)->where('customer_id', $customer->id)->whereIn('status', ['enrolled', 'waitlist'])->first();
        abort_unless($own, 404, 'No estás inscrito en esta clase.');

        $hours = (int) AppointmentFlow::rule($tenant, 'classes.portal_leave_hours');
        if ($own->status === 'enrolled' && now()->gt(Carbon::parse($row->starts_at)->subHours($hours))) {
            return response()->json(['message' => "Solo puedes salir de la clase hasta {$hours} " . ($hours === 1 ? 'hora' : 'horas') . ' antes. Escríbenos si no puedes ir.'], 422);
        }

        ClassSessions::leave($tenant, $own->id, null);
        $this->audit($request, $tenant, 'class.left', $row, $customer);

        return response()->json(['message' => $own->status === 'waitlist' ? 'Saliste de la lista de espera.' : 'Saliste de la clase.']);
    }

    private function audit(Request $request, Tenant $tenant, string $action, object $session, Customer $customer): void
    {
        Audit::log($request, $action, [
            'tenant'       => $tenant->id,
            'entity_type'  => 'class_session',
            'entity_id'    => $session->id,
            'entity_label' => "{$session->title} · " . ClassSessions::local($tenant, $session, 'starts_at')->format('d/m/Y H:i'),
            'branch_id'    => $session->branch_id,
            'after'        => ['customer' => Person::name($customer)],
        ]);
    }
}
