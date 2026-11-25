'use client';

import Link from 'next/link';
import Badge from '@/app/components/common/Badge';
import SectionTitle from '@/app/components/common/SectionTitle';
import { Gift, Receipt, Star } from 'lucide-react';
import { PagedList } from '@/app/components/common/Pager';
import { money, momentOf } from '@/features/sales/sales';
import { shortDate } from '@/features/memberships/memberships';

export interface PortalPointMovement {
  id: string;
  type: string;
  type_label: string;
  points: number;
  balance_after: number;
  reason: string | null;
  sale_code: string | null;
  expires_at: string | null;
  expired_points: number;
  created_at: string;
}

export interface PortalBenefitsData {
  currency?: string;
  points: {
    balance: number;
    value: number;
    amount_per_point: number;
    point_value: number;
    min_redeem_points: number;
    expiring: { expires_at: string; points: number }[];
    movements: PortalPointMovement[];
  } | null;
  gift_cards: {
    id: string;
    code: string;
    initial_amount: number;
    balance: number;
    status: 'active' | 'redeemed' | 'expired' | 'cancelled';
    status_label: string;
    recipient_name: string | null;
    for_me: boolean;
    expires_at: string | null;
  }[] | null;
  purchases: {
    id: string;
    code: string;
    created_at: string;
    status: string;
    status_label: string;
    detail: string;
    total: number;
    paid?: number;
    balance?: number;
    currency: string;
    invoice_number: string | null;
    invoice_label: string | null;
  }[];
  summary?: {
    points: number | null;
    points_value: number | null;
    gift_balance: number | null;
    balance_due: number;
    credit_balance?: number;
    memberships: { id: string; name: string | null; ends_at: string | null }[];
    packages: { id: string; name: string; available: number; expires_at: string | null }[];
  };
}

export function BenefitsSummary({ data, currency, onOpen }: { data: PortalBenefitsData; currency?: string | null; onOpen?: () => void }) {
  const s = data.summary;
  if (!s) return null;
  const tiles = [
    s.points !== null ? { label: 'Puntos', value: `${s.points} pts`, hint: s.points_value ? `Equivalen a ${money(s.points_value, currency)}` : null } : null,
    (s.credit_balance ?? 0) > 0 ? { label: 'Saldo a favor', value: money(s.credit_balance ?? 0, currency), hint: 'Úsalo al pagar tu próxima compra o cita en la sede' } : null,
    s.gift_balance !== null && s.gift_balance > 0 ? { label: 'Saldo en gift cards', value: money(s.gift_balance, currency), hint: 'Úsalo al pagar en el local' } : null,
    ...s.packages.filter((p) => p.available > 0).slice(0, 2).map((p) => ({ label: p.name, value: `${p.available} ${p.available === 1 ? 'sesión' : 'sesiones'}`, hint: p.expires_at ? `Vence el ${shortDate(p.expires_at)}` : null })),
    ...s.memberships.slice(0, 1).map((m) => ({ label: m.name || 'Membresía', value: 'Activa', hint: m.ends_at ? `Hasta el ${shortDate(m.ends_at)}` : null })),
  ].filter(Boolean) as { label: string; value: string; hint: string | null }[];

  if (tiles.length === 0 && s.balance_due <= 0) return null;

  return (
    <section className={`${cardClass} p-5 space-y-3`} data-portal-benefits-summary>
      <div className="flex items-center justify-between gap-3">
        <SectionTitle><Gift size={16} className="text-brand-accent" /> Mis beneficios</SectionTitle>
        {onOpen && <button type="button" onClick={onOpen} data-action="open-benefits" className="text-xs font-bold text-brand-accent hover:text-brand-ink">Ver detalle</button>}
      </div>
      {tiles.length > 0 && (
        <div className="grid grid-cols-2 gap-2.5">
          {tiles.map((t) => (
            <div key={t.label} className="rounded-2xl bg-brand-soft/15 border border-brand-soft/60 px-3.5 py-2.5 min-w-0">
              <span className="block text-[11px] text-slate-500 truncate">{t.label}</span>
              <span className="block text-base font-black text-brand-ink tabular-nums">{t.value}</span>
              {t.hint && <span className="block text-[11px] text-slate-500 truncate">{t.hint}</span>}
            </div>
          ))}
        </div>
      )}
      {s.balance_due > 0 && (
        <p className="text-xs font-semibold text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-3.5 py-2.5" data-balance-due>
          Tienes un saldo pendiente de {money(s.balance_due, currency)}. Puedes pagarlo en tu próxima visita.
        </p>
      )}
    </section>
  );
}

const GIFT_TONES: Record<string, string> = {
  active: 'bg-emerald-50 text-emerald-700',
  redeemed: 'bg-slate-100 text-slate-600',
  expired: 'bg-amber-50 text-amber-700',
  cancelled: 'bg-rose-50 text-rose-600',
};

const cardClass = 'bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden';

export const hasBenefits = (data: PortalBenefitsData | null): boolean =>
  Boolean(data && (data.points || (data.gift_cards && data.gift_cards.length > 0) || data.purchases.length > 0));

