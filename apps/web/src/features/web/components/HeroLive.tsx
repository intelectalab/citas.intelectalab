'use client';

import { useEffect, useState } from 'react';
import { CalendarDays, Check, Globe, MessageCircle } from 'lucide-react';
import { LookIcon } from '@/features/platform/lookIcons';

type Card = { icon: string; title: string; text?: string | null };

const START = 9;
const END = 13;
const HOUR = 56;

const PROS = [
  { name: 'Carla', color: '#3F768C' },
  { name: 'Jorge', color: '#022340' },
];

const BOOKED = [
  { pro: 0, from: 9, to: 10, client: 'Lucía Paredes', service: 'Corte y peinado' },
  { pro: 1, from: 9.5, to: 10.5, client: 'Marco Salas', service: 'Limpieza facial' },
  { pro: 0, from: 10.75, to: 11.75, client: 'Ana Rojas', service: 'Masaje relajante' },
  { pro: 1, from: 12, to: 13, client: 'Diego Quispe', service: 'Manicure' },
];

const ONLINE = [
  { pro: 1, from: 10.75, to: 11.5, client: 'Rosa Medina', service: 'Reservó en línea' },
  { pro: 0, from: 12, to: 12.75, client: 'Pablo Ríos', service: 'Reservó en línea' },
];

const REMINDERS = ['Lucía', 'Marco', 'Ana', 'Diego'];

function clock(hour: number) {
  const h = Math.floor(hour);
  const m = Math.round((hour - h) * 60);
  return `${h}:${String(m).padStart(2, '0')}`;
}

function status(from: number, to: number, now: number) {
  if (to <= now) return { label: 'Atendida', className: 'bg-emerald-50 text-emerald-700' };
  if (from <= now) return { label: 'En atención', className: 'bg-violet-50 text-violet-700' };
  return { label: 'Confirmada', className: 'bg-sky-50 text-sky-700' };
}

