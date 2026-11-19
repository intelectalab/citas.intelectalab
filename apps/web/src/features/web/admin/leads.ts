export interface Lead {
  id: string;
  name: string;
  email: string | null;
  phone: string | null;
  business: string | null;
  business_type: string | null;
  business_type_name: string | null;
  professionals: number | null;
  source: string;
  source_label: string;
  utm: Record<string, string>;
  stage: 'new' | 'contacted' | 'trial' | 'paid' | 'lost';
  stage_label: string;
  coupon: string | null;
  lost_reason: string | null;
  tenant_id: string | null;
  tenant_name: string | null;
  trial_at: string | null;
  paid_at: string | null;
  created_at: string;
  notes?: number;
}

export interface LeadEvent {
  id: string;
  kind: 'stage' | 'note';
  from: string | null;
  to: string | null;
  from_label: string | null;
  to_label: string | null;
  body: string | null;
  user_name: string | null;
  at: string;
}

export const STAGE_TONE: Record<Lead['stage'], string> = {
  new: 'bg-sky-50 text-sky-700',
  contacted: 'bg-violet-50 text-violet-700',
  trial: 'bg-amber-50 text-amber-800',
  paid: 'bg-emerald-50 text-emerald-700',
  lost: 'bg-slate-100 text-slate-600',
};

export const when = (iso: string | null) =>
  iso ? new Date(iso).toLocaleString('es-PE', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'America/Lima' }) : '';
