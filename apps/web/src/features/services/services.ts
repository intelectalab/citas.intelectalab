export const CATEGORY_COLORS = ['#3C7DC4', '#0F9D8A', '#7C5CC4', '#D9467A', '#E0812E', '#C9A227', '#4C9A2A', '#64748B'];

export interface ServiceCategoryRow {
  id: string;
  name: string;
  color?: string | null;
  services_count?: number;
}

export const categoryColor = (color?: string | null) => color || '#94A3B8';

export const taxNote = (rules?: Record<string, unknown> | null) => {
  const included = rules?.['pos.prices_include_tax'] !== false;
  const rate = Number(rules?.['pos.tax_rate'] ?? 18);
  return included ? `IGV ${rate} % incluido` : `sin IGV: al cobrar se suma el ${rate} %`;
};
