export interface Product {
  id: string;
  name: string;
  sku: string;
  barcode?: string | null;
  brand_id?: string | null;
  brand?: string | null;
  unit_id?: string | null;
  unit?: string | null;
  price: number;
  cost?: number;
  min_stock?: number;
  track_stock?: boolean;
  stock?: number;
  in_transit?: number;
  currency?: string;
  kind: 'retail' | 'supply' | 'both';
  kind_label: string;
  category_id: string | null;
  category: string | null;
  status: 'active' | 'inactive';
  sold: number;
  created_at: string;
}

export interface ProductCatalog {
  inventory_enabled?: boolean;
  kinds: { value: Product['kind']; label: string }[];
  categories: { id: string; name: string }[];
  brands?: { id: string; name: string }[];
  units?: { id: string; name: string; abbreviation: string }[];
  products: Product[];
}
