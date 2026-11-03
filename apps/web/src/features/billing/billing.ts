export type BillingState = 'trial' | 'active' | 'grace' | 'locked' | 'card_needed' | 'none';

export interface Billing {
  state: BillingState;
  free: boolean;
  ends_at: string | null;
  grace_ends_at: string | null;
  grace_days: number;
  message: string | null;
  can_pay: boolean;
  online: boolean;
  simulated: boolean;
  plan: { id: string; name: string; currency: string } | null;
  billing_cycle: 'monthly' | 'yearly' | null;
  price: number;
  pending_plan: { id: string; name: string; billing_cycle: 'monthly' | 'yearly' | null; price: number } | null;
  card: {
    required: boolean;
    status: 'authorized' | 'cancelled' | null;
    brand: string | null;
    last4: string | null;
    authorized_at: string | null;
    failed_at: string | null;
  } | null;
}

export interface PlanPayment {
  id: string;
  kind: 'link' | 'card' | 'charge';
  status: 'pending' | 'paid' | 'failed' | 'cancelled' | 'expired';
  amount: number;
  currency: string;
  provider: string;
  message: string | null;
  at: string;
}

export interface PlanInvoice {
  id: string;
  amount: number;
  currency: string;
  reason: string | null;
  paid_at: string | null;
  period_start: string | null;
  period_end: string | null;
  notes: string | null;
}

export interface PlanOption {
  id: string;
  name: string;
  description: string | null;
  currency: string;
  price_monthly: number;
  price_yearly: number;
}

export interface BillingPayload {
  billing: Billing;
  history: PlanPayment[];
  invoices: PlanInvoice[];
  plans: PlanOption[];
}

export const BILLING_STATES: Record<BillingState, { label: string; badge: string }> = {
  trial: { label: 'En prueba', badge: 'bg-sky-50 text-sky-700' },
  active: { label: 'Activo', badge: 'bg-emerald-50 text-emerald-700' },
  grace: { label: 'Vencido: días de gracia', badge: 'bg-amber-50 text-amber-800' },
  locked: { label: 'Solo lectura', badge: 'bg-rose-50 text-rose-700' },
  card_needed: { label: 'Falta la tarjeta', badge: 'bg-rose-50 text-rose-700' },
  none: { label: 'Sin plan', badge: 'bg-slate-100 text-slate-600' },
};

export const PAYMENT_KINDS: Record<PlanPayment['kind'], string> = {
  link: 'Pago en línea',
  card: 'Autorización de tarjeta',
  charge: 'Cobro automático',
};

export const PAYMENT_STATUS: Record<PlanPayment['status'], { label: string; badge: string }> = {
  pending: { label: 'Pendiente', badge: 'bg-slate-100 text-slate-600' },
  paid: { label: 'Listo', badge: 'bg-emerald-50 text-emerald-700' },
  failed: { label: 'Rechazado', badge: 'bg-rose-50 text-rose-700' },
  cancelled: { label: 'Anulado', badge: 'bg-slate-100 text-slate-500' },
  expired: { label: 'Vencido', badge: 'bg-slate-100 text-slate-500' },
};

export const INVOICE_REASONS: Record<string, string> = {
  subscription_create: 'Primer pago',
  subscription_cycle: 'Cobro automático',
  online: 'Pago en línea',
  manual: 'Registrado por la plataforma',
};

export const cycleLabel = (cycle: string | null | undefined) => (cycle === 'yearly' ? 'al año' : 'al mes');

export const cardLabel = (card: Billing['card']) => {
  if (!card || card.status !== 'authorized') return null;
  const brand = card.brand ? card.brand.charAt(0).toUpperCase() + card.brand.slice(1) : 'Tarjeta';
  return card.last4 ? `${brand} terminada en ${card.last4}` : brand;
};
