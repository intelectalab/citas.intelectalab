'use client';

import { ErrorAlert } from '@/app/components/common/Alert';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import Link from 'next/link';
import { Ban, CalendarDays, CalendarX2, CheckCircle, ChevronLeft, ChevronRight, Clock, Hourglass, Plus, RefreshCw, Stethoscope, ThumbsUp } from 'lucide-react';
import FormBlock from '@/features/blocks/components/FormBlock';
import type { BlockOptions } from '@/features/blocks/blocks';
import { api } from '@/lib/axios';
import { suggestPlural, useVocabulary } from '@/hooks/useVocabulary';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { useAppointmentPanels } from './useAppointmentPanels';
import { useAutoRefresh } from './useAutoRefresh';
import Stopwatch from './Stopwatch';
import AgendaClassBlock from '@/features/classes/components/AgendaClassBlock';
import Select from '@/app/components/common/Select';
import DateField from '@/app/components/common/DateField';
import { ProfessionalLink } from '@/app/components/common/PersonLink';
import { useAuth } from '@/hooks/useAuth';
import { useMediaQuery } from '@/hooks/useMediaQuery';
import { AgendaMonth, AgendaWeek, addMonths, daysBetween, mondayOf, monthGrid, type RangePayload } from './AgendaRange';
import {
  RELEASED, addDays, apiError, formatDate, fromMinutes, statusStyle, timeOf, toMinutes, todayISO,
  type Appointment,
} from '../appointments';

interface DayProfessional {
  id: string;
  display_name: string;
  color_code: string | null;
  occupancy?: { scheduled_minutes: number; booked_minutes: number; percent: number | null };
  schedules: { starts: string; ends: string }[];
  blocks: { starts: string; ends: string; reason: string | null; kind?: string; class_id?: string }[];
}

interface PendingMove {
  appointment: Appointment;
  professional: { id: string; display_name: string };
  date: string;
  time: string;
}

interface DayRoom {
  id: string;
  name: string;
  description: string | null;
  active: boolean;
  blocks: { starts: string; ends: string; reason: string | null; kind?: string }[];
}

interface DayColumn {
  id: string;
  kind: 'pro' | 'room';
  name: string;
  schedules: { starts: string; ends: string }[];
  blocks: DayProfessional['blocks'];
  occupancy?: DayProfessional['occupancy'];
  sub: string;
}

type GroupBy = 'pro' | 'room';

const GROUP_KEY = 'agenda.group-by';

const HATCH = 'repeating-linear-gradient(135deg, rgba(148,163,184,0.28) 0 4px, transparent 4px 8px)';

const MARKS: { test: (a: Appointment) => boolean; label: string }[] = [
  { test: (a) => ['online', 'portal', 'payment_link'].includes(a.source), label: 'En línea' },
  { test: (a) => Boolean(a.deposit_paid), label: 'Adelanto' },
  { test: (a) => Boolean(a.is_virtual || a.video_url), label: 'Videollamada' },
  { test: (a) => a.source === 'walkin' || a.source === 'kiosk', label: 'Sin cita' },
];

interface DayPayload {
  date: string;
  now: string;
  holidays?: { name: string; closed: boolean }[];
  professionals: DayProfessional[];
  rooms?: DayRoom[];
  respect_buffers?: boolean;
  my_professional_id?: string | null;
  appointments: Appointment[];
}

type PhoneView = 'pro' | 'list';

type ViewMode = 'day' | 'week' | 'month';

const VIEW_KEY = 'agenda.phone-view';

const MODE_KEY = 'agenda.view';

const readMode = (): ViewMode => {
  try {
    const saved = localStorage.getItem(MODE_KEY);
    return saved === 'week' || saved === 'month' ? saved : 'day';
  } catch {
    return 'day';
  }
};

const MODES: [ViewMode, string][] = [['day', 'Día'], ['week', 'Semana'], ['month', 'Mes']];

const readView = (): PhoneView => {
  try {
    return localStorage.getItem(VIEW_KEY) === 'list' ? 'list' : 'pro';
  } catch {
    return 'pro';
  }
};

const PX = 1.5;
const DEFAULT_RANGE = { start: 8 * 60, end: 20 * 60 };

const CARDS: { id: string; title: string; statuses: string[]; icon: React.ReactNode; color: string; active: string }[] = [
  { id: 'pending', title: 'Pendientes', statuses: ['pending'], icon: <Hourglass size={18} />, color: 'text-amber-600', active: 'border-amber-500 ring-2 ring-amber-500/20' },
  { id: 'confirmed', title: 'Confirmadas', statuses: ['confirmed'], icon: <ThumbsUp size={18} />, color: 'text-sky-600', active: 'border-sky-500 ring-2 ring-sky-500/20' },
  { id: 'ongoing', title: 'En curso', statuses: ['checked_in', 'in_progress'], icon: <Stethoscope size={18} />, color: 'text-violet-600', active: 'border-violet-500 ring-2 ring-violet-500/20' },
  { id: 'completed', title: 'Atendidas', statuses: ['completed'], icon: <CheckCircle size={18} />, color: 'text-emerald-600', active: 'border-emerald-500 ring-2 ring-emerald-500/20' },
  { id: 'released', title: 'Canceladas y ausencias', statuses: RELEASED, icon: <CalendarX2 size={18} />, color: 'text-rose-700', active: 'border-rose-500 ring-2 ring-rose-500/20' },
];

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

