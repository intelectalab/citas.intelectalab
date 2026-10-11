'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { AlertTriangle, CalendarOff, Clock, Loader2, Pencil, Store, Users } from 'lucide-react';
import { api } from '@/lib/axios';
import { useVocabulary } from '@/hooks/useVocabulary';
import { apiError, toMinutes } from '@/features/appointments/appointments';
import { WEEK } from '@/features/branches/branches';
import ProfessionalSchedule from './ProfessionalSchedule';
import { ProfessionalLink } from '@/app/components/common/PersonLink';

interface Range { weekday: number; starts: string; ends: string }
interface Member { id: string; display_name: string; weekly_minutes: number; ranges: Range[] }
interface BranchSchedule {
  id: string;
  name: string;
  hours: Record<string, { opens: string; closes: string }[]>;
  professionals: Member[];
}
interface TeamData {
  can_edit: boolean;
  branches: BranchSchedule[];
  without_schedule: { id: string; display_name: string }[];
}

const card = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs';

// Un tono por persona, de la paleta, para reconocerla en la semana.
const TONES = [
  { bar: 'bg-sky-400', soft: 'bg-sky-50 text-sky-700' },
  { bar: 'bg-emerald-400', soft: 'bg-emerald-50 text-emerald-700' },
  { bar: 'bg-violet-400', soft: 'bg-violet-50 text-violet-700' },
  { bar: 'bg-amber-400', soft: 'bg-amber-50 text-amber-700' },
  { bar: 'bg-rose-400', soft: 'bg-rose-50 text-rose-700' },
  { bar: 'bg-teal-400', soft: 'bg-teal-50 text-teal-700' },
];

const initials = (name: string) =>
  name.replace(/^(Dra?|Ps|Lic|Tec)\.\s+/i, '').split(' ').filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();

const hoursLabel = (minutes: number) => {
  const hours = minutes / 60;
  return `${Number.isInteger(hours) ? hours : hours.toFixed(1)} h`;
};

