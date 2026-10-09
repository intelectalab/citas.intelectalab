<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Recall;
use App\Models\Tenant;

/**
 * Cómo se mueven los controles con las citas: una cita agendada desde un
 * control lo deja "agendado"; al atenderla queda "realizado"; si se cancela o
 * el cliente no asiste, el control vuelve a la lista de pendientes. Y una
 * atención cuyo servicio pide un control lo crea sola.
 */
class Recalls
{
    /**
     * La cita se agendó para cumplir este control.
     */
    public static function book(Recall $recall, Appointment $appointment): void
    {
        $recall->update(['status' => Recall::BOOKED, 'appointment_id' => $appointment->id]);
    }

    /**
     * Llamar después de cambiar la fase de una cita.
     */
    public static function appointmentChanged(Tenant $tenant, Appointment $appointment): void
    {
        $linked = Recall::where('tenant_id', $tenant->id)->where('appointment_id', $appointment->id)->where('status', Recall::BOOKED);

        if ($appointment->status === AppointmentFlow::COMPLETED) {
            $linked->update(['status' => Recall::DONE, 'updated_at' => now()]);
            self::fromService($tenant, $appointment);
        } elseif (in_array($appointment->status, AppointmentFlow::RELEASED, true)) {
            // La cita se cayó: hay que volver a buscar al cliente.
            $linked->update(['status' => Recall::PENDING, 'appointment_id' => null, 'updated_at' => now()]);
        }
    }

    /**
     * El servicio atendido pide un control (por ejemplo, "Profilaxis en 180
     * días"): se crea, salvo que la ficha de esa atención ya haya dejado el suyo.
     */
    private static function fromService(Tenant $tenant, Appointment $appointment): void
    {
        $service = $appointment->service;

        if (!$service?->recall_label || !$service->recall_days || !$tenant->hasModule('records')) {
            return;
        }

        $recordId = $appointment->record?->id;

        $exists = Recall::where('tenant_id', $tenant->id)
            ->where(fn ($q) => $q->where('source_appointment_id', $appointment->id)
                ->when($recordId, fn ($q) => $q->orWhere('care_record_id', $recordId)))
            ->exists();

        if ($exists) {
            return;
        }

        $timezone = AppointmentScheduler::timezone($appointment->branch ?? new \App\Models\Branch(), $tenant);

        Recall::create([
            'tenant_id'             => $tenant->id,
            'customer_id'           => $appointment->customer_id,
            'customer_dependent_id' => $appointment->customer_dependent_id,
            'source_appointment_id' => $appointment->id,
            // El control se agenda con el servicio que indique el servicio atendido, o con el mismo.
            'service_id'            => $service->recall_service_id ?? $service->id,
            'professional_id'       => $appointment->professional_id,
            'label'                 => $service->recall_label,
            'due_date'              => now($timezone)->addDays((int) $service->recall_days)->format('Y-m-d'),
            'status'                => Recall::PENDING,
        ]);
    }
}
