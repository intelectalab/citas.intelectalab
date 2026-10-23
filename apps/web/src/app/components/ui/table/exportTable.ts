export type ExportType = 'text' | 'number' | 'money' | 'date' | 'datetime';

export interface ExportColumn<T> {
  header: string;
  value: (item: T) => string | number | null | undefined;
  type?: ExportType;
  width?: number;
}

export interface ExportInfo {
  title: string;
  company: string;
  user: string;
  scope: string;
  rows: number;
  filters: string[];
  notice?: string;
  currencySymbol: string;
  decimals: number;
}

const pad = (n: number) => String(n).padStart(2, '0');

const wallClock = (raw: string | number | null | undefined, withTime: boolean): Date | null => {
  if (raw === null || raw === undefined || raw === '') return null;
  const text = String(raw);
  const dateOnly = /^(\d{4})-(\d{2})-(\d{2})$/.exec(text);
  if (dateOnly) return new Date(Date.UTC(Number(dateOnly[1]), Number(dateOnly[2]) - 1, Number(dateOnly[3])));
  const local = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})$/.exec(text);
  const parsed = local ? new Date(Number(local[1]), Number(local[2]) - 1, Number(local[3]), Number(local[4]), Number(local[5])) : new Date(text);
  if (Number.isNaN(parsed.getTime())) return null;
  return new Date(Date.UTC(parsed.getFullYear(), parsed.getMonth(), parsed.getDate(), withTime ? parsed.getHours() : 0, withTime ? parsed.getMinutes() : 0));
};

const plainDate = (date: Date, withTime: boolean) =>
  `${pad(date.getUTCDate())}/${pad(date.getUTCMonth() + 1)}/${date.getUTCFullYear()}${withTime ? ` ${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}` : ''}`;

const numberOf = (raw: string | number | null | undefined): number | null => {
  if (raw === null || raw === undefined || raw === '') return null;
  const value = Number(raw);
  return Number.isFinite(value) ? value : null;
};

const moneyFormat = (symbol: string, decimals: number) => `"${symbol.replace(/"/g, '')} "#,##0${decimals > 0 ? `.${'0'.repeat(decimals)}` : ''}`;

export const exportFileName = (title: string, company: string, extension: 'xlsx' | 'csv') => {
  const now = new Date();
  const clean = (text: string) => text.replace(/[\\/:*?"<>|]+/g, ' ').replace(/\s+/g, ' ').trim();
  return `${clean(title)} - ${clean(company)} - ${pad(now.getDate())}-${pad(now.getMonth() + 1)}-${now.getFullYear()}.${extension}`;
};

const infoRows = (info: ExportInfo): [string, string][] => {
  const now = new Date();
  return [
    ['Lista', info.title],
    ['Empresa', info.company],
    ['Exportado por', info.user],
    ['Fecha', `${pad(now.getDate())}/${pad(now.getMonth() + 1)}/${now.getFullYear()} ${pad(now.getHours())}:${pad(now.getMinutes())}`],
    ['Qué se exportó', info.scope],
    ['Filas', String(info.rows)],
    ...(info.filters.length ? info.filters.map((filter, index) => [index === 0 ? 'Filtros' : '', filter] as [string, string]) : [['Filtros', 'Sin filtros: la lista completa'] as [string, string]]),
    ...(info.notice ? [['Aviso', info.notice] as [string, string]] : []),
  ];
};

export async function downloadXlsx<T>(rows: T[], columns: ExportColumn<T>[], info: ExportInfo, fileName: string) {
  const { default: writeExcelFile } = await import('write-excel-file/browser');
  const header = columns.map((column) => ({ value: column.header, fontWeight: 'bold' as const, backgroundColor: '#E2E8F0', textColor: '#022340' }));
  const money = moneyFormat(info.currencySymbol, info.decimals);

  const body = rows.map((item) => columns.map((column) => {
    const raw = column.value(item);
    switch (column.type ?? 'text') {
      case 'number': {
        const value = numberOf(raw);
        return value === null ? null : { value, type: Number };
      }
      case 'money': {
        const value = numberOf(raw);
        return value === null ? null : { value, type: Number, format: money };
      }
      case 'date':
      case 'datetime': {
        const withTime = column.type === 'datetime';
        const value = wallClock(raw, withTime);
        return value === null ? null : { value, type: Date, format: withTime ? 'dd/mm/yyyy hh:mm' : 'dd/mm/yyyy' };
      }
      default:
        return raw === null || raw === undefined || raw === '' ? null : { value: String(raw), type: String };
    }
  }));

  const widths = columns.map((column, index) => ({
    width: column.width ?? Math.min(45, Math.max(10, column.header.length + 2, ...body.slice(0, 200).map((row) => {
      const cell = row[index] as { value?: unknown } | null;
      if (!cell) return 0;
      if (cell.value instanceof Date) return column.type === 'datetime' ? 17 : 11;
      return String(cell.value).length + 2;
    }))),
  }));

  await writeExcelFile([
    { data: [header, ...body], sheet: info.title.slice(0, 31), columns: widths, stickyRowsCount: 1 },
    {
      data: infoRows(info).map(([label, value]) => [{ value: label, fontWeight: 'bold' as const }, { value }]),
      sheet: 'Filtros',
      columns: [{ width: 18 }, { width: 70 }],
    },
  ]).toFile(fileName);
}

const csvCell = (value: string) => (/[",\n\r]/.test(value) ? `"${value.replace(/"/g, '""')}"` : value);

export function downloadCsv<T>(rows: T[], columns: ExportColumn<T>[], fileName: string) {
  const lines = [columns.map((column) => csvCell(column.header)).join(',')];

  rows.forEach((item) => {
    lines.push(columns.map((column) => {
      const raw = column.value(item);
      const type = column.type ?? 'text';
      if (type === 'date' || type === 'datetime') {
        const date = wallClock(raw, type === 'datetime');
        return date ? plainDate(date, type === 'datetime') : '';
      }
      if (type === 'number' || type === 'money') {
        const value = numberOf(raw);
        return value === null ? '' : String(value);
      }
      return csvCell(raw === null || raw === undefined ? '' : String(raw));
    }).join(','));
  });

  const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = fileName;
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}
