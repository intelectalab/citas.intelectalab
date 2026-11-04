'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { Building2, CalendarClock, ChevronRight, Hourglass, Repeat } from 'lucide-react';
import { api } from '@/lib/axios';
import { formatMoney } from '@/lib/currency';

interface Money {
  amount: number;
  others: { currency: string; amount: number }[];
}

interface Summary {
  currency: string;
  mrr: Money;
  yearly: Money;
  active: number;
  total: number;
  trial: number;
  owed: Money & { count: number };
  review: { count: number; amount: number };
}

export default function PlatformHomeCards() {
  const [data, setData] = useState<Summary | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    api.get<Summary>('/admin/reports/summary').then((res) => setData(res.data)).catch(() => setFailed(true));
  }, []);

  if (failed) return null;

  const money = (v: number) => formatMoney(v, data?.currency);
  const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`;
  const cards = data ? [
    { id: 'mrr', label: 'Ingreso mensual', value: money(data.mrr.amount), note: `${money(data.yearly.amount)} al año`, icon: Repeat, href: '/admin/reports' },
    { id: 'active', label: 'Empresas activas', value: String(data.active), note: `De ${data.total} en total`, icon: Building2, href: '/admin/reports?tab=tenants' },
    { id: 'trial', label: 'En prueba', value: String(data.trial), note: 'Aún no pagan su plan', icon: CalendarClock, href: '/admin/reports?tab=tenants' },
    {
      id: 'owed',
      label: 'Por cobrar',
      value: money(data.owed.amount),
      note: [data.owed.count ? plural(data.owed.count, 'empresa', 'empresas') : 'Nadie debe', data.review.count ? plural(data.review.count, 'comprobante en revisión', 'comprobantes en revisión') : ''].filter(Boolean).join(' · '),
      icon: Hourglass,
      href: data.review.count ? '/admin/cobros' : '/admin/reports',
      action: data.review.count ? 'Revisar comprobantes' : undefined,
    },
  ] : [];

  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4" data-platform-summary={data ? 'ready' : 'loading'}>
      {!data
        ? Array.from({ length: 4 }, (_, i) => <div key={i} className="h-[104px] bg-white rounded-2xl border border-slate-100 animate-pulse" />)
        : cards.map((card) => {
            const Icon = card.icon;
            return (
              <Link key={card.id} href={card.href} data-summary={card.id} className="group bg-white px-4 py-3 rounded-2xl border border-slate-100 shadow-[0_4px_20px_-4px_rgba(0,0,0,0.05)] hover:border-brand-soft/60 transition-colors">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <span className="text-[10px] font-semibold tracking-wider text-slate-400 uppercase">{card.label}</span>
                    <div className="mt-0.5 text-2xl font-extrabold tracking-tight text-slate-900 truncate">{card.value}</div>
                    <span className="block text-xs text-slate-500 mt-0.5">{card.note}</span>
                  </div>
                  <div className="p-2 rounded-xl bg-slate-50 border border-slate-100 text-brand-accent"><Icon size={18} /></div>
                </div>
                <span className="mt-2 flex items-center gap-1 text-[11px] font-semibold text-brand-accent group-hover:text-brand-ink">{'action' in card && card.action ? card.action : 'Ver reporte'} <ChevronRight size={12} /></span>
              </Link>
            );
          })}
    </div>
  );
}
