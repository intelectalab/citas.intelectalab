'use client';

import { useEffect, useState } from 'react';
import { CalendarCheck, Clock, Hourglass, Scale, Timer } from 'lucide-react';
import { api } from '@/lib/axios';
import { useVocabulary } from '@/hooks/useVocabulary';
import { addDays, apiError, formatDate, formatMinutes, todayISO, type TimingSummary } from '../appointments';

interface TimingRow extends TimingSummary {
  id: string;
  name: string;
}

interface MetricsPayload {
  from: string;
  to: string;
  summary: TimingSummary;
  by_professional: TimingRow[];
  by_service: TimingRow[];
  by_customer: TimingRow[];
}

const PERIODS: { id: string; label: string; days: number }[] = [
  { id: '7', label: 'Últimos 7 días', days: 7 },
  { id: '30', label: 'Últimos 30 días', days: 30 },
  { id: '90', label: 'Últimos 90 días', days: 90 },
];

const cardClass = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs';

// Diferencia frente al tiempo asignado, con signo y color: verde si terminan
// antes, rojo si se pasan.
function Diff({ minutes }: { minutes: number | null }) {
  if (minutes === null) return <span className="text-slate-400">—</span>;
  if (Math.round(minutes) === 0) return <span className="text-slate-500">En su tiempo</span>;

  return (
    <span className={`font-semibold ${minutes > 0 ? 'text-rose-700' : 'text-emerald-700'}`}>
      {formatMinutes(minutes)} {minutes > 0 ? 'más' : 'menos'}
    </span>
  );
}