export default function PortalBenefits({ data, currency }: { data: PortalBenefitsData; currency?: string | null }) {
  const points = data.points;
  const cards = data.gift_cards ?? [];

  return (
    <div className="space-y-6" data-card="portal-benefits">
      {points && (
        <section className={cardClass} data-portal-points>
          <div className="px-5 pt-5 pb-3">
            <SectionTitle><Star size={16} className="text-brand-accent" /> Mis puntos</SectionTitle>
            <p className="text-xs text-slate-500 mt-1">
              Sumas 1 punto por cada {money(points.amount_per_point, currency)} que pagas. Cada punto vale {money(points.point_value, currency)} al pagar en el local
              {points.min_redeem_points > 1 ? `, desde ${points.min_redeem_points} puntos` : ''}.
            </p>
          </div>
          <div className="px-5 pb-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div className="rounded-2xl bg-brand-soft/15 border border-brand-soft px-4 py-3">
              <span className="block text-xs text-slate-500">Tienes</span>
              <span className="block text-2xl font-black text-brand-ink tabular-nums" data-points-balance>{points.balance} pts</span>
              <span className="block text-xs text-slate-500">Equivalen a {money(points.value, currency)}</span>
            </div>
            <div className="rounded-2xl border border-slate-200 px-4 py-3">
              <span className="block text-xs text-slate-500">Por vencer</span>
              {points.expiring.length === 0 ? (
                <span className="block text-sm text-slate-600 mt-1">Ninguno por ahora.</span>
              ) : (
                <ul className="mt-1 space-y-0.5" data-points-expiring>
                  {points.expiring.map((e) => (
                    <li key={e.expires_at} className="text-sm text-slate-700"><strong className="tabular-nums">{e.points} pts</strong> vencen el {shortDate(e.expires_at)}</li>
                  ))}
                </ul>
              )}
            </div>
          </div>
          <div className="border-t border-slate-100">
            <PagedList
              items={points.movements}
              empty="Aún no tienes movimientos de puntos."
              renderRow={(m) => {
                const expiredAll = m.points > 0 && m.expired_points >= m.points;
                return (
                  <li key={m.id} className="px-5 py-3 flex items-start justify-between gap-3" data-point-movement={m.type}>
                    <div className="min-w-0">
                      <span className="block text-sm font-semibold text-slate-800">{m.type_label}{m.sale_code ? ` · ${m.sale_code}` : ''}</span>
                      <span className="block text-xs text-slate-500">{momentOf(m.created_at)}{m.reason ? ` · ${m.reason}` : ''}</span>
                      {m.points > 0 && m.expired_points > 0 && (
                        <span className="block text-xs font-semibold text-rose-600" data-expired>{expiredAll ? 'Vencidos' : `Vencieron ${m.expired_points}`}</span>
                      )}
                      {m.points > 0 && m.expired_points === 0 && m.expires_at && (
                        <span className="block text-xs text-slate-400">Vencen el {shortDate(m.expires_at)}</span>
                      )}
                    </div>
                    <span className={`text-sm font-black tabular-nums shrink-0 ${m.points > 0 ? (expiredAll ? 'text-slate-400 line-through' : 'text-emerald-700') : 'text-rose-700'}`}>
                      {m.points > 0 ? `+${m.points}` : m.points}
                    </span>
                  </li>
                );
              }}
            />
          </div>
        </section>
      )}

      {data.gift_cards && cards.length > 0 && (
        <section className={cardClass} data-portal-gift-cards>
          <div className="px-5 pt-5 pb-3">
            <SectionTitle><Gift size={16} className="text-brand-accent" /> Mis gift cards</SectionTitle>
            <p className="text-xs text-slate-500 mt-1">Al pagar en el local, da el código y se descuenta de su saldo.</p>
          </div>
          <div className="border-t border-slate-100">
            <PagedList
              items={cards}
              empty="No tienes gift cards."
              renderRow={(g) => (
                <li key={g.id} className="px-5 py-4 flex items-start justify-between gap-3" data-portal-gift-card={g.code}>
                  <div className="min-w-0">
                    <span className="block text-sm font-bold text-slate-800 font-mono">{g.code}</span>
                    <span className="block text-xs text-slate-500">
                      Saldo {money(g.balance, currency)} de {money(g.initial_amount, currency)}
                      {!g.for_me && g.recipient_name ? ` · regalo para ${g.recipient_name}` : ''}
                      {g.expires_at && g.status === 'active' ? ` · vale hasta el ${shortDate(g.expires_at)}` : ''}
                    </span>
                  </div>
                  <Badge tone={GIFT_TONES[g.status]} bold nowrap>{g.status_label}</Badge>
                </li>
              )}
            />
          </div>
        </section>
      )}

      <section className={cardClass} data-portal-purchases>
        <div className="px-5 pt-5 pb-3">
          <SectionTitle><Receipt size={16} className="text-brand-accent" /> Mis compras</SectionTitle>
          <p className="text-xs text-slate-500 mt-1">Abre el comprobante para verlo, imprimirlo o guardarlo en PDF.</p>
        </div>
        <div className="border-t border-slate-100">
          <PagedList
            items={data.purchases}
            empty="Aún no tienes compras pagadas."
            renderRow={(p) => (
              <li key={p.id} className="px-5 py-4 space-y-1" data-portal-purchase={p.code}>
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <span className="block text-sm font-bold text-slate-800">{p.invoice_number ? `${p.invoice_label ?? 'Comprobante'} ${p.invoice_number}` : `Compra ${p.code}`}</span>
                    <span className="block text-xs text-slate-500">{momentOf(p.created_at)} · {p.detail}</span>
                  </div>
                  <span className="text-sm font-black text-brand-ink tabular-nums shrink-0">{money(p.total, p.currency)}</span>
                </div>
                <div className="flex items-center justify-between gap-3">
                  <span className="text-xs text-slate-500">
                    {p.status_label}
                    {(p.balance ?? 0) > 0 && <strong className="ml-1.5 text-amber-700" data-purchase-balance>· saldo {money(p.balance ?? 0, p.currency)}</strong>}
                  </span>
                  <Link href={`/print/mis-compras/${p.id}`} target="_blank" data-receipt-link className="text-sm font-bold text-brand-accent hover:text-brand-ink transition-colors">
                    Ver comprobante
                  </Link>
                </div>
              </li>
            )}
          />
        </div>
      </section>
    </div>
  );
}
