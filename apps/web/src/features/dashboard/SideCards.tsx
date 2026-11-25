'use client';

import Link from 'next/link';
import { ExternalLink, Megaphone, Package, Zap } from 'lucide-react';

export interface LowStockItem {
  id: string;
  name: string;
  warehouse: string;
  quantity: number;
  min: number;
}

export interface MarketingData {
  campaigns: { id: string; name: string; channel: string; status: string; status_label: string; recipients: number }[] | null;
  automations: { id: string; name: string; sent: number }[] | null;
}

const cardClass = 'bg-white rounded-tr-[2rem] rounded-bl-[2rem] rounded-tl-md rounded-br-md border border-slate-200/80 shadow-xs p-6';

const number = (value: number) => new Intl.NumberFormat('es-PE', { maximumFractionDigits: 2 }).format(value);

export function LowStockCard({ items }: { items: LowStockItem[] }) {
  return (
    <div className={cardClass} data-card="low-stock">
      <div className="flex items-center justify-between pb-4 border-b border-slate-100 mb-4">
        <h3 className="font-bold text-brand-ink text-sm flex items-center gap-2">
          <Package className="w-4 h-4 text-brand-accent" /> Productos en su stock mínimo
        </h3>
        <Link href="/products" className="text-xs font-bold text-brand-accent hover:text-brand-ink flex items-center gap-1">
          Ver productos <ExternalLink className="w-3 h-3" />
        </Link>
      </div>
      <ul className="space-y-2">
        {items.map((item) => (
          <li key={`${item.id}-${item.warehouse}`} className="p-3 bg-slate-50 rounded-xl border border-slate-200/60 flex items-center justify-between gap-3" data-low-stock={item.id}>
            <div className="min-w-0">
              <p className="text-xs font-bold text-slate-800 truncate">{item.name}</p>
              <p className="text-[11px] text-slate-400 truncate">{item.warehouse}</p>
            </div>
            <span className={`text-[10px] font-semibold px-2 py-0.5 rounded-md border shrink-0 ${item.quantity <= 0 ? 'text-red-700 bg-red-50 border-red-200' : 'text-amber-700 bg-amber-50 border-amber-200'}`}>
              {number(item.quantity)} de {number(item.min)} mín.
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}

export function MarketingCard({ data }: { data: MarketingData }) {
  return (
    <div className={cardClass} data-card="marketing">
      {data.campaigns !== null && (
        <div>
          <div className="flex items-center justify-between pb-4 border-b border-slate-100 mb-4">
            <h3 className="font-bold text-brand-ink text-sm flex items-center gap-2">
              <Megaphone className="w-4 h-4 text-brand-accent" /> Campañas recientes
            </h3>
            <Link href="/campaigns" className="text-xs font-bold text-brand-accent hover:text-brand-ink flex items-center gap-1">
              Ver todas <ExternalLink className="w-3 h-3" />
            </Link>
          </div>
          {data.campaigns.length === 0 ? (
            <p className="p-4 text-center text-xs text-slate-400">Todavía no hay campañas.</p>
          ) : (
            <ul className="space-y-2">
              {data.campaigns.map((c) => (
                <li key={c.id} className="p-3 bg-slate-50 rounded-xl border border-slate-200/60 flex items-center justify-between gap-3" data-campaign={c.id}>
                  <div className="min-w-0">
                    <p className="text-xs font-bold text-slate-800 truncate">{c.name}</p>
                    <p className="text-[11px] text-slate-400 truncate">{c.channel} · {c.recipients} {c.recipients === 1 ? 'destinatario' : 'destinatarios'}</p>
                  </div>
                  <span className="text-[10px] font-semibold text-slate-600 bg-slate-200/60 px-2 py-0.5 rounded-md shrink-0">{c.status_label}</span>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}

      {data.automations !== null && (
        <div className={data.campaigns !== null ? 'mt-6' : ''}>
          <div className="flex items-center justify-between pb-4 border-b border-slate-100 mb-4">
            <h3 className="font-bold text-brand-ink text-sm flex items-center gap-2">
              <Zap className="w-4 h-4 text-brand-accent" /> Automatizaciones encendidas
            </h3>
            <Link href="/automations" className="text-xs font-bold text-brand-accent hover:text-brand-ink flex items-center gap-1">
              Ver <ExternalLink className="w-3 h-3" />
            </Link>
          </div>
          {data.automations.length === 0 ? (
            <p className="p-4 text-center text-xs text-slate-400">No hay automatizaciones encendidas.</p>
          ) : (
            <ul className="space-y-2.5">
              {data.automations.map((a) => {
                const top = Math.max(1, ...data.automations!.map((x) => x.sent));
                return (
                  <li key={a.id} data-automation={a.id}>
                    <div className="flex items-center justify-between gap-3 text-xs">
                      <span className="text-slate-700 font-semibold truncate">{a.name}</span>
                      <span className="text-slate-500 shrink-0">{a.sent} {a.sent === 1 ? 'enviado' : 'enviados'}</span>
                    </div>
                    <div className="mt-1 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                      <div className="h-full rounded-full bg-[var(--brand-link)]" style={{ width: `${(a.sent / top) * 100}%` }} />
                    </div>
                  </li>
                );
              })}
            </ul>
          )}
          <p className="mt-3 text-[11px] text-slate-400">Envíos de los últimos 30 días.</p>
        </div>
      )}
    </div>
  );
}