function TimingTable({ title, hint, firstColumn, rows, tableKey, showTotal = false }: {
  title: string;
  hint: string;
  firstColumn: string;
  rows: TimingRow[];
  tableKey: string;
  showTotal?: boolean;
}) {
  // Quien más cerca o por debajo de su tiempo asignado atiende, entre quienes tienen medición.
  const measured = rows.filter((row) => row.diff_minutes !== null);
  const best = measured.length > 1 ? measured.reduce((a, b) => ((a.diff_minutes as number) <= (b.diff_minutes as number) ? a : b)) : null;
  const longest = Math.max(1, ...rows.map((row) => row.avg_minutes ?? 0));

  return (
    <section className={`${cardClass} overflow-hidden`} data-table={tableKey}>
      <div className="px-6 pt-5 pb-3">
        <h2 className="text-sm font-extrabold text-[#022340] uppercase tracking-wider">{title}</h2>
        <p className="text-xs text-slate-500 font-medium mt-1">{hint}</p>
      </div>

      {rows.length === 0 ? (
        <p className="px-6 pb-6 text-sm text-slate-400">Aún no hay atenciones en este periodo.</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-[11px] font-bold text-slate-400 uppercase tracking-wider border-y border-slate-100 bg-slate-50">
                <th className="px-6 py-2.5">{firstColumn}</th>
                <th className="px-4 py-2.5 text-right">Atenciones</th>
                <th className="px-4 py-2.5">Tiempo promedio</th>
                <th className="px-4 py-2.5 text-right">Asignado</th>
                <th className="px-4 py-2.5">Frente a lo asignado</th>
                <th className="px-4 py-2.5 text-right">{showTotal ? 'Tiempo total' : 'Espera promedio'}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {rows.map((row) => (
                <tr key={row.id} data-row={row.name}>
                  <td className="px-6 py-3 font-semibold text-slate-800">
                    {row.name}
                    {best?.id === row.id && tableKey === 'professional' && (
                      <span className="ml-2 px-2 py-0.5 text-[11px] font-semibold bg-emerald-50 text-emerald-700 rounded-full">Más ágil</span>
                    )}
                  </td>
                  <td className="px-4 py-3 text-right text-slate-700 tabular-nums">
                    {row.completed}
                    {row.measured < row.completed && (
                      <span className="block text-[11px] text-slate-400">{row.measured} {row.measured === 1 ? 'medida' : 'medidas'}</span>
                    )}
                  </td>
                  <td className="px-4 py-3">
                    {row.avg_minutes === null ? (
                      <span className="text-slate-400">Sin medir</span>
                    ) : (
                      <div className="flex items-center gap-3">
                        <span className="w-20 shrink-0 font-semibold text-slate-800 tabular-nums">{formatMinutes(row.avg_minutes)}</span>
                        <span className="flex-1 h-1.5 min-w-16 rounded-full bg-slate-100 overflow-hidden">
                          <span className="block h-full rounded-full bg-[var(--accent)]" style={{ width: `${Math.max(4, (row.avg_minutes / longest) * 100)}%` }} />
                        </span>
                      </div>
                    )}
                  </td>
                  <td className="px-4 py-3 text-right text-slate-600 tabular-nums">{formatMinutes(row.planned_minutes)}</td>
                  <td className="px-4 py-3"><Diff minutes={row.diff_minutes} /></td>
                  <td className="px-4 py-3 text-right text-slate-600 tabular-nums">
                    {formatMinutes(showTotal ? row.total_minutes : row.avg_wait_minutes)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}

// Cuánto duran de verdad las atenciones frente al tiempo que tienen asignado:
// en general, por profesional, por servicio y por cliente.
export default function AttentionTimes() {
  const vocab = useVocabulary();
  const [period, setPeriod] = useState('30');
  const [data, setData] = useState<MetricsPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    const days = PERIODS.find((p) => p.id === period)!.days;
    const to = todayISO();

    setLoading(true);
    api.get('/appointments/metrics', { params: { from: addDays(to, -(days - 1)), to } })
      .then((res) => { setData(res.data); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar los tiempos de atención.')))
      .finally(() => setLoading(false));
  }, [period]);

  const summary = data?.summary;
  const unmeasured = summary ? summary.completed - summary.measured : 0;

  const cards: { id: string; title: string; value: string; note: string; icon: React.ReactNode; color: string }[] = summary ? [
    { id: 'completed', title: 'Atenciones', value: String(summary.completed), note: `${summary.measured} con tiempo medido`, icon: <CalendarCheck size={18} />, color: 'text-slate-500' },
    { id: 'avg', title: 'Tiempo promedio', value: formatMinutes(summary.avg_minutes), note: 'Lo que dura la atención', icon: <Timer size={18} />, color: 'text-teal-600' },
    { id: 'planned', title: 'Tiempo asignado', value: formatMinutes(summary.planned_minutes), note: 'Lo que se reservó en promedio', icon: <Clock size={18} />, color: 'text-sky-600' },
    {
      id: 'diff',
      title: 'Frente a lo asignado',
      value: summary.diff_minutes === null ? '—' : Math.round(summary.diff_minutes) === 0 ? 'En su tiempo' : `${formatMinutes(summary.diff_minutes)} ${summary.diff_minutes > 0 ? 'más' : 'menos'}`,
      note: summary.diff_minutes === null ? 'Sin atenciones medidas' : summary.diff_minutes > 0 ? 'Las atenciones se alargan' : 'Las atenciones terminan antes',
      icon: <Scale size={18} />,
      color: (summary.diff_minutes ?? 0) > 0 ? 'text-rose-700' : 'text-emerald-600',
    },
    { id: 'wait', title: 'Espera promedio', value: formatMinutes(summary.avg_wait_minutes), note: 'Desde que llega hasta que lo atienden', icon: <Hourglass size={18} />, color: 'text-violet-600' },
  ] : [];

  return (
    <div className="space-y-5 pb-8 font-sans">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-[#022340] tracking-tight">Tiempos de atención</h1>
          <p className="text-sm text-slate-500 font-medium">
            {data ? `Del ${formatDate(data.from, { day: 'numeric', month: 'long' })} al ${formatDate(data.to, { day: 'numeric', month: 'long', year: 'numeric' })}` : 'Cuánto duran de verdad las atenciones'}
          </p>
        </div>

        <div className="flex items-center gap-1 p-1 bg-white border border-slate-200 rounded-xl">
          {PERIODS.map((item) => (
            <button
              key={item.id}
              type="button"
              data-period={item.id}
              aria-pressed={period === item.id}
              onClick={() => setPeriod(item.id)}
              className={`px-3 py-1.5 text-sm font-semibold rounded-lg transition-colors ${
                period === item.id ? 'bg-[#022340] text-white' : 'text-slate-600 hover:bg-slate-50'
              }`}
            >
              {item.label}
            </button>
          ))}
        </div>
      </div>

      {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}

      {loading && !data ? (
        <p className="text-sm text-slate-500">Cargando tiempos...</p>
      ) : data && (
        <>
          <div className="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-5 gap-4">
            {cards.map((card) => (
              <div key={card.id} data-metric={card.id} className="bg-white px-4 py-3 rounded-2xl border border-slate-100 shadow-[0_4px_20px_-4px_rgba(0,0,0,0.05)]">
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <span className="text-[10px] font-semibold tracking-wider text-slate-400 uppercase">{card.title}</span>
                    <div className="mt-0.5 text-2xl font-extrabold tracking-tight text-slate-900">{card.value}</div>
                    <span className="block text-xs text-slate-500 mt-0.5">{card.note}</span>
                  </div>
                  <div className={`p-2 rounded-xl bg-slate-50 border border-slate-100 ${card.color}`}>{card.icon}</div>
                </div>
              </div>
            ))}
          </div>

          {unmeasured > 0 && (
            <p className="text-xs text-slate-500 bg-slate-100 border border-slate-200/70 rounded-xl px-4 py-3" data-unmeasured>
              {unmeasured} {unmeasured === 1 ? 'atención no entra' : 'atenciones no entran'} en los promedios: no se marcó «Iniciar atención» y «Dar por atendida» en su momento,
              o la cita quedó abierta mucho más de lo asignado. Para medir bien, marca cada paso cuando ocurre.
            </p>
          )}

          <TimingTable
            tableKey="professional"
            title={`Por ${vocab.pro.toLowerCase()}`}
            hint="Cuánto tarda cada quien frente al tiempo que tenía asignado. Terminar antes no siempre es mejor: léelo junto con el tipo de servicio que atiende."
            firstColumn={vocab.pro}
            rows={data.by_professional}
          />

          <TimingTable
            tableKey="service"
            title="Por servicio"
            hint="Si un servicio siempre dura más o menos de lo asignado, conviene ajustar su duración en Servicios."
            firstColumn="Servicio"
            rows={data.by_service}
          />

          <TimingTable
            tableKey="customer"
            title={`Por ${vocab.client.toLowerCase()}`}
            hint={`Cuánto tiempo ha ocupado cada ${vocab.client.toLowerCase()} en sus atenciones del periodo.`}
            firstColumn={vocab.client}
            rows={data.by_customer}
            showTotal
          />
        </>
      )}
    </div>
  );
}
