'use client';

import { History } from 'lucide-react';
import { PagedList } from '@/app/components/common/Pager';
import { momentOf } from '@/features/sales/sales';
import type { AccessChange } from '../access';

export default function AccessHistory({ history, empty, note = 'Quién cambió sus permisos, su rol o su cuenta, y cuándo.' }: { history: AccessChange[]; empty: string; note?: string }) {
  return (
    <section className="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden" data-card="history">
      <div className="px-6 pt-5 pb-3">
        <h2 className="flex items-center gap-2 text-sm font-extrabold text-brand-ink"><History className="w-4 h-4 text-brand-accent" /> Historial de cambios</h2>
        <p className="text-xs text-slate-500 mt-1">{note}</p>
      </div>
      <PagedList
        items={history}
        empty={empty}
        renderRow={(change) => (
          <li key={change.id} className="px-6 py-2.5" data-history-row>
            <span className="block text-sm text-slate-700">{change.text}</span>
            <span className="block text-xs text-slate-400">{change.by} · {momentOf(change.at)}</span>
          </li>
        )}
      />
    </section>
  );
}
