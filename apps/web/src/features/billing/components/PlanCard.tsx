'use client';

import SectionTitle from '@/app/components/common/SectionTitle';
import Badge from '@/app/components/common/Badge';
import OptionCard from '@/app/components/common/OptionCard';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { PagedList } from '@/app/components/common/Pager';
import { useCallback, useEffect, useState } from 'react';
import { AlertTriangle, CreditCard, History, Loader2, Repeat } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { apiError, formatDate } from '@/features/appointments/appointments';
import { money, momentOf } from '@/features/sales/sales';
import { BILLING_STATES, INVOICE_REASONS, PAYMENT_KINDS, PAYMENT_STATUS, cardLabel, cycleLabel, type BillingPayload } from '../billing';

const card = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs';
const shortDate = (date: string | null) => (date ? formatDate(date, { day: 'numeric', month: 'long', year: 'numeric' }) : '');

export default function PlanCard() {
  const { refetchUser } = useAuth();
  const [data, setData] = useState<BillingPayload | null>(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState('');
  const [panel, setPanel] = useState<'' | 'plans' | 'history'>('');
  const [choice, setChoice] = useState<{ plan: string; cycle: 'monthly' | 'yearly' }>({ plan: '', cycle: 'monthly' });
  const [confirmCancel, setConfirmCancel] = useState(false);

  const load = useCallback((sync = false) => {
    return api.get('/billing', { params: sync ? { sync: 1 } : {} })
      .then((res) => { setData(res.data); setError(''); return res.data as BillingPayload; })
      .catch((err) => { setError(apiError(err, 'No se pudo cargar tu plan.')); return null; });
  }, []);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const back = params.get('plan');
    if (params.get('tab') === 'plan' || back) setTimeout(() => document.getElementById('plan')?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 300);
    if (!back) { load(); return; }

    setNotice('Revisando tu pago con Mercado Pago...');
    load(true).then((payload) => {
      refetchUser();
      if (!payload) return;
      const last = payload.history[0];
      setNotice(last?.status === 'paid'
        ? back === 'tarjeta' ? 'Listo: tu tarjeta quedó autorizada.' : 'Listo: recibimos tu pago y tu plan quedó activo.'
        : last?.status === 'failed'
          ? ''
          : 'Mercado Pago aún no nos confirma. Si ya pagaste, en unos minutos se verá aquí.');
      if (last?.status === 'failed') setError(back === 'tarjeta' ? 'La tarjeta no se pudo autorizar. Prueba con otra.' : 'El pago fue rechazado. Inténtalo con otro medio.');
      window.history.replaceState(null, '', '/companies');
    });
  }, [load, refetchUser]);

  if (!data) {
    return (
      <section id="plan" className={`${card} p-6 space-y-3`} data-card="plan">
        <SectionTitle><CreditCard size={16} className="text-brand-accent" /> Tu plan</SectionTitle>
        <p className={`text-sm ${error ? 'text-red-600' : 'text-slate-500'}`}>{error || 'Cargando tu plan...'}</p>
      </section>
    );
  }

  const { billing } = data;
  const state = BILLING_STATES[billing.state];
  const cardText = cardLabel(billing.card);
  const authorized = billing.card?.status === 'authorized';
  const paid = billing.price > 0 && !billing.free;
  const failed = Boolean(billing.card?.failed_at);
  const canAct = billing.can_pay && billing.online;
  const showPay = canAct && paid && (['grace', 'locked'].includes(billing.state) || failed || (!authorized && billing.state === 'active'));
  const showCard = canAct && paid && billing.state !== 'locked';

  const go = async (action: string, path: string, body?: object) => {
    try {
      setBusy(action);
      setError('');
      setNotice('');
      const res = await api.post(path, body ?? {});
      if (res.data.checkout_url) {
        window.location.href = res.data.checkout_url;
        return;
      }
      if (res.data.message) setNotice(res.data.message);
      if (res.data.billing) setData(res.data);
      else await load();
      setPanel('');
      await refetchUser();
    } catch (err) {
      setError(apiError(err, 'No se pudo completar. Inténtalo de nuevo.'));
    } finally {
      setBusy('');
    }
  };

  const openPlans = () => {
    setChoice({ plan: billing.pending_plan?.id ?? billing.plan?.id ?? data.plans[0]?.id ?? '', cycle: billing.pending_plan?.billing_cycle ?? billing.billing_cycle ?? 'monthly' });
    setPanel('plans');
  };

  const summary = (() => {
    const price = `${money(billing.price, billing.plan?.currency)} ${cycleLabel(billing.billing_cycle)}`;
    switch (billing.state) {
      case 'trial':
        return `Prueba gratis hasta el ${shortDate(billing.ends_at)}. ${authorized ? `Ese día se cobran ${price} a tu tarjeta.` : paid && billing.online ? 'Autoriza tu tarjeta para que el plan se cobre solo al terminar la prueba.' : ''}`;
      case 'active':
        return billing.free ? 'Plan sin costo.' : `${price}. Vigente hasta el ${shortDate(billing.ends_at)}. ${authorized ? 'Se renueva solo con tu tarjeta.' : 'Paga antes de esa fecha para que no se interrumpa.'}`;
      case 'grace':
        return `Venció el ${shortDate(billing.ends_at)}. Hasta el ${shortDate(billing.grace_ends_at)} todo funciona igual; después tu empresa pasa a solo lectura.`;
      default:
        return billing.message ?? '';
    }
  })();

  return (
    <section id="plan" className={`${card} p-6 space-y-4`} data-card="plan" data-billing-state={billing.state}>
      <SectionTitle><CreditCard size={16} className="text-brand-accent" /> Tu plan</SectionTitle>
      <div className="flex items-center justify-between gap-3">
        <span className="text-2xl font-black text-brand-ink tracking-tight">{billing.plan?.name ?? 'Sin plan'}</span>
        <Badge tone={state.badge} nowrap>{state.label}</Badge>
      </div>
      <p className="text-sm text-slate-600" data-billing-summary>{summary}</p>

      {billing.pending_plan && (
        <p className="text-xs text-slate-600 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2" data-pending-plan>
          Desde el próximo periodo: plan {billing.pending_plan.name}, {money(billing.pending_plan.price, billing.plan?.currency)} {cycleLabel(billing.pending_plan.billing_cycle)}.
        </p>
      )}

      {paid && (
        <div className="flex items-center gap-2 text-sm" data-card-status>
          <CreditCard size={15} className="text-slate-400 shrink-0" />
          {cardText ? <span className="font-semibold text-slate-800">{cardText}</span> : <span className="text-slate-500">Sin tarjeta para el cobro automático</span>}
        </div>
      )}

      {failed && (
        <p className="flex items-start gap-2 text-xs text-rose-700 bg-rose-50 border border-rose-100 rounded-lg px-3 py-2" data-charge-failed>
          <AlertTriangle size={14} className="shrink-0 mt-0.5" />
          No se pudo cobrar a tu tarjeta el {shortDate(billing.card?.failed_at ?? null)}. Mercado Pago lo volverá a intentar; también puedes cambiar la tarjeta o pagar ahora.
        </p>
      )}

      {billing.simulated && (
        <p className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">Cobro simulado: estas son las pruebas del sistema y no se mueve dinero.</p>
      )}

      <SuccessAlert message={notice} />
      <ErrorAlert message={error} />

      {billing.can_pay ? (
        billing.online ? (
          <div className="flex flex-wrap gap-2">
            {showPay && (
              <button type="button" data-billing-pay disabled={busy !== ''} onClick={() => go('pay', '/billing/pay')} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover shadow-sm transition-colors disabled:opacity-50">
                {busy === 'pay' ? <Loader2 size={15} className="animate-spin" /> : null} Pagar ahora
              </button>
            )}
            {showCard && (
              <button type="button" data-billing-card disabled={busy !== ''} onClick={() => go('card', '/billing/card')} className={`flex items-center gap-1.5 px-4 py-2.5 text-sm rounded-xl transition-colors disabled:opacity-50 ${authorized ? 'font-semibold text-slate-700 border border-slate-200 hover:bg-slate-50' : 'font-bold bg-brand text-white hover:bg-brand-hover shadow-sm'}`}>
                {busy === 'card' ? <Loader2 size={15} className="animate-spin" /> : <CreditCard size={15} />} {authorized ? 'Cambiar tarjeta' : 'Autorizar tarjeta'}
              </button>
            )}
            <button type="button" data-billing-plans disabled={busy !== ''} onClick={openPlans} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-slate-700 border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors">
              <Repeat size={15} /> Cambiar plan
            </button>
            <button type="button" data-billing-history onClick={() => setPanel('history')} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-slate-700 border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors">
              <History size={15} /> Ver pagos
            </button>
          </div>
        ) : (
          <p className="text-xs text-slate-500">Para pagar o cambiar de plan, escribe a soporte de la plataforma.</p>
        )
      ) : (
        <p className="text-xs text-slate-500">El administrador de la empresa paga y cambia el plan.</p>
      )}

      {authorized && canAct && (
        confirmCancel ? (
          <div className="flex flex-wrap items-center gap-2 text-xs" data-cancel-confirm>
            <span className="text-slate-600">¿Dejar de cobrar el plan a tu tarjeta? Tendrás que pagar cada periodo con &quot;Pagar ahora&quot;.</span>
            <button type="button" data-cancel-yes disabled={busy !== ''} onClick={() => { setConfirmCancel(false); go('cancel', '/billing/cancel-card'); }} className="px-3 py-1.5 font-semibold text-rose-700 border border-rose-200 rounded-lg hover:bg-rose-50">Sí, dejar de cobrar</button>
            <button type="button" onClick={() => setConfirmCancel(false)} className="px-3 py-1.5 font-semibold text-slate-600 hover:bg-slate-50 rounded-lg">No</button>
          </div>
        ) : (
          <button type="button" data-cancel-card onClick={() => setConfirmCancel(true)} className="text-xs font-semibold text-rose-700 hover:underline">Cancelar el cobro automático</button>
        )
      )}

      <FormDrawer isOpen={panel === 'plans'} onClose={() => setPanel('')} title="Cambiar plan" subtitle="Elige el plan y cada cuánto pagarlo" icon={<Repeat size={18} />}>
        <div className="space-y-4" data-plans-panel>
          <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Cada cuánto pagar">
            {(['monthly', 'yearly'] as const).map((cycle) => (
              <OptionCard key={cycle} type="button" role="radio" aria-checked={choice.cycle === cycle} data-cycle={cycle} selected={choice.cycle === cycle} onClick={() => setChoice({ ...choice, cycle })} className="rounded-xl border px-3.5 py-2.5 text-sm font-bold text-brand-ink">
                {cycle === 'monthly' ? 'Cada mes' : 'Cada año'}
              </OptionCard>
            ))}
          </div>
          <div className="space-y-2" role="radiogroup" aria-label="Plan">
            {data.plans.map((plan) => {
              const price = choice.cycle === 'yearly' ? plan.price_yearly : plan.price_monthly;
              const current = plan.id === billing.plan?.id && choice.cycle === billing.billing_cycle;
              return (
                <OptionCard key={plan.id} type="button" role="radio" aria-checked={choice.plan === plan.id} data-plan-option={plan.id} selected={choice.plan === plan.id} onClick={() => setChoice({ ...choice, plan: plan.id })} className="w-full text-left rounded-xl border px-4 py-3">
                  <span className="flex items-center justify-between gap-3">
                    <span className="text-sm font-bold text-brand-ink">{plan.name}{current ? ' · el tuyo' : ''}</span>
                    <span className="text-sm font-black text-brand-ink tabular-nums">{price > 0 ? `${money(price, plan.currency)} ${cycleLabel(choice.cycle)}` : 'Sin costo'}</span>
                  </span>
                  {plan.description && <span className="block text-xs text-slate-500 mt-1">{plan.description}</span>}
                </OptionCard>
              );
            })}
          </div>
          <p className="text-xs text-slate-500">
            {billing.state === 'trial' || billing.state === 'card_needed'
              ? 'Sigues en tu prueba gratis: el plan nuevo se cobra a tu tarjeta cuando termine.'
              : ['grace', 'locked'].includes(billing.state)
                ? 'Pagas el plan nuevo ahora y empieza de inmediato.'
                : 'El plan nuevo empieza en el próximo periodo, cuando termine el que ya pagaste.'}
          </p>
          <ErrorAlert message={error} />
          <button type="button" data-plan-confirm disabled={busy !== '' || !choice.plan} onClick={() => go('plan', '/billing/plan', { plan_id: choice.plan, billing_cycle: choice.cycle })} className="w-full flex items-center justify-center gap-2 px-5 py-3 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover shadow-sm transition-colors disabled:opacity-50">
            {busy === 'plan' ? <Loader2 size={15} className="animate-spin" /> : null} Confirmar plan
          </button>
        </div>
      </FormDrawer>

      <FormDrawer isOpen={panel === 'history'} onClose={() => setPanel('')} title="Pagos del plan" subtitle="Lo pagado y los intentos con Mercado Pago" icon={<History size={18} />}>
        <div className="space-y-5" data-history-panel>
          <div className="space-y-2">
            <span className="block text-[11px] font-bold text-slate-600 uppercase tracking-wider">Periodos pagados</span>
            <div className="rounded-xl border border-slate-200 overflow-hidden flex flex-col">
              <PagedList
                items={data.invoices}
                empty="Todavía no hay pagos del plan."
                renderRow={(invoice) => (
                  <li key={invoice.id} className="flex items-center gap-3 px-4 py-3">
                    <span className="flex-1 min-w-0">
                      <span className="block text-sm font-semibold text-slate-800">{INVOICE_REASONS[invoice.reason ?? ''] ?? 'Pago'}</span>
                      <span className="block text-xs text-slate-500">{invoice.period_start && invoice.period_end ? `Del ${shortDate(invoice.period_start)} al ${shortDate(invoice.period_end)}` : shortDate(invoice.paid_at)}</span>
                    </span>
                    <span className="text-sm font-bold text-slate-800 tabular-nums">{money(invoice.amount, invoice.currency)}</span>
                  </li>
                )}
              />
            </div>
          </div>
          <div className="space-y-2">
            <span className="block text-[11px] font-bold text-slate-600 uppercase tracking-wider">Movimientos con Mercado Pago</span>
            <div className="rounded-xl border border-slate-200 overflow-hidden flex flex-col">
              <PagedList
                items={data.history}
                empty="Aún no hay movimientos."
                renderRow={(row) => (
                  <li key={row.id} className="flex items-center gap-3 px-4 py-3">
                    <Badge tone={PAYMENT_STATUS[row.status].badge} bold className="shrink-0">{PAYMENT_STATUS[row.status].label}</Badge>
                    <span className="flex-1 min-w-0">
                      <span className="block text-sm font-semibold text-slate-800 truncate">{PAYMENT_KINDS[row.kind]}</span>
                      <span className="block text-xs text-slate-500 truncate">{row.message ?? momentOf(row.at)}</span>
                    </span>
                    {row.kind !== 'card' && <span className="text-sm font-bold text-slate-800 tabular-nums">{money(row.amount, row.currency)}</span>}
                  </li>
                )}
              />
            </div>
          </div>
        </div>
      </FormDrawer>
    </section>
  );
}
