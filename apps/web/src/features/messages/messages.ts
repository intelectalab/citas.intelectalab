export type MessageStatus = 'scheduled' | 'queued' | 'sent' | 'simulated' | 'delivered' | 'read' | 'failed' | 'not_sent' | 'cancelled';

export interface Message {
  id: string;
  kind: string;
  kind_label: string;
  channel: 'email' | 'whatsapp' | 'call';
  status: MessageStatus;
  status_label: string;
  to: string | null;
  to_name: string | null;
  customer: { id: string; name: string } | null;
  subject: string | null;
  body: string;
  scheduled_at: string | null;
  sent_at: string | null;
  delivered_at?: string | null;
  read_at?: string | null;
  created_at: string;
  attempts: number;
  error: string | null;
  by: string | null;
  editable: boolean;
  retryable: boolean;
}

export type EmailState = 'none' | 'simulated' | 'untested' | 'ok' | 'error';

export interface EmailConfig {
  provider: 'smtp' | 'simulated';
  preset?: string | null;
  host?: string;
  port?: number;
  security?: 'tls' | 'ssl';
  username?: string;
  from_address?: string;
  from_name?: string;
  reply_to?: string;
  password_saved?: string | null;
  tested_at?: string | null;
  test_message?: string | null;
  saved_at?: string | null;
}

export interface EmailStatus {
  state: EmailState;
  label: string;
  config: EmailConfig | null;
}

export interface MessagesBoard {
  messages: Message[];
  email: EmailStatus;
  whatsapp?: WhatsAppStatus;
  kinds: { value: string; label: string }[];
  statuses: { value: string; label: string }[];
  due: number;
  can: { create: boolean; edit: boolean; reply?: boolean; settings: boolean };
}

export interface NoticeSetting {
  kind: string;
  label: string;
  when: string;
  manual: boolean;
  enabled: boolean;
  subject: string;
  body: string;
  custom: boolean;
  options: { hours?: number; second?: boolean; days?: number };
  variables: { token: string; label: string }[];
}

export interface MessagingSettingsData {
  email: EmailStatus;
  presets: { key: string; label: string; host: string; port: number; security: 'tls' | 'ssl'; hint: string }[];
  own_email?: boolean;
  notices: NoticeSetting[];
  whatsapp: WhatsAppStatus;
  design: EmailDesign;
}

export interface EmailDesign {
  color: string;
  show_name: boolean;
  footer: string;
  default_footer: string;
  logo_url: string | null;
  company: string;
  colors: string[];
}

export interface Consents {
  email: { granted: boolean; source: string | null; at: string | null };
  whatsapp: { granted: boolean; source: string | null; at: string | null };
}

export const MESSAGE_BADGES: Record<MessageStatus, string> = {
  scheduled: 'bg-violet-50 text-violet-700',
  queued: 'bg-sky-50 text-sky-700',
  sent: 'bg-emerald-50 text-emerald-700',
  simulated: 'bg-brand-soft/25 text-brand-ink',
  delivered: 'bg-emerald-50 text-emerald-800',
  read: 'bg-teal-50 text-teal-800',
  failed: 'bg-rose-50 text-rose-700',
  not_sent: 'bg-amber-50 text-amber-800',
  cancelled: 'bg-slate-100 text-slate-500',
};

export const EMAIL_BADGES: Record<EmailState, string> = {
  none: 'bg-amber-50 text-amber-800 border-amber-200',
  simulated: 'bg-brand-soft/15 text-brand-ink border-brand-soft/50',
  untested: 'bg-sky-50 text-sky-800 border-sky-200',
  ok: 'bg-emerald-50 text-emerald-800 border-emerald-200',
  error: 'bg-rose-50 text-rose-800 border-rose-200',
};

export const outcomeOf = (message: Message, what: string): { ok: boolean; text: string } => {
  switch (message.status) {
    case 'sent':
    case 'delivered':
    case 'read': return { ok: true, text: `Se envió ${what} a ${message.to}.` };
    case 'simulated': return { ok: true, text: `Simulado: ${what} se armó para ${message.to}, pero no salió porque tu empresa está en "Probar sin enviar". Lo ves en Mensajes.` };
    case 'queued': return { ok: true, text: `${what.charAt(0).toUpperCase()}${what.slice(1)} está saliendo hacia ${message.to}. Lo ves en Mensajes.` };
    default: return { ok: false, text: message.error ?? 'No se pudo enviar.' };
  }
};

