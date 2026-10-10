'use client';

import { useEffect, useState } from 'react';
import { CalendarCheck, CalendarClock, Check, ChevronRight, ClipboardList, Timer } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { useVocabulary } from '@/hooks/useVocabulary';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Stopwatch from './Stopwatch';
import { actionLabel, apiError, dateOf, formatDate, formatDateTime, formatMinutes, statusStyle, timeOf, type Appointment } from '../appointments';

interface AppointmentDetailProps {
  appointmentId: string | null;
  onClose: () => void;
  // La cita cambió (fase o notas): quien abre el panel refresca su lista.
  onChanged: (appointment: Appointment) => void;
  onReschedule: (appointment: Appointment) => void;
  // Abre la ficha de atención de la cita.
  onOpenRecord: (appointment: Appointment) => void;
  // Cambia cuando la cita se modificó desde fuera (reprogramación) y hay que volver a leerla.
  reloadKey?: number;
}

// El camino normal de una cita. Cancelada y no asistió quedan fuera: son salidas.
const FLOW = ['pending', 'confirmed', 'checked_in', 'in_progress', 'completed'];

const inputClass =
  'w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800 bg-white';
const sectionTitle = 'text-[11px] font-bold text-slate-400 uppercase tracking-wider';

// Panel lateral de una cita: sus datos, la fase en que está, las acciones que
// la mueven de fase, sus notas y su historial.
export default function AppointmentDetail({ appointmentId, onClose, onChanged, onReschedule, onOpenRecord, reloadKey = 0 }: AppointmentDetailProps) {
  const { user } = useAuth();
  const vocab = useVocabulary();
  const [appointment, setAppointment] = useState<Appointment | null>(null);
  const [loading, setLoading] = useState(false);
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [notes, setNotes] = useState('');
  const [cancelling, setCancelling] = useState(false);
  const [cancelReason, setCancelReason] = useState('');

  const statusNames = Object.fromEntries((user?.tenant?.appointment_statuses ?? []).map((s) => [s.key, s.name]));

  // Los cambios de fase no traen el promedio del cliente: se conserva el que ya se tenía.
  const apply = (data: Appointment) => {
    setAppointment((current) => ({ ...data, customer_timing: data.customer_timing ?? current?.customer_timing }));
    setNotes(data.notes ?? '');
  };

  useEffect(() => {
    if (!appointmentId) return;

    setAppointment(null);
    setError('');
    setNotice('');
    setCancelling(false);
    setCancelReason('');
    setLoading(true);

    api.get(`/appointments/${appointmentId}`)
      .then((res) => apply(res.data))
      .catch((err) => setError(apiError(err, 'No se pudo cargar la cita.')))
      .finally(() => setLoading(false));
  }, [appointmentId, reloadKey]);

  const changeStatus = async (status: string, reason?: string) => {
    if (!appointment) return;

    try {
      setBusy(status);
      setError('');
      setNotice('');
      const res = await api.post(`/appointments/${appointment.id}/status`, { status, reason: reason || null });
      apply(res.data);
      setCancelling(false);
      setCancelReason('');
      // Avisos tras cancelar: fuera de plazo, y si el horario libre le sirve a alguien que espera.
      const waiting = Number(res.data.waitlist_matches ?? 0);
      const notices = [
        res.data.late_cancel ? 'Se canceló fuera del plazo sin costo de tu empresa.' : '',
        waiting > 0
          ? `Este horario le sirve a ${waiting === 1 ? '1 persona' : `${waiting} personas`} de la lista de espera. Revísala en Lista de espera.`
          : '',
      ].filter(Boolean);
      if (notices.length) {
        setNotice(notices.join(' '));
      }
      onChanged(res.data);
    } catch (err) {
      setError(apiError(err, 'No se pudo cambiar la fase de la cita.'));
    } finally {
      setBusy('');
    }
  };

  const saveNotes = async () => {
    if (!appointment) return;

    try {
      setBusy('notes');
      setError('');
      const res = await api.put(`/appointments/${appointment.id}`, { notes: notes.trim() || null });
      apply(res.data);
      setNotice('Notas guardadas.');
      onChanged(res.data);
    } catch (err) {
      setError(apiError(err, 'No se pudieron guardar las notas.'));
    } finally {
      setBusy('');
    }
  };

  const flowIndex = appointment ? FLOW.indexOf(appointment.status) : -1;
  const forward = appointment?.next_statuses.filter((s) => FLOW.indexOf(s) > flowIndex && FLOW.includes(s)) ?? [];
  const backward = appointment?.next_statuses.filter((s) => FLOW.includes(s) && FLOW.indexOf(s) < flowIndex) ?? [];
  const exits = appointment?.next_statuses.filter((s) => !FLOW.includes(s)) ?? [];

  const timing = appointment?.timing;
  const plannedMinutes = timing?.planned_seconds ? timing.planned_seconds / 60 : null;
  const serviceMinutes = timing?.service_seconds != null ? timing.service_seconds / 60 : null;
  const customerTiming = appointment?.customer_timing;
  const showTiming = Boolean(timing && (timing.running || timing.wait_seconds != null || timing.service_seconds != null))
    || Boolean(customerTiming?.measured);

  const rows: [string, string | null | undefined][] = appointment ? [
    [vocab.client, appointment.customer ? `${appointment.customer.name} · ${appointment.customer.code}` : null],
    ['Celular', appointment.customer?.phone],
    [vocab.dependent, appointment.dependent ? `${appointment.dependent.name}${appointment.dependent.species ? ` (${appointment.dependent.species})` : ''}` : null],
    ['Servicio', appointment.service?.name],
    [vocab.pro, appointment.professional?.display_name],
    ['Sede', appointment.branch?.name],
    ['Fecha', formatDate(dateOf(appointment.starts_at))],
    ['Hora', `${timeOf(appointment.starts_at)} a ${timeOf(appointment.ends_at)} · ${appointment.duration_minutes} min`],
    ['Precio', `S/ ${appointment.price}`],
    ['Agendada fuera de horario', appointment.override_reason],
    ['Motivo de cancelación', appointment.cancel_reason],
    ['Reprogramada', appointment.reschedule_count > 0 ? `${appointment.reschedule_count} ${appointment.reschedule_count === 1 ? 'vez' : 'veces'}` : null],
  ] : [];

  return (
    <FormDrawer
      isOpen={appointmentId !== null}
      onClose={onClose}
      title={appointment ? `Cita ${appointment.code}` : 'Cita'}
      subtitle={appointment ? `${appointment.customer?.name ?? ''} · ${formatDateTime(appointment.starts_at)}` : undefined}
      icon={<CalendarCheck size={20} />}
    >
      {loading || !appointment ? (
        <p className="text-sm text-slate-500">{error || 'Cargando cita...'}</p>
      ) : (
        <div className="space-y-6 pb-4">
          <section className="space-y-3">
            <div className="flex items-center justify-between gap-3">
              <span className={sectionTitle}>Fase</span>
              <span data-status={appointment.status} className={`px-2.5 py-1 text-xs rounded-full font-semibold ${statusStyle(appointment.status).badge}`}>
                {appointment.status_name}
              </span>
            </div>

            {flowIndex >= 0 && (
              <ol className="flex items-start">
                {FLOW.map((key, index) => {
                  const current = index === flowIndex;
                  // La última fase, al alcanzarla, ya está cumplida.
                  const done = index < flowIndex || (current && index === FLOW.length - 1);
                  return (
                    <li key={key} className="flex-1 flex flex-col items-center text-center relative">
                      {index > 0 && (
                        <span className={`absolute top-3 right-1/2 w-full h-0.5 ${index <= flowIndex ? 'bg-[var(--accent)]' : 'bg-slate-200'}`} />
                      )}
                      <span
                        className={`relative z-10 w-6 h-6 rounded-full flex items-center justify-center text-[11px] font-bold ${
                          done || current ? 'bg-[var(--accent)] text-[#fff]' : 'bg-slate-200 text-slate-500'
                        } ${current ? 'ring-4 ring-slate-100' : ''}`}
                      >
                        {done ? <Check size={13} /> : index + 1}
                      </span>
                      <span className={`mt-1.5 text-[11px] leading-tight ${current ? 'font-bold text-slate-800' : 'text-slate-500'}`}>
                        {statusNames[key] ?? key}
                      </span>
                    </li>
                  );
                })}
              </ol>
            )}

            {(forward.length > 0 || backward.length > 0 || exits.length > 0 || appointment.can_reschedule) && (
              <div className="flex flex-wrap gap-2 pt-1">
                {forward.map((status, index) => (
                  <button
                    key={status}
                    type="button"
                    data-action={status}
                    disabled={busy !== ''}
                    onClick={() => changeStatus(status)}
                    className={index === 0
                      ? 'px-4 py-2 text-sm font-medium bg-[#022340] text-white rounded-lg hover:bg-[#03315a] shadow-sm transition-colors disabled:opacity-50'
                      : 'px-4 py-2 text-sm font-medium border border-slate-200 rounded-lg text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-50'}
                  >
                    {busy === status ? 'Guardando...' : actionLabel(status, appointment.status)}
                  </button>
                ))}

                {appointment.can_reschedule && (
                  <button
                    type="button"
                    data-action="reschedule"
                    disabled={busy !== ''}
                    onClick={() => onReschedule(appointment)}
                    className="flex items-center gap-1.5 px-4 py-2 text-sm font-medium border border-slate-200 rounded-lg text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-50"
                  >
                    <CalendarClock size={15} /> Reprogramar
                  </button>
                )}

                {backward.map((status) => (
                  <button
                    key={status}
                    type="button"
                    data-action={status}
                    disabled={busy !== ''}
                    onClick={() => changeStatus(status)}
                    className="px-4 py-2 text-sm font-medium border border-slate-200 rounded-lg text-slate-500 hover:bg-slate-50 transition-colors disabled:opacity-50"
                  >
                    {busy === status ? 'Guardando...' : actionLabel(status, appointment.status)}
                  </button>
                ))}

                {exits.map((status) => (
                  <button
                    key={status}
                    type="button"
                    data-action={status}
                    disabled={busy !== ''}
                    onClick={() => (status === 'cancelled' ? setCancelling(true) : changeStatus(status))}
                    className="px-4 py-2 text-sm font-medium border border-rose-200 rounded-lg text-rose-700 hover:bg-rose-50 transition-colors disabled:opacity-50"
                  >
                    {busy === status ? 'Guardando...' : actionLabel(status, appointment.status)}
                  </button>
                ))}
              </div>
            )}

            {cancelling && (
              <div className="p-3 bg-rose-50 border border-rose-200 rounded-lg space-y-2">
                <label className="block text-xs font-semibold uppercase tracking-wider text-rose-700">Motivo de la cancelación</label>
                <input
                  type="text"
                  maxLength={255}
                  aria-label="Motivo de la cancelación"
                  placeholder="Ej. El cliente no podrá asistir"
                  className={inputClass}
                  value={cancelReason}
                  onChange={(e) => setCancelReason(e.target.value)}
                />
                <div className="flex justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => { setCancelling(false); setCancelReason(''); }}
                    className="px-3 py-2 text-sm font-medium border border-slate-200 bg-white rounded-lg text-slate-600 hover:bg-slate-50 transition-colors"
                  >
                    No cancelar
                  </button>
                  <button
                    type="button"
                    data-action="confirm-cancel"
                    disabled={busy !== ''}
                    onClick={() => changeStatus('cancelled', cancelReason.trim())}
                    className="px-3 py-2 text-sm font-medium bg-rose-600 text-white rounded-lg hover:bg-rose-700 transition-colors disabled:opacity-50"
                  >
                    {busy === 'cancelled' ? 'Cancelando...' : 'Cancelar la cita'}
                  </button>
                </div>
              </div>
            )}

            {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}
            {notice && <p className="text-sm text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-lg px-3 py-2">{notice}</p>}
          </section>

          {appointment.record.uses && (
            <section className="pt-5 border-t border-slate-100">
              <button
                type="button"
                data-action="open-record"
                onClick={() => onOpenRecord(appointment)}
                className="w-full flex items-center gap-3 p-3 border border-slate-200 rounded-xl text-left hover:border-[#3F768C] hover:bg-slate-50 transition-colors"
              >
                <span className="p-2 bg-blue-50 text-[#022340] rounded-lg shrink-0"><ClipboardList size={18} /></span>
                <span className="flex-1 min-w-0">
                  <span className="block text-sm font-bold text-slate-800">Ficha de atención</span>
                  <span className="block text-xs text-slate-500">
                    {appointment.record.status === null && 'Sin llenar. Ábrela para registrar la atención.'}
                    {appointment.record.status === 'draft' && 'Hay un borrador guardado. Falta firmarla.'}
                    {appointment.record.status === 'signed' && 'Firmada. Aún se puede corregir.'}
                    {appointment.record.status === 'locked' && 'Firmada y bloqueada. Admite notas.'}
                  </span>
                </span>
                <span
                  data-record-status={appointment.record.status ?? 'none'}
                  className={`px-2.5 py-1 text-xs rounded-full font-semibold shrink-0 ${
                    appointment.record.status === 'signed' || appointment.record.status === 'locked'
                      ? 'bg-emerald-50 text-emerald-700'
                      : appointment.record.status === 'draft' ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600'
                  }`}
                >
                  {appointment.record.status === null ? 'Pendiente' : appointment.record.status === 'draft' ? 'Borrador' : 'Firmada'}
                </span>
                <ChevronRight size={16} className="text-slate-400 shrink-0" />
              </button>
            </section>
          )}

          {showTiming && timing && (
            <section className="pt-5 border-t border-slate-100 space-y-3" data-timing>
              <span className={`flex items-center gap-1.5 ${sectionTitle}`}><Timer size={13} /> Tiempos</span>

              {timing.running === 'service' && (
                <div className="p-4 bg-teal-50 border border-teal-100 rounded-xl">
                  <span className="block text-[11px] font-bold text-teal-600 uppercase tracking-wider">En atención hace</span>
                  <Stopwatch
                    seconds={timing.service_seconds ?? 0}
                    running
                    limitSeconds={timing.planned_seconds}
                    className="block text-3xl font-black text-slate-900"
                  />
                  <span className="block text-xs text-slate-500 mt-0.5">Tiempo asignado: {formatMinutes(plannedMinutes)}</span>
                </div>
              )}

              {timing.running === 'wait' && (
                <div className="p-4 bg-violet-50 border border-violet-100 rounded-xl">
                  <span className="block text-[11px] font-bold text-violet-600 uppercase tracking-wider">Esperando hace</span>
                  <Stopwatch seconds={timing.wait_seconds ?? 0} running className="block text-3xl font-black text-slate-900" />
                </div>
              )}

              <dl className="grid grid-cols-2 gap-x-4 gap-y-3">
                {timing.running !== 'wait' && timing.wait_seconds != null && (
                  <div>
                    <dt className={sectionTitle}>Esperó</dt>
                    <dd className="text-sm font-semibold text-slate-800">{formatMinutes(timing.wait_seconds / 60)}</dd>
                  </div>
                )}

                {!timing.running && serviceMinutes != null && (
                  <div data-service-time>
                    <dt className={sectionTitle}>La atención duró</dt>
                    {timing.measured ? (
                      <dd className="text-sm font-semibold text-slate-800">
                        {formatMinutes(serviceMinutes)}
                        {plannedMinutes != null && Math.round(serviceMinutes) !== Math.round(plannedMinutes) && (
                          <span className={`block text-xs font-medium ${serviceMinutes > plannedMinutes ? 'text-rose-700' : 'text-emerald-700'}`}>
                            {formatMinutes(serviceMinutes - plannedMinutes)} {serviceMinutes > plannedMinutes ? 'más' : 'menos'} de lo asignado ({formatMinutes(plannedMinutes)})
                          </span>
                        )}
                      </dd>
                    ) : (
                      <dd className="text-sm text-slate-500">Sin medir: no se registró el inicio y el fin de la atención.</dd>
                    )}
                  </div>
                )}

                {customerTiming && customerTiming.measured > 0 && (
                  <div data-customer-time>
                    <dt className={sectionTitle}>Promedio de este {vocab.client.toLowerCase()}</dt>
                    <dd className="text-sm font-semibold text-slate-800">
                      {formatMinutes(customerTiming.avg_minutes)}
                      <span className="block text-xs font-medium text-slate-500">
                        en {customerTiming.measured} {customerTiming.measured === 1 ? 'atención' : 'atenciones'}
                      </span>
                    </dd>
                  </div>
                )}
              </dl>
            </section>
          )}

          <section className="pt-5 border-t border-slate-100">
            <dl className="grid grid-cols-2 gap-x-4 gap-y-3">
              {rows.filter(([, value]) => value).map(([label, value]) => (
                <div key={label}>
                  <dt className={sectionTitle}>{label}</dt>
                  <dd className="text-sm font-semibold text-slate-800">{value}</dd>
                </div>
              ))}
            </dl>
          </section>

          <section className="pt-5 border-t border-slate-100 space-y-2">
            <label className={sectionTitle}>Notas</label>
            <textarea
              rows={3}
              maxLength={2000}
              aria-label="Notas de la cita"
              placeholder="Indicaciones para la atención"
              className={inputClass}
              value={notes}
              onChange={(e) => { setNotes(e.target.value); setNotice(''); }}
            />
            {notes.trim() !== (appointment.notes ?? '').trim() && (
              <div className="flex justify-end">
                <button
                  type="button"
                  disabled={busy !== ''}
                  onClick={saveNotes}
                  className="px-4 py-2 text-sm font-medium border border-slate-200 rounded-lg text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-50"
                >
                  {busy === 'notes' ? 'Guardando...' : 'Guardar notas'}
                </button>
              </div>
            )}
          </section>

          {(appointment.history?.length ?? 0) > 0 && (
            <section className="pt-5 border-t border-slate-100 space-y-3">
              <span className={sectionTitle}>Historial</span>
              <ol className="space-y-3">
                {appointment.history!.map((step, index) => (
                  <li key={index} className="flex gap-3">
                    <span className={`mt-1.5 w-2 h-2 rounded-full shrink-0 ${statusStyle(step.to).dot}`} />
                    <div>
                      <span className="block text-sm font-semibold text-slate-800">
                        {step.from === step.to ? 'Reprogramada' : step.from === null ? `Creada como ${step.to_name.toLowerCase()}` : step.to_name}
                      </span>
                      <span className="block text-xs text-slate-500">
                        {formatDateTime(step.at)}{step.by ? ` · ${step.by}` : ''}
                      </span>
                      {step.reason && <span className="block text-xs text-slate-600 mt-0.5">{step.reason}</span>}
                    </div>
                  </li>
                ))}
              </ol>
            </section>
          )}
        </div>
      )}
    </FormDrawer>
  );
}
