'use client';

import Badge from '@/app/components/common/Badge';
import { ErrorAlert } from '@/app/components/common/Alert';
import { inputClass } from '@/app/components/ui/forms/styles';
import { useEffect, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import { BellRing, Boxes, CalendarCheck, CalendarClock, CalendarX, Check, ChevronRight, ClipboardList, MoreHorizontal, Timer, Undo2, UserRound, UserX, Wallet } from 'lucide-react';
import Floating from '@/app/components/common/Floating';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { useVocabulary } from '@/hooks/useVocabulary';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Stopwatch from './Stopwatch';
import { actionLabel, apiError, dateOf, formatDate, formatDateTime, formatMinutes, statusStyle, timeOf, type Appointment } from '../appointments';
import { CustomerLink, ProfessionalLink } from '@/app/components/common/PersonLink';
import ChargePanel from '@/features/sales/components/ChargePanel';
import { money, SALE_BADGES, type ChargeContext } from '@/features/sales/sales';

interface AppointmentDetailProps {
  appointmentId: string | null;
  onClose: () => void;
  onChanged: (appointment: Appointment) => void;
  onReschedule: (appointment: Appointment) => void;
  onOpenRecord: (appointment: Appointment) => void;
  reloadKey?: number;
}

const FLOW = ['pending', 'confirmed', 'checked_in', 'in_progress', 'completed'];

const sectionTitle = 'text-[11px] font-bold text-slate-400 uppercase tracking-wider';

export default function AppointmentDetail({ appointmentId, onClose, onChanged, onReschedule, onOpenRecord, reloadKey = 0 }: AppointmentDetailProps) {
  const { user, hasModule, can } = useAuth();
  const vocab = useVocabulary();
  const [appointment, setAppointment] = useState<Appointment | null>(null);
  const canCharge = hasModule('pos') && !user?.is_saas_admin && (can('sales.create') || can('sales.view'));
  const [charge, setCharge] = useState<ChargeContext | null>(null);
  const [chargeNote, setChargeNote] = useState('');
  const [charging, setCharging] = useState(false);
  const [loading, setLoading] = useState(false);
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [notes, setNotes] = useState('');
  const [videoUrl, setVideoUrl] = useState('');
  const [cancelling, setCancelling] = useState(false);
  const [cancelReason, setCancelReason] = useState('');
  const [byCustomer, setByCustomer] = useState(false);
  const [moreOpen, setMoreOpen] = useState(false);
  const moreRef = useRef<HTMLButtonElement>(null);
  const router = useRouter();

  const statusNames = Object.fromEntries((user?.tenant?.appointment_statuses ?? []).map((s) => [s.key, s.name]));

  const apply = (data: Appointment) => {
    setAppointment((current) => ({ ...data, customer_timing: data.customer_timing ?? current?.customer_timing }));
    setNotes(data.notes ?? '');
    setVideoUrl(data.own_video_url ?? '');
  };

  useEffect(() => {
    if (!appointmentId) return;

    setAppointment(null);
    setError('');
    setNotice('');
    setCancelling(false);
    setCancelReason('');
    setMoreOpen(false);
    setLoading(true);

    api.get(`/appointments/${appointmentId}`)
      .then((res) => apply(res.data))
      .catch((err) => setError(apiError(err, 'No se pudo cargar la cita.')))
      .finally(() => setLoading(false));
  }, [appointmentId, reloadKey]);

  useEffect(() => {
    setCharge(null);
    setChargeNote('');
    if (!appointmentId || !canCharge) return;

    api.get(`/appointments/${appointmentId}/sale`)
      .then((res) => setCharge(res.data))
      .catch((err) => setChargeNote(apiError(err, 'No se pudo cargar el cobro de esta cita.')));
  }, [appointmentId, reloadKey, canCharge]);

  const changeStatus = async (status: string, reason?: string) => {
    if (!appointment) return;

    try {
      setBusy(status);
      setError('');
      setNotice('');
      const res = await api.post(`/appointments/${appointment.id}/status`, { status, reason: reason || null, ...(status === 'cancelled' && byCustomer ? { by_customer: true } : {}) });
      apply(res.data);
      setCancelling(false);
      setCancelReason('');
      setByCustomer(false);
      const waiting = Number(res.data.waitlist_matches ?? 0);
      const notices = [
        res.data.late_cancel && !res.data.penalty ? 'Se canceló fuera del plazo sin costo de tu empresa.' : '',
        res.data.penalty && (status === 'no_show' || status === 'cancelled')
          ? `Se generó una ${res.data.penalty.label.toLowerCase()} de ${money(res.data.penalty.total, res.data.penalty.currency)} por cobrar (venta ${res.data.penalty.code}).`
          : '',
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
      const res = await api.put(`/appointments/${appointment.id}`, { notes: notes.trim() || null, ...(videoUrl.trim() !== (appointment.own_video_url ?? '') ? { video_url: videoUrl.trim() || null } : {}) });
      apply(res.data);
      setNotice('Cambios guardados.');
      onChanged(res.data);
    } catch (err) {
      setError(apiError(err, 'No se pudieron guardar las notas.'));
    } finally {
      setBusy('');
    }
  };

  const sendReminder = async () => {
    if (!appointment) return;

    try {
      setBusy('remind');
      setError('');
      setNotice('');
      const res = await api.post(`/appointments/${appointment.id}/remind`);
      setNotice(res.data?.message ?? 'Recordatorio enviado.');
    } catch (err) {
      setError(apiError(err, 'No se pudo enviar el recordatorio.'));
    } finally {
      setBusy('');
    }
  };

  const flowIndex = appointment ? FLOW.indexOf(appointment.status) : -1;
  const forward = appointment?.next_statuses.filter((s) => FLOW.indexOf(s) > flowIndex && FLOW.includes(s)) ?? [];
  const backward = appointment?.next_statuses.filter((s) => FLOW.includes(s) && FLOW.indexOf(s) < flowIndex) ?? [];
  const exits = appointment?.next_statuses.filter((s) => !FLOW.includes(s)) ?? [];

  const moreItems: { key: string; label: string; icon: React.ReactNode; danger?: boolean; onClick: () => void }[] = appointment ? [
    ...(appointment.can_reschedule ? [{ key: 'reschedule', label: 'Reprogramar', icon: <CalendarClock size={15} />, onClick: () => onReschedule(appointment) }] : []),
    ...backward.map((status) => ({ key: status, label: actionLabel(status, appointment.status), icon: <Undo2 size={15} />, onClick: () => changeStatus(status) })),
    ...(appointment.customer && can('customers.view') ? [{ key: 'customer', label: `Ver ${vocab.pick('client', 'el', 'la')} ${vocab.client.toLowerCase()}`, icon: <UserRound size={15} />, onClick: () => router.push(`/customers/${appointment.customer!.id}`) }] : []),
    ...exits.map((status) => ({
      key: status,
      label: actionLabel(status, appointment.status),
      icon: status === 'cancelled' ? <CalendarX size={15} /> : <UserX size={15} />,
      danger: true,
      onClick: () => (status === 'cancelled' ? setCancelling(true) : changeStatus(status)),
    })),
  ] : [];

  const timing = appointment?.timing;
  const plannedMinutes = timing?.planned_seconds ? timing.planned_seconds / 60 : null;
  const serviceMinutes = timing?.service_seconds != null ? timing.service_seconds / 60 : null;
  const customerTiming = appointment?.customer_timing;
  const showTiming = Boolean(timing && (timing.running || timing.wait_seconds != null || timing.service_seconds != null))
    || Boolean(customerTiming?.measured);

  const rows: [string, React.ReactNode][] = appointment ? [
    [vocab.client, appointment.customer
      ? <><CustomerLink id={appointment.customer.id}>{appointment.customer.name}</CustomerLink> · {appointment.customer.code}</>
      : null],
    ['Celular', appointment.customer?.phone],
    [vocab.dependent, appointment.dependent ? `${appointment.dependent.name}${appointment.dependent.species ? ` (${appointment.dependent.species})` : ''}` : null],
    ['Servicio', appointment.service?.name],
    [vocab.pro, appointment.professional
      ? <ProfessionalLink id={appointment.professional.id}>{appointment.professional.display_name}</ProfessionalLink>
      : null],
    ['Sede', appointment.branch?.name],
    ['Fecha', formatDate(dateOf(appointment.starts_at))],
    ['Hora', `${timeOf(appointment.starts_at)} a ${timeOf(appointment.ends_at)} · ${appointment.duration_minutes} min`],
    ['Precio', money(appointment.price, appointment.currency)],
    ['Penalidad', appointment.penalty
      ? <span data-penalty className={appointment.penalty.status === 'pending' || appointment.penalty.status === 'partial' ? 'text-rose-600' : undefined}>
          {money(appointment.penalty.total, appointment.penalty.currency)} · {appointment.penalty.status === 'paid' ? 'cobrada' : appointment.penalty.status === 'void' ? 'perdonada' : `por cobrar ${money(appointment.penalty.balance, appointment.penalty.currency)}`} · venta {appointment.penalty.code}
        </span>
      : null],
    ['Confirmación', appointment.status === 'pending' && appointment.confirm_by
      ? <span data-confirm-by className={appointment.confirmation_overdue ? 'text-rose-600' : undefined}>
          {appointment.confirmation_overdue ? 'Venció el plazo: debía confirmarse antes del ' : 'Debe confirmarse antes del '}
          {formatDateTime(appointment.confirm_by)}
        </span>
      : null],
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
              <Badge data-status={appointment.status} tone={statusStyle(appointment.status).badge} bold>
                {appointment.status_name}
              </Badge>
            </div>

            {flowIndex >= 0 && (
              <ol className="flex items-start">
                {FLOW.map((key, index) => {
                  const current = index === flowIndex;
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

            {(forward.length > 0 || moreItems.length > 0 || appointment.can_remind) && (
              <div className="flex flex-wrap gap-2 pt-1">
                {forward.map((status, index) => (
                  <button
                    key={status}
                    type="button"
                    data-action={status}
                    disabled={busy !== ''}
                    onClick={() => changeStatus(status)}
                    className={index === 0
                      ? 'px-4 py-2 text-sm font-medium bg-brand text-white rounded-lg hover:bg-brand-hover shadow-sm transition-colors disabled:opacity-50'
                      : 'px-4 py-2 text-sm font-medium border border-slate-200 rounded-lg text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-50'}
                  >
                    {busy === status ? 'Guardando...' : actionLabel(status, appointment.status)}
                  </button>
                ))}

                {appointment.can_remind && (
                  <button
                    type="button"
                    data-action="remind"
                    disabled={busy !== ''}
                    onClick={sendReminder}
                    className="flex items-center gap-1.5 px-4 py-2 text-sm font-medium border border-slate-200 rounded-lg text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-50"
                  >
                    <BellRing size={15} /> {busy === 'remind' ? 'Enviando...' : 'Enviar recordatorio'}
                  </button>
                )}

                {moreItems.length > 0 && (
                  <>
                    <button
                      ref={moreRef}
                      type="button"
                      data-action="more"
                      aria-haspopup="menu"
                      aria-expanded={moreOpen}
                      disabled={busy !== ''}
                      onClick={() => setMoreOpen((open) => !open)}
                      className="flex items-center gap-1.5 px-4 py-2 text-sm font-medium border border-slate-200 rounded-lg text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-50"
                    >
                      <MoreHorizontal size={15} /> Más acciones
                    </button>
                    <Floating anchorRef={moreRef} open={moreOpen} onClose={() => setMoreOpen(false)} align="right" role="menu" className="min-w-[220px] bg-white border border-slate-200 rounded-xl shadow-lg py-1">
                      {moreItems.map((item) => (
                        <button
                          key={item.key}
                          type="button"
                          role="menuitem"
                          data-action={item.key}
                          onClick={() => { setMoreOpen(false); item.onClick(); }}
                          className={`w-full flex items-center gap-2 px-3.5 py-2 text-sm text-left transition-colors ${item.danger ? 'text-rose-700 hover:bg-rose-50' : 'text-slate-700 hover:bg-slate-50'}`}
                        >
                          {item.icon}
                          {item.label}
                        </button>
                      ))}
                    </Floating>
                  </>
                )}
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
                {appointment.late_cancel_strike && (() => {
                  const strike = appointment.late_cancel_strike;
                  const name = appointment.customer?.name ?? `${vocab.pick('client', 'el', 'la')} ${vocab.client.toLowerCase()}`;
                  const count = strike.limit ? `${strike.misses} de ${strike.limit}` : `${strike.misses}`;
                  return (
                    <div className="rounded-lg border border-rose-200 bg-white px-3 py-2 space-y-1.5" data-late-cancel>
                      <label className="flex items-start gap-2.5 cursor-pointer">
                        <input type="checkbox" checked={byCustomer} onChange={(e) => setByCustomer(e.target.checked)} className="mt-0.5 rounded border-slate-300 text-rose-600 focus:ring-rose-500" />
                        <span className="text-sm text-slate-800">Lo pidió {vocab.pick('client', 'el', 'la')} {vocab.client.toLowerCase()}</span>
                      </label>
                      <p className="text-xs text-slate-500">Reservó en línea y faltan menos de {strike.window_hours} horas: si la cancelación es a pedido suyo, cuenta como una falta.</p>
                      {byCustomer && (
                        <p className="text-xs font-semibold text-rose-700" data-late-cancel-warning>
                          {strike.would_block
                            ? `Avísale: con esta, ${name} queda con ${count} faltas y no podrá reservar en línea ${strike.block_days > 0 ? `durante ${strike.block_days} días` : 'hasta que se lo permitas desde su ficha'}.`
                            : `Avísale: con esta, ${name} queda con ${count} ${strike.limit || strike.misses > 1 ? 'faltas' : 'falta'} por reservas en línea.`}
                        </p>
                      )}
                    </div>
                  );
                })()}
                {!appointment.late_cancel_strike && appointment.penalty_preview?.late_cancel && (
                  <div className="rounded-lg border border-rose-200 bg-white px-3 py-2 space-y-1.5" data-late-cancel-penalty>
                    <label className="flex items-start gap-2.5 cursor-pointer">
                      <input type="checkbox" checked={byCustomer} onChange={(e) => setByCustomer(e.target.checked)} className="mt-0.5 rounded border-slate-300 text-rose-600 focus:ring-rose-500" />
                      <span className="text-sm text-slate-800">Lo pidió {vocab.pick('client', 'el', 'la')} {vocab.client.toLowerCase()}</span>
                    </label>
                    <p className="text-xs text-slate-500">Faltan menos de {appointment.penalty_preview.window_hours} horas: si la cancelación es a pedido suyo, se genera una penalidad.</p>
                  </div>
                )}
                {byCustomer && appointment.penalty_preview?.late_cancel && (
                  <p className="text-xs font-semibold text-rose-700" data-penalty-warning>
                    Se generará una penalidad de {money(appointment.penalty_preview.late_cancel, appointment.penalty_preview.currency)} ({appointment.penalty_preview.late_cancel_percent} %) por cobrar.
                  </p>
                )}
                <div className="flex justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => { setCancelling(false); setCancelReason(''); setByCustomer(false); }}
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

            <ErrorAlert message={error} />
            {notice && <p className="text-sm text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-lg px-3 py-2">{notice}</p>}
          </section>

          {canCharge && (charge || chargeNote) && (
            <section className="pt-5 border-t border-slate-100" data-charge-card>
              {charge ? (
                <button
                  type="button"
                  data-action="charge"
                  disabled={Boolean(charge.blocked) && !charge.sale && !charge.package?.current}
                  onClick={() => setCharging(true)}
                  className="w-full flex items-center gap-3 p-3 border border-slate-200 rounded-xl text-left hover:border-brand-accent hover:bg-slate-50 transition-colors disabled:hover:border-slate-200 disabled:hover:bg-transparent disabled:cursor-not-allowed"
                >
                  <span className="p-2 bg-brand-soft/20 text-brand-ink rounded-lg shrink-0">{charge.package?.current ? <Boxes size={18} /> : <Wallet size={18} />}</span>
                  <span className="flex-1 min-w-0">
                    <span className="block text-sm font-bold text-slate-800">
                      {charge.package?.current ? `Paquete ${charge.package.current.code}` : charge.sale?.status === 'paid' ? `Cobrada · ${charge.sale.code}` : charge.sale ? `Cobro ${charge.sale.code}` : 'Cobrar'}
                    </span>
                    <span className="block text-xs text-slate-500">
                      {charge.package?.current
                        ? `${charge.package.current.name} · ${charge.package.current.consumed ? 'sesión descontada' : 'se descuenta al atenderla'}`
                        : !charge.sale && (charge.package?.usable.length ?? 0) > 0
                        ? `${money(charge.preview.total, charge.preview.currency)} por cobrar, o con su paquete ${charge.package!.usable[0].code}.`
                        : charge.sale?.status === 'paid'
                        ? `${money(charge.sale.total, charge.sale.currency)}${charge.sale.invoice ? ` · ${charge.sale.invoice.doc_type_label} ${charge.sale.invoice.number}` : ' · sin comprobante'}`
                        : charge.sale && (charge.sale.refunded ?? 0) > 0
                          ? `${money(charge.sale.total, charge.sale.currency)} · se devolvieron ${money(charge.sale.refunded, charge.sale.currency)}.`
                        : charge.sale
                          ? `Pagado ${money(charge.sale.paid, charge.sale.currency)} de ${money(charge.sale.total, charge.sale.currency)}. Falta ${money(charge.sale.balance, charge.sale.currency)}.`
                          : charge.blocked ?? `${money(charge.preview.total, charge.preview.currency)} por cobrar.`}
                    </span>
                  </span>
                  <Badge
                    data-charge-status={charge.sale?.status ?? 'none'} tone={
                      charge.package?.current ? 'bg-brand-soft/25 text-brand-ink' : charge.sale ? SALE_BADGES[charge.sale.status] : charge.blocked ? 'bg-slate-100 text-slate-500' : 'bg-amber-50 text-amber-700'
                    } bold className="shrink-0"
                  >
                    {charge.package?.current ? 'Con paquete' : charge.sale?.status_label ?? (charge.blocked ? 'No se cobra' : 'Por cobrar')}
                  </Badge>
                  <ChevronRight size={16} className="text-slate-400 shrink-0" />
                </button>
              ) : (
                <p className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">{chargeNote}</p>
              )}
            </section>
          )}

          {appointment.record.uses && can('records.view') && (
            <section className="pt-5 border-t border-slate-100">
              <button
                type="button"
                data-action="open-record"
                onClick={() => onOpenRecord(appointment)}
                className="w-full flex items-center gap-3 p-3 border border-slate-200 rounded-xl text-left hover:border-brand-accent hover:bg-slate-50 transition-colors"
              >
                <span className="p-2 bg-blue-50 text-brand-ink rounded-lg shrink-0"><ClipboardList size={18} /></span>
                <span className="flex-1 min-w-0">
                  <span className="block text-sm font-bold text-slate-800">Ficha de atención</span>
                  <span className="block text-xs text-slate-500">
                    {appointment.record.status === null && 'Sin llenar. Ábrela para registrar la atención.'}
                    {appointment.record.status === 'draft' && 'Hay un borrador guardado. Falta firmarla.'}
                    {appointment.record.status === 'signed' && 'Firmada. Aún se puede corregir.'}
                    {appointment.record.status === 'locked' && 'Firmada y bloqueada. Admite notas.'}
                  </span>
                </span>
                <Badge
                  data-record-status={appointment.record.status ?? 'none'} tone={
                    appointment.record.status === 'signed' || appointment.record.status === 'locked'
                      ? 'bg-emerald-50 text-emerald-700'
                      : appointment.record.status === 'draft' ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600'
                  } bold className="shrink-0"
                >
                  {appointment.record.status === null ? 'Pendiente' : appointment.record.status === 'draft' ? 'Borrador' : 'Firmada'}
                </Badge>
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
                    <dt className={sectionTitle}>Promedio de {vocab.pick('client', 'este', 'esta')} {vocab.client.toLowerCase()}</dt>
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
            {(appointment.is_virtual || appointment.video_url || videoUrl) && (
              <div className="space-y-1.5" data-appointment-video>
                <label htmlFor="appointment-video-url" className={sectionTitle}>Enlace de la videollamada</label>
                <input id="appointment-video-url" maxLength={500} disabled={!can('agenda.edit')} className={inputClass} placeholder={appointment.video_url && !appointment.own_video_url ? `Del servicio: ${appointment.video_url}` : 'https://meet.google.com/...'} value={videoUrl} onChange={(e) => { setVideoUrl(e.target.value); setNotice(''); }} />
                {appointment.video_url && <a href={appointment.video_url} target="_blank" rel="noreferrer" className="inline-block text-xs font-semibold text-brand-accent hover:text-brand-ink break-all">Abrir {appointment.video_url}</a>}
              </div>
            )}
            {can('agenda.edit') && (notes.trim() !== (appointment.notes ?? '').trim() || videoUrl.trim() !== (appointment.own_video_url ?? '')) && (
              <div className="flex justify-end">
                <button
                  type="button"
                  disabled={busy !== ''}
                  onClick={saveNotes}
                  className="px-4 py-2 text-sm font-medium border border-slate-200 rounded-lg text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-50"
                >
                  {busy === 'notes' ? 'Guardando...' : 'Guardar cambios'}
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

      <ChargePanel
        appointmentId={charging && appointment ? appointment.id : null}
        appointmentCode={appointment?.code}
        onClose={() => setCharging(false)}
        onChanged={setCharge}
      />
    </FormDrawer>
  );
}