// Horarios de todo el equipo en una semana, por sede: quién atiende cada día y
// a qué hora, sobre una línea de tiempo. El lápiz abre el horario de la persona.
export default function TeamSchedules() {
  const vocab = useVocabulary();
  const [data, setData] = useState<TeamData | null>(null);
  const [error, setError] = useState('');
  const [branchId, setBranchId] = useState<string | null>(null);
  const [editing, setEditing] = useState<{ id: string; display_name: string } | null>(null);

  const load = useCallback(() => {
    api.get('/schedules')
      .then((res) => {
        setData(res.data);
        setBranchId((current) => (res.data.branches.some((b: BranchSchedule) => b.id === current) ? current : res.data.branches[0]?.id ?? null));
        setError('');
      })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar los horarios.')));
  }, []);

  useEffect(() => { load(); }, [load]);

  const branch = data?.branches.find((b) => b.id === branchId) ?? null;

  // La línea de tiempo va de la primera a la última hora que alguien usa en la sede.
  const [from, to] = useMemo(() => {
    if (!branch) return [8 * 60, 20 * 60];
    const points = [
      ...branch.professionals.flatMap((p) => p.ranges.flatMap((r) => [toMinutes(r.starts), toMinutes(r.ends)])),
      ...Object.values(branch.hours).flat().flatMap((h) => [toMinutes(h.opens), toMinutes(h.closes)]),
    ];
    if (points.length === 0) return [8 * 60, 20 * 60];
    return [Math.floor(Math.min(...points) / 60) * 60, Math.ceil(Math.max(...points) / 60) * 60];
  }, [branch]);

  const left = (time: string) => `${((toMinutes(time) - from) * 100) / (to - from)}%`;
  const width = (starts: string, ends: string) => `${((toMinutes(ends) - toMinutes(starts)) * 100) / (to - from)}%`;
  const today = new Date().getDay();

  if (!data) {
    return error
      ? <p role="alert" className="text-sm text-red-600">{error}</p>
      : <div className="min-h-[50vh] flex items-center justify-center"><Loader2 className="w-8 h-8 text-[#3F768C] animate-spin" /></div>;
  }

  const stats = branch ? [
    { id: 'people', title: `${vocab.pros} en la sede`, value: branch.professionals.length, icon: <Users size={18} /> },
    { id: 'hours', title: 'Horas de atención por semana', value: hoursLabel(branch.professionals.reduce((sum, p) => sum + p.weekly_minutes, 0)), icon: <Clock size={18} /> },
    { id: 'today', title: 'Atienden hoy', value: branch.professionals.filter((p) => p.ranges.some((r) => r.weekday === today)).length, icon: <Store size={18} /> },
  ] : [];

  return (
    <div className="space-y-5 pb-8 font-sans" data-team-schedules>
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-[#022340] tracking-tight">Horarios del equipo</h1>
          <p className="text-sm text-slate-500 font-medium">Quién atiende cada día y a qué hora. Una persona atiende en una sede cuando tiene horario ahí.</p>
        </div>
        <Link href="/professionals" className="text-sm font-bold text-[#3F768C] hover:text-[#022340] transition-colors">Ver {vocab.pros.toLowerCase()}</Link>
      </div>

      {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}

      {data.branches.length > 1 && (
        <div className="flex flex-wrap gap-2" data-branch-tabs>
          {data.branches.map((item) => (
            <button
              key={item.id}
              type="button"
              data-branch={item.id}
              onClick={() => setBranchId(item.id)}
              className={`flex items-center gap-2 px-3.5 py-2 rounded-xl text-sm font-semibold border transition-colors ${
                item.id === branchId ? 'bg-[#022340] border-[#022340] text-white shadow-sm' : 'bg-white border-slate-200 text-slate-600 hover:border-slate-300'
              }`}
            >
              {item.name}
              <span className={`px-1.5 py-0.5 rounded-md text-[11px] font-bold ${item.id === branchId ? 'bg-white/15 text-white' : 'bg-slate-100 text-slate-500'}`}>{item.professionals.length}</span>
            </button>
          ))}
        </div>
      )}

      {data.without_schedule.length > 0 && (
        <div className="flex items-start gap-2 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3" data-without-schedule>
          <AlertTriangle size={16} className="mt-0.5 shrink-0" />
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <span>Sin horario en ninguna sede (la agenda no les ofrece citas):</span>
            {data.without_schedule.map((person) => (
              data.can_edit
                ? <button key={person.id} type="button" onClick={() => setEditing(person)} className="font-bold underline underline-offset-2 hover:text-amber-950">{person.display_name}</button>
                : <span key={person.id} className="font-bold">{person.display_name}</span>
            ))}
          </div>
        </div>
      )}

      {branch && (
        <>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            {stats.map((stat) => (
              <div key={stat.id} data-stat={stat.id} className="bg-white px-4 py-3 rounded-2xl border border-slate-100 shadow-[0_4px_20px_-4px_rgba(0,0,0,0.05)] flex items-start justify-between gap-2">
                <div>
                  <span className="text-[10px] font-semibold tracking-wider text-slate-400 uppercase">{stat.title}</span>
                  <div className="mt-0.5 text-2xl font-extrabold tracking-tight text-slate-900">{stat.value}</div>
                </div>
                <span className="text-[#3F768C]">{stat.icon}</span>
              </div>
            ))}
          </div>

          <section className={`${card} overflow-hidden`} data-week>
            <div className="overflow-x-auto">
              <div className="min-w-[980px]">
                {/* Días, con el horario de la sede */}
                <div className="grid grid-cols-[220px_repeat(7,minmax(0,1fr))] border-b border-slate-200 bg-slate-50/70">
                  <div className="px-5 py-3 text-[11px] font-bold uppercase tracking-wider text-slate-400 flex items-end">{vocab.pro}</div>
                  {WEEK.map((day) => {
                    const hours = branch.hours[String(day.weekday)] ?? [];
                    const limits = Object.keys(branch.hours).length > 0;

                    return (
                      <div key={day.weekday} className={`px-2 py-3 text-center border-l border-slate-200/70 ${day.weekday === today ? 'bg-[#84D0D9]/15' : ''}`}>
                        <span className={`block text-xs font-bold ${day.weekday === today ? 'text-[#022340]' : 'text-slate-700'}`}>{day.label}</span>
                        <span className="block text-[10px] text-slate-400 mt-0.5">
                          {!limits ? 'Sin horario de sede' : hours.length ? hours.map((h) => `${h.opens}–${h.closes}`).join(' · ') : 'Cerrada'}
                        </span>
                      </div>
                    );
                  })}
                </div>

                {branch.professionals.length === 0 && (
                  <div className="p-10 text-center space-y-2">
                    <CalendarOff className="w-8 h-8 text-slate-300 mx-auto" />
                    <p className="text-sm font-semibold text-slate-700">Nadie atiende en esta sede todavía.</p>
                    <p className="text-sm text-slate-500">Dale horario a alguien aquí desde {vocab.pros}.</p>
                  </div>
                )}

                {branch.professionals.map((person, index) => {
                  const tone = TONES[index % TONES.length];

                  return (
                    <div key={person.id} data-member={person.display_name} className="grid grid-cols-[220px_repeat(7,minmax(0,1fr))] border-b border-slate-100 last:border-b-0 hover:bg-slate-50/50 transition-colors group">
                      <div className="px-5 py-3 flex items-center gap-3 min-w-0">
                        <span className={`w-9 h-9 rounded-xl flex items-center justify-center text-xs font-black shrink-0 ${tone.soft}`}>{initials(person.display_name)}</span>
                        <div className="min-w-0 flex-1">
                          <ProfessionalLink id={person.id} className="block text-sm font-bold text-slate-800 truncate">{person.display_name}</ProfessionalLink>
                          <span className="block text-[11px] text-slate-500">{hoursLabel(person.weekly_minutes)} a la semana</span>
                        </div>
                        {data.can_edit && (
                          <button type="button" data-action="edit-schedule" title={`Cambiar el horario de ${person.display_name}`} onClick={() => setEditing(person)} className="p-1.5 text-slate-300 group-hover:text-slate-500 hover:!text-[#022340] hover:bg-slate-100 rounded-lg transition-colors shrink-0">
                            <Pencil size={14} />
                          </button>
                        )}
                      </div>

                      {WEEK.map((day) => {
                        const ranges = person.ranges.filter((r) => r.weekday === day.weekday);
                        const sede = branch.hours[String(day.weekday)] ?? [];

                        return (
                          <div key={day.weekday} data-day={day.weekday} className={`px-2 py-3 border-l border-slate-100 flex flex-col justify-center gap-1.5 ${day.weekday === today ? 'bg-[#84D0D9]/10' : ''}`}>
                            {ranges.length === 0 ? (
                              <span className="block text-center text-xs text-slate-300">—</span>
                            ) : (
                              <>
                                {/* Línea de tiempo del día: en gris claro, cuándo abre la sede. */}
                                <div className="relative h-2 rounded-full bg-slate-100 overflow-hidden">
                                  {sede.map((h, i) => <span key={i} className="absolute inset-y-0 bg-slate-200" style={{ left: left(h.opens), width: width(h.opens, h.closes) }} />)}
                                  {ranges.map((r, i) => <span key={i} className={`absolute inset-y-0 rounded-full ${tone.bar}`} style={{ left: left(r.starts), width: width(r.starts, r.ends) }} />)}
                                </div>
                                <span className="block text-center text-[11px] font-semibold text-slate-600 leading-tight">
                                  {ranges.map((r) => `${r.starts}–${r.ends}`).join(' · ')}
                                </span>
                              </>
                            )}
                          </div>
                        );
                      })}
                    </div>
                  );
                })}
              </div>
            </div>

            <div className="px-5 py-3 border-t border-slate-100 text-[11px] text-slate-400 flex flex-wrap items-center justify-between gap-2">
              <span>Cada barra va de las {String(from / 60).padStart(2, '0')}:00 a las {String(to / 60).padStart(2, '0')}:00. El fondo gris marca cuándo abre la sede.</span>
              <span>Las ausencias y vacaciones se ven en Bloqueos y ausencias.</span>
            </div>
          </section>
        </>
      )}

      <ProfessionalSchedule
        isOpen={editing !== null}
        onClose={() => { setEditing(null); load(); }}
        professional={editing}
        initialBranchId={branchId}
      />
    </div>
  );
}