export default function AgendaDay() {
  const { can, user } = useAuth();
  const statusNames: Record<string, string> = Object.fromEntries((user?.tenant?.appointment_statuses ?? []).map((s) => [s.key, s.name]));
  const vocab = useVocabulary();
  const [date, setDate] = useState(todayISO());
  const [branches, setBranches] = useState<{ id: string; name: string }[]>([]);
  const [branchId, setBranchId] = useState('');
  const [slotInterval, setSlotInterval] = useState(15);
  const [day, setDay] = useState<DayPayload | null>(null);
  const [mode, setMode] = useState<ViewMode>('day');
  const [rangeData, setRangeData] = useState<RangePayload | null>(null);
  const [proFilter, setProFilter] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [activeCard, setActiveCard] = useState('');
  const [updatedAt, setUpdatedAt] = useState('');

  const isPhone = useMediaQuery('(max-width: 639px)', false);
  const [phoneView, setPhoneView] = useState<PhoneView>(() => (typeof window === 'undefined' ? 'pro' : readView()));
  const [focusPro, setFocusPro] = useState('');
  const [groupBy, setGroupBy] = useState<GroupBy>('pro');
  const [blockOpen, setBlockOpen] = useState(false);
  const [blockOptions, setBlockOptions] = useState<BlockOptions | null>(null);
  const [blockPreset, setBlockPreset] = useState<{ date?: string; time?: string; professional_id?: string; branch_id?: string; room_id?: string } | null>(null);
  const nowLine = useRef<HTMLDivElement>(null);

  useEffect(() => {
    try { if (localStorage.getItem(GROUP_KEY) === 'room') setGroupBy('room'); } catch {}
  }, []);

  const chooseGroup = (next: GroupBy) => {
    setGroupBy(next);
    setFocusPro('');
    try { localStorage.setItem(GROUP_KEY, next); } catch {}
  };

  const openBlock = (preset: { date?: string; time?: string; professional_id?: string; room_id?: string } = {}) => {
    setBlockPreset({ date, branch_id: branchId, ...preset });
    setBlockOpen(true);
  };

  const canBlock = can('blocks.create');
  useEffect(() => {
    if (!canBlock) return;
    api.get('/availability-blocks/options').then((res) => setBlockOptions(res.data)).catch(() => setBlockOptions(null));
  }, [canBlock]);
  const scrolledFor = useRef('');

  useEffect(() => { setMode(readMode()); }, []);

  const chooseMode = (next: ViewMode) => {
    setMode(next);
    setActiveCard('');
    try { localStorage.setItem(MODE_KEY, next); } catch {}
  };

  const openDay = (target: string) => {
    setDate(target);
    chooseMode('day');
  };

  const span = useMemo(() => {
    if (mode === 'week') {
      const from = mondayOf(date);
      return { from, to: addDays(from, 6), month: '' };
    }
    if (mode === 'month') {
      const grid = monthGrid(date);
      return { from: grid.start, to: grid.end, month: grid.first };
    }
    return { from: date, to: date, month: '' };
  }, [mode, date]);

  const choosePhoneView = (view: PhoneView) => {
    setPhoneView(view);
    try { localStorage.setItem(VIEW_KEY, view); } catch {}
  };

  const dragging = useRef<{ appointment: Appointment; grabOffset: number } | null>(null);
  const [isDragging, setIsDragging] = useState(false);
  const [dropTarget, setDropTarget] = useState<{ professionalId: string; minutes: number } | null>(null);
  const [pendingMove, setPendingMove] = useState<PendingMove | null>(null);
  const [moving, setMoving] = useState(false);
  const [moveError, setMoveError] = useState('');

  const load = useCallback(() => {
    if (!branchId) return;

    const request = mode === 'day'
      ? api.get('/appointments/day', { params: { date, branch_id: branchId } }).then((res) => setDay(res.data))
      : api.get('/appointments/range', { params: { from: span.from, to: span.to, branch_id: branchId } }).then((res) => setRangeData(res.data));

    request
      .then(() => {
        setError('');
        setUpdatedAt(new Date().toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' }));
      })
      .catch((err) => setError(apiError(err, 'No se pudo cargar la agenda.')))
      .finally(() => setLoading(false));
  }, [date, branchId, mode, span]);

  const { openNew, openDetail, panels, isOpen: panelOpen } = useAppointmentPanels(load);

  useAutoRefresh(load, panelOpen || isDragging || pendingMove !== null);

  useEffect(() => {
    const query = new URLSearchParams(window.location.search);
    const customerId = query.get('cliente');
    const wanted = query.get('cita');
    if (wanted) {
      window.history.replaceState(null, '', '/agenda');
      openDetail(wanted);
      return;
    }
    if (query.get('nueva') === '1' && !customerId) {
      window.history.replaceState(null, '', '/agenda');
      openNew();
      return;
    }
    if (query.get('nueva') !== '1' || !customerId) return;
    window.history.replaceState(null, '', '/agenda');
    const sede = query.get('sede');
    if (sede) setBranchId(sede);

    api.get(`/customers/${customerId}`)
      .then((res) => openNew({
        customer: res.data.customer,
        customer_dependent_id: query.get('dependiente') ?? undefined,
        service_id: query.get('servicio') ?? undefined,
        professional_id: query.get('profesional') ?? undefined,
        branch_id: sede ?? undefined,
        origin_label: 'Próxima cita: elige el día y la hora',
      }))
      .catch((err) => setError(apiError(err, 'No se pudo abrir la nueva cita.')));
  }, [openNew, openDetail]);

  useEffect(() => {
    const onOpen = (event: Event) => {
      const id = (event as CustomEvent<string>).detail;
      if (id) openDetail(id);
    };
    const onNew = () => openNew();
    window.addEventListener('agenda:open', onOpen);
    window.addEventListener('agenda:new', onNew);
    return () => {
      window.removeEventListener('agenda:open', onOpen);
      window.removeEventListener('agenda:new', onNew);
    };
  }, [openNew, openDetail]);

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

  const rangeAppointments = useMemo(
    () => (rangeData && rangeData.from === span.from ? rangeData.appointments.filter((a) => !proFilter || a.professional?.id === proFilter) : []),
    [rangeData, span.from, proFilter],
  );
  const appointments = mode === 'day' ? day?.appointments ?? [] : rangeAppointments;
  const cardAppointments = mode === 'month' ? appointments.filter((a) => a.starts_at.startsWith(span.month.slice(0, 7))) : appointments;
  const proColors = useMemo(
    () => Object.fromEntries((rangeData?.professionals ?? []).map((p) => [p.id, p.color_code])),
    [rangeData],
  );
  const card = CARDS.find((c) => c.id === activeCard);

  const visible = useMemo(
    () => appointments.filter((a) => (card ? card.statuses.includes(a.status) : !RELEASED.includes(a.status))),
    [appointments, card],
  );

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

  const roomsAvailable = (day?.rooms?.length ?? 0) > 0;
  const byRoom = groupBy === 'room' && roomsAvailable;
  const professionals: DayColumn[] = useMemo(() => {
    if (byRoom) {
      const open = day?.professionals.flatMap((p) => p.schedules) ?? [];
      return (day?.rooms ?? []).map((room) => ({
        id: room.id,
        kind: 'room' as const,
        name: room.name,
        sub: room.active ? room.description || 'Sala' : 'Fuera de uso',
        schedules: open,
        blocks: room.blocks,
      }));
    }
    return (day?.professionals ?? []).map((p) => ({
      id: p.id,
      kind: 'pro' as const,
      name: p.display_name,
      sub: p.schedules.length ? p.schedules.map((s) => `${s.starts}–${s.ends}`).join(' · ') : 'Sin horario este día',
      schedules: p.schedules,
      blocks: p.blocks,
      occupancy: p.occupancy,
    }));
  }, [day, byRoom]);
  const inColumn = (appointment: Appointment, column: DayColumn) => (column.kind === 'room' ? appointment.room?.id === column.id : appointment.professional?.id === column.id);
  const countFor = (columnId: string) => {
    const column = professionals.find((c) => c.id === columnId);
    return column ? visible.filter((a) => inColumn(a, column)).length : 0;
  };
  const focused = professionals.find((p) => p.id === focusPro)
    ?? professionals.find((p) => p.id === day?.my_professional_id)
    ?? professionals.find((p) => countFor(p.id) > 0)
    ?? professionals[0];
  const phoneList = isPhone && phoneView === 'list';
  const columns = isPhone && focused ? [focused] : professionals;
  const withoutRoom = byRoom ? visible.filter((a) => !a.room).length : 0;
  const listed = useMemo(() => [...visible].sort((a, b) => a.starts_at.localeCompare(b.starts_at)), [visible]);

  const hours = Array.from({ length: (range.end - range.start) / 60 }, (_, i) => range.start + i * 60);
  const height = (range.end - range.start) * PX;
  const top = (minutes: number) => (Math.max(range.start, Math.min(range.end, minutes)) - range.start) * PX;
  const isToday = day?.date === day?.now.slice(0, 10);
  const nowMinutes = day ? toMinutes(timeOf(day.now)) : 0;

  useEffect(() => {
    if (!isPhone || phoneList || !isToday || !day) return;
    const key = `${day.date}:${branchId}`;
    if (scrolledFor.current === key) return;
    scrolledFor.current = key;
    nowLine.current?.scrollIntoView({ block: 'center' });
  }, [isPhone, phoneList, isToday, day, branchId]);

  const newAt = (column: DayColumn, event: React.MouseEvent<HTMLDivElement>) => {
    if (!can('agenda.create')) return;

    const offset = event.clientY - event.currentTarget.getBoundingClientRect().top;
    const minutes = range.start + Math.floor(offset / PX / slotInterval) * slotInterval;
    openNew({ date, time: fromMinutes(minutes), ...(column.kind === 'pro' ? { professional_id: column.id } : {}), branch_id: branchId });
  };

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

  const handleDrop = (column: DayColumn, event: React.DragEvent<HTMLDivElement>) => {
    event.preventDefault();
    const dragged = dragging.current?.appointment;
    const time = fromMinutes(dropMinutes(event));
    endDrag();

    if (!dragged) return;
    const professional = column.kind === 'pro' ? { id: column.id, display_name: column.name } : dragged.professional;
    if (!professional) return;
    if (dragged.professional?.id === professional.id && timeOf(dragged.starts_at) === time) return;

    setMoveError('');
    setPendingMove({ appointment: dragged, professional, date, time });
  };

  const confirmMove = async () => {
    if (!pendingMove) return;

    try {
      setMoving(true);
      setMoveError('');
      await api.post(`/appointments/${pendingMove.appointment.id}/reschedule`, {
        starts_at: `${pendingMove.date}T${pendingMove.time}`,
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
          <h1 className="text-2xl font-black text-brand-ink tracking-tight">Agenda</h1>
          <p className="text-sm text-slate-500 font-medium first-letter:uppercase" data-agenda-date={date}>
            {mode === 'week'
              ? `${formatDate(span.from, { day: 'numeric', month: 'short' })} – ${formatDate(span.to, { day: 'numeric', month: 'short', year: 'numeric' })}`
              : mode === 'month'
                ? formatDate(span.month, { month: 'long', year: 'numeric' })
                : formatDate(date)}
          </p>
          <p className="flex items-center gap-1.5 text-xs text-slate-400 mt-0.5">
            <button type="button" title="Actualizar ahora" data-refresh onClick={load} className="hover:text-brand-accent transition-colors">
              <RefreshCw size={12} />
            </button>
            <span data-updated-at>{updatedAt ? `Se actualiza sola · última vez a las ${updatedAt}` : 'Se actualiza sola'}</span>
            {mode !== 'month' && <span className="hidden sm:inline">· Arrastra una cita para moverla</span>}
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

          <div className="flex items-center gap-0.5 p-0.5 bg-white border border-slate-200 rounded-lg" role="radiogroup" aria-label="Vista" data-agenda-modes>
            {MODES.map(([value, label]) => (
              <button
                key={value}
                type="button"
                role="radio"
                aria-checked={mode === value}
                data-agenda-mode={value}
                onClick={() => chooseMode(value)}
                className={`px-3 py-1.5 text-sm font-semibold rounded-md transition-colors ${mode === value ? 'bg-brand text-white' : 'text-slate-600 hover:bg-slate-50'}`}
              >
                {label}
              </button>
            ))}
          </div>

          {mode !== 'day' && (rangeData?.professionals.length ?? 0) > 1 && (
            <div className="w-52" data-pro-filter>
              <Select
                ariaLabel={vocab.pro}
                placeholder={`${vocab.pick('pro', 'Todos los', 'Todas las')} ${suggestPlural(vocab.pro).toLowerCase()}`}
                value={proFilter}
                onChange={(e) => setProFilter(String(e.target.value))}
                options={(rangeData?.professionals ?? []).map((p) => ({ value: p.id, label: p.display_name }))}
              />
            </div>
          )}

          <div className="flex items-center gap-2" data-agenda-nav>
          <button type="button" title={mode === 'day' ? 'Día anterior' : mode === 'week' ? 'Semana anterior' : 'Mes anterior'} onClick={() => setDate(mode === 'day' ? addDays(date, -1) : mode === 'week' ? addDays(date, -7) : addMonths(date, -1))} className={navButton}>
            <ChevronLeft size={16} />
          </button>
          <button
            type="button"
            onClick={() => setDate(todayISO())}
            className="px-3 py-2 text-sm font-semibold border border-slate-200 bg-white rounded-lg text-slate-700 hover:bg-slate-50 transition-colors"
          >
            Hoy
          </button>
          <button type="button" title={mode === 'day' ? 'Día siguiente' : mode === 'week' ? 'Semana siguiente' : 'Mes siguiente'} onClick={() => setDate(mode === 'day' ? addDays(date, 1) : mode === 'week' ? addDays(date, 7) : addMonths(date, 1))} className={navButton}>
            <ChevronRight size={16} />
          </button>
          </div>
          <DateField
            ariaLabel="Ir a una fecha"
            size="compact"
            clearable={false}
            className="w-40"
            value={date}
            onChange={(value) => value && setDate(value)}
          />

          {mode === 'day' && roomsAvailable && (
            <div className="flex items-center gap-0.5 p-0.5 bg-white border border-slate-200 rounded-lg" role="radiogroup" aria-label="Ver por" data-agenda-group>
              {([['pro', vocab.pros], ['room', 'Salas']] as [GroupBy, string][]).map(([value, label]) => (
                <button
                  key={value}
                  type="button"
                  role="radio"
                  aria-checked={groupBy === value}
                  data-agenda-group-by={value}
                  onClick={() => chooseGroup(value)}
                  className={`px-3 py-1.5 text-sm font-semibold rounded-md transition-colors ${groupBy === value ? 'bg-brand text-white' : 'text-slate-600 hover:bg-slate-50'}`}
                >
                  {label}
                </button>
              ))}
            </div>
          )}

          {can('blocks.create') && (
            <button
              type="button"
              data-agenda-block
              onClick={() => openBlock({ time: '13:00', ...(day?.my_professional_id ? { professional_id: day.my_professional_id } : {}) })}
              className="flex items-center gap-2 px-4 py-2 text-sm font-semibold border border-slate-200 bg-white rounded-lg text-slate-700 hover:bg-slate-50 transition-colors"
            >
              <Ban size={16} /> Bloquear horario
            </button>
          )}

          {can('agenda.create') && (<button
            type="button"
            onClick={() => openNew({ date, branch_id: branchId })}
            className="flex items-center gap-2 px-4 py-2 text-sm font-bold bg-brand text-white rounded-lg hover:bg-brand-hover shadow-sm transition-colors"
          >
            <Plus size={16} className="text-brand-soft stroke-[2.5]" /> Nueva cita
          </button>)}
        </div>
      </div>

      <div className="grid grid-cols-3 xl:grid-cols-6 gap-2 sm:gap-4" data-agenda-cards>
        {[{ id: '', title: mode === 'day' ? 'Citas del día' : mode === 'week' ? 'Citas de la semana' : 'Citas del mes', statuses: [] as string[], icon: <CalendarDays size={18} />, color: 'text-slate-500', active: 'border-slate-400 ring-2 ring-slate-400/20' }, ...CARDS].map((item) => {
          const count = item.id === ''
            ? cardAppointments.filter((a) => !RELEASED.includes(a.status)).length
            : cardAppointments.filter((a) => item.statuses.includes(a.status)).length;
          const isActive = activeCard === item.id;

          return (
            <button
              key={item.id || 'all'}
              type="button"
              data-card={item.id || 'all'}
              aria-pressed={isActive}
              onClick={() => setActiveCard(isActive ? '' : item.id)}
              className={`text-left bg-white px-2.5 sm:px-4 py-2 sm:py-3 rounded-2xl border shadow-[0_4px_20px_-4px_rgba(0,0,0,0.05)] transition-all duration-300 cursor-pointer hover:-translate-y-1 hover:shadow-[0_10px_25px_-5px_rgba(0,0,0,0.08)] ${
                isActive ? item.active : 'border-slate-100 hover:border-slate-200'
              }`}
            >
              <div className="flex items-center justify-between">
                <div className="min-w-0">
                  <span className="block sm:inline text-[9px] sm:text-[10px] leading-tight font-semibold tracking-wider text-slate-400 uppercase">{item.title}</span>
                  <div className="mt-0.5 text-xl sm:text-2xl font-extrabold tracking-tight text-slate-900">{count}</div>
                </div>
                <div className={`hidden sm:block p-2 rounded-xl bg-slate-50 border border-slate-100 ${item.color}`}>{item.icon}</div>
              </div>
            </button>
          );
        })}
      </div>

      <ErrorAlert message={error} />

      {mode === 'day' && (day?.holidays ?? []).map((holiday) => (
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
        {mode !== 'day' && branches.length > 0 ? (
          !rangeData || rangeData.from !== span.from ? (
            <p className="p-8 text-sm text-slate-500">Cargando agenda...</p>
          ) : mode === 'week' ? (
            <AgendaWeek
              data={rangeData}
              days={daysBetween(span.from, span.to)}
              appointments={visible}
              professionalId={proFilter}
              colors={proColors}
              isPhone={isPhone}
              canCreate={can('agenda.create')}
              slotInterval={slotInterval}
              onOpen={openDetail}
              onDay={openDay}
              onNew={(newDate, time) => openNew({ date: newDate, time, branch_id: branchId, ...(proFilter ? { professional_id: proFilter } : {}) })}
              onMove={(appointment, newDate, time) => {
                if (!appointment.professional) return;
                setMoveError('');
                setPendingMove({ appointment, professional: appointment.professional, date: newDate, time });
              }}
            />
          ) : (
            <AgendaMonth
              data={rangeData}
              days={daysBetween(span.from, span.to)}
              appointments={visible}
              professionalId={proFilter}
              colors={proColors}
              isPhone={isPhone}
              month={span.month}
              onOpen={openDetail}
              onDay={openDay}
            />
          )
        ) : loading && !day ? (
          <p className="p-8 text-sm text-slate-500">Cargando agenda...</p>
        ) : branches.length === 0 ? (
          <p className="p-8 text-sm text-slate-500">Tu empresa no tiene sedes activas.</p>
        ) : !day || day.professionals.length === 0 ? (
          <div className="p-10 text-center space-y-2">
            <Clock className="w-8 h-8 text-slate-300 mx-auto" />
            <p className="text-sm font-semibold text-slate-700">Nadie atiende este día en esta sede.</p>
            <p className="text-sm text-slate-500">
              Define el horario de cada {vocab.pro.toLowerCase()} en{' '}
              <Link href="/professionals" className="font-semibold text-brand-accent hover:text-brand-ink">{vocab.pros}</Link>.
            </p>
          </div>
        ) : (
          <>
          {isPhone && (
            <div className="border-b border-slate-200 bg-slate-50 p-2.5 space-y-2.5" data-phone-controls>
              <div className="grid grid-cols-2 rounded-xl border border-slate-200 bg-white p-0.5" role="radiogroup" aria-label="Cómo ver el día">
                {([['pro', `Por ${vocab.pro.toLowerCase()}`], ['list', 'Lista']] as [PhoneView, string][]).map(([view, label]) => (
                  <button
                    key={view}
                    type="button"
                    role="radio"
                    aria-checked={phoneView === view}
                    data-phone-view={view}
                    onClick={() => choosePhoneView(view)}
                    className={`py-1.5 rounded-lg text-sm font-semibold truncate ${phoneView === view ? 'bg-brand text-white' : 'text-slate-600'}`}
                  >
                    {label}
                  </button>
                ))}
              </div>
              {!phoneList && (
                <div className="flex gap-1.5 overflow-x-auto -mx-2.5 px-2.5 pb-0.5" data-phone-pros>
                  {professionals.map((professional) => {
                    const selected = focused?.id === professional.id;
                    return (
                      <button
                        key={professional.id}
                        type="button"
                        data-phone-pro={professional.id}
                        aria-pressed={selected}
                        onClick={() => setFocusPro(professional.id)}
                        className={`shrink-0 flex items-center gap-1.5 px-3 py-1.5 rounded-full border text-xs font-semibold whitespace-nowrap ${selected ? 'border-brand bg-brand-soft/20 text-brand-ink' : 'border-slate-200 bg-white text-slate-600'}`}
                      >
                        {professional.name}
                        <span className={`px-1.5 rounded-full text-[11px] ${selected ? 'bg-brand text-white' : 'bg-slate-100 text-slate-500'}`}>{countFor(professional.id)}</span>
                      </button>
                    );
                  })}
                </div>
              )}
            </div>
          )}

          {phoneList ? (
            <ul className="divide-y divide-slate-100" data-phone-list>
              {listed.length === 0 && <li className="p-6 text-center text-sm text-slate-500">No hay citas este día.</li>}
              {listed.map((appointment) => {
                const style = statusStyle(appointment.status);
                return (
                  <li key={appointment.id}>
                    <button type="button" data-list-appointment={appointment.id} onClick={() => openDetail(appointment.id)} className="w-full flex items-start gap-3 px-3 py-2.5 text-left hover:bg-slate-50">
                      <span className="w-12 shrink-0 text-sm font-bold text-brand-ink tabular-nums">{timeOf(appointment.starts_at)}</span>
                      <span className="min-w-0 flex-1">
                        <span className="block text-sm font-semibold text-slate-800 truncate">{appointment.dependent?.name ?? appointment.customer?.name}</span>
                        <span className="block text-xs text-slate-500 truncate">{appointment.service?.name}{appointment.professional ? ` · ${appointment.professional.display_name}` : ''}{appointment.room ? ` · ${appointment.room.name}` : ''}</span>
                      </span>
                      <span className={`shrink-0 flex items-center gap-1 px-2 py-0.5 rounded-full border text-[11px] font-semibold ${style.block}`}>
                        <span className={`w-1.5 h-1.5 rounded-full ${style.dot}`} />{appointment.status_name}
                      </span>
                    </button>
                  </li>
                );
              })}
            </ul>
          ) : (
          <div className="overflow-x-auto">
            <div className={isPhone ? '' : 'min-w-max'}>
              <div className="flex border-b border-slate-200 bg-slate-50">
                <div className="w-11 sm:w-16 shrink-0" />
                {columns.map((professional) => (
                  <div key={professional.id} className="flex-1 min-w-[200px] px-3 py-3 border-l border-slate-200">
                    {professional.kind === 'pro'
                      ? <ProfessionalLink id={professional.id} className="block text-sm font-bold text-slate-800 truncate">{professional.name}</ProfessionalLink>
                      : <span className="block text-sm font-bold text-slate-800 truncate" data-room-column={professional.id}>{professional.name}</span>}
                    <span className="block text-xs text-slate-500">
                      {professional.sub}{professional.kind === 'room' ? ` · ${countFor(professional.id)} ${countFor(professional.id) === 1 ? 'cita' : 'citas'}` : ''}
                    </span>
                    {professional.occupancy?.percent !== null && professional.occupancy?.percent !== undefined && (
                      <div className="mt-1.5 flex items-center gap-2" data-occupancy={professional.occupancy.percent} title={`${professional.occupancy.booked_minutes} de ${professional.occupancy.scheduled_minutes} minutos de su horario tienen citas`}>
                        <div className="flex-1 h-1.5 rounded-full bg-slate-200 overflow-hidden">
                          <div className="h-full rounded-full bg-brand" style={{ width: `${professional.occupancy.percent}%` }} />
                        </div>
                        <span className="text-[11px] font-semibold text-slate-500 shrink-0">{professional.occupancy.percent} % ocupado</span>
                      </div>
                    )}
                  </div>
                ))}
              </div>

              <div className="flex">
                <div className="w-11 sm:w-16 shrink-0 relative" style={{ height }}>
                  {hours.map((minutes) => (
                    <span key={minutes} className="absolute right-1.5 sm:right-2 -translate-y-1/2 text-[11px] font-medium text-slate-400" style={{ top: top(minutes) || 8 }}>
                      {fromMinutes(minutes)}
                    </span>
                  ))}
                </div>

                {columns.map((professional) => (
                  <div
                    key={professional.id}
                    data-column={professional.id}
                    onClick={(event) => newAt(professional, event)}
                    onDragOver={(event) => {
                      if (!dragging.current) return;
                      event.preventDefault();
                      const minutes = dropMinutes(event);
                      setDropTarget((current) =>
                        current?.professionalId === professional.id && current.minutes === minutes ? current : { professionalId: professional.id, minutes });
                    }}
                    onDrop={(event) => handleDrop(professional, event)}
                    title={can('agenda.create') ? 'Clic en un espacio libre para agendar' : undefined}
                    className={`flex-1 min-w-[200px] relative border-l border-slate-200 bg-slate-100 ${can('agenda.create') ? 'cursor-pointer' : ''}`}
                    style={{ height }}
                  >
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

                    {professional.blocks.map((block, index) => {
                      const from = top(toMinutes(block.starts));
                      const to = top(block.ends === '24:00' ? 24 * 60 : toMinutes(block.ends));
                      if (to <= from) return null;

                      if (block.kind === 'class' && block.class_id) {
                        return <AgendaClassBlock key={index} classId={block.class_id} reason={block.reason} top={from} height={to - from} />;
                      }

                      return (
                        <div
                          key={index}
                          data-block
                          onClick={(event) => event.stopPropagation()}
                          title={block.kind === 'held' ? 'Horario apartado mientras revisan el comprobante del adelanto' : block.reason ?? undefined}
                          className={`absolute inset-x-0 border-y px-2 py-1 overflow-hidden cursor-not-allowed ${block.kind === 'held' ? 'bg-amber-50 border-amber-300 border-dashed' : 'bg-slate-200 border-slate-300'}`}
                          style={{ top: from, height: to - from, ...(block.kind === 'held' ? {} : { backgroundImage: HATCH }) }}
                        >
                          {block.kind === 'held'
                            ? <span className="text-[11px] font-semibold text-amber-700">Reserva por confirmar{block.reason ? ` · ${block.reason}` : ''}</span>
                            : <span className="text-[11px] font-semibold text-slate-500">{professional.kind === 'room' ? 'Sala bloqueada' : 'No atiende'}{block.reason ? ` · ${block.reason}` : ''}</span>}
                        </div>
                      );
                    })}

                    {dropTarget?.professionalId === professional.id && dragging.current && (
                      <div
                        data-drop-target
                        className="absolute inset-x-1 rounded-lg border-2 border-dashed border-brand-accent bg-sky-50/70 px-2 py-0.5 pointer-events-none z-20"
                        style={{ top: top(dropTarget.minutes), height: dragging.current.appointment.duration_minutes * PX }}
                      >
                        <span className="text-xs font-bold text-brand-ink">{fromMinutes(dropTarget.minutes)}</span>
                      </div>
                    )}

                    {day?.respect_buffers && visible.filter((a) => inColumn(a, professional) && !RELEASED.includes(a.status)).flatMap((appointment) => {
                      const start = toMinutes(timeOf(appointment.starts_at));
                      const end = toMinutes(timeOf(appointment.ends_at)) || 24 * 60;
                      const parts: { key: string; from: number; to: number; label: string }[] = [];
                      if ((appointment.buffers?.before ?? 0) > 0) parts.push({ key: `${appointment.id}-b`, from: start - appointment.buffers!.before, to: start, label: `Preparación ${appointment.buffers!.before} min` });
                      if ((appointment.buffers?.after ?? 0) > 0) parts.push({ key: `${appointment.id}-a`, from: end, to: end + appointment.buffers!.after, label: `Limpieza ${appointment.buffers!.after} min` });
                      return parts.map((part) => (
                        <div key={part.key} data-buffer title={part.label} className="absolute inset-x-1 rounded border border-dashed border-slate-300 pointer-events-none" style={{ top: top(part.from), height: Math.max(4, top(part.to) - top(part.from)), backgroundImage: HATCH }} />
                      ));
                    })}

                    {withLanes(visible.filter((a) => inColumn(a, professional))).map(({ appointment, lane, lanes }) => {
                      const from = top(toMinutes(timeOf(appointment.starts_at)));
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
                          draggable={appointment.can_reschedule}
                          onDragStart={(event) => {
                            dragging.current = { appointment, grabOffset: event.clientY - event.currentTarget.getBoundingClientRect().top };
                            event.dataTransfer.effectAllowed = 'move';
                            event.dataTransfer.setData('text/plain', appointment.code);
                            setIsDragging(true);
                          }}
                          onDragEnd={endDrag}
                          onClick={(event) => { event.stopPropagation(); openDetail(appointment.id); }}
                          title={`${appointment.code} · ${appointment.customer?.name ?? ''} · ${appointment.service?.name ?? ''}${appointment.room ? ` · ${appointment.room.name}` : ''} · ${appointment.status_name}${MARKS.filter((mark) => mark.test(appointment)).map((mark) => ` · ${mark.label}`).join('')}`}
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
                              {appointment.service?.name}{appointment.dependent ? ` · ${appointment.customer?.name}` : ''}{professional.kind === 'room' ? (appointment.professional ? ` · ${appointment.professional.display_name}` : '') : appointment.room ? ` · ${appointment.room.name}` : ''}
                            </span>
                          )}
                          {blockHeight >= 56 && (
                            <span className="block text-[11px] leading-tight truncate opacity-75">{appointment.status_name}</span>
                          )}
                          {blockHeight >= 36 && MARKS.some((mark) => mark.test(appointment)) && (
                            <span className="flex flex-wrap gap-1 mt-0.5" data-marks>
                              {MARKS.filter((mark) => mark.test(appointment)).map((mark) => (
                                <span key={mark.label} className="px-1 rounded bg-white/70 text-[10px] font-bold leading-tight">{mark.label}</span>
                              ))}
                            </span>
                          )}
                        </button>
                      );
                    })}

                    {isToday && nowMinutes >= range.start && nowMinutes <= range.end && (
                      <div ref={professional.id === columns[0]?.id ? nowLine : undefined} className="absolute inset-x-0 border-t-2 border-rose-500 pointer-events-none z-10" style={{ top: top(nowMinutes) }} />
                    )}
                  </div>
                ))}
              </div>
            </div>
          </div>
          )}
          </>
        )}
      </div>

      {mode === 'day' && withoutRoom > 0 && (
        <p className="text-xs text-slate-500" data-without-room>
          {withoutRoom === 1 ? '1 cita del día no tiene sala' : `${withoutRoom} citas del día no tienen sala`}: no aparecen en esta vista. Míralas en «{vocab.pros}».
        </p>
      )}

      <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-slate-600" data-agenda-legend>
        {['pending', 'confirmed', 'checked_in', 'in_progress', 'completed', 'no_show'].map((status) => (
          <span key={status} className="inline-flex items-center gap-1.5">
            <span className={`w-2.5 h-2.5 rounded-full ${statusStyle(status).dot}`} />
            {statusNames[status] ?? status}
          </span>
        ))}
        <span className="inline-flex items-center gap-1.5">
          <span className="w-5 h-3 rounded border border-dashed border-slate-300" style={{ backgroundImage: HATCH }} />
          Bloqueo, ausencia o preparación y limpieza
        </span>
        <span className="inline-flex items-center gap-1.5">
          <span className="w-5 h-3 rounded border border-slate-200 bg-slate-100" />
          Fuera de horario
        </span>
      </div>

      <FormBlock
        isOpen={blockOpen}
        onClose={() => setBlockOpen(false)}
        initialData={null}
        options={blockOptions}
        preset={blockPreset}
        onSaved={load}
      />

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
                Ahora: <span className="font-semibold text-slate-700">{pendingMove.date !== pendingMove.appointment.starts_at.slice(0, 10) ? `${formatDate(pendingMove.appointment.starts_at.slice(0, 10), { weekday: 'short', day: 'numeric', month: 'short' })}, ` : ''}{timeOf(pendingMove.appointment.starts_at)} con {pendingMove.appointment.professional?.display_name}</span>
              </p>
              <p className="text-slate-500">
                Pasa a: <span className="font-semibold text-slate-900" data-move-to>{pendingMove.date !== pendingMove.appointment.starts_at.slice(0, 10) ? `${formatDate(pendingMove.date, { weekday: 'short', day: 'numeric', month: 'short' })}, ` : ''}{pendingMove.time} con {pendingMove.professional.display_name}</span>
              </p>
            </div>
            <ErrorAlert message={moveError} />
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
