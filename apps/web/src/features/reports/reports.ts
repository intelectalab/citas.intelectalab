export type ReportTab = 'sales' | 'appointments' | 'customers' | 'pros' | 'catalog' | 'cash' | 'inventory' | 'mine';

export interface CashSummary {
  sessions: number;
  closed: number;
  with_difference: number;
  sales: number | null;
  income: number | null;
  expense: number | null;
  withdrawal: number | null;
  refund: number | null;
  short: number | null;
  over: number | null;
  online?: number | null;
}

export interface CashRegisterRow {
  id: string;
  name: string;
  branch: string | null;
  sessions: number;
  sales: number;
  net: number;
  difference: number;
  online?: boolean;
}

export interface CashDifferenceRow {
  id: string;
  register: string;
  closed_at: string | null;
  closed_by: string | null;
  difference: number;
  notes: string | null;
}

export interface InventorySummary {
  products: number;
  value: number | null;
  low: number;
  empty: number;
  in_value: number | null;
  out_value: number | null;
  adjust: number;
}

export interface MovedProduct {
  id: string;
  name: string;
  sku: string;
  sold: number;
  used: number;
  quantity: number;
  value: number | null;
}

export interface LowStockRow {
  id: string;
  name: string;
  sku: string;
  quantity: number;
  min_stock: number;
}

export interface Serie {
  key?: string;
  label: string;
  value: number;
  count?: number;
}

export interface SalesSummary {
  income: number | null;
  sales: number;
  ticket: number | null;
  discount: number | null;
  tips: number | null;
  refunds: number | null;
}

export interface AppointmentsSummary {
  appointments: number;
  completed: number;
  occupancy: number | null;
  cancelled: number;
  no_show: number;
  online: number;
  online_percent?: number;
  no_show_alert?: number | null;
  class_attendees?: number | null;
}

export interface CustomersSummary {
  avg_spend?: number;
  rebook_days?: number;
  attended: number;
  new: number;
  returning: number;
  rebooked: number;
}

export interface ProRow {
  id: string;
  name: string;
  completed: number;
  income: number | null;
  collected?: number | null;
  commissions?: number | null;
  occupancy: number | null;
  no_show: number;
  minutes: number | null;
}

export interface CatalogRow {
  id: string | null;
  name: string;
  quantity: number;
  income: number | null;
  collected?: number | null;
}

export interface MineSummary {
  completed: number;
  income: number | null;
  occupancy: number | null;
  no_show: number;
  minutes: number | null;
  customers: number;
  commissions?: number | null;
}

export interface MyCustomer {
  id: string;
  name: string;
  visits: number;
  income: number | null;
  last_at: string;
}

export interface TopCustomer {
  id: string;
  name: string;
  spent: number;
  sales: number;
  visits: number;
}

export interface ReportPayload {
  tab: ReportTab;
  tabs: { key: ReportTab; label: string; locked: boolean }[];
  periods: { value: string; label: string }[];
  branches: { id: string; name: string }[];
  branch_id: string | null;
  branch_locked: boolean;
  range: { period: string; from: string; to: string; prev_from: string; prev_to: string; days: number };
  advanced: boolean;
  money: boolean;
  own_only?: boolean;
  currency: string;
  locked: boolean;
  data: {
    summary?: SalesSummary | AppointmentsSummary | CustomersSummary | MineSummary | CashSummary | InventorySummary;
    previous?: SalesSummary | AppointmentsSummary | CustomersSummary | MineSummary | null;
    by_day?: { day: string; value: number | null }[];
    customers?: MyCustomer[];
    detail?: {
      by_day?: { day: string; value: number }[];
      by_method?: Serie[];
      by_branch?: Serie[];
      by_type?: Serie[];
      occupancy_by_day?: { day: string; value: number | null }[];
      by_source?: Serie[];
      by_channel?: Serie[] | null;
      heatmap?: { weekday: number; hour: number; value: number }[] | null;
    } | null;
    top?: TopCustomer[] | null;
    rows?: ProRow[];
    services?: CatalogRow[];
    products?: CatalogRow[] | null;
    registers?: CashRegisterRow[] | null;
    differences?: CashDifferenceRow[] | null;
    by_type?: Serie[];
    moved?: MovedProduct[];
    low?: LowStockRow[];
  } | null;
}

export const WEEKDAYS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

export const delta = (current: number | null | undefined, previous: number | null | undefined): number | null => {
  if (current === null || current === undefined || previous === null || previous === undefined) return null;
  if (previous === 0) return current === 0 ? 0 : null;
  return Math.round(((current - previous) / Math.abs(previous)) * 100);
};
