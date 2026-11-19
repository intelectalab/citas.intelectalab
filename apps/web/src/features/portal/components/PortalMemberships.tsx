'use client';

import Badge from '@/app/components/common/Badge';
import SectionTitle from '@/app/components/common/SectionTitle';
import { BadgeCheck } from 'lucide-react';
import { PagedList } from '@/app/components/common/Pager';
import { money } from '@/features/sales/sales';
import { STATUS_BADGES, daysLeftLabel, shortDate, type CustomerMembership } from '@/features/memberships/memberships';

export type PortalMembership = Pick<CustomerMembership, 'id' | 'code' | 'name' | 'status' | 'status_label' | 'price' | 'currency' | 'period_label' | 'starts_at' | 'ends_at' | 'days_left' | 'benefits_text' | 'included' | 'period_end' | 'grace_until' | 'paused_at'>;

const validity = (m: PortalMembership): string => {
  if (m.status === 'active') return `Vale hasta el ${shortDate(m.ends_at)}${m.days_left <= 7 ? ` (${m.days_left <= 0 ? 'vence hoy' : m.days_left === 1 ? 'vence mañana' : `vence en ${m.days_left} días`})` : ''}.`;
  if (m.status === 'grace') return `Venció el ${shortDate(m.ends_at)}. Sigues con tus beneficios hasta el ${shortDate(m.grace_until)}: renuévala en tu próxima visita.`;
  if (m.status === 'paused') return `Está pausada desde el ${shortDate(m.paused_at)}. Los días pausada se suman a su vencimiento cuando vuelvas.`;
  return daysLeftLabel(m);
};

export default function PortalMemberships({ memberships }: { memberships: PortalMembership[] }) {
  const current = memberships.find((m) => m.status === 'active' || m.status === 'grace');

  return (
    <section className="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden" data-card="portal-memberships">
      <div className="px-5 pt-5 pb-3">
        <SectionTitle><BadgeCheck size={16} className="text-brand-accent" /> Mis membresías</SectionTitle>
        <p className="text-xs text-slate-500 mt-1">
          {current ? 'Tus beneficios se aplican solos al pagar en el local.' : 'Aquí verás tu membresía y lo que te queda en cada periodo.'}
        </p>
      </div>
      <div className="border-t border-slate-100">
        <PagedList
          items={memberships}
          empty="Aún no tienes membresías."
          renderRow={(m) => (
            <li key={m.id} className="px-5 py-4 space-y-2" data-portal-membership={m.code}>
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <span className="block text-sm font-bold text-slate-800">{m.name}</span>
                  <span className="block text-xs text-slate-500">{m.code} · {money(m.price, m.currency)} · {m.period_label}</span>
                </div>
                <Badge tone={STATUS_BADGES[m.status]} bold nowrap>{m.status_label}</Badge>
              </div>
              <p className="text-sm text-slate-700">{m.benefits_text}</p>
              <span className={`block text-xs font-medium ${m.status === 'grace' || (m.status === 'active' && m.days_left <= 7) ? 'text-amber-700' : 'text-slate-500'}`} data-validity>{validity(m)}</span>
              {(m.status === 'active' || m.status === 'grace') && m.included.length > 0 && (
                <ul className="text-xs text-slate-600 bg-slate-50 border border-slate-100 rounded-lg px-3 py-2 space-y-0.5" data-left>
                  {m.included.map((i) => (
                    <li key={i.service_id}>{i.name}: {i.left === 0 ? `ya usaste los ${i.sessions}` : `te ${i.left === 1 ? 'queda 1' : `quedan ${i.left}`} de ${i.sessions}`}</li>
                  ))}
                  {m.period_end && <li className="text-slate-400">Hasta el {shortDate(m.period_end)}. Lo que no uses no pasa al periodo siguiente.</li>}
                </ul>
              )}
            </li>
          )}
        />
      </div>
    </section>
  );
}
