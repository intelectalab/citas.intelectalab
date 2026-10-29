'use client';

import SectionTitle from '@/app/components/common/SectionTitle';
import { useCallback, useEffect, useState } from 'react';
import { Coins } from 'lucide-react';
import { api } from '@/lib/axios';
import Switch from '@/app/components/common/Switch';
import { PagedList } from '@/app/components/common/Pager';
import { apiError } from '@/features/appointments/appointments';
import type { CurrencyInfo } from '@/lib/currency';

interface PlatformCurrency extends CurrencyInfo {
  is_active: boolean;
  tenants: number;
  plans: number;
}

const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`;

export default function PlatformCurrencies() {
  const [list, setList] = useState<PlatformCurrency[] | null>(null);
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const load = useCallback(() => {
    api.get('/admin/currencies')
      .then((res) => { setList(res.data.currencies); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar las monedas.')));
  }, []);

  useEffect(() => { load(); }, [load]);

  const toggle = async (currency: PlatformCurrency) => {
    try {
      setBusy(currency.code);
      setError('');
      setNotice('');
      const res = await api.put(`/admin/currencies/${currency.code}`, { is_active: !currency.is_active });
      setList(res.data.currencies);
      setNotice(currency.is_active ? `${currency.name} ya no se ofrece a las empresas.` : `${currency.name} ya se puede elegir.`);
    } catch (err) {
      setError(apiError(err, 'No se pudo cambiar.'));
    } finally {
      setBusy('');
    }
  };

  return (
    <section className="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden" data-card="platform-currencies">
      <div className="p-6 pb-3 space-y-1">
        <SectionTitle>
          <span className="text-brand-accent"><Coins size={16} /></span> Monedas
        </SectionTitle>
        <p className="text-xs text-slate-500 font-medium">
          Las monedas que las empresas pueden elegir para cobrar y que puedes usar en el precio de los planes. El cambio rige al instante.
        </p>
        {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2 mt-2">{error}</p>}
        {notice && <p data-notice className="text-sm text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-lg px-3 py-2 mt-2">{notice}</p>}
      </div>
      <div className="border-t border-slate-100 flex flex-col">
        <PagedList
          items={list ?? []}
          empty={list ? 'No hay monedas.' : 'Cargando…'}
          renderRow={(currency) => (
            <li key={currency.code} className="flex items-center justify-between gap-4 px-6 py-3" data-currency={currency.code}>
              <div className="min-w-0">
                <span className="block text-sm font-semibold text-slate-800">{currency.name} <span className="font-normal text-slate-500">· {currency.symbol} · {currency.code}</span></span>
                <span className="block text-xs text-slate-400">
                  {currency.tenants || currency.plans
                    ? [currency.tenants ? `${plural(currency.tenants, 'empresa cobra', 'empresas cobran')} en ella` : '', currency.plans ? plural(currency.plans, 'plan', 'planes') + ' con su precio en ella' : ''].filter(Boolean).join(' · ')
                    : 'Nadie la usa todavía'}
                  {currency.decimals === 0 ? ' · sin decimales' : ''}
                </span>
              </div>
              <Switch checked={currency.is_active} disabled={busy === currency.code} onChange={() => toggle(currency)} label={`Ofrecer ${currency.name}`} />
            </li>
          )}
        />
      </div>
    </section>
  );
}
