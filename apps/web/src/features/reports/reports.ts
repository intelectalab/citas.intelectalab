export type ReportTab = 'sales' | 'appointments' | 'customers' | 'pros' | 'catalog' | 'mine';

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
}

export interface CustomersSummary {
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
    summary?: SalesSummary | AppointmentsSummary | CustomersSummary | MineSummary;
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
      heatmap?: { weekday: number; hour: number; value: number }[] | null;
    } | null;
    top?: TopCustomer[] | null;
    rows?: ProRow[];
    services?: CatalogRow[];
    products?: CatalogRow[] | null;
  } | null;
}

export const WEEKDAYS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

export const delta = (current: number | null | undefined, previous: number | null | undefined): number | null => {
  if (current === null || current === undefined || previous === null || previous === undefined) return null;
  if (previous === 0) return current === 0 ? 0 : null;
  return Math.round(((current - previous) / Math.abs(previous)) * 100);
};
