export type WalkinStatus = 'waiting' | 'serving' | 'done' | 'left';

export interface WalkinEntry {
  id: string;
  ticket: string;
  status: WalkinStatus;
  status_label: string;
  source: 'staff' | 'kiosk';
  source_label: string;
  notes: string | null;
  waited_minutes: number | null;
  elapsed_minutes: number | null;
  arrived_at: string;
  arrived_time: string | null;
  served_time: string | null;
  done_time: string | null;
  left_time: string | null;
  position: number | null;
  wait_minutes: number | null;
  estimated_at: string | null;
  suggested_professional: { id: string; display_name: string } | null;
  branch_id: string;
  branch: { id: string; name: string } | null;
  customer_id: string;
  customer: { id: string; first_name: string; last_name: string; full_name: string; phone: string | null; no_show_count: number } | null;
  customer_dependent_id: string | null;
  dependent: { id: string; name: string } | null;
  service_id: string;
  service: { id: string; name: string; duration_minutes: number; price: number } | null;
  professional_id: string | null;
  professional: { id: string; display_name: string } | null;
  appointment_id: string | null;
  appointment: { id: string; code: string | null; status: string; status_name: string } | null;
}

export interface WalkinSummary {
  waiting: number;
  serving: number;
  done: number;
  left: number;
  avg_wait_minutes: number | null;
}

export interface WalkinContext {
  branches: { id: string; name: string }[];
  services: { id: string; name: string; duration_minutes: number; price: number }[];
  professionals: { id: string; display_name: string }[];
  dependents: { enabled: boolean; kind: 'pet' | 'person' | null; required: boolean };
  rules: { self_checkin: boolean; max_wait: number };
  kiosk_url: string | null;
  history_days: number;
}

export interface WalkinChoices {
  first_free: { wait_minutes: number | null; estimated_at: string | null; suggested_professional: { id: string; display_name: string } | null };
  professionals: { id: string; display_name: string; wait_minutes: number | null; estimated_at: string | null }[];
  waiting: number;
}

export interface WalkinCandidate {
  id: string;
  display_name: string;
  available: boolean;
  overridable: boolean;
  reason: string | null;
}

export const WALKIN_STATUS_STYLES: Record<WalkinStatus, string> = {
  waiting: 'bg-amber-50 text-amber-700',
  serving: 'bg-teal-50 text-teal-700',
  done: 'bg-emerald-50 text-emerald-700',
  left: 'bg-slate-100 text-slate-500',
};

export const LONG_WAIT = 30;

export function formatWaitLabel(minutes: number | null): string {
  if (minutes === null) return 'Sin espacio hoy';
  if (minutes <= 0) return 'Pasa ahora';
  if (minutes < 60) return `~${minutes} min`;
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return m > 0 ? `~${h} h ${m} min` : `~${h} h`;
}

export function choiceLabel(name: string, minutes: number | null, at: string | null): string {
  if (minutes === null) return `${name} · sin espacio hoy`;
  return `${name} · ${minutes <= 0 ? 'libre ahora' : `${formatWaitLabel(minutes)} (${at})`}`;
}
