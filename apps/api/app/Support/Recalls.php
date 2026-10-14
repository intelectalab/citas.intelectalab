<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Recall;
use App\Models\Tenant;

class Recalls
{
    public static function book(Recall $recall, Appointment $appointment): void
    {
        $recall->update(['status' => Recall::BOOKED, 'appointment_id' => $appointment->id]);
    }

    public static function appointmentChanged(Tenant $tenant, Appointment $appointment): void
    {
        $linked = Recall::where('tenant_id', $tenant->id)->where('appointment_id', $appointment->id)->where('status', Recall::BOOKED);

        if ($appointment->status === AppointmentFlow::COMPLETED) {
            $linked->update(['status' => Recall::DONE, 'updated_at' => now()]);
            self::fromService($tenant, $appointment);
        } elseif (in_array($appointment->status, AppointmentFlow::RELEASED, true)) {
            $linked->update(['status' => Recall::PENDING, 'appointment_id' => null, 'updated_at' => now()]);
        }
    }

    private static function fromService(Tenant $tenant, Appointment $appointment): void
    {
        $service = $appointment->service;

        if (!$service?->recall_label || !$service->recall_days) {
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
            'service_id'            => $service->recall_service_id ?? $service->id,
            'professional_id'       => $appointment->professional_id,
            'label'                 => $service->recall_label,
            'due_date'              => now($timezone)->addDays((int) $service->recall_days)->format('Y-m-d'),
            'status'                => Recall::PENDING,
        ]);
    }
}
