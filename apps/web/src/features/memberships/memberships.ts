import { MONTHS_SHORT as MONTHS } from '@/lib/dates';

export type MembershipPeriod = 'monthly' | 'quarterly' | 'semiannual' | 'yearly';
export type MembershipStatus = 'active' | 'grace' | 'paused' | 'expired' | 'cancelled';
export type DiscountScope = 'services' | 'all';

export interface MembershipPlanItem {
  service_id: string;
  name: string;
  sessions: number;
  price: number;
}

export interface MembershipPlan {
  id: string;
  name: string;
  description: string | null;
  price: number;
  currency: string;
  billing_period: MembershipPeriod;
  period_label: string;
  per_label: string;
  discount_percent: number;
  discount_scope: DiscountScope;
  items: MembershipPlanItem[];
  benefits_text: string;
  status: 'active' | 'inactive';
  members: number;
  created_at: string;
}

export interface IncludedLeft {
  service_id: string;
  name: string;
  sessions: number;
  used: number;
  left: number;
}

export interface CustomerMembership {
  id: string;
  code: string;
  name: string;
  status: MembershipStatus;
  status_label: string;
  customer: { id: string; name: string } | null;
  price: number;
  currency: string;
  period_label: string;
  months: number;
  starts_at: string;
  ends_at: string;
  days_left: number;
  benefits_text: string;
  included: IncludedLeft[];
  period_end: string | null;
  uses: number;
  grace_until: string | null;
  paused_at: string | null;
  paused_days: number;
  cancel_reason: string | null;
  cancelled_at: string | null;
}

export interface MembershipUsage {
  id: string;
  service: string;
  quantity: number;
  period_start: string;
  at: string;
  sale: { id: string; code: string; status: string; status_label: string };
  released: boolean;
  appointment: { id: string; code: string } | null;
}

export interface MembershipEvent {
  id: string;
  kind: string;
  detail: string | null;
  by: string | null;
  at: string;
}

export interface MembershipDetailData extends CustomerMembership {
  customer: { id: string; code: string; first_name: string; last_name: string; name: string; phone?: string | null } | null;
  plan_id: string;
  plan_on_sale: boolean;
  discount_percent: number;
  discount_scope_label: string;
  period_start: string | null;
  grace_days: number;
  sale: { id: string; code: string; status: string; status_label: string } | null;
  branch: { id: string; name: string } | null;
  usages: MembershipUsage[];
  events: MembershipEvent[];
  history_ready: boolean;
  can: { edit: boolean; delete: boolean; sell: boolean };
  result?: { days: number };
}

export interface MembershipSummary {
  id: string;
  code: string;
  name: string;
  status?: MembershipStatus;
  ends_at: string;
  grace_until?: string | null;
  days_left: number;
  discount_percent: number;
  discount_scope: DiscountScope;
  period_start: string;
  period_end: string;
  benefits_text: string;
  included: IncludedLeft[];
}

export interface Option { value: string; label: string }

export interface MembershipsBoardData {
  currency: string;
  memberships: CustomerMembership[];
  plans: MembershipPlan[];
  options: { periods: Option[]; scopes: Option[]; statuses: Option[]; services: (Option & { price: number })[] };
  can: { create: boolean; edit: boolean; delete: boolean; sell: boolean };
}

export const STATUS_BADGES: Record<MembershipStatus, string> = {
  active: 'bg-emerald-50 text-emerald-700',
  grace: 'bg-amber-50 text-amber-700',
  paused: 'bg-sky-50 text-sky-700',
  expired: 'bg-slate-100 text-slate-500',
  cancelled: 'bg-red-50 text-red-600',
};

export const shortDate = (value: string | null): string => {
  if (!value) return '—';
  const [y, m, d] = value.slice(0, 10).split('-').map(Number);
  return `${d} ${MONTHS[m - 1]} ${y}`;
};

export const daysLeftLabel = (m: Pick<CustomerMembership, 'status' | 'days_left' | 'ends_at'> & { grace_until?: string | null; paused_at?: string | null }): string => {
  if (m.status === 'grace') return `Venció el ${shortDate(m.ends_at)}. En gracia hasta el ${shortDate(m.grace_until ?? null)}`;
  if (m.status === 'paused') return `Pausada desde el ${shortDate(m.paused_at ?? null)}`;
  if (m.status === 'cancelled') return 'Cancelada';
  if (m.status !== 'active') return `Venció el ${shortDate(m.ends_at)}`;
  if (m.days_left <= 0) return 'Vence hoy';
  return m.days_left === 1 ? 'Vence mañana' : `Vence en ${m.days_left} días`;
};

export const includedLeftText = (items: IncludedLeft[]): string =>
  items.map((i) => `${i.name}: ${i.left === 0 ? 'ya usó' : i.left === 1 ? 'queda 1' : `quedan ${i.left}`} de ${i.sessions}`).join(' · ');
