import { addDays, dateOf, formatDate, timeOf } from '@/features/appointments/appointments';

export type BlockKind = 'vacation' | 'leave' | 'closure' | 'other';

export interface Block {
  id: string;
  professional_id: string | null;
  branch_id: string | null;
  professional: { id: string; display_name: string } | null;
  branch: { id: string; name: string } | null;
  scope: 'professional' | 'branch';
  kind: BlockKind;
  kind_label: string;
  overridable: boolean;
  reason: string | null;
  starts_at: string;
  ends_at: string;
  state: 'current' | 'upcoming' | 'past';
  affected_count: number;
  can_manage: boolean;
  affected?: AffectedAppointment[];
}

export interface AffectedAppointment {
  id: string;
  code: string;
  status: string;
  status_name: string;
  starts_at: string;
  ends_at: string;
  customer: string | null;
  service: string | null;
  professional: string | null;
  package?: string | null;
}

export interface Holiday {
  id: string;
  name: string;
  date: string;
  days_left: number;
  branch_id: string | null;
  branch: { id: string; name: string } | null;
  closed: boolean;
  affected_count: number;
  can_manage: boolean;
  affected?: AffectedAppointment[];
}

export const holidayDueLabel = (days: number): string => {
  if (days < 0) return 'Ya pasó';
  if (days === 0) return 'Hoy';
  if (days === 1) return 'Mañana';
  if (days < 60) return `En ${days} días`;
  return `En ${Math.round(days / 30)} meses`;
};

export interface BlockOptions {
  branches: { id: string; name: string }[];
  professionals: { id: string; display_name: string }[];
  professional_kinds: { key: BlockKind; label: string }[];
  branch_kinds: { key: BlockKind; label: string }[];
  can_block_branch: boolean;
}

export const KIND_STYLES: Record<BlockKind, string> = {
  vacation: 'bg-sky-50 text-sky-700',
  leave: 'bg-violet-50 text-violet-600',
  closure: 'bg-slate-100 text-slate-500',
  other: 'bg-amber-50 text-amber-700',
};

export const STATE_LABELS: Record<Block['state'], { label: string; badge: string }> = {
  current: { label: 'En curso', badge: 'bg-emerald-50 text-emerald-700' },
  upcoming: { label: 'Próximo', badge: 'bg-sky-50 text-sky-700' },
  past: { label: 'Terminado', badge: 'bg-slate-100 text-slate-500' },
};

export const isAllDay = (block: Pick<Block, 'starts_at' | 'ends_at'>): boolean =>
  timeOf(block.starts_at) === '00:00' && timeOf(block.ends_at) === '00:00';

const shortDate = (date: string) => formatDate(date, { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' });

export const rangeLabel = (block: Pick<Block, 'starts_at' | 'ends_at'>): string => {
  const from = dateOf(block.starts_at);

  if (isAllDay(block)) {
    const to = addDays(dateOf(block.ends_at), -1);
    return from === to ? shortDate(from) : `Del ${shortDate(from)} al ${shortDate(to)}`;
  }

  const to = dateOf(block.ends_at);
  return from === to
    ? `${shortDate(from)}, ${timeOf(block.starts_at)} a ${timeOf(block.ends_at)}`
    : `Del ${shortDate(from)}, ${timeOf(block.starts_at)} al ${shortDate(to)}, ${timeOf(block.ends_at)}`;
};

export const durationLabel = (block: Pick<Block, 'starts_at' | 'ends_at'>): string => {
  const minutes = Math.round((new Date(block.ends_at).getTime() - new Date(block.starts_at).getTime()) / 60000);

  if (isAllDay(block)) {
    const days = Math.round(minutes / 1440);
    return days === 1 ? 'Todo el día' : `${days} días`;
  }
  if (minutes < 60) return `${minutes} min`;
  if (minutes >= 1440) return `${Math.floor(minutes / 1440)} d ${Math.floor((minutes % 1440) / 60)} h`;
  return minutes % 60 === 0 ? `${minutes / 60} h` : `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
};