export const STARTERS: { id: string; label: string; subject: string; body: (name: string, company: string) => string }[] = [
  { id: 'blank', label: 'En blanco', subject: '', body: (name, company) => `Hola ${name}:\n\n\n\n${company}` },
  { id: 'results', label: 'Resultados listos', subject: 'Tus resultados están listos', body: (name, company) => `Hola ${name}:\n\nTus resultados ya están listos. Puedes recogerlos en nuestra sede o te los explicamos en tu próxima cita.\n\n${company}` },
  { id: 'control', label: 'Te toca tu control', subject: 'Te toca tu próximo control', body: (name, company) => `Hola ${name}:\n\nYa se acerca la fecha de tu próximo control. Escríbenos o resérvalo en nuestro portal.\n\n${company}` },
  { id: 'hours', label: 'Cambio de horario', subject: 'Cambio en nuestro horario de atención', body: (name, company) => `Hola ${name}:\n\nTe contamos que cambiará nuestro horario de atención. \n\nGracias por tu preferencia.\n\n${company}` },
];

export type WhatsAppState = 'not_ready' | 'none' | 'simulated' | 'untested' | 'ok' | 'error';

export interface WhatsAppStatus {
  state: WhatsAppState;
  label: string;
  channel: 'email' | 'whatsapp' | 'both';
}

export type WhatsAppMessageStatus = 'received' | 'queued' | 'sent' | 'simulated' | 'delivered' | 'read' | 'failed';

export interface WhatsAppConversation {
  id: string;
  phone: string;
  contact_name: string | null;
  customer: { id: string; name: string } | null;
  status: 'open' | 'needs_human' | 'closed';
  status_label: string;
  handoff_reason: string | null;
  handoff_summary?: string | null;
  unread: number;
  window_open: boolean;
  window_ends_at: string | null;
  last_inbound_at: string | null;
  last_message_at: string | null;
  last: { body: string; direction: 'in' | 'out'; status: WhatsAppMessageStatus } | null;
}

export interface WhatsAppMessage {
  id: string;
  direction: 'in' | 'out';
  type: string;
  body: string;
  template: string | null;
  status: WhatsAppMessageStatus;
  status_label: string;
  by: string | null;
  by_assistant: boolean;
  error: string | null;
  at: string | null;
  delivered_at: string | null;
  read_at: string | null;
  media?: { mime: string | null; name: string | null; image: boolean } | null;
}

export interface WhatsAppThread {
  conversation: WhatsAppConversation;
  messages: WhatsAppMessage[];
  assistant: { id: string; action: string; label: string; reason: string | null; at: string }[];
  consent: 'ok' | 'missing' | 'revoked' | 'unknown';
  status: WhatsAppStatus;
  can: { reply: boolean };
}

export const WHATSAPP_BADGES: Record<WhatsAppState, string> = {
  not_ready: 'bg-amber-50 text-amber-800 border-amber-200',
  none: 'bg-slate-50 text-slate-700 border-slate-200',
  simulated: 'bg-brand-soft/15 text-brand-ink border-brand-soft/50',
  untested: 'bg-sky-50 text-sky-800 border-sky-200',
  ok: 'bg-emerald-50 text-emerald-800 border-emerald-200',
  error: 'bg-rose-50 text-rose-800 border-rose-200',
};

export const WA_MESSAGE_BADGES: Record<WhatsAppMessageStatus, string> = {
  received: 'bg-slate-100 text-slate-600',
  queued: 'bg-sky-50 text-sky-700',
  sent: 'bg-emerald-50 text-emerald-700',
  simulated: 'bg-brand-soft/25 text-brand-ink',
  delivered: 'bg-emerald-50 text-emerald-800',
  read: 'bg-teal-50 text-teal-800',
  failed: 'bg-rose-50 text-rose-700',
};
