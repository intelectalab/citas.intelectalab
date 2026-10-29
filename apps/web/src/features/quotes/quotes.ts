export interface QuoteRules {
  validity_days: number;
  allow_installments: boolean;
  auto_create_sale: boolean;
  track_clinical_zones: boolean;
  plan_validity_days: number;
  cancel_policy: 'unused_quote' | 'unused_normal' | 'none';
  cancel_policy_label: string;
}

export interface QuotePlan {
  id: string;
  code: string;
  status: 'active' | 'consumed' | 'expired' | 'cancelled';
  status_label: string;
  expires_at: string | null;
}

export interface QuoteCancelPreview {
  policy: QuoteRules['cancel_policy'];
  policy_label: string;
  used_sessions: number;
  total_sessions: number;
  used_value: number;
  paid: number;
  refund: number;
  refund_max: number;
  refund_blocked: string | null;
  debt: number;
  explain: string;
  currency: string;
  sale: { id: string; code: string } | null;
  can_refund: boolean;
  methods: { value: string; label: string }[];
  cash: { id: string; register_name: string }[];
}

export interface QuoteItemRow {
  id: string;
  item_type: 'service' | 'product' | 'package' | 'other';
  item_type_label: string;
  reference_id: string | null;
  description: string;
  quantity: number;
  unit_price: number;
  discount: number;
  total: number;
  sessions_total: number;
  sessions_used: number;
  zone: string | null;
  phase: string | null;
  notes: string | null;
}

export interface QuoteInstallmentRow {
  id: string;
  number: number;
  due_date: string;
  amount: number;
  status: 'pending' | 'paid' | 'overdue' | 'cancelled';
  status_label: string;
  paid_at: string | null;
}

export interface QuoteHistoryRow {
  id: string;
  from_status: string | null;
  to_status: string;
  to_status_label: string;
  actor: string | null;
  note: string | null;
  created_at: string | null;
}

export interface QuoteRow {
  id: string;
  code: string;
  title: string | null;
  status: 'draft' | 'sent' | 'accepted' | 'rejected' | 'expired' | 'cancelled';
  status_label: string;
  valid_until: string | null;
  days_left: number | null;
  branch_id: string | null;
  branch_name: string | null;
  customer_id: string;
  customer_name: string;
  customer_document: string | null;
  customer_phone: string | null;
  customer_email: string | null;
  professional_id: string | null;
  professional_name: string | null;
  subtotal: number;
  discount: number;
  tax: number;
  total: number;
  notes: string | null;
  accepted_at: string | null;
  accepted_via: 'staff' | 'portal' | null;
  sale_id: string | null;
  sale_code: string | null;
  sale_status: string | null;
  sale_status_label: string | null;
  sale_paid: number;
  sale_balance: number;
  sessions_total: number;
  sessions_used: number;
  progress_percent: number;
  items_count: number;
  items: QuoteItemRow[];
  installments_count: number;
  installments: QuoteInstallmentRow[];
  overdue_installments: number;
  plan: QuotePlan | null;
  history: QuoteHistoryRow[];
  created_at: string | null;
}

export interface QuotesBoardData {
  currency: string;
  rules: QuoteRules;
  tax: { rate: number; included: boolean };
  can: { create: boolean; edit: boolean; delete: boolean; charge: boolean };
  branches: { id: string; name: string }[];
  customers: { id: string; name: string; document_number: string | null; phone: string | null; email: string | null }[];
  professionals: { id: string; display_name: string }[];
  services: { id: string; name: string; price: number; duration_minutes: number }[];
  products: { id: string; name: string; sku: string | null; price: number }[];
  packages: { id: string; name: string; price: number; sessions_total: number }[];
  quotes: QuoteRow[];
  created_sale_id?: string;
  cancel_result?: { refunded: number; balance: number; cancelled_appointments: string[]; message: string };
}

export const QUOTE_STATUS_BADGES: Record<QuoteRow['status'], string> = {
  draft: 'bg-slate-100 text-slate-700',
  sent: 'bg-sky-50 text-sky-700',
  accepted: 'bg-emerald-50 text-emerald-700',
  rejected: 'bg-rose-50 text-rose-700',
  expired: 'bg-amber-50 text-amber-800',
  cancelled: 'bg-slate-100 text-slate-500',
};

export const INSTALLMENT_STATUS_BADGES: Record<QuoteInstallmentRow['status'], string> = {
  pending: 'bg-amber-50 text-amber-800',
  paid: 'bg-emerald-50 text-emerald-700',
  overdue: 'bg-rose-50 text-rose-700',
  cancelled: 'bg-slate-100 text-slate-500',
};
