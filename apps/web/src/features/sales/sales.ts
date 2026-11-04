import { formatMoney } from '@/lib/currency';

export interface SaleInvoice {
  currency?: string;
  id: string;
  doc_type: 'ticket' | 'boleta' | 'factura' | 'nota_credito';
  doc_type_label: string;
  number: string;
  customer_doc_type: string | null;
  customer_doc_number: string | null;
  customer_name: string | null;
  customer_address: string | null;
  taxable: string;
  tax: string;
  total: string;
  status: string;
  simulated: boolean;
  sunat_status: 'pending' | 'accepted' | 'rejected' | 'not_applicable';
  sunat_message: string | null;
  reason?: string | null;
  issued_at: string;
}

export interface SalePayment {
  id: string;
  method: string;
  method_label: string;
  status: string;
  amount: number;
  change: number;
  reference: string | null;
  register: string | null;
  by: string | null;
  at: string;
}

export interface Sale {
  currency?: string;
  id: string;
  code: string;
  status: 'pending' | 'partial' | 'paid' | 'void' | 'partially_refunded' | 'refunded';
  status_label: string;
  subtotal: number;
  discount: number;
  tax: number;
  total: number;
  paid: number;
  balance: number;
  tax_rate: number;
  prices_include_tax: boolean;
  created_at: string;
  paid_at: string | null;
  items: { id: string; description: string; quantity: number; unit_price: number; discount: number; total: number }[];
  payments: SalePayment[];
  coupon: AppliedCoupon | null;
  invoice: SaleInvoice | null;
  refunded?: number;
  deposit?: number;
  deposit_outcome?: 'refund_due' | 'retained' | 'refunded' | null;
}

export interface PaymentLink {
  id: string;
  url: string;
  purpose: 'sale' | 'deposit';
  status: 'pending' | 'paid' | 'expired' | 'cancelled' | 'failed';
  status_label: string;
  amount: number;
  currency: string;
  description: string;
  expires_at: string;
  paid_at: string | null;
  cancel_reason: string | null;
  provider: string;
  simulated: boolean;
  token?: string;
  minutes?: number;
  can_drop?: boolean;
}

export interface PaymentQr {
  image: string;
  holder: string | null;
  updated_at?: string;
}

export interface AppliedCoupon {
  code: string;
  description: string | null;
  benefit: string;
  discount: number;
}

export interface Coupon {
  id: string;
  code: string;
  description: string | null;
  discount_type: 'percent' | 'amount';
  discount_value: number;
  min_purchase: number;
  max_uses: number | null;
  used_count: number;
  starts_on: string | null;
  ends_on: string | null;
  status: 'active' | 'inactive';
  state: 'valid' | 'scheduled' | 'expired' | 'used_up' | 'inactive';
  created_at: string;
}

export interface ChargeContext {
  sale: Sale | null;
  preview: { subtotal: number; discount: number; tax: number; total: number; tax_rate: number; prices_include_tax: boolean; currency?: string };
  coupon?: AppliedCoupon | null;
  blocked: string | null;
  item: string | null;
  methods: { value: string; label: string; needs_reference: boolean }[];
  partial_payments: boolean;
  cash: { required: boolean; sessions: { id: string; register_name: string }[] };
  doc_types: { value: string; label: string }[];
  default_doc_type: string;
  customer: { name: string; document_number: string | null } | null;
  issuer: { name: string; tax_id: string | null; address: string | null };
  change?: number;
  duplicate?: boolean;
  links?: { ready: boolean; hours: number; simulated: boolean; current: PaymentLink | null };
  qr?: Partial<Record<'yape' | 'plin', PaymentQr>>;
  packages?: { id: string; code: string; name: string; created: { code: string; starts_at: string }[]; failed: { date: string; time: string | null; message: string }[] }[];
  package?: {
    current: { id: string; code: string; name: string; status: string; consumed: boolean; usage_label: string | null; late_policy: string } | null;
    usable: { id: string; code: string; name: string; sessions_available: number; sessions_total: number; sessions_used: number; expires_at: string; days_left: number }[];
    can_use: boolean;
  } | null;
  loyalty?: {
    points_enabled: boolean;
    gift_cards_enabled: boolean;
    points: {
      balance: number;
      point_value: number;
      min_redeem_points: number;
      can_redeem: boolean;
      redeemable_value: number;
      max_amount: number;
      amount_per_point: number;
      will_earn: number;
    } | null;
    customer_gift_cards: {
      id: string;
      code: string;
      balance: number;
      initial_amount: number;
      recipient_name: string | null;
      expires_at: string | null;
    }[];
  } | null;
}

