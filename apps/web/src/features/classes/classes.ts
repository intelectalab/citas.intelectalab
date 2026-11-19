export type ClassState = 'open' | 'full' | 'running' | 'done' | 'cancelled';
export type EnrollmentStatus = 'enrolled' | 'waitlist' | 'attended' | 'no_show' | 'cancelled';

export interface ClassSession {
  id: string;
  title: string;
  status: 'scheduled' | 'cancelled';
  state: ClassState;
  state_label: string;
  starts_at: string;
  ends_at: string;
  date: string;
  time: string;
  end_time: string;
  capacity: number;
  taken: number;
  free: number;
  waitlist: number;
  attended: number;
  no_show: number;
  online_booking: boolean;
  notes: string | null;
  series_id: string | null;
  cancel_reason: string | null;
  cancelled_at: string | null;
  service: { id: string; name: string; price?: number; duration_minutes?: number };
  professional: { id: string; display_name: string; color_code: string | null } | null;
  branch: { id: string; name: string } | null;
  room: { id: string; name: string } | null;
}

export interface ClassEnrollment {
  id: string;
  status: EnrollmentStatus;
  status_label: string;
  source: 'staff' | 'portal' | 'online';
  source_label: string;
  position: number | null;
  created_at: string;
  promoted: boolean;
  customer: { id: string; name: string; phone: string | null; email: string | null } | null;
  paid: boolean;
  paid_with: 'sale' | 'package' | 'membership' | null;
  paid_label: string | null;
  sale: { id: string; code: string; status: string; total: number; balance: number } | null;
  package: { id: string; code: string | null; name: string | null } | null;
  has_package: boolean;
  membership: { id: string; name: string } | null;
}

export interface ClassOptions {
  branches: { id: string; name: string }[];
  services: { id: string; name: string; capacity: number; duration_minutes: number; price: number; branch_ids?: string[]; professional_ids: string[] }[];
  professionals: { id: string; display_name: string; branch_ids: string[] }[];
  rooms: { id: string; name: string; branch_id: string }[];
}

export interface ClassCan {
  create: boolean;
  edit: boolean;
  cancel: boolean;
  charge: boolean;
  packages: boolean;
  override: boolean;
  customers: boolean;
}

export interface ClassesBoardData {
  classes: ClassSession[];
  options: ClassOptions;
  can: ClassCan;
  rules: { waitlist: boolean; auto_promote: boolean };
  max_weeks: number;
}

export interface ClassDetailData extends ClassSession {
  enrollments: ClassEnrollment[];
  series: ClassSession[];
  options: ClassOptions;
  can: ClassCan;
  rules: { waitlist: boolean };
  result?: { promoted?: number | string | null; notified?: number; paid_sales?: string[]; status?: string; marked?: number; sale?: { id: string; code: string; status: string; total: number } };
}

export const STATE_BADGES: Record<ClassState, string> = {
  open: 'bg-emerald-50 text-emerald-700',
  full: 'bg-amber-50 text-amber-700',
  running: 'bg-sky-50 text-sky-700',
  done: 'bg-slate-100 text-slate-500',
  cancelled: 'bg-rose-50 text-rose-700',
};

export const ENROLLMENT_BADGES: Record<EnrollmentStatus, string> = {
  enrolled: 'bg-sky-50 text-sky-700',
  waitlist: 'bg-amber-50 text-amber-700',
  attended: 'bg-emerald-50 text-emerald-700',
  no_show: 'bg-rose-50 text-rose-700',
  cancelled: 'bg-slate-100 text-slate-500',
};

export function classDate(date: string): string {
  const [y, m, d] = date.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString('es-PE', { weekday: 'long', day: 'numeric', month: 'long' });
}

export function shortClassDate(date: string): string {
  const [y, m, d] = date.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString('es-PE', { weekday: 'short', day: 'numeric', month: 'short' });
}

export function spotsLabel(c: Pick<ClassSession, 'taken' | 'capacity' | 'free'>): string {
  return `${c.taken} de ${c.capacity} inscritos`;
}
