export type TicketStatus = 'open' | 'in_progress' | 'waiting' | 'resolved';

export interface Ticket {
  id: string;
  code: string;
  subject: string;
  topic: string;
  topic_label: string;
  priority: 'low' | 'normal' | 'high' | 'urgent';
  priority_label: string;
  status: TicketStatus;
  status_label: string;
  overdue: boolean;
  due_at: string | null;
  created_at: string;
  last_message_at: string;
  first_response_at: string | null;
  access_until: string | null;
  created_by: string | null;
  assigned_to: string | null;
  assigned_name: string | null;
  tenant: { id: string; name: string } | null;
}

export interface TicketMessage {
  id: string;
  side: 'tenant' | 'platform';
  author: string;
  body: string;
  created_at: string;
  attachment: { name: string; url: string } | null;
}

export interface Option {
  value: string;
  label: string;
}

export const STATUS_TONE: Record<TicketStatus, string> = {
  open: 'bg-sky-50 text-sky-700',
  in_progress: 'bg-violet-50 text-violet-700',
  waiting: 'bg-amber-50 text-amber-800',
  resolved: 'bg-emerald-50 text-emerald-700',
};

export const PRIORITY_TONE: Record<Ticket['priority'], string> = {
  low: 'bg-slate-100 text-slate-600',
  normal: 'bg-slate-100 text-slate-700',
  high: 'bg-amber-50 text-amber-800',
  urgent: 'bg-rose-50 text-rose-700',
};

export const dateTime = (iso: string | null | undefined) =>
  iso ? new Date(iso).toLocaleString('es-PE', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) : '—';

export const ATTACHMENT_ACCEPT = 'image/png,image/jpeg,image/webp,image/gif,application/pdf';