export default function HeroLive({ cards }: { cards: Card[] }) {
  const [tick, setTick] = useState(0);

  useEffect(() => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const id = window.setInterval(() => setTick((value) => value + 1), 2600);
    return () => window.clearInterval(id);
  }, []);

  const loop = tick % 14;
  const now = START + 0.25 + loop * 0.25;
  const online = ONLINE.slice(0, loop >= 9 ? 2 : loop >= 4 ? 1 : 0);
  const reminder = REMINDERS[Math.floor(tick / 3) % REMINDERS.length];
  const confirmed = tick % 3 !== 0;
  const active = cards.length ? tick % Math.min(3, cards.length) : 0;

  return (
    <div className="pb-14" data-landing-live>
      {cards.length > 0 && (
        <div className="mb-3 grid grid-cols-3 gap-2" data-landing-cards>
          {cards.slice(0, 3).map((card, index) => (
            <div key={card.title} title={card.text ?? undefined} className={`flex items-center gap-2 rounded-2xl border p-2 sm:p-2.5 transition-colors duration-500 ${index === active ? 'bg-white border-brand-soft shadow-sm' : 'bg-white/50 border-slate-200'}`}>
              <span className={`shrink-0 inline-flex p-1.5 rounded-lg transition-colors duration-500 ${index === active ? 'bg-brand text-brand-soft' : 'bg-brand-soft/20 text-brand-accent'}`}><LookIcon name={card.icon} className="w-4 h-4" /></span>
              <span className="min-w-0 text-[11px] sm:text-xs font-bold text-brand-ink leading-tight">{card.title}</span>
            </div>
          ))}
        </div>
      )}
      <div className="relative">
      <div className="bg-white rounded-3xl border border-slate-200 shadow-xl shadow-brand-ink/10 overflow-hidden">
        <div className="flex items-center justify-between gap-3 px-4 sm:px-5 py-3.5 border-b border-slate-100">
          <span className="flex items-center gap-2.5 min-w-0">
            <span className="p-2 rounded-xl bg-brand-soft/20 text-brand-accent shrink-0"><CalendarDays className="w-4 h-4" /></span>
            <span className="min-w-0">
              <span className="block text-sm font-black text-brand-ink leading-tight">Agenda de hoy</span>
              <span className="block text-[11px] text-slate-500">{BOOKED.length + online.length} citas · 2 profesionales</span>
            </span>
          </span>
          <span className="text-[11px] font-bold text-brand-accent tabular-nums whitespace-nowrap">Ahora {clock(now)}</span>
        </div>

        <div className="px-3 sm:px-4 pt-3 pb-4">
          <div className="grid grid-cols-[2.5rem_1fr_1fr] gap-2 pb-2 text-[11px] font-bold text-slate-500">
            <span />
            {PROS.map((pro) => (
              <span key={pro.name} className="flex items-center gap-1.5 min-w-0">
                <span className="w-5 h-5 rounded-full text-white text-[10px] font-black flex items-center justify-center shrink-0" style={{ background: pro.color }}>{pro.name[0]}</span>
                <span className="truncate">{pro.name}</span>
              </span>
            ))}
          </div>

          <div className="relative grid grid-cols-[2.5rem_1fr_1fr] gap-2" style={{ height: (END - START) * HOUR }}>
            <div className="relative">
              {Array.from({ length: END - START }, (_, index) => (
                <span key={index} className="absolute left-0 text-[10px] font-semibold text-slate-400 tabular-nums" style={{ top: index * HOUR }}>{START + index}:00</span>
              ))}
            </div>
            {PROS.map((pro, proIndex) => (
              <div key={pro.name} className="relative rounded-xl bg-slate-50/80 site-hours">
                {[...BOOKED, ...online].filter((item) => item.pro === proIndex).map((item) => {
                  const isOnline = ONLINE.includes(item);
                  const state = isOnline ? { label: 'Nueva', className: 'bg-brand-soft/30 text-brand-ink' } : status(item.from, item.to, now);
                  return (
                    <div
                      key={item.client}
                      className={`absolute inset-x-1 rounded-lg border-l-[3px] bg-white px-2 py-1 overflow-hidden shadow-xs ${isOnline ? 'site-pop site-flash' : ''}`}
                      style={{ top: (item.from - START) * HOUR + 2, height: (item.to - item.from) * HOUR - 4, borderColor: isOnline ? 'var(--brand-soft)' : pro.color }}
                    >
                      <span className="flex items-center justify-between gap-1">
                        <span className="text-[11px] font-bold text-brand-ink truncate">{item.client}</span>
                        {isOnline && <Globe className="w-3 h-3 text-brand-accent shrink-0" />}
                      </span>
                      <span className="block text-[10px] text-slate-500 truncate">{item.service}</span>
                      <span key={state.label} className={`hidden sm:inline-block mt-0.5 text-[9px] font-bold rounded px-1.5 py-px site-pop ${state.className}`}>{state.label}</span>
                    </div>
                  );
                })}
              </div>
            ))}

            <div className="site-now absolute left-9 right-0 flex items-center pointer-events-none" style={{ top: (now - START) * HOUR }} aria-hidden="true">
              <span className="w-2 h-2 rounded-full bg-rose-500 site-breathe -ml-1 shrink-0" />
              <span className="flex-1 h-px bg-rose-400" />
            </div>
          </div>
        </div>
      </div>

      <div key={reminder} className="absolute -bottom-14 right-2 sm:-right-5 w-60 bg-white rounded-2xl rounded-tr-md border border-slate-200 shadow-lg p-3 site-pop" data-landing-reminder>
        <span className="flex items-center gap-1.5 text-[10px] font-bold text-emerald-700"><MessageCircle className="w-3.5 h-3.5" /> Recordatorio enviado</span>
        <span className="block mt-1 text-xs text-slate-600 leading-snug">Hola {reminder}, te esperamos mañana a las 9:00 am. ¿Confirmas tu cita?</span>
        {confirmed && <span className="mt-1.5 inline-flex items-center gap-1 text-[11px] font-bold text-brand-ink bg-brand-soft/25 rounded-full px-2 py-0.5 site-pop"><Check className="w-3 h-3" /> {reminder} confirmó</span>}
      </div>
      </div>

      <span className="sr-only">Ejemplo de la agenda del día de dos profesionales, con citas que cambian de estado y reservas en línea que llegan.</span>
    </div>
  );
}
