'use client';

import { ArrowRight, CalendarCheck, Check, MapPin, Plus, Users } from 'lucide-react';
import { CORE_MODULE_LABELS, MODULE_LABELS, OPTIONAL_MODULE_KEYS } from '@/lib/modules';
import type { PublicPlan } from '@/features/subscriptions/components/PlanCard';
import type { WebPlan } from '../web';

export function planModules(plan: PublicPlan): string[] {
  const included = new Set(Object.values(plan.modules ?? {}).flat());
  return OPTIONAL_MODULE_KEYS.filter((key) => included.has(key));
}

function money(value: number) {
  return value.toLocaleString('es-PE', { maximumFractionDigits: value % 1 ? 2 : 0, minimumFractionDigits: value % 1 ? 2 : 0 });
}

function capacity(plan: PublicPlan) {
  const limits = plan.limits ?? {};
  const rows: { icon: typeof MapPin; value: string; label: string }[] = [];
  if (limits.branches) rows.push({ icon: MapPin, value: limits.branches.toLocaleString('es-PE'), label: limits.branches === 1 ? 'sede' : 'sedes' });
  if (limits.professionals) rows.push({ icon: Users, value: limits.professionals.toLocaleString('es-PE'), label: limits.professionals === 1 ? 'profesional' : 'profesionales' });
  if (limits.appointments_month) rows.push({ icon: CalendarCheck, value: limits.appointments_month.toLocaleString('es-PE'), label: 'citas al mes' });
  return rows;
}

export default function SitePlanCard({ plan, previous, cycle, onSelect, index, badge }: { plan: WebPlan; previous: WebPlan | null; cycle: 'monthly' | 'yearly'; onSelect: (plan: PublicPlan) => void; index: number; badge?: string }) {
  const symbol = plan.currency_symbol ?? 'S/';
  const yearly = cycle === 'yearly' && plan.price_yearly > 0;
  const saving = yearly ? Math.max(0, Math.round(plan.price_monthly * 12 - plan.price_yearly)) : 0;
  const modules = planModules(plan);
  const before = previous ? new Set(planModules(previous)) : null;
  const inherits = Boolean(before && [...before].every((key) => modules.includes(key)));
  const list = inherits ? modules.filter((key) => !before!.has(key)).map((key) => MODULE_LABELS[key]) : [...Object.values(CORE_MODULE_LABELS), ...modules.map((key) => MODULE_LABELS[key])];
  const trial = plan.trial_config?.has_trial !== false ? plan.trial_config?.trial_days ?? 0 : 0;
  const popular = Boolean(plan.is_popular);
  const rows = capacity(plan);

  return (
    <div
      data-plan-card={plan.name}
      data-reveal
      style={{ ['--d' as string]: `${index * 90}ms` }}
      className={`site-lift relative rounded-3xl border p-6 flex flex-col ${popular ? 'bg-brand text-white border-brand shadow-xl shadow-brand-ink/20 lg:-my-3 lg:py-9' : 'bg-white border-slate-200'}`}
    >
      {popular && (
        <span data-plan-popular className="absolute -top-3 left-1/2 -translate-x-1/2 px-3 py-1 rounded-full bg-brand-soft text-brand-ink text-[11px] font-black shadow-sm whitespace-nowrap">{badge ?? 'El más elegido'}</span>
      )}

      <div className="lg:min-h-[6.25rem]">
        <h3 className={`text-lg font-black ${popular ? 'text-white' : 'text-brand-ink'}`}>{plan.name}</h3>
        {plan.tagline && <p className={`text-xs font-bold mt-0.5 ${popular ? 'text-brand-soft' : 'text-brand-accent'}`} data-plan-tagline>{plan.tagline}</p>}
        {plan.description && <p className={`text-xs mt-1.5 leading-relaxed ${popular ? 'text-white/70' : 'text-slate-500'}`}>{plan.description}</p>}
      </div>

      <div className="mt-4" data-plan-price={yearly ? plan.price_yearly : plan.price_monthly}>
        <div className="flex items-baseline gap-1">
          <span className={`text-sm font-bold ${popular ? 'text-white/70' : 'text-slate-400'}`}>{symbol}</span>
          <span key={`${cycle}-${plan.id}`} className={`text-4xl font-black tabular-nums tracking-tight site-pop ${popular ? 'text-white' : 'text-brand-ink'}`}>{money(yearly ? plan.price_yearly : plan.price_monthly)}</span>
          <span className={`text-xs font-semibold ${popular ? 'text-white/60' : 'text-slate-400'}`}>{yearly ? '/ año' : '/ mes'}</span>
        </div>
        <p className={`text-[11px] mt-1 min-h-4 whitespace-nowrap ${popular ? 'text-white/70' : 'text-slate-500'}`}>
          {yearly ? <>Equivale a {symbol} {money(Math.round(plan.price_yearly / 12))} al mes{saving > 0 && <span className={`font-bold ${popular ? 'text-brand-soft' : 'text-emerald-700'}`} data-plan-saving> · ahorras {symbol} {money(saving)}</span>}</> : trial ? `${trial} días gratis, sin tarjeta` : 'Pago mes a mes'}
        </p>
      </div>

      {rows.length > 0 && (
        <div className={`mt-5 grid gap-1.5 rounded-2xl p-3 ${popular ? 'bg-white/10' : 'bg-brand-soft/10'}`} data-plan-capacity>
          {rows.map((row) => (
            <div key={row.label} className="flex items-center gap-2.5 text-xs">
              <row.icon className={`w-4 h-4 shrink-0 ${popular ? 'text-brand-soft' : 'text-brand-accent'}`} />
              <span className={`font-black tabular-nums ${popular ? 'text-white' : 'text-brand-ink'}`}>{row.value}</span>
              <span className={popular ? 'text-white/70' : 'text-slate-500'}>{row.label}</span>
            </div>
          ))}
        </div>
      )}

      <div className="mt-5 flex-1">
        <p className={`text-xs font-bold mb-2.5 ${popular ? 'text-white' : 'text-brand-ink'}`}>
          {inherits && previous ? <>Todo lo de {previous.name}, y además:</> : 'Incluye:'}
        </p>
        <ul className="space-y-2" data-plan-includes>
          {list.map((item) => (
            <li key={item} className={`flex items-start gap-2 text-xs ${popular ? 'text-white/85' : 'text-slate-600'}`}>
              <span className={`mt-px w-4 h-4 rounded-full flex items-center justify-center shrink-0 ${popular ? 'bg-brand-soft/25 text-brand-soft' : inherits ? 'bg-brand-soft/25 text-brand-accent' : 'bg-emerald-50 text-emerald-600'}`}>
                {inherits ? <Plus className="w-2.5 h-2.5" strokeWidth={3} /> : <Check className="w-2.5 h-2.5" strokeWidth={3} />}
              </span>
              {item}
            </li>
          ))}
        </ul>
      </div>

      <button
        type="button"
        onClick={() => onSelect(plan)}
        className={`group mt-6 w-full font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 text-sm transition-colors cursor-pointer ${popular ? 'bg-white text-brand-ink hover:bg-brand-soft' : 'bg-white text-brand-ink border border-brand-ink/15 hover:bg-brand hover:text-white hover:border-brand'}`}
      >
        {trial ? 'Probar gratis' : 'Elegir este plan'}
        <ArrowRight className="w-4 h-4 transition-transform group-hover:translate-x-1" />
      </button>
    </div>
  );
}
