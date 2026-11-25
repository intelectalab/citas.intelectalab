'use client';

import { useState } from 'react';
import Link from 'next/link';
import { Ban, CalendarDays, CalendarRange, ChevronRight, ClipboardList, Loader2, Target, Users } from 'lucide-react';
import { api } from '@/lib/axios';
import { formatMoney } from '@/lib/currency';
import { useVocabulary } from '@/hooks/useVocabulary';
import { ErrorAlert } from '@/app/components/common/Alert';
import FormBlock from '@/features/blocks/components/FormBlock';
import type { BlockOptions } from '@/features/blocks/blocks';
import { apiError, formatDate, statusStyle, timeOf } from '@/features/appointments/appointments';
import { CustomerLink } from '@/app/components/common/PersonLink';

export interface MyDayData {
  professional: { id: string; name: string };
  appointments: {
    id: string; code: string | null; starts_at: string; ends_at: string; who: string | null; customer_id: string | null;
    service: string | null; branch: string | null; status: string; status_name: string; source: string | null;
    alert: string | null; notes: string | null; advance: { to: string; label: string } | null; record: boolean; record_done: boolean;
  }[];
  classes: { id: string; title: string; starts_at: string; ends_at: string; enrolled: number; capacity: number }[];
  production: { value: number; goal: number | null; progress: number | null };
  next_days: { day: string; count: number }[];
  can_block: boolean;
}

const cardClass = 'bg-white rounded-tr-[2rem] rounded-bl-[2rem] rounded-tl-md rounded-br-md border border-slate-200/80 shadow-xs p-6';

export function BlockMyTimeButton({ onSaved }: { onSaved: () => void }) {
  const [open, setOpen] = useState(false);
  const [options, setOptions] = useState<BlockOptions | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const start = () => {
    setError('');
    if (options) { setOpen(true); return; }
    setLoading(true);
    api.get('/availability-blocks/options')
      .then((res) => { setOptions(res.data); setOpen(true); })
      .catch((err) => setError(apiError(err, 'No se pudo abrir el bloqueo.')))
      .finally(() => setLoading(false));
  };

  return (
    <>
      <button
        type="button"
        onClick={start}
        data-action="block-my-time"
        className="bg-white hover:bg-slate-50 text-brand-ink font-semibold px-4 py-2.5 rounded-tl-xl rounded-br-xl rounded-tr-sm rounded-bl-sm text-sm transition-all border border-slate-200 flex items-center gap-2 shadow-2xs cursor-pointer"
      >
        {loading ? <Loader2 className="w-4 h-4 animate-spin text-brand-accent" /> : <Ban className="w-4 h-4 text-brand-accent" />}
        Bloquear mi horario
      </button>
      {error && <span className="text-xs text-red-600 font-semibold">{error}</span>}
      <FormBlock isOpen={open} onClose={() => setOpen(false)} initialData={null} options={options} onSaved={() => { setOpen(false); onSaved(); }} />
    </>
  );
}

