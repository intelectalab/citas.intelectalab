export type MessageStatus = 'scheduled' | 'queued' | 'sent' | 'simulated' | 'failed' | 'not_sent' | 'cancelled';

export interface Message {
  id: string;
  kind: string;
  kind_label: string;
  channel: 'email' | 'whatsapp';
  status: MessageStatus;
  status_label: string;
  to: string | null;
  to_name: string | null;
  customer: { id: string; name: string } | null;
  subject: string | null;
  body: string;
  scheduled_at: string | null;
  sent_at: string | null;
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
  kinds: { value: string; label: string }[];
  statuses: { value: string; label: string }[];
  due: number;
  can: { create: boolean; edit: boolean; settings: boolean };
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
  notices: NoticeSetting[];
  whatsapp: { state: string; label: string };
}

export interface Consents {
  email: { granted: boolean; source: string | null; at: string | null };
  whatsapp: { granted: boolean; source: string | null; at: string | null };
}

export const MESSAGE_BADGES: Record<MessageStatus, string> = {
  scheduled: 'bg-violet-50 text-violet-700',
  queued: 'bg-sky-50 text-sky-700',
  sent: 'bg-emerald-50 text-emerald-700',
  simulated: 'bg-[#84D0D9]/25 text-[#022340]',
  failed: 'bg-rose-50 text-rose-700',
  not_sent: 'bg-amber-50 text-amber-800',
  cancelled: 'bg-slate-100 text-slate-500',
};

export const EMAIL_BADGES: Record<EmailState, string> = {
  none: 'bg-amber-50 text-amber-800 border-amber-200',
  simulated: 'bg-[#84D0D9]/15 text-[#022340] border-[#84D0D9]/50',
  untested: 'bg-sky-50 text-sky-800 border-sky-200',
  ok: 'bg-emerald-50 text-emerald-800 border-emerald-200',
  error: 'bg-rose-50 text-rose-800 border-rose-200',
};

export const outcomeOf = (message: Message, what: string): { ok: boolean; text: string } => {
  switch (message.status) {
    case 'sent': return { ok: true, text: `Se envió ${what} a ${message.to}.` };
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
