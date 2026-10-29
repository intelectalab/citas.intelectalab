'use client';

import { ArrowRight, Building2, Check } from 'lucide-react';
import { CORE_MODULE_LABELS, MODULE_LABELS, OPTIONAL_MODULE_KEYS } from '@/lib/modules';

export interface PublicPlan {
  currency_symbol?: string;
  id: string;
  name: string;
  description?: string | null;
  price_monthly: number;
  trial_config?: { has_trial?: boolean; trial_days?: number };
  modules?: Record<string, string[]>;
  limits?: Record<string, number>;
  features?: Record<string, any> | string[];
  is_popular?: boolean;
}

export function planHighlights(plan: PublicPlan): string[] {
  const limits = plan.limits ?? {};
  const items = [...Object.values(CORE_MODULE_LABELS)];

  if (limits.branches) items.push(limits.branches === 1 ? '1 sede' : `Hasta ${limits.branches} sedes`);
  if (limits.professionals) items.push(`Hasta ${limits.professionals} profesionales`);
  if (limits.appointments_month) items.push(`${limits.appointments_month.toLocaleString('es-PE')} citas al mes`);

  const included = new Set(Object.values(plan.modules ?? {}).flat());
  return [...items, ...OPTIONAL_MODULE_KEYS.filter((key) => included.has(key)).map((key) => MODULE_LABELS[key])];
}

export default function PlanCard({ plan, onSelect }: { plan: PublicPlan; onSelect: (plan: PublicPlan) => void }) {
  return (
    <div
      data-plan-card={plan.name}
      className={`bg-slate-50/50 border rounded-2xl p-6 shadow-sm hover:shadow-md transition-all flex flex-col justify-between relative ${
        plan.is_popular ? 'border-brand-accent ring-2 ring-brand-accent/10 bg-white' : 'border-slate-200'
      }`}
    >
      <div className="space-y-4">
        <div className="flex items-center justify-between">
          <h3 className="text-xl font-bold text-brand-ink">{plan.name}</h3>
          <div className="w-8 h-8 rounded-xl bg-brand-soft/20 flex items-center justify-center text-brand-accent">
            <Building2 className="w-4 h-4" />
          </div>
        </div>

        {plan.description && (
          <p className="text-xs text-slate-500 font-medium">{plan.description}</p>
        )}

        <div className="flex items-baseline gap-1">
          <span className="text-3xl font-black text-brand-ink">{plan.currency_symbol ?? 'S/'} {plan.price_monthly}</span>
          <span className="text-xs text-slate-400 font-medium">/ mes</span>
        </div>

        {plan.trial_config?.has_trial && plan.trial_config.trial_days ? (
          <span className="inline-block px-2.5 py-1 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-700 text-[11px] font-bold">
            {plan.trial_config.trial_days} días de prueba gratis
          </span>
        ) : null}

        <div className="border-t border-slate-200/60 pt-4 space-y-2">
          <span className="text-xs font-bold text-slate-400 uppercase tracking-wider block">
            Incluye
          </span>
          <ul className="text-xs text-slate-600 space-y-2 font-medium" data-plan-includes>
            {planHighlights(plan).map((item) => (
              <li key={item} className="flex items-center gap-2">
                <Check className="w-4 h-4 text-emerald-500 shrink-0" />
                {item}
              </li>
            ))}
          </ul>
        </div>
      </div>

      <button
        type="button"
        onClick={() => onSelect(plan)}
        className="mt-6 w-full bg-brand hover:bg-brand-hover text-white font-bold py-2.5 px-4 rounded-xl flex items-center justify-center gap-2 text-xs transition-all shadow-sm cursor-pointer"
      >
        <span>Elegir Plan y Registrarse</span>
        <ArrowRight className="w-3.5 h-3.5 text-brand-soft" />
      </button>
    </div>
  );
}
