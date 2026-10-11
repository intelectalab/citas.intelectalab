// Filtro de fecha de las tablas: periodos listos (hoy, esta semana…), un día o un rango.

export type DateFilterValue =
  | { kind: 'preset'; id: string }
  | { kind: 'day'; date: string }
  | { kind: 'range'; from: string; to: string };

export interface DateRange {
  // AAAA-MM-DD, ambos incluidos.
  from: string;
  to: string;
}

export interface DatePreset {
  id: string;
  label: string;
  hint: string;
  // Periodos que miran hacia adelante: no se ofrecen para fechas que siempre son pasadas.
  future?: boolean;
  range: () => DateRange;
}

export const toISO = (date: Date): string => {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
};

export const fromISO = (iso: string): Date => {
  const [y, m, d] = iso.split('-').map(Number);
  return new Date(y, m - 1, d);
};

const shift = (days: number): string => {
  const date = new Date();
  date.setDate(date.getDate() + days);
  return toISO(date);
};

// La semana empieza el lunes.
const weekRange = (offset: number): DateRange => {
  const start = new Date();
  start.setDate(start.getDate() - ((start.getDay() + 6) % 7) + offset * 7);
  const end = new Date(start);
  end.setDate(start.getDate() + 6);
  return { from: toISO(start), to: toISO(end) };
};

const monthRange = (offset: number): DateRange => {
  const now = new Date();
  return {
    from: toISO(new Date(now.getFullYear(), now.getMonth() + offset, 1)),
    to: toISO(new Date(now.getFullYear(), now.getMonth() + offset + 1, 0)),
  };
};

export const DATE_PRESETS: DatePreset[] = [
  { id: 'today', label: 'Hoy', hint: 'Todo lo de hoy', range: () => ({ from: shift(0), to: shift(0) }) },
  { id: 'yesterday', label: 'Ayer', hint: 'El día anterior', range: () => ({ from: shift(-1), to: shift(-1) }) },
  { id: 'tomorrow', label: 'Mañana', hint: 'El día siguiente', future: true, range: () => ({ from: shift(1), to: shift(1) }) },
  { id: 'this_week', label: 'Esta semana', hint: 'De lunes a domingo', range: () => weekRange(0) },
  { id: 'last_week', label: 'Semana pasada', hint: 'De lunes a domingo de la semana anterior', range: () => weekRange(-1) },
  { id: 'next_week', label: 'Próxima semana', hint: 'De lunes a domingo de la semana que viene', future: true, range: () => weekRange(1) },
  { id: 'last_7', label: 'Últimos 7 días', hint: 'Hoy y los seis días anteriores', range: () => ({ from: shift(-6), to: shift(0) }) },
  { id: 'next_7', label: 'Próximos 7 días', hint: 'Hoy y los seis días siguientes', future: true, range: () => ({ from: shift(0), to: shift(6) }) },
  { id: 'this_month', label: 'Este mes', hint: 'Del primer al último día del mes', range: () => monthRange(0) },
  { id: 'last_month', label: 'Mes pasado', hint: 'Todo el mes anterior', range: () => monthRange(-1) },
  { id: 'next_month', label: 'Próximo mes', hint: 'Todo el mes que viene', future: true, range: () => monthRange(1) },
  { id: 'last_30', label: 'Últimos 30 días', hint: 'Hoy y los 29 días anteriores', range: () => ({ from: shift(-29), to: shift(0) }) },
  {
    id: 'this_year',
    label: 'Este año',
    hint: 'Del 1 de enero al 31 de diciembre',
    range: () => ({ from: `${new Date().getFullYear()}-01-01`, to: `${new Date().getFullYear()}-12-31` }),
  },
];

export const dateRangeOf = (value: DateFilterValue | null | undefined): DateRange | null => {
  if (!value) return null;
  if (value.kind === 'day') return { from: value.date, to: value.date };
  if (value.kind === 'range') return { from: value.from, to: value.to };
  return DATE_PRESETS.find((preset) => preset.id === value.id)?.range() ?? null;
};

export const formatDay = (iso: string): string =>
  fromISO(iso).toLocaleDateString('es-PE', { day: 'numeric', month: 'short', year: 'numeric' }).replace(/\./g, '');

export const describeDateFilter = (value: DateFilterValue | null | undefined): string => {
  if (!value) return 'Todas';
  if (value.kind === 'day') return formatDay(value.date);
  if (value.kind === 'range') return `${formatDay(value.from)} – ${formatDay(value.to)}`;
  return DATE_PRESETS.find((preset) => preset.id === value.id)?.label ?? 'Todas';
};

/**
 * Día (AAAA-MM-DD) de un valor de la API. Las horas que ya vienen en hora local
 * ("2026-10-19T09:00") se toman tal cual; las que traen zona se pasan a la del navegador.
 */
export const dayOf = (raw: string | null | undefined): string | null => {
  if (!raw) return null;
  if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw;
  if (/(Z|[+-]\d{2}:?\d{2})$/.test(raw)) {
    const date = new Date(raw);
    return Number.isNaN(date.getTime()) ? null : toISO(date);
  }
  return /^\d{4}-\d{2}-\d{2}/.test(raw) ? raw.slice(0, 10) : null;
};

// Un registro entra si su día (o su tramo, cuando dura varios días) toca el rango.
export const inDateRange = (range: DateRange, start: string | null | undefined, end?: string | null): boolean => {
  const first = dayOf(start);
  if (!first) return false;
  const last = dayOf(end) ?? first;
  return first <= range.to && last >= range.from;
};
