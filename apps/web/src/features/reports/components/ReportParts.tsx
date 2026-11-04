import Link from 'next/link';
import type { Serie } from '../reports';
import { WEEKDAYS } from '../reports';
import { formatDate } from '@/features/appointments/appointments';

export const cardClass = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs';

interface KpiProps {
  id: string;
  title: string;
  value: string;
  note?: string;
  change?: number | null;
  lowerIsBetter?: boolean;
  icon: React.ReactNode;
  color: string;
}

export function Kpi({ id, title, value, note, change, lowerIsBetter = false, icon, color }: KpiProps) {
  const good = change !== null && change !== undefined && (lowerIsBetter ? change < 0 : change > 0);
  const bad = change !== null && change !== undefined && (lowerIsBetter ? change > 0 : change < 0);

  return (
    <div data-kpi={id} className="bg-white px-4 py-3 rounded-2xl border border-slate-100 shadow-[0_4px_20px_-4px_rgba(0,0,0,0.05)]">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <span className="text-[10px] font-semibold tracking-wider text-slate-400 uppercase">{title}</span>
          <div className="mt-0.5 text-2xl font-extrabold tracking-tight text-slate-900 truncate">{value}</div>
          {change !== undefined && (
            <span data-change={change ?? ''} className={`block text-xs font-semibold mt-0.5 ${good ? 'text-emerald-700' : bad ? 'text-rose-700' : 'text-slate-400'}`}>
              {change === null ? 'Sin comparación' : change === 0 ? 'Igual que el periodo anterior' : `${change > 0 ? '▲' : '▼'} ${Math.abs(change)} % frente al anterior`}
            </span>
          )}
          {note && <span className="block text-xs text-slate-500 mt-0.5">{note}</span>}
        </div>
        <div className={`p-2 rounded-xl bg-slate-50 border border-slate-100 ${color}`}>{icon}</div>
      </div>
    </div>
  );
}

export function Section({ title, hint, children, actions, dataKey }: { title: string; hint?: string; children: React.ReactNode; actions?: React.ReactNode; dataKey: string }) {
  return (
    <section className={`${cardClass} overflow-hidden`} data-section={dataKey}>
      <div className="px-6 pt-5 pb-3 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-sm font-extrabold text-brand-ink uppercase tracking-wider">{title}</h2>
          {hint && <p className="text-xs text-slate-500 font-medium mt-1">{hint}</p>}
        </div>
        {actions}
      </div>
      {children}
    </section>
  );
}

const dayLabel = (day: string) => formatDate(day, { day: 'numeric', month: 'short' });
const dayTitle = (day: string) => formatDate(day, { weekday: 'short', day: 'numeric', month: 'short' });
const weekLabel = (day: string) => formatDate(day, { day: 'numeric', month: 'short' });
const weekTitle = (day: string) => `Semana del ${formatDate(day, { day: 'numeric', month: 'long' })}`;

export const WEEKLY_AFTER = 45;

export const isWeekly = (points: { day: string }[]) => points.length > WEEKLY_AFTER;

type Point = { day: string; value: number | null };

const byWeek = (points: Point[], aggregate: 'sum' | 'avg'): Point[] => {
  const weeks: { day: string; values: number[]; open: boolean }[] = [];
  points.forEach((p, i) => {
    if (i % 7 === 0) weeks.push({ day: p.day, values: [], open: false });
    const week = weeks[weeks.length - 1];
    if (p.value !== null) {
      week.values.push(p.value);
      week.open = true;
    }
  });
  return weeks.map((w) => ({
    day: w.day,
    value: !w.open ? null : aggregate === 'sum' ? w.values.reduce((s, v) => s + v, 0) : Math.round(w.values.reduce((s, v) => s + v, 0) / w.values.length),
  }));
};

const niceMax = (value: number) => {
  if (value <= 0) return 1;
  const power = Math.pow(10, Math.floor(Math.log10(value)));
  const step = [1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10].find((s) => s * power >= value) ?? 10;
  return step * power;
};

