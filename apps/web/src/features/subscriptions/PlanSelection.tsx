'use client';

import { useState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { Check, Building2, Loader2, Zap } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';

interface Plan {
  currency_symbol?: string;
  id: string;
  name: string;
  slug: string;
  price_monthly: number | string;
  price_yearly: number | string;
  max_users: number;
  max_branches: number;
  max_appointments_monthly: number | null;
  features: Record<string, boolean>;
  status: string;
}

const FALLBACK_PLANS: Plan[] = [
  {
    id: '11111111-1111-1111-1111-111111111111',
    name: 'Esencial',
    slug: 'esencial',
    price_monthly: '149.00',
    price_yearly: '1490.00',
    max_users: 5,
    max_branches: 1,
    max_appointments_monthly: 150,
    features: { crm: true, pos: true, reports_basic: true, whatsapp_notifications: false },
    status: 'active'
  },
  {
    id: '22222222-2222-2222-2222-222222222222',
    name: 'Profesional',
    slug: 'profesional',
    price_monthly: '299.00',
    price_yearly: '2990.00',
    max_users: 20,
    max_branches: 3,
    max_appointments_monthly: null,
    features: { crm: true, pos: true, audit: true, reports_advanced: true, whatsapp_notifications: true },
    status: 'active'
  },
  {
    id: '33333333-3333-3333-3333-333333333333',
    name: 'Empresarial',
    slug: 'empresarial',
    price_monthly: '599.00',
    price_yearly: '5990.00',
    max_users: 100,
    max_branches: 20,
    max_appointments_monthly: null,
    features: { crm: true, pos: true, audit: true, custom_domain: true, priority_support: true, reports_advanced: true, whatsapp_notifications: true },
    status: 'active'
  }
];

interface PlanSelectionProps {
  isEmbedded?: boolean;
}

export default function PlanSelection({ isEmbedded = false }: PlanSelectionProps) {
  const router = useRouter();
  const { user, loading: authLoading, refetchUser } = useAuth();

  const [plans, setPlans] = useState<Plan[]>(FALLBACK_PLANS);
  const [loadingPlans, setLoadingPlans] = useState(true);
  const [submittingPlanId, setSubmittingPlanId] = useState<string | null>(null);
  const [billingCycle, setBillingCycle] = useState<'monthly' | 'yearly'>('monthly');

  useEffect(() => {
    let isMounted = true;
    const fetchPlans = async () => {
      try {
        const response = await api.get('/plans');
        if (isMounted && response.data && Array.isArray(response.data)) {
          setPlans(response.data);
        }
      } catch (error) {
        console.warn('No se pudieron obtener los planes desde la API, usando fallback.', error);
      } finally {
        if (isMounted) setLoadingPlans(false);
      }
    };

    fetchPlans();
    return () => {
      isMounted = false;
    };
  }, []);

  const handleSelectPlan = async (plan: Plan) => {
    const currentToken = localStorage.getItem('auth_token');
    console.log("Token recuperado en el plan selection:", currentToken);

    if (!currentToken) {
      alert("No se encontró una sesión activa. Por favor, inicia sesión nuevamente.");
      router.push('/login');
      return;
    }

    setSubmittingPlanId(plan.id);
    try {
      const response = await api.post('/subscriptions/subscribe', {
        plan_id: plan.id,
        billing_cycle: billingCycle,
      });

      const token = response.data?.token;
      if (token) {
        localStorage.setItem('last_activity', String(Date.now()));
        localStorage.setItem('auth_token', token);
        api.defaults.headers.common['Authorization'] = `Bearer ${token}`;
      }

      if (response.data?.checkout_url) {
        window.location.href = response.data.checkout_url;
      } else {
        await refetchUser();
        router.refresh();
      }
    } catch (error: any) {
      console.error('Error al procesar la selección del plan:', error);
      alert(error.response?.data?.message || 'Ocurrió an error al seleccionar el plan.');
    } finally {
      setSubmittingPlanId(null);
    }
  };

  if (authLoading) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[300px] gap-3">
        <Loader2 className="w-8 h-8 text-[#3F768C] animate-spin" />
        <p className="text-xs font-semibold text-slate-400">Verificando estado de la cuenta...</p>
      </div>
    );
  }

  return (
    <div className={`bg-slate-50/50 rounded-3xl p-6 md:p-10 border border-slate-200/80 shadow-xs ${isEmbedded ? 'w-full' : ''}`}>
      <div className="text-center max-w-2xl mx-auto space-y-3 mb-8">
        <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-[#84D0D9]/20 text-[#022340] text-xs font-bold uppercase tracking-wider">
          <Zap className="w-3.5 h-3.5 text-[#3F768C]" />
          Planes y Membresías
        </div>
        <h2 className="text-3xl md:text-4xl font-extrabold text-[#022340] tracking-tight">
          Selecciona un plan para activar tu cuenta
        </h2>
        <p className="text-xs md:text-sm text-slate-500 font-medium">
          Elige la opción que mejor se adapte a tu empresa para acceder a todas las funcionalidades del ERP.
        </p>

        <div className="pt-2 flex items-center justify-center gap-3 text-xs font-semibold">
          <span className={billingCycle === 'monthly' ? 'text-[#022340]' : 'text-slate-400'}>Facturación Mensual</span>
          <button
            type="button"
            onClick={() => setBillingCycle(prev => prev === 'monthly' ? 'yearly' : 'monthly')}
            className="w-12 h-6 bg-slate-200 rounded-full p-1 transition-colors relative"
          >
            <div className={`w-4 h-4 bg-[#022340] rounded-full transition-transform ${billingCycle === 'yearly' ? 'translate-x-6' : 'translate-x-0'}`} />
          </button>
          <span className={billingCycle === 'yearly' ? 'text-[#022340]' : 'text-slate-400'}>Facturación Anual</span>
        </div>
      </div>

      {loadingPlans ? (
        <div className="flex flex-col items-center justify-center py-12 gap-3">
          <Loader2 className="w-8 h-8 text-[#3F768C] animate-spin" />
          <p className="text-xs font-semibold text-slate-400">Cargando opciones de planes...</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          {plans.map((plan) => {
            const price = billingCycle === 'monthly' ? plan.price_monthly : plan.price_yearly;
            const isLoading = submittingPlanId === plan.id;

            return (
              <div
                key={plan.id}
                className="bg-white rounded-2xl border border-slate-200/80 p-6 flex flex-col justify-between shadow-2xs hover:shadow-md transition-all duration-200 hover:border-[#3F768C]/50"
              >
                <div>
                  <div className="flex items-center justify-between mb-4">
                    <h3 className="text-lg font-bold text-[#022340]">{plan.name}</h3>
                    <div className="p-2 bg-slate-100 rounded-xl text-slate-600">
                      <Building2 className="w-4 h-4" />
                    </div>
                  </div>

                  <div className="mb-6">
                    <div className="flex items-baseline gap-1">
                      <span className="text-3xl font-black text-[#022340]">{plan.currency_symbol ?? 'S/'} {Math.floor(Number(price))}</span>
                      <span className="text-xs text-slate-400 font-medium">
                        /{billingCycle === 'monthly' ? 'mes' : 'año'}
                      </span>
                    </div>
                  </div>

                  <div className="space-y-3 pt-4 border-t border-slate-100">
                    <span className="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">
                      Incluye
                    </span>
                    <ul className="space-y-2.5 text-xs text-slate-600">
                      <li className="flex items-center gap-2">
                        <Check className="w-4 h-4 text-emerald-500 shrink-0" />
                        <span>Acceso completo al sistema</span>
                      </li>
                      <li className="flex items-center gap-2">
                        <Check className="w-4 h-4 text-emerald-500 shrink-0" />
                        <span>Gestión de ventas y compras</span>
                      </li>
                      <li className="flex items-center gap-2">
                        <Check className="w-4 h-4 text-emerald-500 shrink-0" />
                        <span>Hasta {plan.max_users} usuarios</span>
                      </li>
                      <li className="flex items-center gap-2">
                        <Check className="w-4 h-4 text-emerald-500 shrink-0" />
                        <span>{plan.max_branches} sucursal{plan.max_branches > 1 ? 'es' : ''}</span>
                      </li>
                      {plan.features?.whatsapp_notifications && (
                        <li className="flex items-center gap-2">
                          <Check className="w-4 h-4 text-emerald-500 shrink-0" />
                          <span>Notificaciones por WhatsApp</span>
                        </li>
                      )}
                    </ul>
                  </div>
                </div>

                <button
                  type="button"
                  disabled={isLoading || submittingPlanId !== null}
                  onClick={() => handleSelectPlan(plan)}
                  className="mt-8 w-full bg-[#022340] hover:bg-[#012840] text-white font-bold py-3 px-4 rounded-xl text-xs transition-all flex items-center justify-center gap-2 shadow-sm disabled:opacity-50 cursor-pointer"
                >
                  {isLoading ? (
                    <>
                      <Loader2 className="w-4 h-4 animate-spin text-[#84D0D9]" />
                      <span>Procesando...</span>
                    </>
                  ) : (
                    <>
                      <span>Contratar Plan</span>
                      <span className="text-[#84D0D9]">→</span>
                    </>
                  )}
                </button>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}