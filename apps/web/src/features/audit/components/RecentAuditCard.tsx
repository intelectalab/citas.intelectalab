'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { ArrowRight, History, LifeBuoy } from 'lucide-react';
import SectionTitle from '@/app/components/common/SectionTitle';
import { api } from '@/lib/axios';
import { momentOf } from '@/features/sales/sales';
import { changeSummary, type AuditRow } from '../audit';
import AuditDetailPanel from './AuditDetailPanel';

const LIMIT = 6;

interface RecentAuditCardProps {
  endpoint: string;
  params: Record<string, string>;
  title: string;
  allHref: string;
  empty: string;
  className: string;
  showActor?: boolean;
  refreshKey?: unknown;
}

export default function RecentAuditCard({ endpoint, params, title, allHref, empty, className, showActor = true, refreshKey }: RecentAuditCardProps) {
  const [rows, setRows] = useState<AuditRow[] | null>(null);
  const [viewing, setViewing] = useState<AuditRow | null>(null);
  const key = JSON.stringify(params);

  useEffect(() => {
    api.get(endpoint, { params: { ...JSON.parse(key), from: '2020-01-01', limit: LIMIT } })
      .then((res) => setRows(res.data.events))
      .catch(() => setRows([]));
  }, [endpoint, key, refreshKey]);

  return (
    <section className={className} data-recent-audit>
      <div className="flex items-center justify-between gap-3 mb-4">
        <SectionTitle>
          <span className="text-brand-accent"><History size={16} /></span> {title}
        </SectionTitle>
        <Link href={allHref} data-recent-audit-all className="inline-flex items-center gap-1 text-xs font-bold text-brand-accent hover:text-brand-ink transition-colors shrink-0">
          Ver todo <ArrowRight size={14} />
        </Link>
      </div>

      {rows === null ? (
        <p className="text-xs text-slate-400">Cargando…</p>
      ) : rows.length === 0 ? (
        <p className="text-xs text-slate-500">{empty}</p>
      ) : (
        <ul className="divide-y divide-slate-100">
          {rows.map((row) => (
            <li key={row.id}>
              <button type="button" onClick={() => setViewing(row)} className="w-full flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4 py-2.5 text-left hover:bg-slate-50 rounded-lg px-2 -mx-2 transition-colors">
                <span className="text-xs text-slate-400 sm:w-36 shrink-0">{momentOf(row.at)}</span>
                <span className="min-w-0 flex-1">
                  <span className="flex items-center gap-1.5 text-sm font-semibold text-slate-800">
                    {row.impersonator && <LifeBuoy size={14} className="text-amber-600 shrink-0" />}
                    {row.label}
                  </span>
                  <span className="block text-xs text-slate-500 truncate">{[row.entity_label, changeSummary(row) !== row.entity_label ? changeSummary(row) : ''].filter(Boolean).join(' · ') || row.area_label}</span>
                </span>
                {showActor && <span className="text-xs font-medium text-slate-500 sm:text-right shrink-0">{row.impersonator?.name ?? row.actor.name}</span>}
              </button>
            </li>
          ))}
        </ul>
      )}

      <AuditDetailPanel row={viewing} onClose={() => setViewing(null)} />
    </section>
  );
}
