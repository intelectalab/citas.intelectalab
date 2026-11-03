'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { Loader2, ShoppingBag } from 'lucide-react';
import SectionTitle from '@/app/components/common/SectionTitle';
import Select from '@/app/components/common/Select';
import { api } from '@/lib/axios';
import { money } from '@/features/sales/sales';
import { portalError } from '../portal';

export interface ShopPackage {
  id: string;
  name: string;
  description: string | null;
  price: number;
  currency: string;
  items: { service_id: string; name: string; sessions: number; price: number }[];
  sessions: number;
  normal_total: number;
  savings: number;
  savings_percent: number;
  validity_days: number;
  terms_text: string;
}

export interface ShopData {
  packages: ShopPackage[];
  branches: { id: string; name: string }[];
}

export default function PortalShop({ data }: { data: ShopData }) {
  const router = useRouter();
  const [branchId, setBranchId] = useState(data.branches.length === 1 ? data.branches[0].id : '');
  const [buying, setBuying] = useState<string | null>(null);
  const [terms, setTerms] = useState<string | null>(null);
  const [error, setError] = useState('');

  const buy = async (pkg: ShopPackage) => {
    if (!branchId) {
      setError('Elige primero la sede donde lo usarás.');
      return;
    }
    try {
      setBuying(pkg.id);
      setError('');
      const res = await api.post<{ token: string }>(`/portal/shop/packages/${pkg.id}`, { branch_id: branchId });
      router.push(`/pay/${res.data.token}`);
    } catch (err) {
      setError(portalError(err, 'No se pudo iniciar la compra.'));
      setBuying(null);
    }
  };

  return (
    <section className="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden" data-card="portal-shop">
      <div className="px-5 pt-5 pb-3 space-y-3">
        <div>
          <SectionTitle><ShoppingBag size={16} className="text-brand-accent" /> Comprar un paquete</SectionTitle>
          <p className="text-xs text-slate-500 mt-1">Paga en línea y el paquete queda a tu nombre al instante. Luego reservas cada sesión sin pagar nada más.</p>
        </div>
        {data.branches.length > 1 && (
          <div className="max-w-xs" data-field="shop-branch">
            <Select
              label="¿En qué sede lo usarás?"
              placeholder="Elige la sede"
              value={branchId}
              options={data.branches.map((b) => ({ label: b.name, value: b.id }))}
              onChange={(e) => { setBranchId(String(e.target.value)); setError(''); }}
            />
          </div>
        )}
        {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-xl px-4 py-3">{error}</p>}
      </div>
      <ul className="border-t border-slate-100 divide-y divide-slate-100">
        {data.packages.map((pkg) => (
          <li key={pkg.id} className="px-5 py-4 space-y-2" data-shop-package={pkg.name}>
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <span className="block text-sm font-bold text-slate-800">{pkg.name}</span>
                <span className="block text-xs text-slate-500">{pkg.items.map((i) => `${i.sessions} × ${i.name}`).join(' · ')}</span>
                <span className="block text-xs text-slate-500">Válido {pkg.validity_days} días desde la compra</span>
              </div>
              <div className="text-right shrink-0">
                <span className="block text-base font-black text-brand-ink tabular-nums">{money(pkg.price, pkg.currency)}</span>
                {pkg.savings > 0 && <span className="block text-xs font-semibold text-emerald-700">Ahorras {money(pkg.savings, pkg.currency)} ({pkg.savings_percent} %)</span>}
              </div>
            </div>
            {pkg.description && <p className="text-xs text-slate-600">{pkg.description}</p>}
            {terms === pkg.id && <p className="text-xs text-slate-600 bg-slate-50 border border-slate-100 rounded-lg px-3 py-2 leading-relaxed">{pkg.terms_text}</p>}
            <div className="flex flex-wrap items-center gap-2">
              <button type="button" onClick={() => setTerms(terms === pkg.id ? null : pkg.id)} className="text-xs font-bold text-brand-accent underline underline-offset-2">
                {terms === pkg.id ? 'Ocultar condiciones' : 'Ver condiciones'}
              </button>
              <button
                type="button"
                data-action="buy-package"
                disabled={buying !== null}
                onClick={() => buy(pkg)}
                className="ml-auto inline-flex items-center gap-1.5 px-4 py-2 text-xs font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50"
              >
                {buying === pkg.id ? <Loader2 size={14} className="animate-spin" /> : <ShoppingBag size={14} />} Comprar
              </button>
            </div>
          </li>
        ))}
      </ul>
      <p className="px-5 py-3 text-[11px] text-slate-400 border-t border-slate-100">Al comprar aceptas las condiciones del paquete. Para devoluciones, comunícate con nosotros.</p>
    </section>
  );
}
