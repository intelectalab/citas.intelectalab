import { MONTHS_SHORT as MONTHS } from '@/lib/dates';
import type { AppliedCoupon } from '@/features/sales/sales';
import type { PickedCustomer } from '@/features/customers/components/CustomerPicker';
import type { PackagePlan, ScheduleDetails } from '@/features/packages/packages';

export type CartItemType = 'appointment' | 'service' | 'product' | 'package';

export interface CartItem {
  type: CartItemType;
  id: string;
  quantity: number;
  professional_id?: string | null;
  label: string;
  unit_price: number;
  details?: ScheduleDetails | null;
}

export interface Cart {
  saleId: string | null;
  code: string | null;
  editBlocked: string | null;
  customer: PickedCustomer | null;
  coupon: string;
  items: CartItem[];
}

export interface PendingAppointment {
  id: string;
  code: string;
  starts_at: string;
  today: boolean;
  status: string;
  status_name: string;
  customer: { id: string; name: string } | null;
  service: string | null;
  professional: { id: string; name: string } | null;
  price: number;
  currency?: string;
}

export interface PendingSale {
  id: string;
  code: string;
  status: 'pending' | 'partial';
  created_at: string;
  customer: { id: string; name: string } | null;
  by: string | null;
  detail: string;
  total: number;
  balance: number;
  paid?: number;
  deposit?: number;
  currency?: string;
}

export interface PosService {
  id: string;
  name: string;
  price: number;
  duration_minutes: number;
  category: string | null;
  professional_ids: string[];
}

export interface PosProduct {
  id: string;
  name: string;
  sku: string;
  price: number;
  category: string | null;
  track_stock?: boolean;
  stock?: number | null;
}

export interface PosBoard {
  branches: { id: string; name: string }[];
  branch_id: string | null;
  currency: string;
  tax: { rate: number; included: boolean };
  products_ready: boolean;
  can: { create: boolean; edit: boolean; charge: boolean; walk_in: boolean; products: boolean; packages?: boolean };
  packages_enabled?: boolean;
  inventory_enabled?: boolean;
  allow_negative_stock?: boolean;
  pending: { appointments: PendingAppointment[]; sales: PendingSale[] };
  services: PosService[];
  products: PosProduct[];
  packages?: PackagePlan[];
  professionals: { id: string; display_name: string }[];
}

export interface PreviewLine {
  type: CartItemType;
  id: string;
  description: string;
  quantity: number;
  unit_price: number;
  total: number;
  professional: { id: string; name: string } | null;
  appointment: { code: string; starts_at: string; status: string } | null;
  details?: ScheduleDetails | null;
  package?: { sessions: number; booking_mode: 'free' | 'scheduled'; schedule_label: string; scheduled: number } | null;
}

export interface CartPreview {
  branch_id: string;
  customer: PickedCustomer | null;
  lines: PreviewLine[];
  totals: { subtotal: number; discount: number; tax: number; total: number; tax_rate: number; prices_include_tax: boolean; currency: string };
  coupon: AppliedCoupon | null;
}

export interface LoadedCart {
  sale_id: string;
  code: string;
  status: string;
  branch_id: string;
  customer: PickedCustomer | null;
  coupon_code: string | null;
  edit_blocked: string | null;
  items: { type: CartItemType; id: string; quantity: number; professional_id: string | null; details?: ScheduleDetails | null }[];
}

export interface WalkInOptions {
  now: string;
  duration_minutes: number;
  price: number;
  currency: string;
  professionals: { id: string; display_name: string; free_now: boolean; now_reason: string | null; slots: string[] }[];
}

export const emptyCart = (): Cart => ({ saleId: null, code: null, editBlocked: null, customer: null, coupon: '', items: [] });

const WEEKDAYS = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];

export const whenOf = (local: string, today: boolean): string => {
  const [date, time] = local.split('T');
  if (today) return `Hoy ${time}`;
  const [y, m, d] = date.split('-').map(Number);
  const day = new Date(y, m - 1, d);
  return `${WEEKDAYS[day.getDay()]} ${d} ${MONTHS[m - 1]} · ${time}`;
};
