'use client';

import { useState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { api } from '@/lib/axios';
import { Building2, Check, ArrowRight, Sparkles } from 'lucide-react';
import { RegisterModal } from '@/features/subscriptions/components/RegisterModal';

interface Plan {
  currency_symbol?: string;
  id: string | number;
  name: string;
  price_monthly: number;
  description?: string;
  features?: string[] | Record<string, any>;
  is_popular?: boolean;
}

export default function LandingPage() {
  const router = useRouter();
  const [plans, setPlans] = useState<Plan[]>([]);
  const [selectedPlan, setSelectedPlan] = useState<Plan | null>(null);
  
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  useEffect(() => {
    api.get('/plans')
      .then((res) => {
        const responseData = res.data;
        const rawData = Array.isArray(responseData) 
          ? responseData 
          : (responseData.data || responseData.plans || []);
        setPlans(rawData);
      })
      .catch((err) => {
        console.error('Error al cargar planes:', err);
      });
  }, []);

  const handleSelectPlan = (plan: Plan) => {
    setSelectedPlan(plan);
    setIsModalOpen(true);
  };

  return (
    <div className="min-h-screen bg-slate-100/70 text-[#1F2937] font-sans flex flex-col">
      
      <header className="w-full bg-white border-b border-slate-200/80 px-6 lg:px-16 py-4 flex items-center justify-between sticky top-0 z-40">
        <div className="flex items-center gap-2.5">
          <div className="w-9 h-9 rounded-xl bg-[#022340] text-[#84D0D9] flex items-center justify-center font-bold">
            <Building2 className="w-5 h-5" />
          </div>
          <span className="text-lg font-black tracking-tight text-[#022340]">MiSaaS</span>
        </div>

        <div className="flex items-center gap-4">
          <button 
            onClick={() => {
              document.getElementById('planes')?.scrollIntoView({ behavior: 'smooth' });
            }}
            className="text-xs sm:text-sm font-bold text-slate-600 hover:text-[#022340] transition-colors cursor-pointer bg-transparent border-none p-0"
          >
            Planes
          </button>
          
          <button 
            onClick={() => router.push('/login')}
            className="text-xs sm:text-sm font-bold text-slate-600 hover:text-[#022340] transition-colors cursor-pointer"
          >
            Iniciar sesión
          </button>

          <button 
            onClick={() => {
              if (plans.length > 0) handleSelectPlan(plans[0]);
            }}
            className="bg-[#022340] hover:bg-[#01182c] text-white font-bold text-xs sm:text-sm px-4 py-2 rounded-xl transition-all shadow-sm cursor-pointer"
          >
            Comenzar
          </button>
        </div>
      </header>

      <div className="flex-1 max-w-6xl mx-auto px-6 lg:px-16 py-12 md:py-20 grid grid-cols-1 md:grid-cols-2 gap-12 items-center">
        <div className="space-y-6">
          <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-[#84D0D9]/15 border border-[#84D0D9]/30 text-[#022340] text-xs font-bold uppercase tracking-wider">
            <Sparkles className="w-3.5 h-3.5 text-[#3F768C]" />
            Plataforma Empresarial SaaS
          </div>
          <h1 className="text-4xl sm:text-5xl font-black text-[#022340] tracking-tight leading-tight">
            Gestiona tu empresa desde una sola plataforma.
          </h1>
          <p className="text-slate-500 text-sm sm:text-base font-medium leading-relaxed">
            Selecciona el plan que necesitas, registra tu empresa y accede de forma inmediata a los módulos habilitados para tu suscripción.
          </p>
          <div className="flex items-center gap-4 pt-2">
            <button 
              onClick={() => {
                document.getElementById('planes')?.scrollIntoView({ behavior: 'smooth' });
              }}
              className="bg-[#022340] hover:bg-[#01182c] text-white font-bold py-3 px-6 rounded-xl text-xs sm:text-sm transition-all shadow-md flex items-center gap-2 cursor-pointer"
            >
              <span>Ver planes</span>
              <ArrowRight className="w-4 h-4 text-[#84D0D9]" />
            </button>
            <button 
              onClick={() => router.push('/login')}
              className="bg-white hover:bg-slate-50 text-[#022340] border border-slate-200 font-bold py-3 px-6 rounded-xl text-xs sm:text-sm transition-all shadow-xs cursor-pointer"
            >
              Ya tengo una cuenta
            </button>
          </div>
        </div>

        <div className="bg-white p-6 sm:p-8 rounded-3xl border border-slate-200 shadow-xl space-y-6 relative overflow-hidden">
          <div className="absolute top-0 right-0 w-32 h-32 bg-[#84D0D9]/10 rounded-full blur-2xl pointer-events-none" />
          <div className="space-y-1">
            <span className="text-xs text-slate-400 font-semibold uppercase tracking-wider">Ventas del mes</span>
            <div className="text-3xl sm:text-4xl font-black text-[#022340]">S/ 48,250</div>
            <span className="text-xs text-emerald-600 font-bold">+12.8% respecto al mes anterior</span>
          </div>
          <div className="border-t border-slate-100 pt-4 space-y-2">
            <span className="text-xs text-slate-400 font-semibold uppercase tracking-wider">Módulos disponibles</span>
            <div className="text-xs font-bold text-[#3F768C] bg-slate-50 p-3 rounded-xl border border-slate-100">
              Ventas • Clientes • Inventario • CRM
            </div>
          </div>
        </div>
      </div>

      <div id="planes" className="bg-white border-t border-slate-200/80 py-20 px-6 lg:px-16">
        <div className="max-w-5xl mx-auto space-y-12">
          <div className="text-center space-y-3 max-w-2xl mx-auto">
            <h2 className="text-3xl sm:text-4xl font-black text-[#022340] tracking-tight">
              Elige el plan para tu empresa
            </h2>
            <p className="text-slate-500 text-sm font-medium">
              Los módulos disponibles dependen del plan contratado.
            </p>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
            {plans.map((plan) => (
              <div 
                key={plan.id} 
                className={`bg-slate-50/50 border rounded-2xl p-6 shadow-sm hover:shadow-md transition-all flex flex-col justify-between relative ${
                  plan.is_popular ? 'border-[#3F768C] ring-2 ring-[#3F768C]/10 bg-white' : 'border-slate-200'
                }`}
              >
                <div className="space-y-4">
                  <div className="path items-center justify-between flex">
                    <h3 className="text-xl font-bold text-[#022340]">{plan.name}</h3>
                    <div className="w-8 h-8 rounded-xl bg-[#84D0D9]/20 flex items-center justify-center text-[#3F768C]">
                      <Building2 className="w-4 h-4" />
                    </div>
                  </div>

                  <div className="flex items-baseline gap-1">
                    <span className="text-3xl font-black text-[#022340]">{plan.currency_symbol ?? 'S/'} {plan.price_monthly}</span>
                    <span className="text-xs text-slate-400 font-medium">/ mes</span>
                  </div>

                  <div className="border-t border-slate-200/60 pt-4 space-y-2">
                    <span className="text-xs font-bold text-slate-400 uppercase tracking-wider block">
                      Incluye
                    </span>
                    <ul className="text-xs text-slate-600 space-y-2 font-medium">
                      <li className="flex items-center gap-2">
                        <Check className="w-4 h-4 text-emerald-500 shrink-0" />
                        Acceso completo al módulo ERP
                      </li>
                      <li className="flex items-center gap-2">
                        <Check className="w-4 h-4 text-emerald-500 shrink-0" />
                        Rol de Dueño (Owner) incluido
                      </li>
                    </ul>
                  </div>
                </div>

                <button
                  onClick={() => handleSelectPlan(plan)}
                  className="mt-6 w-full bg-[#022340] hover:bg-[#01182c] text-white font-bold py-2.5 px-4 rounded-xl flex items-center justify-center gap-2 text-xs transition-all shadow-sm cursor-pointer"
                >
                  <span>Elegir Plan y Registrarse</span>
                  <ArrowRight className="w-3.5 h-3.5 text-[#84D0D9]" />
                </button>
              </div>
            ))}
          </div>
        </div>
      </div>

      <RegisterModal 
        isOpen={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        selectedPlan={selectedPlan as any}
      />

    </div>
  );
}