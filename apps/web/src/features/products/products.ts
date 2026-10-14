export interface Product {
  id: string;
  name: string;
  sku: string;
  price: number;
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
  kinds: { value: Product['kind']; label: string }[];
  categories: { id: string; name: string }[];
  products: Product[];
}