export interface CashMovement {
  id: string;
  type: 'sale' | 'income' | 'expense' | 'withdrawal' | 'refund';
  type_label: string;
  sign: 1 | -1;
  amount: number;
  description: string | null;
  by: string | null;
  at: string;
}

export interface CashSession {
  mine?: boolean;
  hidden?: boolean;
  currency?: string;
  id: string;
  register_id: string;
  branch_id: string;
  register_name?: string | null;
  branch_name?: string | null;
  status: 'open' | 'closed';
  opened_at: string;
  closed_at: string | null;
  opened_by: string | null;
  closed_by: string | null;
  opening_amount: number;
  sales: number;
  income: number;
  expense: number;
  withdrawal: number;
  refund: number;
  expected_amount: number;
  closing_amount: number | null;
  difference: number | null;
  closing_notes: string | null;
  movement_count: number;
  by_method: { method: string; label: string; total: number; count: number }[];
  movements?: CashMovement[];
  tolerance?: number;
}

export interface CashRegister {
  id: string;
  name: string;
  status: 'active' | 'inactive';
  branch_id: string;
  branch_name: string | null;
  session: CashSession | null;
}

export interface CashBoardData {
  can_manage: boolean;
  tolerance: number;
  branches: { id: string; name: string }[];
  registers: CashRegister[];
  sessions: CashSession[];
}

export const money = (value: number | string | null | undefined, currency?: string | null): string => formatMoney(value, currency);

export const momentOf = (iso: string | null | undefined): string =>
  iso
    ? new Date(iso).toLocaleString('es-PE', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false }).replace(/\./g, '')
    : '—';

export const cleanAmount = (text: string): string => {
  const [whole, ...rest] = text.replace(',', '.').replace(/[^\d.]/g, '').split('.');
  return rest.length ? `${whole.slice(0, 7)}.${rest.join('').slice(0, 2)}` : whole.slice(0, 7);
};

export const SUNAT_LABELS: Record<SaleInvoice['sunat_status'], string> = {
  accepted: 'Aceptado por SUNAT',
  pending: 'Pendiente de envío a SUNAT',
  rejected: 'Rechazado por SUNAT',
  not_applicable: 'Comprobante interno',
};

export interface SaleRow {
  currency?: string;
  id: string;
  code: string;
  status: Sale['status'];
  status_label: string;
  created_at: string;
  branch_id: string;
  branch_name: string | null;
  customer: { id: string; name: string } | null;
  appointment_code: string | null;
  detail: string;
  item_types?: string[];
  total: number;
  paid: number;
  balance: number;
  refunded: number;
  invoice_number: string | null;
  invoice_label: string | null;
  unbilled?: boolean;
  appointment_id?: string;
}

export interface UnbilledAppointment {
  currency?: string;
  appointment_id: string;
  appointment_code: string;
  at: string;
  branch_id: string;
  branch_name: string | null;
  customer: { id: string; name: string } | null;
  detail: string | null;
  total: number;
}

export interface SaleRefund {
  id: string;
  amount: number;
  method_label: string;
  reason: string;
  by: string | null;
  at: string;
  credit_note: SaleInvoice | null;
}

export interface SaleDetail extends Sale {
  branch: { id: string; name: string | null };
  customer: { id: string; name: string } | null;
  appointment: { id: string; code: string | null } | null;
  appointments?: { id: string; code: string }[];
  can_edit?: boolean;
  void_reason: string | null;
  issuer: { name: string; tax_id: string | null; address: string | null; logo_url?: string | null; footer?: string };
  refunds: SaleRefund[];
  can_manage: boolean;
  void_blocked: string | null;
  refund_available: number;
  refund_blocked: string | null;
  refund_methods: { value: string; label: string }[];
  cash_sessions: { id: string; register_name: string }[];
}

export interface PaymentRow {
  currency?: string;
  id: string;
  at: string;
  method: string;
  method_label: string;
  kind: 'payment' | 'refund';
  status: string;
  amount: number;
  reference: string | null;
  sale_id: string | null;
  sale_code: string | null;
  customer: { id: string; name: string } | null;
  branch_id: string | null;
  branch_name: string | null;
  register: string | null;
  by: string | null;
}

export const SALE_BADGES: Record<Sale['status'], string> = {
  pending: 'bg-amber-50 text-amber-700',
  partial: 'bg-sky-50 text-sky-700',
  paid: 'bg-emerald-50 text-emerald-700',
  void: 'bg-slate-100 text-slate-500',
  partially_refunded: 'bg-violet-50 text-violet-600',
  refunded: 'bg-rose-50 text-rose-700',
};
