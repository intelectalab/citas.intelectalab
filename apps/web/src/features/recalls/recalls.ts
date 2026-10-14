export type RecallStatus = 'pending' | 'notified' | 'booked' | 'done' | 'cancelled';

export interface Recall {
  id: string;
  label: string;
  due_date: string;
  days_left: number;
  status: RecallStatus;
  status_name: string;
  notified_at: string | null;
  notes: string | null;
  closed_reason: string | null;
  origin: 'record' | 'service' | 'manual';
  source_code: string | null;
  customer: { id: string; code: string; name: string; first_name: string; last_name: string; phone: string | null; email: string | null } | null;
  dependent: { id: string; name: string; species: string | null } | null;
  service: { id: string; name: string } | null;
  professional: { id: string; display_name: string } | null;
  appointment: { id: string; code: string; status: string; status_name: string; starts_at: string } | null;
}

export const RECALL_STATUS_STYLES: Record<RecallStatus, string> = {
  pending: 'bg-amber-50 text-amber-700',
  notified: 'bg-sky-50 text-sky-700',
  booked: 'bg-violet-50 text-violet-600',
  done: 'bg-emerald-50 text-emerald-700',
  cancelled: 'bg-slate-100 text-slate-500',
};

export const ORIGIN_LABELS: Record<Recall['origin'], string> = {
  record: 'Ficha de atención',
  service: 'Lo pide el servicio',
  manual: 'Registrado a mano',
};

export const dueLabel = (days: number): string => {
  if (days === 0) return 'Hoy';
  if (days === 1) return 'Mañana';
  if (days === -1) return 'Venció ayer';
  if (days < 0) return `Venció hace ${-days} días`;
  if (days < 30) return `En ${days} días`;
  const weeks = Math.round(days / 7);
  return weeks < 9 ? `En ${weeks} semanas` : `En ${Math.round(days / 30)} meses`;
};

export const dueTone = (days: number): { bucket: 'overdue' | 'week' | 'later'; text: string } => {
  if (days < 0) return { bucket: 'overdue', text: 'text-rose-700' };
  if (days <= 7) return { bucket: 'week', text: 'text-amber-700' };
  return { bucket: 'later', text: 'text-slate-500' };
};
