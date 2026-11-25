export type CampaignStatus = 'draft' | 'scheduled' | 'sending' | 'sent' | 'cancelled' | 'blocked';

export type EmailState = 'none' | 'simulated' | 'ok';

export type WhatsAppState = 'not_ready' | 'none' | 'simulated' | 'ok';

export type CampaignChannel = 'email' | 'whatsapp' | 'both';

export interface ChannelResults {
  sent: number;
  pending: number;
  failed: number;
  skipped: number;
  simulated: number;
  delivered: number;
  read: number;
}

export interface CampaignResults {
  delivered?: number;
  read?: number;
  revenue?: number;
  sent: number;
  pending: number;
  failed: number;
  skipped: number;
  simulated: number;
  by_channel?: Partial<Record<'email' | 'whatsapp', ChannelResults>>;
  booked: { customers: number; appointments: number };
}

export interface QuotaState {
  kind: 'whatsapp' | 'campaign_email';
  label: string;
  limit: number | null;
  used: number;
  pending: number;
  remaining: number | null;
  needed: number;
  enough: boolean;
}

export interface WhatsAppTemplate {
  template: string;
  language: string;
  params: string[];
}

export const CHANNEL_LABELS: Record<CampaignChannel, string> = {
  email: 'Correo',
  whatsapp: 'WhatsApp',
  both: 'Correo y WhatsApp',
};

export const channelsOf = (channel: CampaignChannel): ('email' | 'whatsapp')[] =>
  channel === 'both' ? ['email', 'whatsapp'] : [channel];

export const EMAIL_REASON_LABELS: Record<string, string> = {
  no_email: 'no tienen correo',
  no_consent: 'no aceptaron promociones por correo',
};

export const WA_REASON_LABELS: Record<string, string> = {
  not_connected: 'tu empresa aún no conecta WhatsApp',
  no_phone: 'no tienen celular',
  unsubscribed: 'pidieron no recibir promociones',
  no_consent: 'no aceptaron mensajes por WhatsApp',
};

export interface Segment {
  group: string;
  service_id?: string;
  branch_id?: string;
  tag_id?: string;
  only_branch?: string;
}

export interface Campaign {
  id: string;
  name: string;
  status: CampaignStatus;
  status_label: string;
  segment: Segment;
  channel?: CampaignChannel;
  channel_label?: string;
  whatsapp?: WhatsAppTemplate | null;
  blocked?: string | null;
  audience: string;
  subject: string;
  body: string;
  coupon: { id: string; code: string; description: string | null } | null;
  scheduled_at: string | null;
  sent_at: string | null;
  created_at: string;
  by: string | null;
  stats: { audience?: number; eligible?: number; no_consent?: number; no_email?: number };
  results: CampaignResults | null;
  editable: boolean;
}

export interface Recipient {
  id: string;
  customer: { id: string; name: string };
  channel?: 'email' | 'whatsapp';
  email: string | null;
  to?: string | null;
  state: 'skipped' | 'sent' | 'simulated' | 'delivered' | 'read' | 'pending' | 'failed';
  label: string;
  reason: string | null;
  sent_at: string | null;
}

export interface BookedAppointment {
  id: string;
  code: string;
  customer: { id: string; name: string };
  service: string | null;
  starts_at: string;
  created_at: string;
}

export interface CampaignDetail extends Campaign {
  recipients: Recipient[];
  booked_list: BookedAppointment[];
  booked_days: number;
  email: { state: EmailState; label: string };
  whatsapp_state?: { state: WhatsAppState; label: string };
}

export interface Group {
  key: string;
  label: string;
  hint: string;
  needs: 'service' | 'branch' | 'tag' | null;
}

export interface Variable {
  token: string;
  label: string;
}

export interface CampaignOptions {
  groups: Group[];
  services: { id: string; name: string }[];
  branches: { id: string; name: string }[];
  tags?: { id: string; name: string; color: string | null; customers: number }[];
  coupons: { id: string; code: string; description: string | null; benefit: string }[];
  variables: Variable[];
  volume_warning: number;
  email: { state: EmailState; label: string };
  whatsapp?: { state: WhatsAppState; label: string };
  channels?: { key: CampaignChannel; label: string }[];
  wa_variables?: { key: string; label: string }[];
  wa_max_params?: number;
  quota?: { email: QuotaState; whatsapp: QuotaState };
  branch_scope?: { branch_id: string; branch_name: string | null; can_choose: boolean } | null;
}

export interface Audience {
  total: number;
  eligible: number;
  no_consent: number;
  no_email: number;
  channel?: CampaignChannel;
  channels?: Partial<Record<'email' | 'whatsapp', Record<string, number>>>;
  quota?: Partial<Record<'email' | 'whatsapp', QuotaState>>;
  sample?: { first_name: string; name: string } | null;
}

export interface SegmentCount {
  key: string;
  label: string;
  hint: string;
  count: number;
}

export const COUPON_TOKEN = '[Cupón]';

export const copyName = (name: string) => {
  const base = name.replace(/ \(copia( \d+)?\)$/, '');
  const copy = `${base} (copia)`;
  return copy.length > 150 ? `${base.slice(0, 150 - 8)} (copia)` : copy;
};

export const CAMPAIGN_BADGES: Record<CampaignStatus, string> = {
  draft: 'bg-amber-50 text-amber-800',
  scheduled: 'bg-violet-50 text-violet-700',
  sending: 'bg-sky-50 text-sky-700',
  sent: 'bg-emerald-50 text-emerald-700',
  cancelled: 'bg-slate-100 text-slate-500',
  blocked: 'bg-rose-50 text-rose-700',
};

export const RECIPIENT_BADGES: Record<Recipient['state'], string> = {
  sent: 'bg-emerald-50 text-emerald-700',
  delivered: 'bg-emerald-50 text-emerald-700',
  read: 'bg-emerald-50 text-emerald-700',
  simulated: 'bg-brand-soft/25 text-brand-ink',
  pending: 'bg-sky-50 text-sky-700',
  failed: 'bg-rose-50 text-rose-700',
  skipped: 'bg-slate-100 text-slate-500',
};

export const EMAIL_NOTICE: Record<EmailState, string> = {
  none: 'bg-amber-50 text-amber-800 border-amber-200',
  simulated: 'bg-brand-soft/15 text-brand-ink border-brand-soft/50',
  ok: 'bg-emerald-50 text-emerald-800 border-emerald-200',
};
