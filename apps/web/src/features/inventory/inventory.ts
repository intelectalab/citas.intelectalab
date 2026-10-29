import type { Product } from '@/features/products/products';

export interface InventoryRules {
  allow_negative: boolean;
  consume_on_service: boolean;
  transfer_two_step: boolean;
  cost_method: 'last' | 'average' | 'none';
  alert_min_stock: boolean;
}

export interface WarehouseInfo {
  id: string;
  branch_id: string;
  branch_name: string;
  branch_code: string | null;
  name: string;
  is_main: boolean;
  status: 'active' | 'inactive';
  can_operate: boolean;
}

export interface ProductStockEntry {
  warehouse_id: string;
  branch_id: string;
  branch_name: string;
  quantity: number;
  in_transit: number;
}

export interface InventoryProduct extends Product {
  cost: number;
  min_stock: number;
  track_stock: boolean;
  stocks: Record<string, ProductStockEntry>;
  total_stock: number;
  total_in_transit: number;
  stock_value: number;
  out_of_stock: boolean;
  low_stock: boolean;
}

export type MovementType =
  | 'initial'
  | 'purchase'
  | 'sale'
  | 'service_consumption'
  | 'adjustment'
  | 'transfer_out'
  | 'transfer_in'
  | 'return';

export interface InventoryMovement {
  id: string;
  warehouse_id: string;
  branch_id: string;
  branch_name: string;
  product_id: string;
  product_name: string;
  product_sku: string;
  type: MovementType;
  type_label: string;
  quantity: number;
  unit_cost: number;
  balance_after: number;
  reference_type: string | null;
  reference_id: string | null;
  reason: string | null;
  by: string;
  created_at: string;
}

export interface TransferItem {
  id: string;
  product_id: string;
  product_name: string;
  product_sku: string;
  quantity: number;
  received_quantity: number;
}

export interface StockTransfer {
  id: string;
  code: string;
  from_warehouse_id: string;
  from_branch_id: string;
  from_branch_name: string;
  to_warehouse_id: string;
  to_branch_id: string;
  to_branch_name: string;
  status: 'draft' | 'in_transit' | 'received' | 'cancelled';
  status_label: string;
  notes: string | null;
  created_by_name: string;
  received_by_name: string | null;
  sent_at: string | null;
  received_at: string | null;
  created_at: string;
  items: TransferItem[];
  total_units: number;
}

export interface Supplier {
  id: string;
  name: string;
  document_number: string | null;
  contact_name: string | null;
  email: string | null;
  phone: string | null;
  address: string | null;
  status: 'active' | 'inactive';
  orders_count: number;
  total_bought: number;
  created_at: string;
}

export interface PurchaseOrderItem {
  id: string;
  product_id: string;
  product_name: string;
  product_sku: string;
  quantity: number;
  received_quantity: number;
  pending_quantity: number;
  unit_cost: number;
  total: number;
}

export interface PurchaseOrder {
  id: string;
  code: string;
  supplier_id: string;
  supplier_name: string;
  warehouse_id: string;
  branch_id: string;
  branch_name: string;
  status: 'draft' | 'ordered' | 'partial' | 'received' | 'cancelled';
  status_label: string;
  subtotal: number;
  tax: number;
  total: number;
  currency: string;
  expected_at: string | null;
  received_at: string | null;
  notes: string | null;
  created_by_name: string;
  created_at: string;
  items: PurchaseOrderItem[];
}

export interface InventoryBoardData {
  currency: string;
  rules: InventoryRules;
  branch_limit: string | null;
  can: {
    create: boolean;
    edit: boolean;
    delete: boolean;
    products: boolean;
    export: boolean;
  };
  warehouses: WarehouseInfo[];
  categories: { id: string; name: string }[];
  kinds: { value: Product['kind']; label: string }[];
  products: InventoryProduct[];
  movements: InventoryMovement[];
  transfers: StockTransfer[];
  suppliers: Supplier[];
  purchases: PurchaseOrder[];
}

export const MOVEMENT_TONES: Record<MovementType, string> = {
  initial: 'bg-brand-soft/20 text-brand-ink',
  purchase: 'bg-emerald-50 text-emerald-700',
  sale: 'bg-blue-50 text-blue-700',
  service_consumption: 'bg-violet-50 text-violet-700',
  adjustment: 'bg-amber-50 text-amber-700',
  transfer_out: 'bg-orange-50 text-orange-700',
  transfer_in: 'bg-teal-50 text-teal-700',
  return: 'bg-indigo-50 text-indigo-700',
};

export const TRANSFER_TONES: Record<StockTransfer['status'], string> = {
  draft: 'bg-slate-100 text-slate-600',
  in_transit: 'bg-amber-50 text-amber-700',
  received: 'bg-emerald-50 text-emerald-700',
  cancelled: 'bg-slate-100 text-slate-500',
};

export const PURCHASE_TONES: Record<PurchaseOrder['status'], string> = {
  draft: 'bg-slate-100 text-slate-600',
  ordered: 'bg-amber-50 text-amber-700',
  partial: 'bg-blue-50 text-blue-700',
  received: 'bg-emerald-50 text-emerald-700',
  cancelled: 'bg-slate-100 text-slate-500',
};

export function formatUnits(n: number): string {
  const rounded = Math.round(n * 100) / 100;
  return Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(2).replace(/\.?0+$/, '');
}
