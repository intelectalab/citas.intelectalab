import type { CulqiCheckoutConfig } from './culqi';

export type BillingState = 'trial' | 'active' | 'grace' | 'locked' | 'none';

export interface CulqiAction extends CulqiCheckoutConfig {
  action: 'card' | 'pay';
  payment_id: string;
}

export interface CancelOffer { percent: number; months: number; price: number; text: string }

export interface CancellationInfo {
  scheduled: { effective_at: string; reason: string } | null;
  reasons: { key: string; label: string }[];
  offers: Record<string, CancelOffer>;
  effective_at: string;
  discount: { percent: number; cycles: number } | null;
}

export interface Billing {
  state: BillingState;
  reason?: string | null;
  free: boolean;
  ends_at: string | null;
  grace_ends_at: string | null;
  grace_days: number;
  trial_days_left?: number | null;
  overdue?: { day: number | null; readonly_on: string | null; cancel_on: string | null; last_failure: string | null } | null;
  provider?: 'culqi' | 'mercadopago' | 'simulated' | null;
  message: string | null;
  can_pay: boolean;
  online: boolean;
  simulated: boolean;
  cancellation?: CancellationInfo | null;
  charge?: number;
  coupon?: { code: string; text: string; cycles_left: number } | null;
  coupons?: boolean;
  plan: { id: string; name: string; currency: string } | null;
  billing_cycle: 'monthly' | 'yearly' | null;
  price: number;
  pending_plan: { id: string; name: string; billing_cycle: 'monthly' | 'yearly' | null; price: number } | null;
  methods?: { card: boolean; transfer: boolean; transfer_cycles?: string[] };
  next_period?: [string, string] | null;
  review?: TransferPayment | null;
  rejected?: TransferPayment | null;
  card: {
    status: 'authorized' | 'cancelled' | null;
    saved?: boolean;
    provider?: string | null;
    brand: string | null;
    last4: string | null;
    authorized_at: string | null;
    failed_at: string | null;
  } | null;
}

export interface TransferAccount {
  id: string;
  type: 'bank' | 'yape' | 'plin';
  label?: string;
  bank?: string;
  holder?: string;
  account_number?: string;
  cci?: string;
  currency?: string;
  phone?: string;
  qr?: string;
}

export interface TransferPayment {
  id: string;
  status: 'review' | 'paid' | 'rejected';
  amount: number;
  currency: string;
  plan?: string;
  billing_cycle?: string;
  method?: string;
  operation_number?: string;
  paid_on?: string;
  message?: string;
  sent_at: string;
  reviewed_at?: string;
  reviewer?: string;
  tenant?: { id: string; name: string };
  voucher_type?: 'image' | 'pdf';
  period?: [string, string];
}

export interface PlanPayment {
  id: string;
  kind: 'link' | 'card' | 'charge' | 'transfer';
  status: 'pending' | 'review' | 'paid' | 'failed' | 'rejected' | 'cancelled' | 'expired';
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
  grace: { label: 'Pago vencido', badge: 'bg-amber-50 text-amber-800' },
  locked: { label: 'Solo lectura', badge: 'bg-rose-50 text-rose-700' },
  none: { label: 'Sin plan', badge: 'bg-slate-100 text-slate-600' },
};

export const PAYMENT_KINDS: Record<PlanPayment['kind'], string> = {
  link: 'Pago en línea',
  card: 'Tarjeta guardada',
  charge: 'Cobro automático',
  transfer: 'Transferencia',
};

export const PAYMENT_STATUS: Record<PlanPayment['status'], { label: string; badge: string }> = {
  pending: { label: 'Pendiente', badge: 'bg-slate-100 text-slate-600' },
  review: { label: 'En revisión', badge: 'bg-amber-50 text-amber-800' },
  rejected: { label: 'No confirmado', badge: 'bg-rose-50 text-rose-700' },
  paid: { label: 'Listo', badge: 'bg-emerald-50 text-emerald-700' },
  failed: { label: 'Rechazado', badge: 'bg-rose-50 text-rose-700' },
  cancelled: { label: 'Anulado', badge: 'bg-slate-100 text-slate-500' },
  expired: { label: 'Vencido', badge: 'bg-slate-100 text-slate-500' },
};

export const INVOICE_REASONS: Record<string, string> = {
  subscription_create: 'Primer pago',
  subscription_cycle: 'Cobro a la tarjeta',
  online: 'Pago en línea',
  manual: 'Registrado por la plataforma',
  transfer: 'Transferencia',
};

export const cycleLabel = (cycle: string | null | undefined) => (cycle === 'yearly' ? 'al año' : 'al mes');

export const cardLabel = (card: Billing['card']) => {
  if (!card || card.status !== 'authorized') return null;
  const brand = card.brand ? card.brand.charAt(0).toUpperCase() + card.brand.slice(1) : 'Tarjeta';
  return card.last4 ? `${brand} terminada en ${card.last4}` : brand;
};

export const PROVIDER_LABELS: Record<string, string> = { culqi: 'Culqi', mercadopago: 'Mercado Pago', simulated: 'la simulación' };

export const providerLabel = (provider: string | null | undefined) => (provider ? PROVIDER_LABELS[provider] ?? provider : 'la pasarela');

export const transferAllowed = (billing: Billing, cycle: string | null | undefined) => Boolean(billing.methods?.transfer) && (billing.methods?.transfer_cycles ?? ['monthly', 'yearly']).includes(cycle ?? 'monthly');

export const asClause = (text: string) => text.trim().replace(/\.$/, '').replace(/^\p{Lu}(?!\p{Lu})/u, (letter) => letter.toLowerCase());
