'use client';

import { useState, useEffect } from 'react';
import Link from 'next/link';
import { api } from '@/lib/axios';
import { ArrowRight, Sparkles, DoorClosed, Mail } from 'lucide-react';
import { RegisterModal } from '@/features/subscriptions/components/RegisterModal';
import PlanCard, { type PublicPlan as Plan } from '@/features/subscriptions/components/PlanCard';

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
            <h1 className="text-3xl font-black text-brand-ink tracking-tight">
              El registro está cerrado por ahora
            </h1>
            <p className="text-slate-500 text-sm font-medium leading-relaxed">
              En este momento no estamos recibiendo empresas nuevas desde esta página. Si ya tienes una cuenta, puedes iniciar sesión como siempre.
            </p>
            {supportEmail && (
              <p className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-50 border border-slate-200 text-sm font-semibold text-brand-ink">
                <Mail className="w-4 h-4 text-brand-accent" />
                Escríbenos a <a href={`mailto:${supportEmail}`} className="text-brand-accent hover:underline">{supportEmail}</a> y creamos tu cuenta.
              </p>
            )}
            <div>
              <Link
                href="/login"
                className="inline-flex items-center gap-2 bg-brand hover:bg-brand-hover text-white font-bold py-2.5 px-5 rounded-xl text-xs transition-all shadow-sm"
              >
                Ir a iniciar sesión <ArrowRight className="w-3.5 h-3.5 text-brand-soft" />
              </Link>
            </div>
          </div>
        ) : (
          <>
        <div className="text-center space-y-3 max-w-2xl mx-auto">
          <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-brand-soft/15 border border-brand-soft/30 text-brand-ink text-xs font-bold uppercase tracking-wider">
            <Sparkles className="w-3.5 h-3.5 text-brand-accent" />
            Paso 1: Elige tu Plan
          </div>
          <h1 className="text-3xl sm:text-4xl font-black text-brand-ink tracking-tight">
            Comienza a gestionar tu negocio
          </h1>
          <p className="text-slate-500 text-sm font-medium">
            Selecciona el plan ideal para tu empresa. El registro se completará paso a paso.
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 pt-4">
          {plans.map((plan) => (
            <PlanCard key={plan.id} plan={plan} onSelect={handleSelectPlan} />
          ))}
        </div>

          </>
        )}

        <div className="text-center text-xs text-slate-500 font-medium pt-4 border-t border-slate-100">
          ¿Ya tienes una cuenta registrada?{' '}
          <Link href="/login" className="text-brand-accent font-bold hover:underline">
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