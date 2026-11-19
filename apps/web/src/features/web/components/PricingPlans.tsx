'use client';

import { useState } from 'react';
import PlanCard, { type PublicPlan } from '@/features/subscriptions/components/PlanCard';
import { RegisterModal } from '@/features/subscriptions/components/RegisterModal';
import type { WebPlan } from '../web';

export default function PricingPlans({ plans, allowSignup, showToggle = true }: { plans: WebPlan[]; allowSignup: boolean; showToggle?: boolean }) {
  const [cycle, setCycle] = useState<'monthly' | 'yearly'>('monthly');
  const [selected, setSelected] = useState<PublicPlan | null>(null);
  const bestSaving = plans.reduce((best, plan) => {
    const full = plan.price_monthly * 12;
    return full > 0 && plan.price_yearly > 0 ? Math.max(best, Math.round(((full - plan.price_yearly) / full) * 100)) : best;
  }, 0);
  const anyYearly = plans.some((plan) => plan.price_yearly > 0);

  const pick = (plan: PublicPlan) => {
    if (!allowSignup) {
      window.location.href = '/register';
      return;
    }
    setSelected(plan);
  };

  return (
    <div className="space-y-8" data-pricing>
      {showToggle && anyYearly && (
        <div className="flex justify-center">
          <div role="group" aria-label="Forma de pago" className="inline-flex p-1 rounded-xl bg-slate-100 border border-slate-200" data-cycle-toggle>
            {(['monthly', 'yearly'] as const).map((value) => (
              <button
                key={value}
                type="button"
                aria-pressed={cycle === value}
                data-cycle={value}
                onClick={() => setCycle(value)}
                className={`px-4 py-2 text-sm font-bold rounded-lg transition-colors cursor-pointer ${cycle === value ? 'bg-white text-brand-ink shadow-sm' : 'text-slate-500 hover:text-brand-ink'}`}
              >
                {value === 'monthly' ? 'Mensual' : 'Anual'}
                {value === 'yearly' && bestSaving > 0 && <span className="ml-1.5 text-[11px] font-bold text-emerald-700">-{bestSaving} %</span>}
              </button>
            ))}
          </div>
        </div>
      )}

      <div className={`grid grid-cols-1 md:grid-cols-2 ${plans.length >= 4 ? 'lg:grid-cols-4' : 'lg:grid-cols-3'} gap-6 items-stretch pt-3`}>
        {plans.map((plan) => (
          <PlanCard key={plan.id} plan={plan} cycle={cycle} onSelect={pick} actionLabel="Probar gratis con este plan" />
        ))}
      </div>

      {cycle === 'yearly' && (
        <p className="text-center text-xs text-slate-500" data-yearly-note>El pago anual se hace por adelantado y también puede ser por transferencia, Yape o Plin. Durante la prueba gratis no se cobra nada.</p>
      )}

      <RegisterModal isOpen={Boolean(selected)} onClose={() => setSelected(null)} selectedPlan={selected as never} />
    </div>
  );
}
