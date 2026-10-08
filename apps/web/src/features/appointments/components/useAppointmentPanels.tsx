'use client';

import { useState } from 'react';
import AppointmentForm from './AppointmentForm';
import AppointmentDetail from './AppointmentDetail';
import RecordPanel from '@/features/records/components/RecordPanel';
import type { Appointment } from '../appointments';

type FormInitial = { date?: string; time?: string; professional_id?: string; branch_id?: string };

// Los paneles laterales de una cita (nueva, detalle y reprogramar) y cómo
// abrirlos. La agenda y la lista de citas los usan igual.
export function useAppointmentPanels(onChange: () => void) {
  const [formOpen, setFormOpen] = useState(false);
  const [formInitial, setFormInitial] = useState<FormInitial | undefined>(undefined);
  const [rescheduling, setRescheduling] = useState<Appointment | null>(null);
  const [detailId, setDetailId] = useState<string | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
  // Cita cuya ficha de atención está abierta, encima del detalle.
  const [recordFor, setRecordFor] = useState<string | null>(null);

  const openNew = (initial?: FormInitial) => {
    setRescheduling(null);
    setFormInitial(initial);
    setFormOpen(true);
  };

  const openDetail = (id: string) => setDetailId(id);

  const panels = (
    <>
      <AppointmentDetail
        appointmentId={formOpen || recordFor ? null : detailId}
        reloadKey={reloadKey}
        onClose={() => setDetailId(null)}
        onChanged={onChange}
        onReschedule={(appointment) => {
          setRescheduling(appointment);
          setFormInitial(undefined);
          setFormOpen(true);
        }}
        onOpenRecord={(appointment) => setRecordFor(appointment.id)}
      />

      <RecordPanel
        appointmentId={recordFor}
        onClose={() => {
          // Al cerrar la ficha se vuelve a la cita, ya con el estado de la ficha al día.
          setRecordFor(null);
          setReloadKey((key) => key + 1);
        }}
        onChanged={onChange}
      />

      <AppointmentForm
        isOpen={formOpen}
        initial={formInitial}
        reschedule={rescheduling}
        onClose={() => { setFormOpen(false); setRescheduling(null); }}
        onSaved={(appointment) => {
          // Al reprogramar se vuelve al detalle, ya con la nueva fecha.
          if (rescheduling) {
            setDetailId(appointment.id);
            setReloadKey((key) => key + 1);
          }
          onChange();
        }}
      />
    </>
  );

  // Hay un panel abierto: quien refresca en segundo plano espera a que se cierre.
  const isOpen = formOpen || detailId !== null || recordFor !== null;

  return { openNew, openDetail, panels, isOpen };
}
