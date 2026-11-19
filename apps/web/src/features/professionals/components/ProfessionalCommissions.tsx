'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { Percent, Target } from 'lucide-react';
import { api } from '@/lib/axios';
import SectionTitle from '@/app/components/common/SectionTitle';
import { money } from '@/features/sales/sales';
import { apiError } from '@/features/appointments/appointments';

type Rule = { id: string; type: 'service' | 'exception' | 'product'; service: string | null; product: string | null; percent: number; fixed: number };

type Data = {
  month: string;
  currency: string;
  goal_ready: boolean;
  goal: number | null;
  production: number;
  with_products?: boolean;
  progress: number | null;
  earned: number;
  own_percent: number | null;
  base: 'net' | 'gross';
  on_products: boolean;
  rules: Rule[];
  can_edit: boolean;
};

const card = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs';

const ruleTitle = (rule: Rule) =>
  rule.type === 'exception' ? `Excepción: ${rule.service}`
    : rule.type === 'service' ? `${rule.service} (para todos)`
      : rule.product ? `Producto: ${rule.product}` : 'Todos los productos';

export default function ProfessionalCommissions({ professionalId, reload, onEdit }: { professionalId: string; reload: number; onEdit?: () => void }) {
  const [data, setData] = useState<Data | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    api.get(`/professionals/${professionalId}/commissions`)
      .then((res) => { setData(res.data); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar las comisiones.')));
  }, [professionalId, reload]);

  const value = (rule: Rule) => (rule.fixed > 0 ? `${money(rule.fixed, data?.currency)} c/u` : `${rule.percent} %`);
  const rules = (data?.rules ?? []).filter((rule) => rule.type !== 'product' || data?.on_products);

  return (
    <section className={`${card} xl:col-span-3 overflow-hidden`} data-card="commissions">
      <div className="px-6 pt-5 pb-3">
        <SectionTitle><Target className="w-4 h-4 text-brand-accent" /> Comisiones y meta</SectionTitle>
        <p className="text-xs text-slate-500 font-medium mt-1">
          {data ? `${data.month}. ` : ''}Producción = el precio de lo que atendió este mes, con las sesiones de paquetes, como en Mis números{data?.with_products ? ', más los productos que vendió' : ''}.
        </p>
      </div>

      {error ? (
        <p className="px-6 pb-6 text-sm text-red-600">{error}</p>
      ) : !data ? (
        <p className="px-6 pb-6 text-sm text-slate-400">Cargando…</p>
      ) : (
        <div className="grid grid-cols-1 lg:grid-cols-2 border-t border-slate-100 lg:divide-x divide-slate-100">
          <div className="p-6 space-y-3" data-part="goal">
            <h4 className="text-sm font-bold text-brand-ink">Meta del mes</h4>
            {!data.goal_ready ? (
              <p className="text-sm text-amber-700">Falta actualizar la base de datos para guardar metas.</p>
            ) : data.goal ? (
              <>
                <p className="flex flex-wrap items-baseline gap-x-2">
                  <span className="text-2xl font-black text-brand-ink tabular-nums" data-value="production">{money(data.production, data.currency)}</span>
                  <span className="text-sm text-slate-500">de {money(data.goal, data.currency)} · <b data-value="progress">{data.progress} %</b></span>
                </p>
                <div className="h-2.5 rounded-full bg-slate-100 overflow-hidden" role="progressbar" aria-valuenow={data.progress ?? 0} aria-valuemin={0} aria-valuemax={100}>
                  <div className={`h-full rounded-full ${(data.progress ?? 0) < 50 ? 'bg-amber-400' : 'bg-brand-accent'}`} style={{ width: `${data.progress}%` }} />
                </div>
                <p className="text-xs text-slate-500">{(data.progress ?? 0) >= 100 ? '¡Meta cumplida!' : `Le faltan ${money(Math.max(0, data.goal - data.production), data.currency)}.`}</p>
              </>
            ) : (
              <p className="text-sm text-slate-500">
                Sin meta. Este mes lleva <b className="text-slate-700">{money(data.production, data.currency)}</b>.
                {data.can_edit && onEdit && <> <button type="button" data-action="set-goal" onClick={() => { onEdit(); setTimeout(() => { const field = document.getElementById('pro-goal'); field?.scrollIntoView({ block: 'center' }); field?.focus(); }, 350); }} className="font-bold text-brand-accent hover:text-brand-ink">Ponerle una meta</button></>}
              </p>
            )}
            <p className="text-sm text-slate-600 pt-2 border-t border-slate-100">
              Comisión ganada este mes: <b className="text-slate-800" data-value="earned">{money(data.earned, data.currency)}</b>
              {' · '}<Link href="/commissions" className="font-bold text-brand-accent hover:text-brand-ink">Ver liquidación</Link>
            </p>
          </div>

          <div className="p-6 space-y-3" data-part="rules">
            <h4 className="flex items-center gap-1.5 text-sm font-bold text-brand-ink"><Percent size={14} className="text-brand-accent" /> Reglas que le aplican</h4>
            <ul className="divide-y divide-slate-100 text-sm">
              <li className="flex items-center justify-between gap-3 py-2">
                <span className="text-slate-600">Su porcentaje por defecto</span>
                <b className="text-slate-800 tabular-nums">{data.own_percent !== null ? `${data.own_percent} %` : 'Sin comisión'}</b>
              </li>
              {rules.map((rule) => (
                <li key={rule.id} className="flex items-center justify-between gap-3 py-2">
                  <span className="text-slate-600 min-w-0 truncate">{ruleTitle(rule)}</span>
                  <b className="text-slate-800 tabular-nums whitespace-nowrap">{value(rule)}</b>
                </li>
              ))}
            </ul>
            <p className="text-xs text-slate-400">
              Se calcula sobre el precio {data.base === 'gross' ? 'antes de descuentos' : 'cobrado, ya con descuentos'}. Una excepción gana a la regla del servicio, y esta a su porcentaje.
              {data.can_edit && <> <Link href="/commissions/rules" className="font-bold text-brand-accent hover:text-brand-ink">Editar reglas</Link></>}
            </p>
          </div>
        </div>
      )}
    </section>
  );
}
