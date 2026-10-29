'use client';

import Badge from '@/app/components/common/Badge';
import SectionTitle from '@/app/components/common/SectionTitle';
import { useEffect, useState } from 'react';
import Link from 'next/link';
import { Boxes } from 'lucide-react';
import { api } from '@/lib/axios';
import { PagedList } from '@/app/components/common/Pager';
import { money } from '@/features/sales/sales';
import { UsageBar } from './PackagesBoard';
import { STATUS_BADGES, daysLeftLabel, type CustomerPackage } from '../packages';

const card = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs';

export default function CustomerPackages({ customerId }: { customerId: string }) {
  const [packages, setPackages] = useState<CustomerPackage[] | null>(null);

  useEffect(() => {
    api.get('/packages', { params: { customer_id: customerId } })
      .then((res) => setPackages(res.data.packages ?? []))
      .catch(() => setPackages([]));
  }, [customerId]);

  if (packages === null) return null;

  const active = packages.filter((p) => p.status === 'active');
  const sorted = [...active, ...packages.filter((p) => p.status !== 'active')];

  return (
    <section className={`${card} overflow-hidden flex flex-col`} data-card="packages">
      <div className="px-6 pt-5 pb-3 flex items-start justify-between gap-3">
        <div>
          <SectionTitle><Boxes className="w-4 h-4 text-brand-accent" /> Paquetes</SectionTitle>
          <p className="text-xs text-slate-500 font-medium mt-1">
            {active.length ? `${active.length} vigente${active.length === 1 ? '' : 's'}: ${active.reduce((sum, p) => sum + p.sessions_total - p.sessions_used, 0)} sesiones por usar.` : 'Sesiones que pagó por adelantado.'}
          </p>
        </div>
      </div>
      <div className="border-t border-slate-100 flex flex-col">
        <PagedList
          items={sorted}
          empty="No tiene paquetes."
          renderRow={(p) => (
            <li key={p.id} className="flex items-center gap-4 px-6 py-3 hover:bg-slate-50 transition-colors" data-customer-package={p.code}>
              <div className="flex-1 min-w-0">
                <Link href={`/packages/${p.id}`} className="block text-sm font-semibold text-slate-800 hover:text-brand-accent truncate">{p.name}</Link>
                <span className="block text-xs text-slate-500">{p.code} · {money(p.price, p.currency)} · {daysLeftLabel(p)}</span>
              </div>
              <div className="hidden sm:block w-44 shrink-0"><UsageBar pkg={p} /></div>
              <Badge tone={STATUS_BADGES[p.status]} nowrap>{p.status_label}</Badge>
            </li>
          )}
        />
      </div>
    </section>
  );
}
