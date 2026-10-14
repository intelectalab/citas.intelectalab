export interface CurrencyInfo {
  code: string;
  name: string;
  symbol: string;
  decimals: number;
  is_active?: boolean;
}

let catalog: CurrencyInfo[] = [{ code: 'PEN', name: 'Sol peruano', symbol: 'S/', decimals: 2 }];
let current = 'PEN';

export const setCurrencies = (list?: CurrencyInfo[] | null, code?: string | null) => {
  if (list && list.length) catalog = list;
  if (code) current = code;
};

export const currentCurrency = () => current;

export const currencyOf = (code?: string | null): CurrencyInfo =>
  catalog.find((c) => c.code === (code || current)) ?? catalog.find((c) => c.code === current) ?? catalog[0];

export const currencySymbol = (code?: string | null) => currencyOf(code).symbol;

export const formatMoney = (value: number | string | null | undefined, code?: string | null, decimals?: number): string => {
  const currency = currencyOf(code);
  const digits = decimals ?? currency.decimals;

  return `${currency.symbol} ${Number(value ?? 0).toLocaleString('es-PE', { minimumFractionDigits: digits, maximumFractionDigits: digits })}`;
};
