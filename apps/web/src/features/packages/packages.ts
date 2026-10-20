import { MONTHS_SHORT as MONTHS } from '@/lib/dates';
export type BookingMode = 'free' | 'scheduled';
export type Frequency = 'weekly' | 'twice_weekly' | 'biweekly' | 'monthly' | 'custom';
export type ProfessionalMode = 'chosen' | 'any';
export type LatePolicy = 'lose' | 'reschedule';
export type RefundPolicy = 'none' | 'unused_normal' | 'unused_package';
export type PackageStatus = 'active' | 'consumed' | 'expired' | 'cancelled';

export interface CustomDay {
  weekday: number;
  time: string | null;
}

export interface Conditions {
  validity_days: number;
  booking_mode: BookingMode;
  frequency: Frequency;
  custom_days: CustomDay[];
  repeat_weeks: number;
  professional_mode: ProfessionalMode;
  late_policy: LatePolicy;
  refund_policy: RefundPolicy;
  terms: string | null;
  terms_text?: string;
}

export interface PlanItem {
  service_id: string;
  name: string;
  sessions: number;
  price: number;
  duration_minutes: number;
}

export interface PackagePlan extends Conditions {
  id: string;
  name: string;
  description: string | null;
  price: number;
  currency: string;
  status: 'active' | 'inactive';
  items: PlanItem[];
  sessions: number;
  normal_total: number;
  savings: number;
  savings_percent: number;
  schedule_label: string;
  terms_text: string;
  sold: number;
  created_at: string;
}

export interface PackageItem {
  service_id: string;
  name: string;
  total: number;
  used: number;
  scheduled: number;
  available: number;
  unit_price: number;
  duration_minutes: number;
}

export interface CustomerPackage {
  id: string;
  code: string;
  name: string;
  status: PackageStatus;
  status_label: string;
  customer: { id: string; name: string } | null;
  price: number;
  currency: string;
  purchased_at: string;
  expires_at: string;
  days_left: number;
  items: PackageItem[];
  sessions_total: number;
  sessions_used: number;
  sessions_scheduled: number;
  sessions_available: number;
  pending_value: number;
  refunded_amount: number;
  sale_id: string | null;
  professional_id: string | null;
  schedule_label: string;
  conditions: Conditions;
}

export interface Option {
  value: string;
  label: string;
}

export interface PackageOptions {
  modes: Option[];
  frequencies: Option[];
  professional_modes: Option[];
  late_policies: Option[];
  refund_policies: Option[];
  statuses: Option[];
  weekdays: string[];
}

export interface PackagesBoardData {
  currency: string;
  packages: CustomerPackage[];
  plans: PackagePlan[];
  options: PackageOptions;
  can: { create: boolean; edit: boolean; delete: boolean; use: boolean; sell: boolean };
}

export interface PackageUsage {
  id: string;
  kind: string;
  kind_label: string;
  note: string | null;
  at: string;
  service: string;
  appointment: { id: string; code: string } | null;
  professional: { id: string; name: string } | null;
  by: string | null;
}

export interface PackageAppointment {
  id: string;
  code: string;
  starts_at: string;
  status: string;
  status_name: string;
  service: string | null;
  professional: { id: string; name: string } | null;
  branch: string | null;
}

export interface PackageEvent {
  id: string;
  kind: string;
  detail: string | null;
  amount: number | null;
  by: string | null;
  at: string;
}

export interface RefundQuote {
  policy: RefundPolicy;
  policy_label: string;
  amount: number;
  suggested: number;
  explain: string;
  currency: string;
  blocked: string | null;
  sale: { id: string; code: string } | null;
}

export interface PackageDetailData extends Omit<CustomerPackage, 'customer'> {
  customer: { id: string; name: string; phone: string | null } | null;
  terms_text: string;
  cancel_reason: string | null;
  cancelled_at: string | null;
  sale: { id: string; code: string; status: string; status_label: string } | null;
  branch: { id: string; name: string } | null;
  professional: { id: string; name: string } | null;
  usages: PackageUsage[];
  appointments: PackageAppointment[];
  events: PackageEvent[];
  refund: RefundQuote | null;
  refund_methods: Option[];
  cash_sessions: { id: string; register_name: string }[];
  can: { edit: boolean; delete: boolean; use: boolean; schedule: boolean };
  today: string;
  result?: { created?: { code: string }[]; failed?: { date: string; time: string | null; message: string }[]; cancelled_appointments?: string[]; refunded?: number };
}

export interface SlotInput {
  date: string;
  time: string | null;
  professional_id?: string | null;
}

