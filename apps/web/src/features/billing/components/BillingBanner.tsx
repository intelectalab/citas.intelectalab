'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { AlertTriangle, CreditCard, Lock, X } from 'lucide-react';
import { useAuth } from '@/hooks/useAuth';
import { formatDate } from '@/features/appointments/appointments';

const day = (date: string | null | undefined) => (date ? formatDate(date, { day: 'numeric', month: 'long' }) : '');

export default function BillingBanner() {
  const { user } = useAuth();
  const pathname = usePathname();
  const billing = user?.tenant?.billing;
  const [blocked, setBlocked] = useState('');

  useEffect(() => {
    const show = (event: Event) => setBlocked(String((event as CustomEvent).detail ?? ''));
    window.addEventListener('plan-locked', show);
    return () => window.removeEventListener('plan-locked', show);
  }, []);

  useEffect(() => {
    if (!blocked) return;
    const timer = setTimeout(() => setBlocked(''), 9000);
    return () => clearTimeout(timer);
  }, [blocked]);

  const toast = blocked ? (
    <div role="alert" data-plan-locked-toast className="fixed bottom-4 left-4 right-4 sm:left-auto sm:right-6 sm:max-w-md z-[60] flex items-start gap-3 bg-rose-600 text-white text-sm font-semibold rounded-2xl shadow-2xl px-4 py-3">
      <Lock size={16} className="shrink-0 mt-0.5" />
      <span className="flex-1">No se guardó. {blocked}</span>
      <button type="button" aria-label="Cerrar" onClick={() => setBlocked('')} className="shrink-0 opacity-80 hover:opacity-100"><X size={16} /></button>
    </div>
  ) : null;

  if (!billing || user?.impersonator || pathname === '/companies') return toast;

  const daysLeft = billing.ends_at ? Math.ceil((new Date(`${billing.ends_at}T23:59:59`).getTime() - Date.now()) / 86400000) : null;
  const authorized = billing.card?.status === 'authorized';
  const failed = Boolean(billing.card?.failed_at) && billing.state !== 'locked';

  let tone = '';
  let icon = <AlertTriangle size={15} className="shrink-0" />;
  let text = '';

  if (billing.state === 'locked' || billing.state === 'card_needed' || billing.state === 'none') {
    tone = 'bg-rose-600 text-white';
    icon = <Lock size={15} className="shrink-0" />;
    text = billing.state === 'card_needed'
      ? 'Falta autorizar la tarjeta para empezar tu prueba gratis: mientras tanto solo puedes ver y exportar.'
      : 'Solo lectura: el plan no está pagado. Puedes ver y exportar, pero no agendar, vender ni cambiar nada. El portal no recibe reservas.';
  } else if (billing.state === 'grace') {
    tone = 'bg-amber-400 text-[#0B1B2B]';
    text = `Tu plan venció el ${day(billing.ends_at)}. Paga antes del ${day(billing.grace_ends_at)} para no pasar a solo lectura.`;
  } else if (failed) {
    tone = 'bg-amber-400 text-[#0B1B2B]';
    icon = <CreditCard size={15} className="shrink-0" />;
    text = 'No se pudo cobrar el plan a tu tarjeta. Mercado Pago lo volverá a intentar; puedes cambiar la tarjeta o pagar ahora.';
  } else if (billing.state === 'trial' && daysLeft !== null && daysLeft <= 7 && !authorized && billing.price > 0) {
    tone = 'bg-sky-100 text-sky-900';
    text = `Tu prueba gratis termina ${daysLeft <= 0 ? 'hoy' : daysLeft === 1 ? 'mañana' : `en ${daysLeft} días`}. ${billing.online ? 'Autoriza tu tarjeta para seguir sin interrupciones.' : 'Escribe a soporte para activar tu plan.'}`;
  } else {
    return toast;
  }

  const action = billing.can_pay
    ? (billing.online ? <Link href="/companies?tab=plan" data-banner-action className="shrink-0 px-3 py-1 rounded-lg bg-brand text-white font-bold hover:bg-brand-hover transition-colors">{billing.state === 'card_needed' || (billing.state === 'trial' && !authorized) ? 'Autorizar tarjeta' : 'Ver mi plan'}</Link> : null)
    : <span className="shrink-0 opacity-90">Avísale al administrador de tu empresa.</span>;

  return (
    <>
    {toast}
    <div className={`${tone} px-3 sm:px-6 py-2 flex flex-wrap sm:flex-nowrap items-center justify-between gap-2 sm:gap-4 text-xs font-semibold`} data-billing-banner={billing.state} role="status">
      <span className="flex items-center gap-2">{icon} {text}</span>
      {action}
    </div>
    </>
  );
}
