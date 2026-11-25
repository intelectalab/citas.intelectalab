'use client';

import Link from 'next/link';
import { BarChart3, ExternalLink } from 'lucide-react';
import { formatMoney } from '@/lib/currency';
import { formatDate } from '@/features/appointments/appointments';

export interface RevenueDay {
  day: string;
  services: number;
  others: number;
}

const money = (value: number) => formatMoney(value, null, 0);

const niceMax = (value: number) => {
  if (value <= 0) return 1;
  const power = Math.pow(10, Math.floor(Math.log10(value)));
  const step = [1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10].find((s) => s * power >= value) ?? 10;
  return step * power;
};

const short = (value: number) => (value >= 1000 ? `${(value / 1000).toFixed(value >= 10000 ? 0 : 1)} mil` : money(value));

export default function RevenueCard({ days, ticket, sales, canReports }: { days: RevenueDay[]; ticket: number | null; sales: number | null; canReports: boolean }) {
  const totals = days.map((d) => d.services + d.others);
  const top = Math.max(0, ...totals);
  const max = niceMax(top);
  const sum = totals.reduce((s, v) => s + v, 0);

  return (
    <div className="bg-white rounded-tr-[2rem] rounded-bl-[2rem] rounded-tl-md rounded-br-md border border-slate-200/80 shadow-xs p-6" data-card="revenue">
      <div className="flex flex-wrap items-center justify-between gap-3 pb-4 border-b border-slate-100 mb-5">
        <h2 className="text-xs font-bold text-brand-ink uppercase tracking-wider flex items-center gap-2">
          <BarChart3 className="w-4 h-4 text-brand-accent" /> Ingresos de los últimos 14 días
        </h2>
        <div className="flex flex-wrap items-center gap-4 text-[11px] text-slate-500 font-medium">
          <span className="inline-flex items-center gap-1.5"><i className="w-2.5 h-2.5 rounded-sm bg-[var(--brand-link)]" />Servicios</span>
          <span className="inline-flex items-center gap-1.5"><i className="w-2.5 h-2.5 rounded-sm bg-[var(--brand-soft)]" />Productos, paquetes y otros</span>
          {canReports && (
            <Link href="/reports" className="text-xs font-bold text-brand-accent hover:text-brand-ink flex items-center gap-1">
              Reportes <ExternalLink className="w-3 h-3" />
            </Link>
          )}
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-stretch">
        <div className="lg:col-span-9 min-w-0">
          {top <= 0 ? (
            <p className="p-6 text-center text-xs text-slate-400 bg-slate-50/80 rounded-xl border border-slate-100">Aún no hay ventas cobradas en estos 14 días.</p>
          ) : (
            <div data-chart="revenue">
              <div className="flex">
                <div className="relative w-14 shrink-0 h-40 text-[10px] text-slate-400 tabular-nums">
                  {[1, 0.5, 0].map((f) => (
                    <span key={f} className="absolute right-2 -translate-y-1/2 whitespace-nowrap" style={{ top: `${(1 - f) * 100}%` }}>{short(max * f)}</span>
                  ))}
                </div>
                <div className="relative flex-1 h-40">
                  {[0, 0.5, 1].map((f) => (
                    <span key={f} className={`absolute inset-x-0 border-t ${f === 1 ? 'border-slate-200' : 'border-dashed border-slate-100'}`} style={{ top: `${f * 100}%` }} />
                  ))}
                  <div className="relative flex items-end gap-1 h-full">
                    {days.map((d) => {
                      const total = d.services + d.others;
                      const title = `${formatDate(d.day, { weekday: 'short', day: 'numeric', month: 'short' })}: ${money(total)} (servicios ${money(d.services)} · otros ${money(d.others)})`;
                      return (
                        <div key={d.day} className="flex-1 h-full flex items-end justify-center group relative min-w-0" aria-label={title} data-day={d.day}>
                          {total > 0 && (
                            <div className="w-full max-w-8 flex flex-col justify-end rounded-t-md overflow-hidden group-hover:opacity-80 transition-opacity" style={{ height: `${Math.max(1.5, (total / max) * 100)}%` }}>
                              {d.others > 0 && <div className="bg-[var(--brand-soft)]" style={{ height: `${(d.others / total) * 100}%` }} />}
                              {d.services > 0 && <div className="bg-[var(--brand-link)] flex-1" />}
                            </div>
                          )}
                          <span className="pointer-events-none absolute left-1/2 -translate-x-1/2 hidden group-hover:block whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-[11px] font-semibold text-white shadow z-10" style={{ bottom: `calc(${(total / max) * 100}% + 6px)` }}>
                            {title}
                          </span>
                        </div>
                      );
                    })}
                  </div>
                </div>
              </div>
              <div className="flex gap-1 mt-1.5 ml-14">
                {days.map((d, i) => (
                  <span key={d.day} className="flex-1 min-w-0 text-[10px] text-slate-400 text-center whitespace-nowrap">{i % 2 === 1 ? formatDate(d.day, { day: 'numeric' }) : ''}</span>
                ))}
              </div>
            </div>
          )}
        </div>

        <div className="lg:col-span-3 grid grid-cols-2 lg:grid-cols-1 gap-3">
          <div className="bg-slate-50 rounded-xl border border-slate-200/60 px-4 py-3" data-metric="ticket">
            <span className="block text-[11px] text-slate-500 font-semibold">Ticket promedio (30 días)</span>
            <span className="block text-2xl font-extrabold text-brand-ink tracking-tight mt-1">{ticket === null ? '—' : money(ticket)}</span>
            <span className="block text-[11px] text-slate-400">{sales ?? 0} {sales === 1 ? 'venta' : 'ventas'}</span>
          </div>
          <div className="bg-slate-50 rounded-xl border border-slate-200/60 px-4 py-3" data-metric="revenue-14">
            <span className="block text-[11px] text-slate-500 font-semibold">Total de los 14 días</span>
            <span className="block text-2xl font-extrabold text-brand-ink tracking-tight mt-1">{money(sum)}</span>
            <span className="block text-[11px] text-slate-400">Ventas cobradas, sin propinas</span>
          </div>
        </div>
      </div>
    </div>
  );
}
