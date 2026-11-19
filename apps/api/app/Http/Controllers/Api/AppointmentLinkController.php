<?php

namespace App\Http\Controllers\Api;

use App\Models\Appointment;
use App\Models\Tenant;
use App\Support\AppointmentFlow;
use App\Support\AppointmentLinks;
use App\Support\Branding;
use App\Support\PortalLook;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

class AppointmentLinkController extends PortalController
{
    private ?Appointment $linked = null;

    private function resolve(Request $request): Appointment
    {
        if ($this->linked) {
            return $this->linked;
        }

        $id = AppointmentLinks::idFrom((string) $request->route('token'));
        $appointment = $id ? Appointment::with(self::RELATIONS)->find($id) : null;
        abort_unless($appointment && $appointment->customer, 404, 'Este enlace no es válido. Revisa que lo hayas copiado completo o entra al portal.');
        abort_if($appointment->tenant?->status !== 'active', 404, 'Este enlace ya no está disponible.');

        if ($reason = AppointmentLinks::expiredReason($appointment)) {
            throw new HttpResponseException(response()->json([
                'message' => $reason === 'cancelled'
                    ? 'Esta cita fue cancelada, así que este enlace ya no sirve. Si quieres otra cita, puedes reservar de nuevo.'
                    : 'Este enlace venció porque la cita ya pasó. Para ver tus citas anteriores, entra al portal.',
                'code'    => 'LINK_EXPIRED',
                'reason'  => $reason,
                'company' => ['name' => $appointment->tenant->name, 'slug' => $appointment->tenant->slug],
            ], 410));
        }

        $customer = $appointment->customer;
        $request->setUserResolver(fn () => $customer);

        return $this->linked = $appointment;
    }

    protected function tenant(Request $request): Tenant
    {
        return $this->resolve($request)->tenant->loadMissing(['settings', 'activeSubscription.plan']);
    }

    protected function ownAppointment(Request $request, string $id): Appointment
    {
        return $this->resolve($request)->fresh(self::RELATIONS);
    }

    public function show(Request $request, string $token)
    {
        $tenant = $this->tenant($request);
        $appointment = $this->resolve($request);
        $brand = Branding::present($tenant);

        return response()->json([
            'company'     => ['name' => $tenant->name, 'logo' => $brand['logo_url'], 'primary' => $brand['primary'], 'accent' => $brand['accent'], 'slug' => $tenant->slug],
            'contact'     => PortalLook::forPortal($tenant)['contact'],
            'customer'    => $appointment->customer->first_name,
            'cancel_window_hours' => (int) AppointmentFlow::rule($tenant, 'cancel.window_hours'),
            'cancel_allowed' => (bool) AppointmentFlow::rule($tenant, 'cancel.client_allowed'),
            'appointment' => $this->presentForCustomer($appointment, $tenant),
            'link_expires_at' => AppointmentLinks::expiresAt($appointment)?->toIso8601String(),
        ]);
    }

    public function linkConfirm(Request $request, string $token)
    {
        $appointment = $this->resolve($request);
        abort_unless($appointment->starts_at->isFuture(), 422, 'Esta cita ya pasó.');

        return $this->confirm($request, $appointment->id);
    }

    public function linkCancel(Request $request, string $token)
    {
        $appointment = $this->resolve($request);

        return $this->cancel($request, $appointment->id);
    }
}
