'use client';

import { useEffect, useState, type ReactNode } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { AlertTriangle, CreditCard, Lock, X } from 'lucide-react';
import { useAuth } from '@/hooks/useAuth';
import { formatDate } from '@/features/appointments/appointments';
import { money } from '@/features/sales/sales';
import { asClause } from '../billing';

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

  const daysLeft = billing.trial_days_left ?? (billing.ends_at ? Math.ceil((new Date(`${billing.ends_at}T23:59:59`).getTime() - Date.now()) / 86400000) : null);
  const authorized = billing.card?.status === 'authorized';
  const failure = billing.overdue?.last_failure ?? null;
  const owed = billing.overdue?.amount ? ` de ${money(billing.overdue.amount, billing.plan?.currency)}` : '';
  const toReadonly = billing.overdue?.days_to_readonly ?? null;
  const goPlan = (label: string) => <Link href="/companies?tab=plan" data-banner-action className="shrink-0 px-3 py-1 rounded-lg bg-brand text-white font-bold hover:bg-brand-hover transition-colors">{label}</Link>;

  const rows: { key: string; tone: string; icon: ReactNode; text: string; action: ReactNode }[] = [];
  const payAction = billing.can_pay
    ? (billing.online ? goPlan(billing.state === 'trial' ? 'Activar mi plan' : billing.state === 'grace' || billing.state === 'locked' ? 'Pagar mi plan' : 'Ver mi plan') : null)
    : <span className="shrink-0 opacity-90">Avísale al administrador de tu empresa.</span>;

  if (billing.state === 'locked' || billing.state === 'none') {
    rows.push({
      key: billing.state,
      tone: 'bg-rose-600 text-white',
      icon: <Lock size={15} className="shrink-0" />,
      text: billing.reason === 'canceled'
        ? 'Tu plan está cancelado: puedes ver y exportar tus datos. Si pagas el plan, tu empresa vuelve a tener todo.'
        : `Solo lectura: el plan${owed} no está pagado. Puedes ver y exportar, pero no agendar, vender ni cambiar nada. El portal no recibe reservas.${billing.overdue?.cancel_on ? ` Si no se paga antes del ${day(billing.overdue.cancel_on)}, el plan se cancelará.` : ''}`,
      action: payAction,
    });
  } else if (billing.state === 'grace') {
    const when = toReadonly === null ? '' : toReadonly <= 0 ? ' Hoy es el último día antes de quedar en solo lectura.' : ` Faltan ${toReadonly} ${toReadonly === 1 ? 'día' : 'días'} para que quede en solo lectura.`;
    rows.push({
      key: 'grace',
      tone: 'bg-amber-400 text-[#0B1B2B]',
      icon: failure ? <CreditCard size={15} className="shrink-0" /> : <AlertTriangle size={15} className="shrink-0" />,
      text: `Tu plan${owed} venció el ${day(billing.ends_at)}${failure ? ` y el cobro no pasó (${asClause(failure)})` : ''}. Págalo antes del ${day(billing.overdue?.readonly_on ?? billing.grace_ends_at)} para que tu empresa no quede en solo lectura.${when}`,
      action: payAction,
    });
  } else if (billing.state === 'trial' && daysLeft !== null && daysLeft <= 7 && !authorized && billing.price > 0) {
    rows.push({
      key: 'trial',
      tone: 'bg-sky-100 text-sky-900',
      icon: <AlertTriangle size={15} className="shrink-0" />,
      text: `Tu prueba gratis termina ${daysLeft <= 0 ? 'hoy' : daysLeft === 1 ? 'mañana' : `en ${daysLeft} días`}. ${billing.online ? 'Activa tu plan para que tu empresa siga usando el sistema.' : 'Escribe a soporte para activar tu plan.'}`,
      action: payAction,
    });
  }

  (billing.notices ?? []).forEach((notice, index) => {
    rows.push({
      key: `${notice.kind}-${notice.key ?? index}`,
      tone: notice.kind === 'usage' ? (notice.level === 'full' ? 'bg-rose-100 text-rose-900' : 'bg-amber-100 text-amber-900') : 'bg-slate-200 text-slate-800',
      icon: <AlertTriangle size={15} className="shrink-0" />,
      text: notice.text,
      action: notice.action ? goPlan(notice.action) : null,
    });
  });

  if (!rows.length) return toast;

  return (
    <>
    {toast}
    {rows.map((row) => (
      <div key={row.key} className={`${row.tone} px-3 sm:px-6 py-2 flex flex-wrap sm:flex-nowrap items-center justify-between gap-2 sm:gap-4 text-xs font-semibold`} data-billing-banner={row.key.startsWith('usage') ? 'usage' : row.key.split('-')[0]} role="status">
        <span className="flex items-center gap-2">{row.icon} {row.text}</span>
        {row.action}
      </div>
    ))}
    </>
  );
}
