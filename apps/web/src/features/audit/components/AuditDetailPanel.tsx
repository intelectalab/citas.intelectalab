'use client';

import Link from 'next/link';
import { ArrowRight, History, LifeBuoy } from 'lucide-react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { momentOf } from '@/features/sales/sales';
import { ACTOR_TYPES, type AuditRow } from '../audit';

export default function AuditDetailPanel({ row, onClose, tenantHref }: { row: AuditRow | null; onClose: () => void; tenantHref?: (id: string) => string }) {
  if (!row) return null;

  const facts: [string, React.ReactNode][] = [
    ['Cuándo', momentOf(row.at)],
    ['Quién', <span key="who"><span className="font-semibold text-slate-800">{row.impersonator?.name ?? row.actor.name}</span><span className="block text-xs text-slate-400">{row.impersonator ? `Soporte de la plataforma, en la cuenta de ${row.actor.name}` : ACTOR_TYPES[row.actor.type]}</span></span>],
    ...(row.tenant && tenantHref ? [['Empresa', <Link key="tenant" href={tenantHref(row.tenant.id)} className="font-semibold text-brand-accent hover:underline">{row.tenant.name}</Link>] as [string, React.ReactNode]] : []),
    ...(row.branch ? [['Sede', row.branch.name] as [string, React.ReactNode]] : []),
    ...(row.entity_label && row.entity_label !== row.tenant?.name ? [['Sobre qué', row.entity_label] as [string, React.ReactNode]] : []),
    ['Área', row.area_label],
    ['Desde', [row.device, row.ip].filter(Boolean).join(' · ') || '—'],
  ];

  return (
    <FormDrawer isOpen onClose={onClose} title={row.label} subtitle={momentOf(row.at)} icon={<History size={18} />}>
      <div className="space-y-6" data-audit-detail={row.id}>
        {row.impersonator && (
          <div className="flex items-start gap-3 p-3.5 rounded-xl bg-amber-50 border border-amber-200 text-sm text-amber-800" data-audit-support>
            <LifeBuoy size={18} className="shrink-0 mt-0.5" />
            <span>Lo hizo <span className="font-semibold">{row.impersonator.name}</span>, del soporte de la plataforma, dentro de la cuenta de {row.actor.name}.</span>
          </div>
        )}

        <dl className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
          {facts.map(([label, value]) => (
            <div key={label}>
              <dt className="text-xs font-bold text-slate-400">{label}</dt>
              <dd className="mt-0.5 text-slate-700">{value}</dd>
            </div>
          ))}
        </dl>

        <section className="space-y-2" data-audit-changes>
          <h3 className="text-sm font-bold text-brand-ink">Qué cambió</h3>
          {row.changes.length === 0 ? (
            <p className="text-sm text-slate-500">Esta acción no cambia datos que se puedan comparar.</p>
          ) : (
            <ul className="space-y-2">
              {row.changes.map((change, index) => (
                <li key={index} className="p-3 rounded-xl bg-slate-50 border border-slate-200">
                  <span className="block text-xs font-bold text-slate-500">{change.field}</span>
                  <span className="mt-1 flex flex-wrap items-center gap-2 text-sm">
                    {change.from !== null && <span className="text-slate-500 line-through decoration-slate-300">{change.from}</span>}
                    {change.from !== null && change.to !== null && <ArrowRight size={14} className="text-slate-400 shrink-0" />}
                    {change.to !== null ? <span className="font-semibold text-slate-800">{change.to}</span> : <span className="text-slate-500">Se quitó</span>}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>
    </FormDrawer>
  );
}
