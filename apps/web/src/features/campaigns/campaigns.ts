export type CampaignStatus = 'scheduled' | 'sending' | 'sent' | 'cancelled';

export type EmailState = 'none' | 'simulated' | 'ok';

export interface CampaignResults {
  sent: number;
  pending: number;
  failed: number;
  skipped: number;
  simulated: number;
  booked: { customers: number; appointments: number };
}

export interface Segment {
  group: string;
  service_id?: string;
  branch_id?: string;
  only_branch?: string;
}

export interface Campaign {
  id: string;
  name: string;
  status: CampaignStatus;
  status_label: string;
  segment: Segment;
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
  email: string | null;
  state: 'skipped' | 'sent' | 'simulated' | 'pending' | 'failed';
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
}

export interface Group {
  key: string;
  label: string;
  hint: string;
  needs: 'service' | 'branch' | null;
}

export interface Variable {
  token: string;
  label: string;
}

export interface CampaignOptions {
  groups: Group[];
  services: { id: string; name: string }[];
  branches: { id: string; name: string }[];
  coupons: { id: string; code: string; description: string | null; benefit: string }[];
  variables: Variable[];
  volume_warning: number;
  email: { state: EmailState; label: string };
  branch_scope?: { branch_id: string; branch_name: string | null; can_choose: boolean } | null;
}

export interface Audience {
  total: number;
  eligible: number;
  no_consent: number;
  no_email: number;
}

export const COUPON_TOKEN = '[Cupón]';

export const copyName = (name: string) => {
  const base = name.replace(/ \(copia( \d+)?\)$/, '');
  const copy = `${base} (copia)`;
  return copy.length > 150 ? `${base.slice(0, 150 - 8)} (copia)` : copy;
};

export const CAMPAIGN_BADGES: Record<CampaignStatus, string> = {
  scheduled: 'bg-violet-50 text-violet-700',
  sending: 'bg-sky-50 text-sky-700',
  sent: 'bg-emerald-50 text-emerald-700',
  cancelled: 'bg-slate-100 text-slate-500',
};

export const RECIPIENT_BADGES: Record<Recipient['state'], string> = {
  sent: 'bg-emerald-50 text-emerald-700',
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
