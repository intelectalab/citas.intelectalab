'use client';

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import Link from 'next/link';
import { CalendarDays, CalendarX2, CheckCircle, ChevronLeft, ChevronRight, Clock, Hourglass, Plus, RefreshCw, Stethoscope, ThumbsUp } from 'lucide-react';
import { api } from '@/lib/axios';
import { useVocabulary } from '@/hooks/useVocabulary';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { useAppointmentPanels } from './useAppointmentPanels';
import { useAutoRefresh } from './useAutoRefresh';
import Stopwatch from './Stopwatch';
import Select from '@/app/components/common/Select';
import { ProfessionalLink } from '@/app/components/common/PersonLink';
import {
  RELEASED, addDays, apiError, formatDate, fromMinutes, statusStyle, timeOf, toMinutes, todayISO,
  type Appointment,
} from '../appointments';

interface DayProfessional {
  id: string;
  display_name: string;
  color_code: string | null;
  schedules: { starts: string; ends: string }[];
  blocks: { starts: string; ends: string; reason: string | null; kind?: string }[];
}

// Cita que se soltó en otro horario y espera confirmación.
interface PendingMove {
  appointment: Appointment;
  professional: DayProfessional;
  time: string;
}

interface DayPayload {
  date: string;
  now: string;
  // Feriados de ese día en la sede; si cierra, nadie atiende.
  holidays?: { name: string; closed: boolean }[];
  professionals: DayProfessional[];
  appointments: Appointment[];
}

// Alto de un minuto en la grilla, en píxeles.
const PX = 1.5;
const DEFAULT_RANGE = { start: 8 * 60, end: 20 * 60 };

// Tarjetas del día. Cada una filtra la grilla por las fases que agrupa.
const CARDS: { id: string; title: string; statuses: string[]; icon: React.ReactNode; color: string; active: string }[] = [
  { id: 'pending', title: 'Pendientes', statuses: ['pending'], icon: <Hourglass size={18} />, color: 'text-amber-600', active: 'border-amber-500 ring-2 ring-amber-500/20' },
  { id: 'confirmed', title: 'Confirmadas', statuses: ['confirmed'], icon: <ThumbsUp size={18} />, color: 'text-sky-600', active: 'border-sky-500 ring-2 ring-sky-500/20' },
  { id: 'ongoing', title: 'En curso', statuses: ['checked_in', 'in_progress'], icon: <Stethoscope size={18} />, color: 'text-violet-600', active: 'border-violet-500 ring-2 ring-violet-500/20' },
  { id: 'completed', title: 'Atendidas', statuses: ['completed'], icon: <CheckCircle size={18} />, color: 'text-emerald-600', active: 'border-emerald-500 ring-2 ring-emerald-500/20' },
  { id: 'released', title: 'Canceladas y ausencias', statuses: RELEASED, icon: <CalendarX2 size={18} />, color: 'text-rose-700', active: 'border-rose-500 ring-2 ring-rose-500/20' },
];

// Reparte en carriles las citas que se cruzan en una misma columna, para que
// ninguna tape a otra (pasa con la sobre-agenda y al ver las canceladas).
function withLanes(appointments: Appointment[]) {
  const sorted = [...appointments].sort((a, b) => a.starts_at.localeCompare(b.starts_at));
  const placed: { appointment: Appointment; lane: number; lanes: number }[] = [];
  let cluster: typeof placed = [];
  let clusterEnd = '';

  const close = () => {
    const lanes = Math.max(1, ...cluster.map((item) => item.lane + 1));
    cluster.forEach((item) => { item.lanes = lanes; });
    cluster = [];
  };

  for (const appointment of sorted) {
    if (cluster.length && appointment.starts_at >= clusterEnd) close();

    const taken = cluster.filter((item) => item.appointment.occupied_until > appointment.starts_at).map((item) => item.lane);
    let lane = 0;
    while (taken.includes(lane)) lane++;

    const item = { appointment, lane, lanes: 1 };
    cluster.push(item);
    placed.push(item);
    if (appointment.occupied_until > clusterEnd) clusterEnd = appointment.occupied_until;
  }
  close();

  return placed;
}

