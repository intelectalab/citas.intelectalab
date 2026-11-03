'use client';

import { useMemo, useRef, useState } from 'react';
import Stopwatch from './Stopwatch';
import {
  RELEASED, addDays, dateOf, formatDate, fromMinutes, statusStyle, timeOf, toMinutes,
  type Appointment,
} from '../appointments';

export interface RangeProfessional {
  id: string;
  display_name: string;
  color_code: string | null;
  schedules: { weekday: number; starts: string; ends: string }[];
}

export interface RangePayload {
  from: string;
  to: string;
  now: string;
  holidays: { date: string; name: string; closed: boolean }[];
  professionals: RangeProfessional[];
  blocks: { professional_id: string | null; starts: string; ends: string; reason: string | null }[];
  my_professional_id?: string | null;
  appointments: Appointment[];
}

export const WEEKDAY_NAMES = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

export const weekdayOf = (date: string) => new Date(`${date}T12:00:00`).getDay();

export const mondayOf = (date: string) => addDays(date, -((weekdayOf(date) + 6) % 7));

export const addMonths = (date: string, months: number) => {
  const [y, m] = date.split('-').map(Number);
  const target = new Date(y, m - 1 + months, 1);
  return `${target.getFullYear()}-${String(target.getMonth() + 1).padStart(2, '0')}-01`;
};

export const monthGrid = (date: string) => {
  const first = `${date.slice(0, 7)}-01`;
  const start = mondayOf(first);
  const last = addDays(addMonths(first, 1), -1);
  const end = addDays(mondayOf(last), 6);
  return { first, start, end };
};

export const daysBetween = (from: string, to: string) => {
  const days: string[] = [];
  for (let day = from; day <= to; day = addDays(day, 1)) days.push(day);
  return days;
};

const PX = 1.2;

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

const scheduleFor = (data: RangePayload, date: string, professionalId: string) => {
  const weekday = weekdayOf(date);
  return data.professionals
    .filter((p) => !professionalId || p.id === professionalId)
    .flatMap((p) => p.schedules.filter((s) => s.weekday === weekday));
};

const closedOn = (data: RangePayload, date: string) => data.holidays.find((h) => h.date === date && h.closed);

interface ViewProps {
  data: RangePayload;
  days: string[];
  appointments: Appointment[];
  professionalId: string;
  colors: Record<string, string | null>;
  isPhone: boolean;
  onOpen: (id: string) => void;
}

interface WeekProps extends ViewProps {
  canCreate: boolean;
  slotInterval: number;
  onNew: (date: string, time: string) => void;
  onMove: (appointment: Appointment, date: string, time: string) => void;
  onDay: (date: string) => void;
}

