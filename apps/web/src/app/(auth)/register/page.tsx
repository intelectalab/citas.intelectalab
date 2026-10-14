'use client';

import { useState, useEffect } from 'react';
import Link from 'next/link';
import { api } from '@/lib/axios';
import { Building2, Check, ArrowRight, Sparkles, DoorClosed, Mail } from 'lucide-react';
import { RegisterModal } from '@/features/subscriptions/components/RegisterModal';
import { CORE_MODULE_LABELS, MODULE_LABELS } from '@/lib/modules';

interface Plan {
  currency_symbol?: string;
  id: string;
  name: string;
  description?: string | null;
  price_monthly: number;
  trial_config?: { has_trial?: boolean; trial_days?: number };
  modules?: Record<string, string[]>;
  limits?: Record<string, number>;
  features?: Record<string, any>;
}

function planHighlights(plan: Plan): string[] {
  const limits = plan.limits ?? {};
  const items = [Object.values(CORE_MODULE_LABELS).join(' y ')];

  if (limits.branches) items.push(limits.branches === 1 ? '1 sede' : `Hasta ${limits.branches} sedes`);
  if (limits.professionals) items.push(`Hasta ${limits.professionals} profesionales`);
  if (limits.appointments_month) items.push(`${limits.appointments_month.toLocaleString('es-PE')} citas al mes`);

  const modules = Object.values(plan.modules ?? {}).flat();
  return [...items, ...modules.map((key) => MODULE_LABELS[key] ?? key)];
}

export default function RegisterPage() {
  const [plans, setPlans] = useState<Plan[]>([]);
  const [selectedPlan, setSelectedPlan] = useState<Plan | null>(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [signupOpen, setSignupOpen] = useState<boolean | null>(null);
  const [supportEmail, setSupportEmail] = useState<string | null>(null);

  useEffect(() => {
    api.get('/plans')
      .then((res) => {
        setPlans(res.data);
      })
      .catch((err) => {
        console.error('Error al cargar planes:', err);
      });
  }, []);

  useEffect(() => {
    api.get('/platform')
      .then((res) => {
        setSignupOpen(res.data.allow_tenant_signup !== false);
        setSupportEmail(res.data.support_email ?? null);
      })
      .catch(() => setSignupOpen(true));
  }, []);

  const handleSelectPlan = (plan: Plan) => {
    setSelectedPlan(plan);
    setIsModalOpen(true);
  };

  return (
    <div className="min-h-screen bg-slate-100/70 text-[#1F2937] p-6 md:p-12 font-sans flex items-center justify-center">
      <div className="max-w-5xl w-full mx-auto space-y-8 bg-white p-8 md:p-12 rounded-3xl border border-slate-200/80 shadow-xl">

        {signupOpen === false ? (
          <div className="max-w-xl mx-auto text-center space-y-4 py-8">
            <span className="inline-flex p-4 rounded-2xl bg-amber-50 border border-amber-200 text-amber-600">
              <DoorClosed className="w-8 h-8" />
            </span>
            <h1 className="text-3xl font-black text-[#022340] tracking-tight">
              El registro está cerrado por ahora
            </h1>
            <p className="text-slate-500 text-sm font-medium leading-relaxed">
              En este momento no estamos recibiendo empresas nuevas desde esta página. Si ya tienes una cuenta, puedes iniciar sesión como siempre.
            </p>
            {supportEmail && (
              <p className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-50 border border-slate-200 text-sm font-semibold text-[#022340]">
                <Mail className="w-4 h-4 text-[#3F768C]" />
                Escríbenos a <a href={`mailto:${supportEmail}`} className="text-[#3F768C] hover:underline">{supportEmail}</a> y creamos tu cuenta.
              </p>
            )}
            <div>
              <Link
                href="/login"
                className="inline-flex items-center gap-2 bg-[#022340] hover:bg-[#012840] text-white font-bold py-2.5 px-5 rounded-xl text-xs transition-all shadow-sm"
              >
                Ir a iniciar sesión <ArrowRight className="w-3.5 h-3.5 text-[#84D0D9]" />
              </Link>
            </div>
          </div>
        ) : (
          <>
        <div className="text-center space-y-3 max-w-2xl mx-auto">
          <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-[#84D0D9]/15 border border-[#84D0D9]/30 text-[#022340] text-xs font-bold uppercase tracking-wider">
            <Sparkles className="w-3.5 h-3.5 text-[#3F768C]" />
            Paso 1: Elige tu Plan
          </div>
          <h1 className="text-3xl sm:text-4xl font-black text-[#022340] tracking-tight">
            Comienza a gestionar tu negocio
          </h1>
          <p className="text-slate-500 text-sm font-medium">
            Selecciona el plan ideal para tu empresa. El registro se completará paso a paso.
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 pt-4">
          {plans.map((plan) => (
            <div
              key={plan.id}
              className="bg-slate-50/50 border border-slate-200 rounded-2xl p-6 shadow-sm hover:shadow-md transition-all flex flex-col justify-between relative"
            >
              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <h2 className="text-xl font-bold text-[#022340]">{plan.name}</h2>
                  <div className="w-8 h-8 rounded-xl bg-[#84D0D9]/20 flex items-center justify-center text-[#3F768C]">
                    <Building2 className="w-4 h-4" />
                  </div>
                </div>

                {plan.description && (
                  <p className="text-xs text-slate-500 font-medium">{plan.description}</p>
                )}

                <div className="flex items-baseline gap-1">
                  <span className="text-3xl font-black text-[#022340]">{plan.currency_symbol ?? 'S/'} {plan.price_monthly}</span>
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
                  <ul className="text-xs text-slate-600 space-y-2 font-medium">
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
                onClick={() => handleSelectPlan(plan)}
                className="mt-6 w-full bg-[#022340] hover:bg-[#012840] text-white font-bold py-2.5 px-4 rounded-xl flex items-center justify-center gap-2 text-xs transition-all shadow-sm cursor-pointer"
              >
                <span>Elegir Plan y Registrarse</span>
                <ArrowRight className="w-3.5 h-3.5 text-[#84D0D9]" />
              </button>
            </div>
          ))}
        </div>

          </>
        )}

        <div className="text-center text-xs text-slate-500 font-medium pt-4 border-t border-slate-100">
          ¿Ya tienes una cuenta registrada?{' '}
          <Link href="/login" className="text-[#3F768C] font-bold hover:underline">
            Iniciar Sesión
          </Link>
        </div>
      </div>

      <RegisterModal
        isOpen={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        selectedPlan={selectedPlan}
      />
    </div>
  );
}