export interface CheckedSlot {
  index: number;
  date: string;
  time: string | null;
  weekday?: string;
  starts_at?: string;
  ok: boolean;
  professional: { id: string; name: string } | null;
  reasons: { code: string; message: string }[];
}

export interface SchedulePreview {
  service: { id: string; name: string; duration_minutes: number };
  count: number;
  expires_at: string;
  slots: CheckedSlot[];
  ok: boolean;
  professionals: { id: string; name: string }[];
}

export interface FreeTimes {
  date: string;
  weekday: string;
  times: { time: string; label: string; professionals: { id: string; name: string }[] }[];
  next_days: { date: string; weekday: string; count: number; first: { time: string; label: string; professionals: { id: string; name: string }[] }[] }[];
}

export interface ScheduleDetails {
  professional_id: string | null;
  branch_id?: string | null;
  slots: SlotInput[];
}

export const STATUS_BADGES: Record<PackageStatus, string> = {
  active: 'bg-emerald-50 text-emerald-700',
  consumed: 'bg-slate-100 text-slate-600',
  expired: 'bg-amber-50 text-amber-700',
  cancelled: 'bg-rose-50 text-rose-700',
};

export const WEEKDAYS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
export const WEEKDAYS_SHORT = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];


export const hour12 = (time: string | null | undefined): string => {
  if (!time) return '';
  const [h, m] = time.split(':').map(Number);
  return `${h % 12 || 12}:${String(m).padStart(2, '0')} ${h < 12 ? 'a. m.' : 'p. m.'}`;
};

export const dayLabel = (date: string): string => {
  const [y, m, d] = date.slice(0, 10).split('-').map(Number);
  const day = new Date(y, m - 1, d);
  return `${WEEKDAYS[day.getDay()]} ${d} ${MONTHS[m - 1]}`;
};

export const shortDate = (iso: string): string => {
  const date = new Date(iso);
  return `${date.getDate()} ${MONTHS[date.getMonth()]} ${date.getFullYear()}`;
};

export const sessionsLabel = (count: number): string => (count === 1 ? '1 sesión' : `${count} sesiones`);

export const daysLeftLabel = (pkg: Pick<CustomerPackage, 'status' | 'days_left' | 'expires_at'>): string => {
  if (pkg.status === 'expired') return `Venció el ${shortDate(pkg.expires_at)}`;
  if (pkg.status !== 'active') return `Valía hasta el ${shortDate(pkg.expires_at)}`;
  if (pkg.days_left <= 0) return 'Vence hoy';
  if (pkg.days_left === 1) return 'Vence mañana';
  return `Vence en ${pkg.days_left} días`;
};

export const FREQUENCY_HINTS: Record<Frequency, string> = {
  weekly: 'Ej. todos los martes a la misma hora',
  twice_weekly: 'Ej. martes y viernes, o lunes y jueves',
  biweekly: 'Ej. un sábado sí y otro no',
  monthly: 'Ej. el mismo día de cada mes',
  custom: 'Eliges los días de la semana y la hora de cada uno',
};

export const MODE_HINTS: Record<BookingMode, string> = {
  free: 'Ej. 5 manicures: el cliente reserva cada una cuando quiere',
  scheduled: 'Ej. 10 terapias martes y jueves: se agendan todas al venderlo',
};

export const PROFESSIONAL_HINTS: Record<ProfessionalMode, string> = {
  chosen: 'El cliente sigue con la misma persona en todas las sesiones',
  any: 'Cada sesión la atiende quien esté libre ese día',
};

export const LATE_HINTS: Record<LatePolicy, string> = {
  lose: 'Si avisa a tiempo se reprograma; si avisa tarde o no llega, la sesión se pierde',
  reschedule: 'La sesión no se pierde nunca: se vuelve a agendar',
};

export const REFUND_HINTS: Record<RefundPolicy, string> = {
  none: 'Ej. una promoción: si se arrepiente, no se devuelve dinero',
  unused_normal: 'Ej. pagó S/ 450 por 10 (S/ 60 c/u sueltas), usó 3: se devuelven S/ 270',
  unused_package: 'Ej. pagó S/ 450 por 10, usó 3: se devuelven S/ 315',
};

export const sessionsPerWeek = (frequency: Frequency, customDays: CustomDay[], repeatWeeks: number): number => {
  switch (frequency) {
    case 'twice_weekly': return 2;
    case 'biweekly': return 0.5;
    case 'monthly': return 12 / 52;
    case 'custom': return Math.max(1, customDays.length) / Math.max(1, repeatWeeks);
    default: return 1;
  }
};
