'use client';

import Badge from '@/app/components/common/Badge';
import SectionTitle from '@/app/components/common/SectionTitle';
import { Boxes } from 'lucide-react';
import { PagedList } from '@/app/components/common/Pager';
import { money } from '@/features/sales/sales';
import { UsageBar } from '@/features/packages/components/PackagesBoard';
import { STATUS_BADGES, daysLeftLabel, shortDate, type CustomerPackage } from '@/features/packages/packages';

export type PortalPackage = Omit<CustomerPackage, 'customer' | 'pending_value' | 'refunded_amount' | 'sale_id' | 'professional_id' | 'conditions'> & { terms_text: string };

export default function PortalPackages({ packages, onBook }: { packages: PortalPackage[]; onBook?: () => void }) {
  const active = packages.filter((p) => p.status === 'active');
  const sorted = [...active, ...packages.filter((p) => p.status !== 'active')];

  return (
    <section className="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden" data-card="portal-packages">
      <div className="px-5 pt-5 pb-3">
        <SectionTitle><Boxes size={16} className="text-[#3F768C]" /> Mis paquetes</SectionTitle>
        <p className="text-xs text-slate-500 mt-1">
          {active.length
            ? (() => {
              const left = active.reduce((sum, p) => sum + p.sessions_total - p.sessions_used, 0);
              const booked = active.reduce((sum, p) => sum + p.sessions_scheduled, 0);
              return `Tienes ${left === 1 ? '1 sesión' : `${left} sesiones`} por usar${booked ? ` (${booked} ya ${booked === 1 ? 'tiene' : 'tienen'} cita)` : ''}. Al reservar uno de estos servicios, la cita usa tu paquete y no pagas nada.`;
            })()
            : 'Aquí verás las sesiones que pagues por adelantado.'}
        </p>
      </div>
      <div className="border-t border-slate-100">
        <PagedList
          items={sorted}
          empty="Aún no tienes paquetes."
          renderRow={(p) => (
            <li key={p.id} className="px-5 py-4 space-y-2" data-portal-package={p.code}>
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <span className="block text-sm font-bold text-slate-800">{p.name}</span>
                  <span className="block text-xs text-slate-500">{p.code} · {money(p.price, p.currency)} · comprado el {shortDate(p.purchased_at)}</span>
                </div>
                <Badge tone={STATUS_BADGES[p.status]} bold nowrap>{p.status_label}</Badge>
              </div>
              <UsageBar pkg={p} />
              <span className={`block text-xs font-medium ${p.status === 'active' && p.days_left <= 15 ? 'text-amber-700' : 'text-slate-500'}`}>{daysLeftLabel(p)}</span>
              <p className="text-xs text-slate-600 bg-slate-50 border border-slate-100 rounded-lg px-3 py-2 leading-relaxed">{p.terms_text}</p>
              {p.status === 'active' && p.sessions_available > 0 && onBook && (
                <button type="button" onClick={onBook} className="text-xs font-bold text-[#3F768C] underline underline-offset-2">Reservar una sesión</button>
              )}
            </li>
          )}
        />
      </div>
    </section>
  );
}
