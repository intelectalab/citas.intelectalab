'use client';

import { useEffect, useState } from 'react';
import { BarChart3, ListChecks } from 'lucide-react';
import SectionTitle from '@/app/components/common/SectionTitle';
import { api } from '@/lib/axios';
import { formatMoney } from '@/lib/currency';

interface Performance {
  currency: string;
  with_products: boolean;
  days: { date: string; amount: number }[] | null;
  total: number | null;
  services: { name: string; count: number }[];
}

const card = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs';

const dayLabel = (date: string, options: Intl.DateTimeFormatOptions) => new Date(`${date}T12:00:00`).toLocaleDateString('es-PE', options);

export default function ProfessionalPerformance({ professionalId, color }: { professionalId: string; color?: string | null }) {
  const [data, setData] = useState<Performance | null>(null);
  const tint = color || 'var(--brand-link)';

  useEffect(() => {
    api.get(`/professionals/${professionalId}/performance`)
      .then((res) => setData(res.data))
      .catch(() => setData(null));
  }, [professionalId]);

  if (!data) return null;

  const max = Math.max(1, ...(data.days ?? []).map((d) => d.amount));
  const topCount = Math.max(1, ...data.services.map((s) => s.count));

  return (
    <div className="xl:col-span-3 grid grid-cols-1 lg:grid-cols-3 gap-5" data-card="performance">
      {data.days && (
        <section className={`${card} p-6 lg:col-span-2 min-w-0`} data-card="production">
          <div className="flex flex-wrap items-start justify-between gap-2 mb-4">
            <div>
              <SectionTitle><BarChart3 className="w-4 h-4 text-brand-accent" /> Producción diaria, 30 días</SectionTitle>
              <p className="text-xs text-slate-500 font-medium mt-1">Atenciones completadas{data.with_products ? ' y productos que vendió' : ''}, por día.</p>
            </div>
            <span className="text-lg font-extrabold text-slate-900 tabular-nums" data-production-total>{formatMoney(data.total ?? 0)}</span>
          </div>
          <div className="relative h-36 flex items-end gap-[3px]" role="img" aria-label="Producción de cada día de los últimos 30 días">
            {data.days.map((d) => (
              <div key={d.date} className="flex-1 h-full flex items-end group relative min-w-0" data-day={d.date}>
                <div className="w-full rounded-t-sm group-hover:opacity-80 transition-opacity" style={{ height: `${d.amount > 0 ? Math.max(3, (d.amount / max) * 100) : 1.5}%`, backgroundColor: d.amount > 0 ? tint : '#E2E8F0' }} />
                <span className="pointer-events-none absolute left-1/2 -translate-x-1/2 bottom-full mb-1 hidden group-hover:block whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-[11px] font-semibold text-white shadow z-10">
                  {dayLabel(d.date, { day: 'numeric', month: 'short' })}: {formatMoney(d.amount)}
                </span>
              </div>
            ))}
          </div>
          <div className="flex justify-between mt-1.5 text-[10px] text-slate-400 tabular-nums">
            <span>{dayLabel(data.days[0].date, { day: 'numeric', month: 'short' })}</span>
            <span>Hoy</span>
          </div>
        </section>
      )}

      <section className={`${card} p-6 min-w-0 ${data.days ? '' : 'lg:col-span-3'}`} data-card="services-done">
        <SectionTitle><ListChecks className="w-4 h-4 text-brand-accent" /> Servicios realizados</SectionTitle>
        <p className="text-xs text-slate-500 font-medium mt-1 mb-4">Atenciones completadas en los últimos 30 días.</p>
        {data.services.length === 0 ? (
          <p className="text-sm text-slate-400">Ninguna todavía.</p>
        ) : (
          <ul className="space-y-2.5">
            {data.services.map((s) => (
              <li key={s.name} data-service-done={s.name}>
                <div className="flex justify-between gap-3 text-sm">
                  <span className="text-slate-700 truncate">{s.name}</span>
                  <span className="font-semibold text-slate-900 tabular-nums">{s.count}</span>
                </div>
                <span className="block mt-1 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                  <span className="block h-full rounded-full" style={{ width: `${(s.count / topCount) * 100}%`, backgroundColor: tint }} />
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
