'use client';

import { useState } from 'react';
import AppointmentForm, { type CustomerOption } from './AppointmentForm';
import AppointmentDetail from './AppointmentDetail';
import RecordPanel from '@/features/records/components/RecordPanel';
import type { Appointment } from '../appointments';

type FormInitial = {
  date?: string; time?: string; professional_id?: string; branch_id?: string;
  customer?: CustomerOption; customer_dependent_id?: string; service_id?: string; recall_id?: string; recall_label?: string;
  waitlist_id?: string; origin_label?: string;
};

export function useAppointmentPanels(onChange: () => void) {
  const [formOpen, setFormOpen] = useState(false);
  const [formInitial, setFormInitial] = useState<FormInitial | undefined>(undefined);
  const [rescheduling, setRescheduling] = useState<Appointment | null>(null);
  const [detailId, setDetailId] = useState<string | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
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
          if (rescheduling) {
            setDetailId(appointment.id);
            setReloadKey((key) => key + 1);
          }
          onChange();
        }}
      />
    </>
  );

  const isOpen = formOpen || detailId !== null || recordFor !== null;

  return { openNew, openDetail, panels, isOpen };
}
