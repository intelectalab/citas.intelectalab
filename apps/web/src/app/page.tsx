'use client';

import { useState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { api } from '@/lib/axios';
import { Building2, ArrowRight, Sparkles } from 'lucide-react';
import { RegisterModal } from '@/features/subscriptions/components/RegisterModal';
import PlanCard, { type PublicPlan as Plan } from '@/features/subscriptions/components/PlanCard';
import { usePlatform } from '@/hooks/usePlatform';
import { LookIcon } from '@/features/platform/lookIcons';

export default function LandingPage() {
  const router = useRouter();
  const { platform } = usePlatform();
  const { brand, landing } = platform.look;
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
      
      <header className="w-full bg-white border-b border-slate-200/80 px-4 sm:px-6 lg:px-16 py-4 flex items-center justify-between gap-3 sm:gap-0 sticky top-0 z-40">
        <div className="flex items-center gap-2.5 min-w-0">
          {brand.logo_url ? (
            <img src={brand.logo_url} alt={platform.product_name} className="h-9 max-w-40 object-contain" data-landing-logo />
          ) : (
            <>
              <div className="min-w-9 h-9 px-1.5 rounded-xl bg-brand text-brand-soft flex items-center justify-center font-bold text-xs">
                {brand.short_name ?? <Building2 className="w-5 h-5" />}
              </div>
              <span className="text-lg font-black tracking-tight text-brand-ink truncate" data-product-name>{platform.product_name}</span>
            </>
          )}
        </div>

        <div className="flex items-center gap-3 sm:gap-4 shrink-0">
          <button 
            onClick={() => {
              document.getElementById('planes')?.scrollIntoView({ behavior: 'smooth' });
            }}
            className="hidden sm:inline text-xs sm:text-sm font-bold text-slate-600 hover:text-brand-ink transition-colors cursor-pointer bg-transparent border-none p-0"
          >
            Planes
          </button>
          
          <button 
            onClick={() => router.push('/login')}
            className="whitespace-nowrap text-xs sm:text-sm font-bold text-slate-600 hover:text-brand-ink transition-colors cursor-pointer"
          >
            Iniciar sesión
          </button>

          <button 
            onClick={() => {
              if (plans.length > 0) handleSelectPlan(plans[0]);
            }}
            className="bg-brand hover:bg-brand-hover text-white font-bold text-xs sm:text-sm px-4 py-2 rounded-xl transition-all shadow-sm cursor-pointer"
          >
            Comenzar
          </button>
        </div>
      </header>

      <div className="flex-1 max-w-6xl mx-auto px-6 lg:px-16 py-12 md:py-20 grid grid-cols-1 md:grid-cols-2 gap-12 items-center">
        <div className="space-y-6">
          {landing.tag && (
            <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-brand-soft/15 border border-brand-soft/30 text-brand-ink text-xs font-bold uppercase tracking-wider" data-landing-tag>
              <Sparkles className="w-3.5 h-3.5 text-brand-accent" />
              {landing.tag}
            </div>
          )}
          <h1 className="text-4xl sm:text-5xl font-black text-brand-ink tracking-tight leading-tight" data-landing-title>
            {landing.title}{landing.highlight && <> <span className="text-brand-accent">{landing.highlight}</span></>}
          </h1>
          {landing.description && (
            <p className="text-slate-500 text-sm sm:text-base font-medium leading-relaxed">
              {landing.description}
            </p>
          )}
          <div className="flex items-center gap-4 pt-2">
            <button 
              onClick={() => {
                document.getElementById('planes')?.scrollIntoView({ behavior: 'smooth' });
              }}
              className="bg-brand hover:bg-brand-hover text-white font-bold py-3 px-6 rounded-xl text-xs sm:text-sm transition-all shadow-md flex items-center gap-2 cursor-pointer"
            >
              <span>{landing.primary_button}</span>
              <ArrowRight className="w-4 h-4 text-brand-soft" />
            </button>
            <button
              onClick={() => router.push('/login')}
              className="bg-white hover:bg-slate-50 text-brand-ink border border-slate-200 font-bold py-3 px-6 rounded-xl text-xs sm:text-sm transition-all shadow-xs cursor-pointer"
            >
              {landing.secondary_button}
            </button>
          </div>
        </div>

        {landing.image_url ? (
          <img src={landing.image_url} alt="" className="w-full max-h-[26rem] object-cover rounded-3xl border border-slate-200 shadow-xl" data-landing-image />
        ) : landing.cards.length > 0 ? (
          <div className="space-y-3" data-landing-cards>
            {landing.cards.map((card, index) => (
              <div key={index} className="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-start gap-4">
                <span className="p-2.5 rounded-xl bg-brand-soft/20 text-brand-accent shrink-0"><LookIcon name={card.icon} className="w-5 h-5" /></span>
                <span>
                  <span className="block font-bold text-brand-ink">{card.title}</span>
                  {card.text && <span className="block text-sm text-slate-500 mt-0.5">{card.text}</span>}
                </span>
              </div>
            ))}
          </div>
        ) : (
        <div className="bg-white p-6 sm:p-8 rounded-3xl border border-slate-200 shadow-xl space-y-6 relative overflow-hidden">
          <div className="absolute top-0 right-0 w-32 h-32 bg-brand-soft/10 rounded-full blur-2xl pointer-events-none" />
          <div className="space-y-1">
            <span className="text-xs text-slate-400 font-semibold uppercase tracking-wider">Ventas del mes</span>
            <div className="text-3xl sm:text-4xl font-black text-brand-ink">S/ 48,250</div>
            <span className="text-xs text-emerald-600 font-bold">+12.8% respecto al mes anterior</span>
          </div>
          <div className="border-t border-slate-100 pt-4 space-y-2">
            <span className="text-xs text-slate-400 font-semibold uppercase tracking-wider">Módulos disponibles</span>
            <div className="text-xs font-bold text-brand-accent bg-slate-50 p-3 rounded-xl border border-slate-100">
              Ventas • Clientes • Inventario • CRM
            </div>
          </div>
        </div>
        )}
      </div>

      <div id="planes" className="bg-white border-t border-slate-200/80 py-20 px-6 lg:px-16">
        <div className="max-w-5xl mx-auto space-y-12">
          <div className="text-center space-y-3 max-w-2xl mx-auto">
            <h2 className="text-3xl sm:text-4xl font-black text-brand-ink tracking-tight">
              {landing.plans_title}
            </h2>
            {landing.plans_text && (
              <p className="text-slate-500 text-sm font-medium">
                {landing.plans_text}
              </p>
            )}
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-6 items-stretch">
            {plans.map((plan) => (
              <PlanCard key={plan.id} plan={plan} onSelect={handleSelectPlan} />
            ))}
          </div>
        </div>
      </div>

      <footer className="bg-white border-t border-slate-200/80 px-6 lg:px-16 py-5 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500" data-landing-footer>
        <span>© {new Date().getFullYear()} {platform.product_name}</span>
        {brand.made_by && (
          brand.made_by_url
            ? <a href={brand.made_by_url} target="_blank" rel="noreferrer" className="font-semibold text-brand-accent hover:text-brand-ink">{brand.made_by}</a>
            : <span className="font-semibold">{brand.made_by}</span>
        )}
      </footer>

      <RegisterModal
        isOpen={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        selectedPlan={selectedPlan as any}
      />

    </div>
  );
}