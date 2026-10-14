export interface CompanyProfileData {
  can_edit: boolean;
  tenant: {
    name: string;
    code: string;
    timezone: string;
    business_type: string | null;
    member_since: string | null;
  };
  company: {
    legal_name: string | null;
    tax_id: string | null;
    phone: string | null;
    address: string | null;
    district: string | null;
    department: string | null;
  };
  subscription: {
    plan: string | null;
    status: string;
    billing_cycle: string;
    trial_ends_at: string | null;
    ends_at: string | null;
  } | null;
  usage: { key: string; used: number; limit: number | null }[];
  totals: { customers: number; services: number };
}

export const SUBSCRIPTION_STATUS: Record<string, { label: string; badge: string }> = {
  active: { label: 'Activo', badge: 'bg-emerald-50 text-emerald-700' },
  trialing: { label: 'En prueba', badge: 'bg-sky-50 text-sky-700' },
  past_due: { label: 'Pago pendiente', badge: 'bg-amber-50 text-amber-700' },
  canceled: { label: 'Cancelado', badge: 'bg-slate-100 text-slate-500' },
};