// Agenda de un día en una sede: una columna por profesional, con su horario,
// sus bloqueos y sus citas. Un clic en un espacio libre abre una cita nueva a esa hora.
export default function AgendaDay() {
  const vocab = useVocabulary();
  const [date, setDate] = useState(todayISO());
  const [branches, setBranches] = useState<{ id: string; name: string }[]>([]);
  const [branchId, setBranchId] = useState('');
  const [slotInterval, setSlotInterval] = useState(15);
  const [day, setDay] = useState<DayPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [activeCard, setActiveCard] = useState('');
  const [updatedAt, setUpdatedAt] = useState('');

  // Arrastre de una cita: cuál se arrastra, dónde caería y el cambio por confirmar.
  const dragging = useRef<{ appointment: Appointment; grabOffset: number } | null>(null);
  const [isDragging, setIsDragging] = useState(false);
  const [dropTarget, setDropTarget] = useState<{ professionalId: string; minutes: number } | null>(null);
  const [pendingMove, setPendingMove] = useState<PendingMove | null>(null);
  const [moving, setMoving] = useState(false);
  const [moveError, setMoveError] = useState('');

  const load = useCallback(() => {
    if (!branchId) return;

    api.get('/appointments/day', { params: { date, branch_id: branchId } })
      .then((res) => {
        setDay(res.data);
        setError('');
        setUpdatedAt(new Date().toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' }));
      })
      .catch((err) => setError(apiError(err, 'No se pudo cargar la agenda.')))
      .finally(() => setLoading(false));
  }, [date, branchId]);

  const { openNew, openDetail, panels, isOpen: panelOpen } = useAppointmentPanels(load);

  // La agenda se actualiza sola para mostrar lo que agendan otras personas. Espera
  // mientras hay un panel abierto o una cita a medio mover, para no interrumpir.
  useAutoRefresh(load, panelOpen || isDragging || pendingMove !== null);

  useEffect(() => {
    api.get('/appointments/context')
      .then((res) => {
        setBranches(res.data.branches ?? []);
        setSlotInterval(res.data.slot_interval || 15);
        setBranchId(res.data.branches?.[0]?.id ?? '');
        if (!res.data.branches?.length) setLoading(false);
      })
      .catch((err) => { setError(apiError(err, 'No se pudo cargar la agenda.')); setLoading(false); });
  }, []);

  useEffect(() => {
    setLoading(true);
    load();
  }, [load]);

  const appointments = day?.appointments ?? [];
  const card = CARDS.find((c) => c.id === activeCard);

  // Las canceladas y las ausencias ya no ocupan el horario: solo se ven al pedirlas.
  const visible = useMemo(
    () => appointments.filter((a) => (card ? card.statuses.includes(a.status) : !RELEASED.includes(a.status))),
    [appointments, card],
  );

  // Horas que muestra la grilla: de la primera a la última con horario o citas.
  const range = useMemo(() => {
    const starts: number[] = [];
    const ends: number[] = [];

    day?.professionals.forEach((p) => p.schedules.forEach((s) => { starts.push(toMinutes(s.starts)); ends.push(toMinutes(s.ends)); }));
    appointments.forEach((a) => { starts.push(toMinutes(timeOf(a.starts_at))); ends.push(toMinutes(timeOf(a.ends_at)) || 24 * 60); });

    if (!starts.length) return DEFAULT_RANGE;

    return {
      start: Math.floor(Math.min(...starts) / 60) * 60,
      end: Math.min(24 * 60, Math.ceil(Math.max(...ends) / 60) * 60),
    };
  }, [day, appointments]);

  const hours = Array.from({ length: (range.end - range.start) / 60 }, (_, i) => range.start + i * 60);
  const height = (range.end - range.start) * PX;
  const top = (minutes: number) => (Math.max(range.start, Math.min(range.end, minutes)) - range.start) * PX;
  const isToday = day?.date === day?.now.slice(0, 10);
  const nowMinutes = day ? toMinutes(timeOf(day.now)) : 0;

  const newAt = (professionalId: string, event: React.MouseEvent<HTMLDivElement>) => {
    const offset = event.clientY - event.currentTarget.getBoundingClientRect().top;
    const minutes = range.start + Math.floor(offset / PX / slotInterval) * slotInterval;
    openNew({ date, time: fromMinutes(minutes), professional_id: professionalId, branch_id: branchId });
  };

  // Hora en la que caería la cita arrastrada: su borde superior, ajustado al intervalo.
  const dropMinutes = (event: React.DragEvent<HTMLDivElement>) => {
    const offset = event.clientY - event.currentTarget.getBoundingClientRect().top - (dragging.current?.grabOffset ?? 0);
    const duration = dragging.current?.appointment.duration_minutes ?? 0;
    const minutes = range.start + Math.round(offset / PX / slotInterval) * slotInterval;

    return Math.max(range.start, Math.min(range.end - duration, minutes));
  };

  const endDrag = () => {
    dragging.current = null;
    setIsDragging(false);
    setDropTarget(null);
  };

  const handleDrop = (professional: DayProfessional, event: React.DragEvent<HTMLDivElement>) => {
    event.preventDefault();
    const dragged = dragging.current?.appointment;
    const time = fromMinutes(dropMinutes(event));
    endDrag();

    if (!dragged) return;
    // Soltarla donde estaba no cambia nada.
    if (dragged.professional?.id === professional.id && timeOf(dragged.starts_at) === time) return;

    setMoveError('');
    setPendingMove({ appointment: dragged, professional, time });
  };

  const confirmMove = async () => {
    if (!pendingMove) return;

    try {
      setMoving(true);
      setMoveError('');
      await api.post(`/appointments/${pendingMove.appointment.id}/reschedule`, {
        starts_at: `${date}T${pendingMove.time}`,
        professional_id: pendingMove.professional.id,
        branch_id: branchId,
      });
      setPendingMove(null);
      load();
    } catch (err) {
      setMoveError(apiError(err, 'No se pudo mover la cita.'));
    } finally {
      setMoving(false);
    }
  };

  const navButton = 'p-2 border border-slate-200 bg-white rounded-lg text-slate-600 hover:bg-slate-50 transition-colors';

  return (
    <div className="space-y-5 pb-8 font-sans">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-[#022340] tracking-tight">Agenda</h1>
          <p className="text-sm text-slate-500 font-medium first-letter:uppercase" data-agenda-date={date}>{formatDate(date)}</p>
          <p className="flex items-center gap-1.5 text-xs text-slate-400 mt-0.5">
            <button type="button" title="Actualizar ahora" data-refresh onClick={load} className="hover:text-[#3F768C] transition-colors">
              <RefreshCw size={12} />
            </button>
            <span data-updated-at>{updatedAt ? `Se actualiza sola · última vez a las ${updatedAt}` : 'Se actualiza sola'}</span>
            <span>· Arrastra una cita para moverla</span>
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {branches.length > 1 && (
            <div className="w-60" data-branch-select>
              <Select
                ariaLabel="Sede"
                clearable={false}
                value={branchId}
                onChange={(e) => setBranchId(String(e.target.value))}
                options={branches.map((branch) => ({ value: branch.id, label: branch.name }))}
              />
            </div>
          )}

          <button type="button" title="Día anterior" onClick={() => setDate(addDays(date, -1))} className={navButton}>
            <ChevronLeft size={16} />
          </button>
          <button
            type="button"
            onClick={() => setDate(todayISO())}
            className="px-3 py-2 text-sm font-semibold border border-slate-200 bg-white rounded-lg text-slate-700 hover:bg-slate-50 transition-colors"
          >
            Hoy
          </button>
          <button type="button" title="Día siguiente" onClick={() => setDate(addDays(date, 1))} className={navButton}>
            <ChevronRight size={16} />
          </button>
          <input
            type="date"
            aria-label="Ir a una fecha"
            value={date}
            onChange={(e) => e.target.value && setDate(e.target.value)}
            className="text-sm border border-slate-200 bg-white rounded-lg px-3 py-2 text-slate-800 focus:outline-none focus:ring-2 focus:ring-[#022340]/20"
          />

          <button
            type="button"
            onClick={() => openNew({ date, branch_id: branchId })}
            className="flex items-center gap-2 px-4 py-2 text-sm font-bold bg-[#022340] text-white rounded-lg hover:bg-[#03315a] shadow-sm transition-colors"
          >
            <Plus size={16} className="text-[#84D0D9] stroke-[2.5]" /> Nueva cita
          </button>
        </div>
      </div>

      <div className="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-4">
        {[{ id: '', title: 'Citas del día', statuses: [] as string[], icon: <CalendarDays size={18} />, color: 'text-slate-500', active: 'border-slate-400 ring-2 ring-slate-400/20' }, ...CARDS].map((item) => {
          const count = item.id === ''
            ? appointments.filter((a) => !RELEASED.includes(a.status)).length
            : appointments.filter((a) => item.statuses.includes(a.status)).length;
          const isActive = activeCard === item.id;

          return (
            <button
              key={item.id || 'all'}
              type="button"
              data-card={item.id || 'all'}
              aria-pressed={isActive}
              onClick={() => setActiveCard(isActive ? '' : item.id)}
              className={`text-left bg-white px-4 py-3 rounded-2xl border shadow-[0_4px_20px_-4px_rgba(0,0,0,0.05)] transition-all duration-300 cursor-pointer hover:-translate-y-1 hover:shadow-[0_10px_25px_-5px_rgba(0,0,0,0.08)] ${
                isActive ? item.active : 'border-slate-100 hover:border-slate-200'
              }`}
            >
              <div className="flex items-center justify-between">
                <div>
                  <span className="text-[10px] font-semibold tracking-wider text-slate-400 uppercase">{item.title}</span>
                  <div className="mt-0.5 text-2xl font-extrabold tracking-tight text-slate-900">{count}</div>
                </div>
                <div className={`p-2 rounded-xl bg-slate-50 border border-slate-100 ${item.color}`}>{item.icon}</div>
              </div>
            </button>
          );
        })}
      </div>

      {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}

      {(day?.holidays ?? []).map((holiday) => (
        <p
          key={holiday.name}
          data-holiday={holiday.closed ? 'closed' : 'open'}
          className={`text-sm rounded-lg px-3 py-2 border ${holiday.closed ? 'text-rose-700 bg-rose-50 border-rose-200' : 'text-sky-700 bg-sky-50 border-sky-200'}`}
        >
          <span className="font-semibold">Feriado: {holiday.name}.</span>{' '}
          {holiday.closed ? 'La sede cierra este día: no se ofrecen horarios ni se puede agendar.' : 'Se atiende con normalidad.'}
        </p>
      ))}

      <div className="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        {loading && !day ? (
          <p className="p-8 text-sm text-slate-500">Cargando agenda...</p>
        ) : branches.length === 0 ? (
          <p className="p-8 text-sm text-slate-500">Tu empresa no tiene sedes activas.</p>
        ) : !day || day.professionals.length === 0 ? (
          <div className="p-10 text-center space-y-2">
            <Clock className="w-8 h-8 text-slate-300 mx-auto" />
            <p className="text-sm font-semibold text-slate-700">Nadie atiende este día en esta sede.</p>
            <p className="text-sm text-slate-500">
              Define el horario de cada {vocab.pro.toLowerCase()} en{' '}
              <Link href="/professionals" className="font-semibold text-[#3F768C] hover:text-[#022340]">{vocab.pros}</Link>.
            </p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <div className="min-w-max">
              <div className="flex border-b border-slate-200 bg-slate-50">
                <div className="w-16 shrink-0" />
                {day.professionals.map((professional) => (
                  <div key={professional.id} className="flex-1 min-w-[200px] px-3 py-3 border-l border-slate-200">
                    <ProfessionalLink id={professional.id} className="block text-sm font-bold text-slate-800 truncate">{professional.display_name}</ProfessionalLink>
                    <span className="block text-xs text-slate-500">
                      {professional.schedules.length
                        ? professional.schedules.map((s) => `${s.starts}–${s.ends}`).join(' · ')
                        : 'Sin horario este día'}
                    </span>
                  </div>
                ))}
              </div>

              <div className="flex">
                <div className="w-16 shrink-0 relative" style={{ height }}>
                  {hours.map((minutes) => (
                    <span key={minutes} className="absolute right-2 -translate-y-1/2 text-[11px] font-medium text-slate-400" style={{ top: top(minutes) || 8 }}>
                      {fromMinutes(minutes)}
                    </span>
                  ))}
                </div>

                {day.professionals.map((professional) => (
                  <div
                    key={professional.id}
                    data-column={professional.id}
                    onClick={(event) => newAt(professional.id, event)}
                    onDragOver={(event) => {
                      if (!dragging.current) return;
                      event.preventDefault();
                      const minutes = dropMinutes(event);
                      setDropTarget((current) =>
                        current?.professionalId === professional.id && current.minutes === minutes ? current : { professionalId: professional.id, minutes });
                    }}
                    onDrop={(event) => handleDrop(professional, event)}
                    title="Clic en un espacio libre para agendar"
                    className="flex-1 min-w-[200px] relative border-l border-slate-200 bg-slate-100 cursor-pointer"
                    style={{ height }}
                  >
                    {/* Horas en que atiende */}
                    {professional.schedules.map((schedule, index) => (
                      <div
                        key={index}
                        className="absolute inset-x-0 bg-white pointer-events-none"
                        style={{ top: top(toMinutes(schedule.starts)), height: top(toMinutes(schedule.ends)) - top(toMinutes(schedule.starts)) }}
                      />
                    ))}

                    {hours.map((minutes) => (
                      <div key={minutes} className="absolute inset-x-0 border-t border-slate-200/70 pointer-events-none" style={{ top: top(minutes) }} />
                    ))}

                    {/* Bloqueos y ausencias */}
                    {professional.blocks.map((block, index) => {
                      const from = top(toMinutes(block.starts));
                      const to = top(block.ends === '24:00' ? 24 * 60 : toMinutes(block.ends));
                      if (to <= from) return null;

                      return (
                        <div
                          key={index}
                          data-block
                          onClick={(event) => event.stopPropagation()}
                          className="absolute inset-x-0 bg-slate-200 border-y border-slate-300 px-2 py-1 overflow-hidden cursor-not-allowed"
                          style={{ top: from, height: to - from }}
                        >
                          <span className="text-[11px] font-semibold text-slate-500">No atiende{block.reason ? ` · ${block.reason}` : ''}</span>
                        </div>
                      );
                    })}

                    {/* Dónde caería la cita que se está arrastrando */}
                    {dropTarget?.professionalId === professional.id && dragging.current && (
                      <div
                        data-drop-target
                        className="absolute inset-x-1 rounded-lg border-2 border-dashed border-[#3F768C] bg-sky-50/70 px-2 py-0.5 pointer-events-none z-20"
                        style={{ top: top(dropTarget.minutes), height: dragging.current.appointment.duration_minutes * PX }}
                      >
                        <span className="text-xs font-bold text-[#022340]">{fromMinutes(dropTarget.minutes)}</span>
                      </div>
                    )}

                    {withLanes(visible.filter((a) => a.professional?.id === professional.id)).map(({ appointment, lane, lanes }) => {
                      const from = top(toMinutes(timeOf(appointment.starts_at)));
                      // Si la atención terminó antes, el bloque llega hasta ahí: el resto quedó libre.
                      const occupiedMinutes = Math.max(0, toMinutes(timeOf(appointment.occupied_until)) - toMinutes(timeOf(appointment.starts_at)))
                        || appointment.duration_minutes;
                      const blockHeight = Math.max(22, Math.min(occupiedMinutes, appointment.duration_minutes) * PX - 2);
                      const timing = appointment.timing;
                      const style = statusStyle(appointment.status);

                      return (
                        <button
                          key={appointment.id}
                          type="button"
                          data-appointment={appointment.id}
                          // Solo se mueven las citas que aún se pueden reprogramar.
                          draggable={appointment.can_reschedule}
                          onDragStart={(event) => {
                            dragging.current = { appointment, grabOffset: event.clientY - event.currentTarget.getBoundingClientRect().top };
                            event.dataTransfer.effectAllowed = 'move';
                            event.dataTransfer.setData('text/plain', appointment.code);
                            setIsDragging(true);
                          }}
                          onDragEnd={endDrag}
                          onClick={(event) => { event.stopPropagation(); openDetail(appointment.id); }}
                          title={`${appointment.code} · ${appointment.customer?.name ?? ''} · ${appointment.service?.name ?? ''} · ${appointment.status_name}`}
                          className={`absolute rounded-lg border px-2 py-1 text-left overflow-hidden shadow-xs hover:shadow-md transition-shadow ${
                            appointment.can_reschedule ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer'
                          } ${style.block} ${RELEASED.includes(appointment.status) ? 'opacity-70' : ''} ${
                            isDragging && dragging.current?.appointment.id === appointment.id ? 'opacity-40' : ''
                          }`}
                          style={{
                            top: from + 1,
                            height: blockHeight,
                            left: `calc(${(lane / lanes) * 100}% + 3px)`,
                            width: `calc(${100 / lanes}% - 6px)`,
                          }}
                        >
                          <span className="flex items-center gap-1.5 text-xs font-bold leading-tight">
                            <span className={`w-1.5 h-1.5 rounded-full shrink-0 ${style.dot}`} />
                            <span className="truncate">{timeOf(appointment.starts_at)} {appointment.dependent?.name ?? appointment.customer?.name}</span>
                            {/* Cronómetro: cuánto lleva en atención o esperando */}
                            {timing.running && (
                              <Stopwatch
                                seconds={(timing.running === 'service' ? timing.service_seconds : timing.wait_seconds) ?? 0}
                                running
                                limitSeconds={timing.running === 'service' ? timing.planned_seconds : null}
                                className="ml-auto shrink-0 px-1.5 rounded bg-white/70 text-[11px] font-bold"
                              />
                            )}
                          </span>
                          {blockHeight >= 36 && (
                            <span className="block text-[11px] leading-tight truncate opacity-90">
                              {appointment.service?.name}{appointment.dependent ? ` · ${appointment.customer?.name}` : ''}
                            </span>
                          )}
                          {blockHeight >= 56 && (
                            <span className="block text-[11px] leading-tight truncate opacity-75">{appointment.status_name}</span>
                          )}
                        </button>
                      );
                    })}

                    {isToday && nowMinutes >= range.start && nowMinutes <= range.end && (
                      <div className="absolute inset-x-0 border-t-2 border-rose-500 pointer-events-none z-10" style={{ top: top(nowMinutes) }} />
                    )}
                  </div>
                ))}
              </div>
            </div>
          </div>
        )}
      </div>

      {panels}

      <AppModal
        isOpen={pendingMove !== null}
        type="confirm"
        title="¿Mover la cita?"
        message={pendingMove ? (
          <div className="space-y-3 pt-1">
            <p className="text-sm text-slate-600">
              <strong>{pendingMove.appointment.code}</strong> · {pendingMove.appointment.customer?.name} · {pendingMove.appointment.service?.name}
            </p>
            <div className="p-3 bg-slate-50 border border-slate-200/80 rounded-xl text-sm space-y-1">
              <p className="text-slate-500">
                Ahora: <span className="font-semibold text-slate-700">{timeOf(pendingMove.appointment.starts_at)} con {pendingMove.appointment.professional?.display_name}</span>
              </p>
              <p className="text-slate-500">
                Pasa a: <span className="font-semibold text-slate-900" data-move-to>{pendingMove.time} con {pendingMove.professional.display_name}</span>
              </p>
            </div>
            {moveError && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{moveError}</p>}
          </div>
        ) : ''}
        confirmText="Sí, mover"
        cancelText="No mover"
        isLoading={moving}
        onConfirm={confirmMove}
        onClose={() => { setPendingMove(null); setMoveError(''); }}
      />
    </div>
  );
}