export function AgendaWeek({ data, days, appointments, professionalId, colors, isPhone, canCreate, slotInterval, onOpen, onNew, onMove, onDay }: WeekProps) {
  const dragging = useRef<{ appointment: Appointment; grabOffset: number } | null>(null);
  const [dropTarget, setDropTarget] = useState<{ date: string; minutes: number } | null>(null);
  const today = dateOf(data.now);

  const range = useMemo(() => {
    const starts: number[] = [];
    const ends: number[] = [];
    data.professionals.filter((p) => !professionalId || p.id === professionalId)
      .forEach((p) => p.schedules.forEach((s) => { starts.push(toMinutes(s.starts)); ends.push(toMinutes(s.ends)); }));
    appointments.forEach((a) => { starts.push(toMinutes(timeOf(a.starts_at))); ends.push(toMinutes(timeOf(a.ends_at)) || 24 * 60); });
    if (!starts.length) return { start: 8 * 60, end: 20 * 60 };
    return { start: Math.floor(Math.min(...starts) / 60) * 60, end: Math.min(24 * 60, Math.ceil(Math.max(...ends) / 60) * 60) };
  }, [data, appointments, professionalId]);

  const hours = Array.from({ length: (range.end - range.start) / 60 }, (_, i) => range.start + i * 60);
  const height = (range.end - range.start) * PX;
  const top = (minutes: number) => (Math.max(range.start, Math.min(range.end, minutes)) - range.start) * PX;
  const nowMinutes = toMinutes(timeOf(data.now));

  const minutesAt = (event: React.MouseEvent<HTMLDivElement> | React.DragEvent<HTMLDivElement>, grab = 0, duration = 0) => {
    const offset = event.clientY - event.currentTarget.getBoundingClientRect().top - grab;
    const minutes = range.start + Math.round(offset / PX / slotInterval) * slotInterval;
    return Math.max(range.start, Math.min(range.end - duration, minutes));
  };

  if (isPhone) {
    return (
      <ul className="divide-y divide-slate-100" data-week-list>
        {days.map((day) => {
          const items = appointments.filter((a) => dateOf(a.starts_at) === day).sort((a, b) => a.starts_at.localeCompare(b.starts_at));
          const closed = closedOn(data, day);
          return (
            <li key={day} data-week-day={day}>
              <button type="button" onClick={() => onDay(day)} className={`w-full flex items-center justify-between px-3 py-2 text-left ${day === today ? 'bg-brand-soft/20' : 'bg-slate-50'}`}>
                <span className="text-sm font-bold text-brand-ink first-letter:uppercase">{formatDate(day, { weekday: 'long', day: 'numeric', month: 'short' })}</span>
                <span className="text-xs text-slate-500">{closed ? `Feriado · ${closed.name}` : `${items.filter((a) => !RELEASED.includes(a.status)).length} citas`}</span>
              </button>
              {items.map((appointment) => {
                const style = statusStyle(appointment.status);
                return (
                  <button key={appointment.id} type="button" data-list-appointment={appointment.id} onClick={() => onOpen(appointment.id)} className="w-full flex items-start gap-3 px-3 py-2 text-left hover:bg-slate-50" style={{ borderLeft: `4px solid ${colors[appointment.professional?.id ?? ''] ?? '#cbd5e1'}` }}>
                    <span className="w-12 shrink-0 text-sm font-bold text-brand-ink tabular-nums">{timeOf(appointment.starts_at)}</span>
                    <span className="min-w-0 flex-1">
                      <span className="block text-sm font-semibold text-slate-800 truncate">{appointment.dependent?.name ?? appointment.customer?.name}</span>
                      <span className="block text-xs text-slate-500 truncate">{appointment.service?.name}{appointment.professional ? ` · ${appointment.professional.display_name}` : ''}</span>
                    </span>
                    <span className={`shrink-0 flex items-center gap-1 px-2 py-0.5 rounded-full border text-[11px] font-semibold ${style.block}`}>
                      <span className={`w-1.5 h-1.5 rounded-full ${style.dot}`} />{appointment.status_name}
                    </span>
                  </button>
                );
              })}
            </li>
          );
        })}
      </ul>
    );
  }

  return (
    <div className="overflow-x-auto" data-week>
      <div className="min-w-[900px]">
        <div className="flex border-b border-slate-200 bg-slate-50">
          <div className="w-14 shrink-0" />
          {days.map((day, index) => {
            const closed = closedOn(data, day);
            const holiday = data.holidays.find((h) => h.date === day);
            return (
              <button key={day} type="button" data-week-head={day} onClick={() => onDay(day)} title="Ver este día" className={`flex-1 px-2 py-2 border-l border-slate-200 text-left hover:bg-white ${day === today ? 'bg-brand-soft/20' : ''}`}>
                <span className="block text-[11px] font-bold uppercase text-slate-400">{WEEKDAY_NAMES[index]}</span>
                <span className={`block text-lg font-extrabold leading-tight ${day === today ? 'text-brand-accent' : 'text-slate-800'}`}>{Number(day.slice(8, 10))}</span>
                {holiday && <span className={`block text-[10px] font-semibold truncate ${closed ? 'text-rose-700' : 'text-sky-700'}`}>{closed ? 'Cerrado' : 'Feriado'} · {holiday.name}</span>}
              </button>
            );
          })}
        </div>

        <div className="flex">
          <div className="w-14 shrink-0 relative" style={{ height }}>
            {hours.map((minutes) => (
              <span key={minutes} className="absolute right-2 -translate-y-1/2 text-[11px] font-medium text-slate-400" style={{ top: top(minutes) || 8 }}>{fromMinutes(minutes)}</span>
            ))}
          </div>

          {days.map((day) => {
            const closed = closedOn(data, day);
            const items = appointments.filter((a) => dateOf(a.starts_at) === day);
            const dayBlocks = data.blocks.filter((b) => (!b.professional_id || b.professional_id === professionalId) && dateOf(b.starts) <= day && dateOf(b.ends) >= day);

            return (
              <div
                key={day}
                data-week-column={day}
                onClick={(event) => { if (canCreate && !closed) onNew(day, fromMinutes(minutesAt(event))); }}
                onDragOver={(event) => {
                  if (!dragging.current || closed) return;
                  event.preventDefault();
                  const minutes = minutesAt(event, dragging.current.grabOffset, dragging.current.appointment.duration_minutes);
                  setDropTarget((current) => (current?.date === day && current.minutes === minutes ? current : { date: day, minutes }));
                }}
                onDrop={(event) => {
                  event.preventDefault();
                  const dragged = dragging.current;
                  setDropTarget(null);
                  dragging.current = null;
                  if (!dragged || closed) return;
                  const time = fromMinutes(minutesAt(event, dragged.grabOffset, dragged.appointment.duration_minutes));
                  if (dateOf(dragged.appointment.starts_at) === day && timeOf(dragged.appointment.starts_at) === time) return;
                  onMove(dragged.appointment, day, time);
                }}
                title={canCreate && !closed ? 'Clic en un espacio libre para agendar' : undefined}
                className={`flex-1 relative border-l border-slate-200 ${closed ? 'bg-rose-50/60' : 'bg-slate-100'} ${canCreate && !closed ? 'cursor-pointer' : ''}`}
                style={{ height }}
              >
                {!closed && scheduleFor(data, day, professionalId).map((schedule, index) => (
                  <div key={index} className="absolute inset-x-0 bg-white pointer-events-none" style={{ top: top(toMinutes(schedule.starts)), height: top(toMinutes(schedule.ends)) - top(toMinutes(schedule.starts)) }} />
                ))}
                {hours.map((minutes) => (
                  <div key={minutes} className="absolute inset-x-0 border-t border-slate-200/70 pointer-events-none" style={{ top: top(minutes) }} />
                ))}
                {professionalId && dayBlocks.map((block, index) => {
                  const from = top(dateOf(block.starts) < day ? 0 : toMinutes(timeOf(block.starts)));
                  const to = top(dateOf(block.ends) > day ? 24 * 60 : toMinutes(timeOf(block.ends)));
                  if (to <= from) return null;
                  return (
                    <div key={index} onClick={(event) => event.stopPropagation()} className="absolute inset-x-0 bg-slate-200 border-y border-slate-300 px-1.5 py-0.5 overflow-hidden cursor-not-allowed" style={{ top: from, height: to - from }}>
                      <span className="text-[10px] font-semibold text-slate-500">No atiende{block.reason ? ` · ${block.reason}` : ''}</span>
                    </div>
                  );
                })}
                {dropTarget?.date === day && dragging.current && (
                  <div data-drop-target className="absolute inset-x-1 rounded-lg border-2 border-dashed border-brand-accent bg-sky-50/70 px-1.5 pointer-events-none z-20" style={{ top: top(dropTarget.minutes), height: dragging.current.appointment.duration_minutes * PX }}>
                    <span className="text-[11px] font-bold text-brand-ink">{fromMinutes(dropTarget.minutes)}</span>
                  </div>
                )}
                {withLanes(items).map(({ appointment, lane, lanes }) => {
                  const from = top(toMinutes(timeOf(appointment.starts_at)));
                  const blockHeight = Math.max(20, appointment.duration_minutes * PX - 2);
                  const style = statusStyle(appointment.status);
                  const timing = appointment.timing;
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
                      }}
                      onDragEnd={() => { dragging.current = null; setDropTarget(null); }}
                      onClick={(event) => { event.stopPropagation(); onOpen(appointment.id); }}
                      title={`${timeOf(appointment.starts_at)} · ${appointment.customer?.name ?? ''} · ${appointment.service?.name ?? ''} · ${appointment.professional?.display_name ?? ''} · ${appointment.status_name}`}
                      className={`absolute rounded-md border px-1.5 py-0.5 text-left overflow-hidden shadow-xs hover:shadow-md transition-shadow ${appointment.can_reschedule ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer'} ${style.block} ${RELEASED.includes(appointment.status) ? 'opacity-70' : ''}`}
                      style={{
                        top: from + 1,
                        height: blockHeight,
                        left: `calc(${(lane / lanes) * 100}% + 2px)`,
                        width: `calc(${100 / lanes}% - 4px)`,
                        borderLeft: `4px solid ${colors[appointment.professional?.id ?? ''] ?? 'currentColor'}`,
                      }}
                    >
                      <span className="flex items-center gap-1 text-[11px] font-bold leading-tight">
                        <span className="truncate">{timeOf(appointment.starts_at)} {appointment.dependent?.name ?? appointment.customer?.name}</span>
                        {timing.running && (
                          <Stopwatch seconds={(timing.running === 'service' ? timing.service_seconds : timing.wait_seconds) ?? 0} running limitSeconds={timing.running === 'service' ? timing.planned_seconds : null} className="ml-auto shrink-0 px-1 rounded bg-white/70 text-[10px] font-bold" />
                        )}
                      </span>
                      {blockHeight >= 34 && <span className="block text-[10px] leading-tight truncate opacity-90">{appointment.service?.name}</span>}
                      {blockHeight >= 48 && !professionalId && <span className="block text-[10px] leading-tight truncate opacity-75">{appointment.professional?.display_name}</span>}
                    </button>
                  );
                })}
                {day === today && nowMinutes >= range.start && nowMinutes <= range.end && (
                  <div className="absolute inset-x-0 border-t-2 border-rose-500 pointer-events-none z-10" style={{ top: top(nowMinutes) }} />
                )}
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}

interface MonthProps extends ViewProps {
  month: string;
  onDay: (date: string) => void;
}

export function AgendaMonth({ data, days, appointments, professionalId, colors, isPhone, month, onOpen, onDay }: MonthProps) {
  const today = dateOf(data.now);
  const byDay = useMemo(() => {
    const map: Record<string, Appointment[]> = {};
    appointments.forEach((a) => { (map[dateOf(a.starts_at)] ??= []).push(a); });
    Object.values(map).forEach((list) => list.sort((a, b) => a.starts_at.localeCompare(b.starts_at)));
    return map;
  }, [appointments]);

  const occupancy = (day: string) => {
    if (closedOn(data, day)) return null;
    const scheduled = scheduleFor(data, day, professionalId).reduce((sum, s) => sum + toMinutes(s.ends) - toMinutes(s.starts), 0);
    if (scheduled <= 0) return null;
    const booked = (byDay[day] ?? []).filter((a) => !RELEASED.includes(a.status)).reduce((sum, a) => sum + a.duration_minutes, 0);
    return Math.min(100, Math.round((booked * 100) / scheduled));
  };

  return (
    <div data-month>
      <div className="grid grid-cols-7 border-b border-slate-200 bg-slate-50">
        {WEEKDAY_NAMES.map((name) => <span key={name} className="px-2 py-2 text-[11px] font-bold uppercase text-slate-400 text-center sm:text-left">{name}</span>)}
      </div>
      <div className="grid grid-cols-7">
        {days.map((day) => {
          const items = (byDay[day] ?? []).filter((a) => !RELEASED.includes(a.status));
          const holiday = data.holidays.find((h) => h.date === day);
          const inMonth = day.slice(0, 7) === month.slice(0, 7);
          const occ = occupancy(day);

          if (isPhone) {
            return (
              <button key={day} type="button" data-month-day={day} onClick={() => onDay(day)} className={`h-14 border-b border-l border-slate-100 flex flex-col items-center justify-center gap-1 ${inMonth ? '' : 'opacity-40'} ${day === today ? 'bg-brand-soft/20' : ''}`}>
                <span className={`text-sm font-bold ${day === today ? 'text-brand-accent' : holiday?.closed ? 'text-rose-600' : 'text-slate-700'}`}>{Number(day.slice(8, 10))}</span>
                {items.length > 0 && <span className="flex items-center gap-0.5 text-[10px] font-semibold text-slate-500"><span className="w-1.5 h-1.5 rounded-full bg-[var(--accent)]" />{items.length}</span>}
              </button>
            );
          }

          return (
            <div
              key={day}
              role="button"
              tabIndex={0}
              data-month-day={day}
              onClick={() => onDay(day)}
              onKeyDown={(event) => { if (event.key === 'Enter') onDay(day); }}
              title="Ver este día"
              className={`min-h-28 border-b border-l border-slate-100 p-1.5 text-left cursor-pointer hover:bg-slate-50 transition-colors ${inMonth ? 'bg-white' : 'bg-slate-50/70 opacity-60'} ${holiday?.closed ? 'bg-rose-50/60' : ''}`}
            >
              <div className="flex items-center justify-between gap-1">
                <span className={`text-sm font-bold ${day === today ? 'px-1.5 rounded-full bg-brand text-white' : 'text-slate-700'}`}>{Number(day.slice(8, 10))}</span>
                {items.length > 0 && <span className="text-[11px] font-semibold text-slate-500">{items.length} {items.length === 1 ? 'cita' : 'citas'}</span>}
              </div>
              {holiday && <span className={`block text-[10px] font-semibold truncate ${holiday.closed ? 'text-rose-700' : 'text-sky-700'}`}>{holiday.closed ? 'Cerrado' : 'Feriado'} · {holiday.name}</span>}
              {occ !== null && (
                <span className="mt-1 block h-1 rounded-full bg-slate-100 overflow-hidden" title={`Ocupación ${occ} %`} data-occupancy={occ}>
                  <span className="block h-full rounded-full bg-[var(--accent)]" style={{ width: `${Math.max(occ ? 4 : 0, occ)}%` }} />
                </span>
              )}
              <div className="mt-1 space-y-0.5">
                {items.slice(0, 3).map((appointment) => (
                  <button
                    key={appointment.id}
                    type="button"
                    data-month-appointment={appointment.id}
                    onClick={(event) => { event.stopPropagation(); onOpen(appointment.id); }}
                    className="w-full flex items-center gap-1 rounded px-1 text-[11px] text-slate-700 hover:bg-slate-100 truncate"
                  >
                    <span className="w-1.5 h-1.5 rounded-full shrink-0" style={{ background: colors[appointment.professional?.id ?? ''] ?? '#94a3b8' }} />
                    <span className="font-semibold tabular-nums">{timeOf(appointment.starts_at)}</span>
                    <span className="truncate">{appointment.dependent?.name ?? appointment.customer?.name}</span>
                  </button>
                ))}
                {items.length > 3 && <span className="block px-1 text-[11px] font-semibold text-brand-accent">+{items.length - 3} más</span>}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
