export type CommissionStatus = 'calculated' | 'approved' | 'paid';

export interface CommissionEntry {
  id: string;
  earned_at: string;
  professional: { id: string; name: string };
  branch_name: string | null;
  source: 'sale' | 'package_use' | 'refund';
  source_label: string;
  kind: 'service' | 'product';
  sale: { id: string; code: string } | null;
  description: string;
  base: number;
  percent: number;
  fixed: number;
  rule_name: string | null;
  amount: number;
  currency: string;
  status: CommissionStatus;
  status_label: string;
  approved_at: string | null;
  paid_at: string | null;
}

export interface CommissionByProfessional {
  id: string;
  name: string;
  calculated: number;
  approved: number;
  paid: number;
  total: number;
  count: number;
}

export interface CommissionSettings {
  base: 'net' | 'gross';
  on_products: boolean;
  on_package_use: boolean;
  auto_approve: boolean;
}

export interface CommissionsPayload {
  period: string;
  periods: { value: string; label: string }[];
  currency: string;
  scope: 'tenant' | 'branch' | 'own';
  rules: CommissionSettings;
  products: boolean;
  can: { rules: boolean; approve: boolean; pay: boolean };
  summary: {
    calculated: number;
    approved: number;
    paid: number;
    total: number;
    calculated_count: number;
    approved_count: number;
    paid_count: number;
    professionals: number;
  };
  by_professional: CommissionByProfessional[];
  entries: CommissionEntry[];
}

export type RuleType = 'service' | 'exception' | 'product';

export interface CommissionRule {
  id: string;
  type: RuleType;
  professional_id: string | null;
  professional: string | null;
  service_id: string | null;
  service: string | null;
  product_id: string | null;
  product: string | null;
  percent: number;
  fixed: number;
  active: boolean;
}

export interface RulesPayload {
  rules: CommissionRule[];
  can_edit: boolean;
  products: boolean;
  base: 'net' | 'gross';
  on_products: boolean;
  professionals: { id: string; name: string; commission_percent: string | number | null }[];
  services: { id: string; name: string; price: string | number }[];
  product_list: { id: string; name: string; price: string | number }[];
}

export interface RulePreview {
  found: boolean;
  source?: 'exception' | 'service' | 'own' | 'product' | 'products';
  label?: string;
  amount?: number;
  message: string;
}

export const STATUS_TONES: Record<CommissionStatus, string> = {
  calculated: 'bg-sky-50 text-sky-700',
  approved: 'bg-amber-50 text-amber-700',
  paid: 'bg-emerald-50 text-emerald-700',
};

export const percentText = (value: number) => `${Number(value).toLocaleString('es-PE', { maximumFractionDigits: 2 })} %`;
