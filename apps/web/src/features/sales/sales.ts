// Tipos y utilidades que comparten el cobro de una cita y la caja.

export interface SaleInvoice {
  id: string;
  doc_type: 'ticket' | 'boleta' | 'factura' | 'nota_credito';
  doc_type_label: string;
  // Serie y número: B001-00000001
  number: string;
  customer_doc_type: string | null;
  customer_doc_number: string | null;
  customer_name: string | null;
  customer_address: string | null;
  taxable: string;
  tax: string;
  total: string;
  status: string;
  // No se envió a SUNAT de verdad: la empresa aún no conecta su proveedor.
  simulated: boolean;
  sunat_status: 'pending' | 'accepted' | 'rejected' | 'not_applicable';
  sunat_message: string | null;
  // Solo en una nota de crédito: por qué se emitió.
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
  // Cupón con que se creó la venta, si usó alguno.
  coupon: AppliedCoupon | null;
  invoice: SaleInvoice | null;
  // Total devuelto al cliente.
  refunded?: number;
}

export interface AppliedCoupon {
  code: string;
  description: string | null;
  // "10 %" o "S/ 20.00"
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
  // AAAA-MM-DD, en la hora de la empresa.
  starts_on: string | null;
  ends_on: string | null;
  status: 'active' | 'inactive';
  state: 'valid' | 'scheduled' | 'expired' | 'used_up' | 'inactive';
  created_at: string;
}

// Lo que hace falta para cobrar una cita: su venta (si ya tiene) y las opciones de cobro.
export interface ChargeContext {
  sale: Sale | null;
  preview: { subtotal: number; discount: number; tax: number; total: number; tax_rate: number; prices_include_tax: boolean };
  // Cupón que se está probando antes de cobrar (aún sin venta).
  coupon?: AppliedCoupon | null;
  // Por qué no se puede cobrar, si es el caso.
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
  // Efectivo que debe haber (abierto) o que debía haber al cerrar.
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

// 1234.5 -> "S/ 1,234.50"
export const money = (value: number | string | null | undefined): string =>
  `S/ ${Number(value ?? 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

// "12 oct 2026, 15:30" en la hora del navegador.
export const momentOf = (iso: string | null | undefined): string =>
  iso
    ? new Date(iso).toLocaleString('es-PE', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false }).replace(/\./g, '')
    : '—';

// Deja escribir solo un monto: dígitos y un punto con hasta dos decimales.
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

// Una fila de la pantalla Ventas.
export interface SaleRow {
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
  total: number;
  paid: number;
  balance: number;
  refunded: number;
  invoice_number: string | null;
  invoice_label: string | null;
  // Cita atendida que nadie cobró: aún no tiene venta.
  unbilled?: boolean;
  appointment_id?: string;
}

// Lo que la API entrega por cada cita atendida sin cobrar.
export interface UnbilledAppointment {
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

// La venta completa, con lo que se puede hacer con ella.
export interface SaleDetail extends Sale {
  branch: { id: string; name: string | null };
  customer: { id: string; name: string } | null;
  appointment: { id: string; code: string | null } | null;
  void_reason: string | null;
  issuer: { name: string; tax_id: string | null; address: string | null };
  refunds: SaleRefund[];
  can_manage: boolean;
  // Por qué no se puede anular o devolver, si es el caso.
  void_blocked: string | null;
  refund_available: number;
  refund_blocked: string | null;
  refund_methods: { value: string; label: string }[];
  cash_sessions: { id: string; register_name: string }[];
}

// Una fila de la pantalla Pagos: un cobro o una devolución (monto negativo).
export interface PaymentRow {
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
