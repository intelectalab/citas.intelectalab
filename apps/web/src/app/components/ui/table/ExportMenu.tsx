'use client';

import { useEffect, useRef, useState } from 'react';
import { Download, FileSpreadsheet, FileText, Loader2 } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { apiError } from '@/features/appointments/appointments';
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

const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`;

export default function ExportMenu<T>({ title, screen, columns, filtered, selected, filters, notice }: ExportMenuProps<T>) {
  const { user } = useAuth();
  const [open, setOpen] = useState(false);
  const [scope, setScope] = useState<'filtered' | 'selected'>('filtered');
  const [busy, setBusy] = useState<'' | 'xlsx' | 'csv'>('');
  const [error, setError] = useState('');
  const box = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const close = (e: MouseEvent) => { if (box.current && !box.current.contains(e.target as Node)) setOpen(false); };
    const escape = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('mousedown', close);
    document.addEventListener('keydown', escape);
    return () => { document.removeEventListener('mousedown', close); document.removeEventListener('keydown', escape); };
  }, [open]);

  const chosen = scope === 'selected' && selected.length > 0 ? 'selected' : 'filtered';
  const rows = chosen === 'selected' ? selected : filtered;
  const company = user?.tenant?.name ?? 'Empresa';

  const run = async (format: 'xlsx' | 'csv') => {
    if (rows.length === 0) {
      setError('No hay filas para exportar con estos filtros.');
      return;
    }
    try {
      setBusy(format);
      setError('');
      await api.post('/data-exports', { screen, title, format, scope: chosen, rows: rows.length, filters: [...filters, ...(notice ? [notice] : [])] });
      const fileName = exportFileName(title, company, format);
      if (format === 'csv') {
        downloadCsv(rows, columns, fileName);
      } else {
        await downloadXlsx(rows, columns, {
          title,
          company,
          user: user ? `${user.first_name ?? ''} ${user.last_name ?? ''}`.trim() || user.email : '',
          scope: chosen === 'selected' ? `Solo las filas seleccionadas (${rows.length})` : `Todo lo que muestra la lista con sus filtros (${rows.length})`,
          rows: rows.length,
          filters,
          notice,
          currencySymbol: user?.tenant?.currency?.symbol ?? 'S/',
          decimals: user?.tenant?.currency?.decimals ?? 2,
        }, fileName);
      }
      setOpen(false);
    } catch (err) {
      setError(apiError(err, 'No se pudo exportar. Inténtalo de nuevo.'));
    } finally {
      setBusy('');
    }
  };

  return (
    <div className="relative" ref={box}>
      <button type="button" className={styles.exportBtn} data-export-toggle aria-expanded={open} onClick={() => { setOpen(!open); setError(''); setScope(selected.length > 0 ? 'selected' : 'filtered'); }}>
        <Download size={14} />
        <span className={`${styles.btnText} ${styles.btnTextCollapse}`}>Exportar</span>
      </button>

      {open && (
        <div className="absolute right-0 top-full mt-2 z-30 w-80 rounded-2xl border border-slate-200 bg-white p-4 shadow-xl space-y-3" data-export-menu>
          <div>
            <span className="block text-sm font-bold text-[#022340]">Exportar {title.toLowerCase()}</span>
            <span className="block text-xs text-slate-500 mt-0.5">Con las columnas de la tabla y los filtros que tienes puestos.</span>
          </div>

          <div className="space-y-1.5" role="radiogroup" aria-label="Qué exportar">
            {([
              ['filtered', `Todo lo filtrado (${plural(filtered.length, 'fila', 'filas')})`, false],
              ['selected', `Solo los seleccionados (${selected.length})`, selected.length === 0],
            ] as ['filtered' | 'selected', string, boolean][]).map(([value, label, disabled]) => (
              <button key={value} type="button" role="radio" aria-checked={chosen === value} data-export-scope={value} disabled={disabled} onClick={() => setScope(value)}
                className={`w-full text-left rounded-xl border px-3 py-2 text-sm transition-colors disabled:opacity-50 disabled:cursor-not-allowed ${chosen === value ? 'border-[#022340] bg-[#84D0D9]/15 text-[#022340] font-semibold' : 'border-slate-200 text-slate-600 hover:bg-slate-50'}`}>
                {label}
              </button>
            ))}
            {selected.length === 0 && <span className="block text-xs text-slate-400">Marca filas con las casillas para exportar solo esas.</span>}
          </div>

          {notice && <p className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2" data-export-notice>{notice}</p>}

          <div className="grid grid-cols-2 gap-2">
            <button type="button" data-export-format="xlsx" disabled={busy !== ''} onClick={() => run('xlsx')}
              className="flex items-center justify-center gap-1.5 rounded-xl bg-[#022340] px-3 py-2.5 text-sm font-bold text-white hover:bg-[#03315a] disabled:opacity-60">
              {busy === 'xlsx' ? <Loader2 size={15} className="animate-spin" /> : <FileSpreadsheet size={15} />} Excel
            </button>
            <button type="button" data-export-format="csv" disabled={busy !== ''} onClick={() => run('csv')}
              className="flex items-center justify-center gap-1.5 rounded-xl border border-[#022340] px-3 py-2.5 text-sm font-bold text-[#022340] hover:bg-[#84D0D9]/15 disabled:opacity-60">
              {busy === 'csv' ? <Loader2 size={15} className="animate-spin" /> : <FileText size={15} />} CSV
            </button>
          </div>

          {error && <p role="alert" className="text-xs text-red-600">{error}</p>}
          <p className="text-[11px] text-slate-400">La descarga queda registrada con tu nombre.</p>
        </div>
      )}
    </div>
  );
}
