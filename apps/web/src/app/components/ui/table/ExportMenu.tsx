'use client';

import { useRef, useState } from 'react';
import { Download, FileSpreadsheet, FileText, Loader2 } from 'lucide-react';
import Floating from '@/app/components/common/Floating';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { usePlatform } from '@/hooks/usePlatform';
import { apiError } from '@/features/appointments/appointments';
import { isLight, resolveColors } from '@/lib/brand';
import { downloadCsv, downloadXlsx, exportFileName, type ExportColumn } from './exportTable';
import styles from './DataTable.module.css';

interface ExportMenuProps<T> {
  title: string;
  screen: string;
  columns: ExportColumn<T>[];
  filtered: T[];
  selected: T[];
  filters: string[];
  notice?: string;
}

type Amount = 'all' | 'selected' | number;

const LIMITS = [50, 100, 500];

const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`;

const storageKey = (screen: string) => `export-columns:${screen}`;

const readHidden = (screen: string): string[] => {
  try {
    const saved = JSON.parse(localStorage.getItem(storageKey(screen)) ?? '[]');
    return Array.isArray(saved) ? saved.filter((item): item is string => typeof item === 'string') : [];
  } catch {
    return [];
  }
};

const saveHidden = (screen: string, hidden: string[]) => {
  try {
    if (hidden.length) localStorage.setItem(storageKey(screen), JSON.stringify(hidden));
    else localStorage.removeItem(storageKey(screen));
  } catch {
  }
};

export default function ExportMenu<T>({ title, screen, columns, filtered, selected, filters, notice }: ExportMenuProps<T>) {
  const { user } = useAuth();
  const { platform } = usePlatform();
  const [open, setOpen] = useState(false);
  const [amount, setAmount] = useState<Amount>('all');
  const [hidden, setHidden] = useState<string[]>([]);
  const [busy, setBusy] = useState<'' | 'xlsx' | 'csv'>('');
  const [error, setError] = useState('');
  const buttonRef = useRef<HTMLButtonElement>(null);

  const isPlatform = !user?.tenant && Boolean(user?.is_saas_admin);
  const limits = LIMITS.filter((limit) => limit < filtered.length);
  const chosen: Amount = amount === 'selected' && selected.length === 0 ? 'all' : typeof amount === 'number' && !limits.includes(amount) ? 'all' : amount;
  const rows = chosen === 'selected' ? selected : typeof chosen === 'number' ? filtered.slice(0, chosen) : filtered;
  const shown = columns.filter((column) => !hidden.includes(column.header));
  const company = isPlatform ? platform.product_name : user?.tenant?.name ?? 'Empresa';
  const color = resolveColors(isPlatform ? platform.look.brand : user?.tenant?.branding).primary;

  const toggle = () => {
    if (!open) {
      setAmount(selected.length > 0 ? 'selected' : 'all');
      setHidden(readHidden(screen).filter((header) => columns.some((column) => column.header === header)));
      setError('');
    }
    setOpen(!open);
  };

  const changeHidden = (next: string[]) => {
    setHidden(next);
    saveHidden(screen, next);
    setError('');
  };

  const run = async (format: 'xlsx' | 'csv') => {
    if (rows.length === 0) {
      setError('No hay filas para exportar con estos filtros.');
      return;
    }
    if (shown.length === 0) {
      setError('Marca al menos una columna.');
      return;
    }
    const scopeText = chosen === 'selected'
      ? `Solo las filas seleccionadas (${rows.length})`
      : typeof chosen === 'number'
        ? `Las primeras ${rows.length} de ${filtered.length}, en el orden de la lista`
        : `Todo lo que muestra la lista con sus filtros (${rows.length})`;
    const columnsNote = hidden.length ? `${shown.length} de ${columns.length}: ${shown.map((column) => column.header).join(', ')}` : undefined;
    try {
      setBusy(format);
      setError('');
      await api.post(isPlatform ? '/admin/data-exports' : '/data-exports', {
        screen,
        title,
        format,
        scope: chosen === 'selected' ? 'selected' : 'filtered',
        rows: rows.length,
        limit: typeof chosen === 'number' ? chosen : null,
        columns: hidden.length ? shown.map((column) => column.header) : [],
        filters: [...filters, ...(notice ? [notice] : [])],
      });
      const fileName = exportFileName(title, company, format);
      if (format === 'csv') {
        downloadCsv(rows, shown, fileName);
      } else {
        await downloadXlsx(rows, shown, {
          title,
          company,
          user: user ? `${user.first_name ?? ''} ${user.last_name ?? ''}`.trim() || user.email : '',
          scope: scopeText,
          rows: rows.length,
          filters,
          notice,
          currencySymbol: user?.tenant?.currency?.symbol ?? 'S/',
          decimals: user?.tenant?.currency?.decimals ?? 2,
          color,
          headerText: isLight(color) ? '#0F172A' : '#FFFFFF',
          columnsNote,
        }, fileName);
      }
      setOpen(false);
    } catch (err) {
      setError(apiError(err, 'No se pudo exportar. Inténtalo de nuevo.'));
    } finally {
      setBusy('');
    }
  };

  const amounts: [Amount, string, boolean][] = [
    ['all', `Todas (${filtered.length})`, false],
    ...limits.map((limit) => [limit, `Primeras ${limit}`, false] as [Amount, string, boolean]),
    ['selected', `Seleccionadas (${selected.length})`, selected.length === 0],
  ];

  return (
    <>
      <button ref={buttonRef} type="button" className={styles.exportBtn} data-export-toggle aria-expanded={open} onClick={toggle}>
        <Download size={14} />
        <span className={`${styles.btnText} ${styles.btnTextCollapse}`}>Exportar</span>
      </button>

      <Floating
        anchorRef={buttonRef}
        open={open}
        onClose={() => setOpen(false)}
        align="right"
        role="dialog"
        className="w-80 max-w-[calc(100vw-1rem)] rounded-2xl border border-slate-200 bg-white shadow-xl overflow-y-auto select-scroll animate-in fade-in duration-150"
      >
        <div className="p-4 space-y-3" data-export-menu>
          <div>
            <span className="block text-sm font-bold text-brand-ink">Exportar {title.toLowerCase()}</span>
            <span className="block text-xs text-slate-500 mt-0.5">Con los filtros que tienes puestos y en el orden de la lista.</span>
          </div>

          <div className="space-y-1.5">
            <span className="block text-xs font-semibold text-slate-600">Cuántas filas</span>
            <div className="flex flex-wrap gap-1.5" role="radiogroup" aria-label="Cuántas filas">
              {amounts.map(([value, label, disabled]) => (
                <button key={String(value)} type="button" role="radio" aria-checked={chosen === value} data-export-amount={value} disabled={disabled} onClick={() => setAmount(value)}
                  className={`rounded-lg border px-2.5 py-1.5 text-xs transition-colors disabled:opacity-50 disabled:cursor-not-allowed ${chosen === value ? 'border-brand bg-brand-soft/15 text-brand-ink font-semibold' : 'border-slate-200 text-slate-600 hover:bg-slate-50'}`}>
                  {label}
                </button>
              ))}
            </div>
            {typeof chosen === 'number' && <span className="block text-xs text-slate-400" data-export-amount-hint>Las primeras {chosen} según el orden de la lista. Para tener las más recientes, ordena por fecha antes de exportar.</span>}
            {selected.length === 0 && <span className="block text-xs text-slate-400">Marca filas con las casillas para exportar solo esas.</span>}
          </div>

          <div className="space-y-1.5">
            <div className="flex items-center justify-between gap-2">
              <span className="text-xs font-semibold text-slate-600">Columnas ({shown.length} de {columns.length})</span>
              <span className="flex gap-2 text-xs font-semibold">
                <button type="button" data-export-columns-all onClick={() => changeHidden([])} className="text-brand-accent hover:text-brand-ink">Todas</button>
                <button type="button" data-export-columns-none onClick={() => changeHidden(columns.map((column) => column.header))} className="text-brand-accent hover:text-brand-ink">Ninguna</button>
              </span>
            </div>
            <div className="max-h-44 overflow-y-auto rounded-xl border border-slate-200 px-3 py-1.5" data-export-columns>
              {columns.map((column) => (
                <label key={column.header} className="flex items-center gap-2 py-1 text-xs text-slate-700 cursor-pointer">
                  <input type="checkbox" data-export-column={column.header} checked={!hidden.includes(column.header)}
                    onChange={(e) => changeHidden(e.target.checked ? hidden.filter((header) => header !== column.header) : [...hidden, column.header])}
                    className="rounded border-slate-300 accent-brand cursor-pointer" />
                  {column.header}
                </label>
              ))}
            </div>
            <span className="block text-[11px] text-slate-400">Se recuerdan para la próxima vez en este equipo.</span>
          </div>

          {notice && <p className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2" data-export-notice>{notice}</p>}

          <div className="grid grid-cols-2 gap-2">
            <button type="button" data-export-format="xlsx" disabled={busy !== ''} onClick={() => run('xlsx')}
              className="flex items-center justify-center gap-1.5 rounded-xl bg-brand px-3 py-2.5 text-sm font-bold text-white hover:bg-brand-hover disabled:opacity-60">
              {busy === 'xlsx' ? <Loader2 size={15} className="animate-spin" /> : <FileSpreadsheet size={15} />} Excel
            </button>
            <button type="button" data-export-format="csv" disabled={busy !== ''} onClick={() => run('csv')}
              className="flex items-center justify-center gap-1.5 rounded-xl border border-brand px-3 py-2.5 text-sm font-bold text-brand-ink hover:bg-brand-soft/15 disabled:opacity-60">
              {busy === 'csv' ? <Loader2 size={15} className="animate-spin" /> : <FileText size={15} />} CSV
            </button>
          </div>

          {error && <p role="alert" className="text-xs text-red-600">{error}</p>}
          <p className="text-[11px] text-slate-400">{plural(rows.length, 'fila', 'filas')} · La descarga queda registrada con tu nombre.</p>
        </div>
      </Floating>
    </>
  );
}