export function DayBars({ points, format, empty, labelOf, titleOf, aggregate = 'sum', axis }: { points: Point[]; format: (v: number) => string; empty: string; labelOf?: (day: string) => string; titleOf?: (day: string) => string; aggregate?: 'sum' | 'avg'; axis?: (v: number) => string }) {
  const weekly = !labelOf && isWeekly(points);
  const bars = weekly ? byWeek(points, aggregate) : points;
  const label = labelOf ?? (weekly ? weekLabel : dayLabel);
  const title = titleOf ?? (weekly ? weekTitle : dayTitle);
  const top = Math.max(0, ...bars.map((p) => p.value ?? 0));
  if (top <= 0) return <p className="px-6 pb-6 text-sm text-slate-400">{empty}</p>;
  const max = niceMax(top);
  const labelEvery = Math.max(1, Math.ceil(bars.length / 12));
  const tick = axis ?? format;
  const gap = bars.length > 40 ? 'gap-[2px]' : 'gap-1';

  return (
    <div className="px-6 pb-5" data-chart="days" data-weekly={weekly ? 'true' : 'false'}>
      <div className="flex">
        <div className="relative w-16 shrink-0 h-44 text-[10px] text-slate-400 tabular-nums">
          {[1, 0.5, 0].map((f) => (
            <span key={f} className="absolute right-2 -translate-y-1/2 whitespace-nowrap" style={{ top: `${(1 - f) * 100}%` }}>{tick(max * f)}</span>
          ))}
        </div>
        <div className="relative flex-1 h-44">
          {[0, 0.5, 1].map((f) => (
            <span key={f} className={`absolute inset-x-0 border-t ${f === 1 ? 'border-slate-200' : 'border-dashed border-slate-100'}`} style={{ top: `${f * 100}%` }} />
          ))}
          <div className={`relative flex items-end ${gap} h-full`}>
            {bars.map((p) => (
              <div key={p.day} className="flex-1 h-full flex items-end justify-center group relative min-w-0" aria-label={`${title(p.day)}: ${p.value === null ? 'sin horario' : format(p.value)}`}>
                {p.value !== null && p.value > 0 && (
                  <div className="w-full max-w-10 rounded-t-md bg-[var(--brand-link)] group-hover:opacity-80 transition-opacity" style={{ height: `${Math.max(1.5, (p.value / max) * 100)}%` }} />
                )}
                <span className="pointer-events-none absolute left-1/2 -translate-x-1/2 hidden group-hover:block whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-[11px] font-semibold text-white shadow z-10" style={{ bottom: `calc(${p.value ? (p.value / max) * 100 : 0}% + 6px)` }}>
                  {title(p.day)}: {p.value === null ? 'sin horario' : format(p.value)}
                </span>
              </div>
            ))}
          </div>
        </div>
      </div>
      <div className={`flex ${gap} mt-1.5 ml-16`}>
        {bars.map((p, i) => (
          <span key={p.day} className="flex-1 min-w-0 text-[10px] text-slate-400 text-center whitespace-nowrap overflow-visible">{i % labelEvery === 0 ? label(p.day) : ''}</span>
        ))}
      </div>
    </div>
  );
}

export function ListBars({ items, format, empty, hrefOf }: { items: Serie[]; format: (v: number) => string; empty: string; hrefOf?: (item: Serie) => string | undefined }) {
  const max = Math.max(0, ...items.map((i) => Math.abs(i.value)));
  if (items.length === 0 || max <= 0) return <p className="px-6 pb-6 text-sm text-slate-400">{empty}</p>;

  return (
    <ul className="px-6 pb-5 space-y-2.5" data-chart="list">
      {items.map((item) => (
        <li key={item.key ?? item.label}>
          <div className="flex items-center justify-between gap-3 text-sm">
            <ItemLabel href={hrefOf?.(item)} className="font-semibold text-slate-700 truncate">{item.label}</ItemLabel>
            <span className="tabular-nums text-slate-800 font-semibold">{format(item.value)}</span>
          </div>
          <span className="mt-1 block h-1.5 rounded-full bg-slate-100 overflow-hidden">
            <span className="block h-full rounded-full bg-[var(--brand-link)]" style={{ width: `${item.value === 0 ? 0 : Math.max(3, (Math.abs(item.value) / max) * 100)}%` }} />
          </span>
        </li>
      ))}
    </ul>
  );
}

function ItemLabel({ href, className, children }: { href?: string; className: string; children: React.ReactNode }) {
  if (!href) return <span className={className}>{children}</span>;
  return <Link href={href} data-drill={href} className={`${className} hover:text-brand-accent hover:underline`} title="Ver el detalle">{children}</Link>;
}

const DONUT_COLORS = ['var(--brand-link)', 'var(--brand-soft)', 'var(--chart-3)', 'var(--chart-4)', 'var(--chart-5)', 'var(--chart-6)', 'var(--chart-7)', 'var(--chart-8)'];

