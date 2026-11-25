export type SegmentKey = 'new' | 'frequent' | 'vip' | 'at_risk' | 'inactive' | 'no_visits' | 'birthday_month' | 'debt';

export interface CustomerInsights {
  visits: number;
  spend_12m: number | null;
  points: number | null;
  segments: SegmentKey[];
  origin: string | null;
  origin_label: string | null;
}

export interface SegmentCount {
  key: SegmentKey;
  count: number;
  hint: string | null;
}

type Pick = (word: 'client', masculine: string, feminine: string) => string;

export const segmentLabel = (key: string, pick: Pick): string => ({
  new: pick('client', 'Nuevos', 'Nuevas'),
  frequent: 'Frecuentes',
  vip: 'VIP',
  at_risk: 'En riesgo',
  inactive: pick('client', 'Inactivos', 'Inactivas'),
  no_visits: 'Sin visitas',
  birthday_month: 'Cumpleaños del mes',
  debt: 'Con saldo pendiente',
} as Record<string, string>)[key] ?? key;

export const SEGMENT_TONES: Record<string, string> = {
  new: 'bg-sky-50 text-sky-700',
  frequent: 'bg-emerald-50 text-emerald-700',
  vip: 'bg-amber-50 text-amber-800',
  at_risk: 'bg-orange-50 text-orange-700',
  inactive: 'bg-slate-100 text-slate-600',
  no_visits: 'bg-slate-100 text-slate-500',
  birthday_month: 'bg-pink-50 text-pink-700',
  debt: 'bg-rose-50 text-rose-700',
};

export const daysSince = (local?: string | null): number | null => {
  if (!local) return null;
  const [y, m, d] = local.slice(0, 10).split('-').map(Number);
  const then = new Date(y, m - 1, d).getTime();
  const now = new Date();
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
  return Math.max(0, Math.round((today - then) / 86400000));
};