export default function MyDay({ data, onOpen, onChanged }: { data: MyDayData; onOpen: (id: string) => void; onChanged: () => void }) {
  const vocab = useVocabulary();
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState('');

  const advance = async (id: string, to: string) => {
    setBusy(id);
    setError('');
    try {
      await api.post(`/appointments/${id}/status`, { status: to });
      onChanged();
    } catch (err) {
      setError(apiError(err, 'No se pudo cambiar el estado de la cita.'));
    } finally {
      setBusy(null);
    }
  };

  const timeline = [
    ...data.appointments.map((a) => ({ at: a.starts_at, appointment: a, group: null })),
    ...data.classes.map((c) => ({ at: c.starts_at, appointment: null, group: c })),
  ].sort((a, b) => a.at.localeCompare(b.at));

  const done = data.appointments.filter((a) => a.status === 'completed').length;
  const { value, goal, progress } = data.production;

  return (
    <div className="grid grid-cols-1 lg:grid-cols-12 gap-6" data-card="my-day">
      <div className={`lg:col-span-8 ${cardClass}`}>
        <div className="flex flex-wrap items-center justify-between gap-2 pb-4 border-b border-slate-100 mb-5">
          <h2 className="text-xs font-bold text-brand-ink uppercase tracking-wider flex items-center gap-2">
            <CalendarDays className="w-4 h-4 text-brand-accent" /> Mis citas de hoy
          </h2>
          <span className="text-[11px] text-slate-400 font-medium">
            {data.appointments.length} {data.appointments.length === 1 ? 'cita' : 'citas'} · {done} {done === 1 ? 'atendida' : 'atendidas'}
            {data.classes.length > 0 ? ` · ${data.classes.length} ${data.classes.length === 1 ? 'clase' : 'clases'}` : ''}
          </span>
        </div>

        {error && <div className="mb-4"><ErrorAlert message={error} /></div>}

        {timeline.length === 0 && (
          <div className="p-6 text-center text-xs text-slate-400 bg-slate-50/80 rounded-xl border border-slate-100">
            No tienes citas hoy. Revisa tu agenda de la semana.
          </div>
        )}

        <ul className="space-y-3">
          {timeline.map(({ appointment: a, group: g }) => {
            if (g) {
              return (
                <li key={`class-${g.id}`} className="p-3 bg-slate-50/80 rounded-xl border border-slate-100 flex items-center gap-3" data-my-class={g.id}>
                  <div className="text-xs font-bold text-slate-500 w-24 shrink-0">{timeOf(g.starts_at)} – {timeOf(g.ends_at)}</div>
                  <div className="w-1 h-8 rounded-full bg-violet-400 shrink-0" />
                  <div className="flex-1 min-w-0">
                    <p className="text-xs font-bold text-brand-ink truncate">{g.title}</p>
                    <p className="text-[11px] text-slate-500 flex items-center gap-1"><Users className="w-3 h-3" /> {g.enrolled} de {g.capacity} inscritos</p>
                  </div>
                  <Link href="/classes" className="text-[11px] font-bold text-brand-accent hover:text-brand-ink shrink-0">Ver clase</Link>
                </li>
              );
            }
            if (!a) return null;
            return (
              <li key={a.id} className="p-3 bg-slate-50/80 rounded-xl border border-slate-100 flex flex-col sm:flex-row sm:items-center gap-3" data-my-appointment={a.id}>
                <div className="flex items-center gap-3 flex-1 min-w-0">
                  <div className="text-xs font-bold text-slate-500 w-24 shrink-0">{timeOf(a.starts_at)} – {timeOf(a.ends_at)}</div>
                  <div className={`w-1 h-10 rounded-full shrink-0 ${statusStyle(a.status).dot}`} />
                  <div className="flex-1 min-w-0">
                    <p className="text-xs font-bold text-brand-ink truncate">
                      <CustomerLink id={a.customer_id}>{a.who ?? vocab.client}</CustomerLink>
                      <span className={`ml-2 px-2 py-0.5 text-[10px] rounded-md font-semibold ${statusStyle(a.status).badge}`}>{a.status_name}</span>
                    </p>
                    <p className="text-[11px] text-slate-500 truncate">{[a.service, a.source, a.branch].filter(Boolean).join(' • ')}</p>
                    {(a.alert || a.notes) && (
                      <p className="text-[11px] text-amber-700 font-medium mt-0.5 line-clamp-2" data-note>{[a.alert, a.notes].filter(Boolean).join(' · ')}</p>
                    )}
                  </div>
                </div>
                <div className="flex items-center gap-2 shrink-0 sm:justify-end">
                  {a.advance && (
                    <button
                      type="button"
                      disabled={busy !== null}
                      onClick={() => advance(a.id, a.advance!.to)}
                      data-advance={a.advance.to}
                      className="px-3 py-1.5 bg-brand hover:bg-brand-hover text-white text-[11px] font-bold rounded-lg transition-all disabled:opacity-50 cursor-pointer inline-flex items-center gap-1.5"
                    >
                      {busy === a.id && <Loader2 className="w-3 h-3 animate-spin" />}
                      {a.advance.label}
                    </button>
                  )}
                  {a.record && (
                    <button
                      type="button"
                      onClick={() => onOpen(a.id)}
                      data-open-record={a.id}
                      className="px-3 py-1.5 bg-white hover:bg-slate-50 text-brand-ink text-[11px] font-bold rounded-lg border border-slate-200 transition-all cursor-pointer inline-flex items-center gap-1.5"
                    >
                      <ClipboardList className="w-3 h-3 text-brand-accent" /> {a.record_done ? 'Ficha' : 'Llenar ficha'}
                    </button>
                  )}
                  <button
                    type="button"
                    onClick={() => onOpen(a.id)}
                    data-open={a.id}
                    aria-label="Abrir la cita"
                    className="p-1.5 bg-white hover:bg-slate-50 text-slate-500 rounded-lg border border-slate-200 transition-all cursor-pointer"
                  >
                    <ChevronRight className="w-3.5 h-3.5" />
                  </button>
                </div>
              </li>
            );
          })}
        </ul>
      </div>

      <div className="lg:col-span-4 space-y-6">
        <div className={cardClass} data-card="my-production">
          <h3 className="font-bold text-brand-ink text-sm flex items-center gap-2 pb-4 border-b border-slate-100 mb-4">
            <Target className="w-4 h-4 text-brand-accent" /> Mi producción del mes
          </h3>
          <span className="block text-3xl font-extrabold text-brand-ink tracking-tight">{formatMoney(value, null, 0)}</span>
          {goal ? (
            <>
              <p className="text-[11px] text-slate-500 mt-1">Meta {formatMoney(goal, null, 0)} · {progress}%</p>
              <div className="mt-3 h-2 rounded-full bg-slate-100 overflow-hidden" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={progress ?? 0}>
                <div className={`h-full rounded-full ${(progress ?? 0) < 50 ? 'bg-amber-400' : 'bg-emerald-500'}`} style={{ width: `${progress ?? 0}%` }} />
              </div>
            </>
          ) : (
            <p className="text-[11px] text-slate-400 mt-1">Aún no tienes una meta del mes.</p>
          )}
          <p className="text-[11px] text-slate-400 mt-3">Suma lo atendido y los productos que vendiste este mes.</p>
        </div>

        <div className={cardClass} data-card="next-days">
          <h3 className="font-bold text-brand-ink text-sm flex items-center gap-2 pb-4 border-b border-slate-100 mb-4">
            <CalendarRange className="w-4 h-4 text-brand-accent" /> Próximos días
          </h3>
          <ul className="grid grid-cols-3 gap-2">
            {data.next_days.map((d) => (
              <li key={d.day} data-next-day={d.day}>
                <Link href="/agenda" className="block bg-slate-50 hover:bg-brand-soft/10 rounded-xl border border-slate-200/60 px-2 py-2.5 text-center transition-colors">
                  <span className="block text-[10px] text-slate-500 font-semibold uppercase">{formatDate(d.day, { weekday: 'short', day: 'numeric' })}</span>
                  <span className="block text-xl font-extrabold text-brand-ink">{d.count}</span>
                  <span className="block text-[10px] text-slate-400">{d.count === 1 ? 'cita' : 'citas'}</span>
                </Link>
              </li>
            ))}
          </ul>
        </div>
      </div>
    </div>
  );
}