export function Donut({ items, format, unit, empty, limit = 6, hrefOf }: { items: Serie[]; format: (v: number) => string; unit: string; empty: string; limit?: number; hrefOf?: (item: Serie) => string | undefined }) {
  const positive = items.filter((i) => i.value > 0).sort((a, b) => b.value - a.value);
  const shown = positive.length > limit ? [...positive.slice(0, limit - 1), { key: 'others', label: 'Otros', value: positive.slice(limit - 1).reduce((s, i) => s + i.value, 0) }] : positive;
  const total = shown.reduce((s, i) => s + i.value, 0);
  if (total <= 0) return <p className="px-6 pb-6 text-sm text-slate-400">{empty}</p>;

  const radius = 46;
  const length = 2 * Math.PI * radius;
  const offsets = shown.reduce<number[]>((list, _item, index) => [...list, index === 0 ? 0 : list[index - 1] + (shown[index - 1].value / total) * length], []);

  return (
    <div className="px-6 pb-5 flex flex-wrap items-center gap-5" data-chart="donut">
      <svg viewBox="0 0 120 120" className="w-28 h-28 shrink-0 -rotate-90" role="img" aria-label={unit}>
        <circle cx="60" cy="60" r={radius} fill="none" strokeWidth="16" className="stroke-slate-100" />
        {shown.map((item, index) => {
          const part = (item.value / total) * length;
          return (
            <circle key={item.key ?? item.label} cx="60" cy="60" r={radius} fill="none" strokeWidth="16" stroke={DONUT_COLORS[index % DONUT_COLORS.length]} strokeDasharray={`${part} ${length - part}`} strokeDashoffset={-offsets[index]}>
              <title>{`${item.label}: ${format(item.value)}`}</title>
            </circle>
          );
        })}
      </svg>
      <ul className="flex-1 min-w-48 space-y-1.5">
        {shown.map((item, index) => (
          <li key={item.key ?? item.label} className="flex items-center gap-2 text-sm">
            <i className="w-2.5 h-2.5 rounded-full shrink-0" style={{ background: DONUT_COLORS[index % DONUT_COLORS.length] }} />
            <ItemLabel href={item.key === 'others' ? undefined : hrefOf?.(item)} className="font-semibold text-slate-700 truncate flex-1">{item.label}</ItemLabel>
            <span className="tabular-nums text-slate-800 font-semibold">{format(item.value)}</span>
            <span className="tabular-nums text-xs text-slate-400 w-10 text-right">{Math.round((item.value / total) * 100)} %</span>
          </li>
        ))}
      </ul>
    </div>
  );
}

export function Heatmap({ cells }: { cells: { weekday: number; hour: number; value: number }[] }) {
  if (cells.length === 0) return <p className="px-6 pb-6 text-sm text-slate-400">Aún no hay citas en este periodo.</p>;
  const hours = cells.map((c) => c.hour);
  const from = Math.min(...hours);
  const to = Math.max(...hours);
  const max = Math.max(...cells.map((c) => c.value));
  const value = (weekday: number, hour: number) => cells.find((c) => c.weekday === weekday && c.hour === hour)?.value ?? 0;
  const range = Array.from({ length: to - from + 1 }, (_, i) => from + i);

  return (
    <div className="px-6 pb-5 overflow-x-auto" data-chart="heatmap">
      <table className="text-[11px]">
        <thead>
          <tr>
            <th />
            {range.map((h) => <th key={h} className="px-0.5 pb-1 font-semibold text-slate-400 text-center w-9">{h}h</th>)}
          </tr>
        </thead>
        <tbody>
          {WEEKDAYS.map((day, index) => (
            <tr key={day}>
              <th className="pr-2 text-right font-semibold text-slate-500">{day}</th>
              {range.map((h) => {
                const v = value(index + 1, h);
                return (
                  <td key={h} className="p-0.5">
                    <div
                      title={`${day} ${h}:00 — ${v} ${v === 1 ? 'cita' : 'citas'}`}
                      className="h-7 w-9 rounded-md flex items-center justify-center font-semibold"
                      style={{ background: v ? `color-mix(in srgb, var(--brand-link) ${Math.round(15 + (v / max) * 85)}%, transparent)` : 'var(--color-slate-50)', color: v / max > 0.55 ? '#fff' : 'var(--color-slate-600)' }}
                    >
                      {v || ''}
                    </div>
                  </td>
                );
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export function Table<T>({ columns, rows, empty, rowKey }: { columns: { header: string; align?: 'right'; cell: (row: T) => React.ReactNode }[]; rows: T[]; empty: string; rowKey: (row: T) => string }) {
  if (rows.length === 0) return <p className="px-6 pb-6 text-sm text-slate-400">{empty}</p>;

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="text-left text-[11px] font-bold text-slate-400 uppercase tracking-wider border-y border-slate-100 bg-slate-50">
            {columns.map((c, i) => <th key={c.header} className={`${i === 0 ? 'px-6' : 'px-4'} py-2.5 ${c.align === 'right' ? 'text-right' : ''}`}>{c.header}</th>)}
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {rows.map((row) => (
            <tr key={rowKey(row)}>
              {columns.map((c, i) => <td key={c.header} className={`${i === 0 ? 'px-6 font-semibold text-slate-800' : 'px-4 text-slate-700'} py-3 ${c.align === 'right' ? 'text-right tabular-nums' : ''}`}>{c.cell(row)}</td>)}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
