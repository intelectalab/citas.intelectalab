'use client';

import { useEffect, useRef, useState } from 'react';
import { Download, FileSpreadsheet, Loader2, Upload } from 'lucide-react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Badge from '@/app/components/common/Badge';
import Button from '@/app/components/common/Button';
import OptionCard from '@/app/components/common/OptionCard';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { PagedList } from '@/app/components/common/Pager';
import { api } from '@/lib/axios';
import { formatPhone } from '@/lib/phone';
import { apiError } from '@/features/appointments/appointments';

interface PreviewRow {
  row: number;
  name: string;
  document: string | null;
  phone: string | null;
  email: string | null;
  action: 'create' | 'update' | 'skip' | 'error';
  errors: string[];
  match: { id: string; name: string; code: string; by: string } | null;
}

interface Preview {
  rows: PreviewRow[];
  columns: string[];
  missing_columns: string[];
  counts: { create: number; update: number; skip: number; error: number };
}

const ACTIONS: Record<PreviewRow['action'], { label: string; tone: string }> = {
  create: { label: 'Nuevo', tone: 'bg-emerald-50 text-emerald-700' },
  update: { label: 'Se actualiza', tone: 'bg-sky-50 text-sky-700' },
  skip: { label: 'Se salta', tone: 'bg-slate-100 text-slate-600' },
  error: { label: 'Con error', tone: 'bg-rose-50 text-rose-700' },
};

interface Props {
  isOpen: boolean;
  onClose: () => void;
  onImported: () => void;
  clientsWord: string;
}

export default function CustomerImport({ isOpen, onClose, onImported, clientsWord }: Props) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [mode, setMode] = useState<'skip' | 'update'>('skip');
  const [preview, setPreview] = useState<Preview | null>(null);
  const [filter, setFilter] = useState<PreviewRow['action'] | ''>('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [done, setDone] = useState('');
  const clients = clientsWord.toLowerCase();

  useEffect(() => {
    if (!isOpen) return;
    setFile(null);
    setPreview(null);
    setFilter('');
    setError('');
    setDone('');
    setMode('skip');
  }, [isOpen]);

  const send = (url: string, chosen: File, chosenMode: 'skip' | 'update') => {
    const data = new FormData();
    data.append('file', chosen);
    data.append('mode', chosenMode);
    return api.post(url, data, { headers: { 'Content-Type': 'multipart/form-data' } });
  };

  const analyze = (chosen: File | null, chosenMode: 'skip' | 'update') => {
    if (!chosen) return;
    setBusy(true);
    setError('');
    setDone('');
    send('/customers/import/preview', chosen, chosenMode)
      .then((res) => setPreview(res.data))
      .catch((err) => { setPreview(null); setError(apiError(err, 'No se pudo leer el archivo.')); })
      .finally(() => setBusy(false));
  };

  const pick = (chosen: File | null) => {
    setFile(chosen);
    analyze(chosen, mode);
  };

  const changeMode = (next: 'skip' | 'update') => {
    setMode(next);
    analyze(file, next);
  };

  const downloadTemplate = async () => {
    try {
      const res = await api.get('/customers/import/template');
      const { default: writeExcelFile } = await import('write-excel-file/browser');
      const columns = res.data.columns as { header: string; example: string }[];
      const sheets = [{
        sheet: clientsWord,
        columns: columns.map(() => ({ width: 22 })),
        data: [
          columns.map((c) => ({ value: c.header, fontWeight: 'bold' as const })),
          columns.map((c) => (c.example ? { value: c.example, type: String } : null)),
        ],
      }];
      await writeExcelFile(sheets as never).toFile(`Plantilla de ${clients}.xlsx`);
    } catch (err) {
      setError(apiError(err, 'No se pudo descargar la plantilla.'));
    }
  };

  const run = () => {
    if (!file) return;
    setBusy(true);
    setError('');
    send('/customers/import', file, mode)
      .then((res) => { setDone(res.data.message); setPreview(null); setFile(null); onImported(); })
      .catch((err) => setError(apiError(err, 'No se pudo importar.')))
      .finally(() => setBusy(false));
  };

  const rows = preview ? preview.rows.filter((r) => !filter || r.action === filter) : [];
  const ready = preview ? preview.counts.create + preview.counts.update : 0;

  return (
    <FormDrawer isOpen={isOpen} onClose={onClose} title={`Importar ${clients}`} subtitle="Desde un Excel o un CSV. Primero ves cómo quedará y luego confirmas." icon={<FileSpreadsheet size={20} />} maxWidth="max-w-2xl">
      <div className="flex flex-col h-full">
        <div className="flex-1 overflow-y-auto px-1 pb-28 space-y-5">
          <section className="space-y-2">
            <p className="text-sm text-slate-600">1. Descarga la plantilla, copia en ella tus {clients} (uno por fila) y guárdala.</p>
            <button type="button" data-action="download-template" onClick={downloadTemplate} className="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-accent hover:text-brand-ink">
              <Download size={15} /> Descargar plantilla de Excel
            </button>
          </section>

          <section className="space-y-2">
            <p className="text-sm text-slate-600">2. Elige el archivo.</p>
            <input ref={inputRef} type="file" accept=".xlsx,.csv,.txt" className="hidden" data-field="import-file" onChange={(e) => pick(e.target.files?.[0] ?? null)} />
            <button type="button" data-action="choose-file" onClick={() => inputRef.current?.click()} className="w-full flex items-center justify-center gap-2 px-4 py-6 rounded-xl border-2 border-dashed border-slate-200 text-sm font-semibold text-slate-600 hover:border-brand-accent hover:text-brand-ink transition-colors">
              {busy ? <Loader2 size={18} className="animate-spin" /> : <Upload size={18} />}
              {file ? file.name : 'Elegir archivo (.xlsx o .csv)'}
            </button>
          </section>

          <section className="space-y-2">
            <p className="text-sm text-slate-600">3. Si alguien ya está registrado (mismo documento o mismo celular):</p>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
              {([['skip', 'Saltarlo', 'Se deja como está.'], ['update', 'Actualizar sus datos', 'Se completan o cambian con lo del archivo. Lo vacío no borra nada.']] as const).map(([value, title, text]) => (
                <OptionCard key={value} selected={mode === value} onClick={() => changeMode(value)} className="text-left p-3 rounded-xl border" data-mode={value}>
                  <span className="block text-sm font-bold text-brand-ink">{title}</span>
                  <span className="block text-xs text-slate-500 mt-0.5">{text}</span>
                </OptionCard>
              ))}
            </div>
          </section>

          <ErrorAlert message={error} />
          <SuccessAlert message={done} />

          {preview && (
            <section className="space-y-3" data-import-preview>
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                {(Object.keys(ACTIONS) as PreviewRow['action'][]).map((action) => (
                  <button
                    key={action}
                    type="button"
                    data-count={action}
                    aria-pressed={filter === action}
                    onClick={() => setFilter((current) => (current === action ? '' : action))}
                    className={`px-3 py-2 rounded-xl border text-left transition-colors ${filter === action ? 'border-brand-accent ring-2 ring-brand-accent/20' : 'border-slate-200 hover:border-slate-300'}`}
                  >
                    <span className="block text-[10px] font-semibold tracking-wider text-slate-400 uppercase">{ACTIONS[action].label}</span>
                    <span className="block text-xl font-extrabold text-slate-900">{preview.counts[action]}</span>
                  </button>
                ))}
              </div>
              {preview.missing_columns.length > 0 && (
                <p className="text-xs text-slate-500">Columnas que no vienen en tu archivo (se dejan vacías): {preview.missing_columns.join(', ')}.</p>
              )}
              <div className="rounded-xl border border-slate-200 overflow-hidden">
                <PagedList
                  key={filter}
                  items={rows}
                  empty="No hay filas en este grupo."
                  renderRow={(r) => (
                    <li key={r.row} data-import-row={r.row} data-action-type={r.action} className="px-4 py-2.5 flex items-start gap-3">
                      <span className="w-12 shrink-0 text-xs font-mono text-slate-400 pt-0.5">Fila {r.row}</span>
                      <div className="flex-1 min-w-0">
                        <span className="block text-sm font-semibold text-slate-800 truncate">{r.name || 'Sin nombre'}</span>
                        <span className="block text-xs text-slate-500 truncate">{[r.document, formatPhone(r.phone), r.email].filter(Boolean).join(' · ') || 'Sin documento ni celular'}</span>
                        {r.match && r.action !== 'error' && <span className="block text-xs text-slate-500">Ya existe por su {r.match.by}: {r.match.name} ({r.match.code})</span>}
                        {r.errors.map((e) => <span key={e} className="block text-xs font-semibold text-rose-700">{e}</span>)}
                      </div>
                      <Badge tone={ACTIONS[r.action].tone} nowrap>{ACTIONS[r.action].label}</Badge>
                    </li>
                  )}
                />
              </div>
              {preview.counts.error > 0 && <p className="text-xs text-slate-500">Las filas con error no se importan. Corrígelas en tu archivo y vuelve a elegirlo, o importa ahora las demás.</p>}
            </section>
          )}
        </div>

        <div className="absolute bottom-0 left-0 right-0 bg-white border-t border-slate-200 p-4 flex items-center justify-end gap-3 z-10">
          <Button variant="secondary" type="button" onClick={onClose}>Cerrar</Button>
          <Button variant="classic" type="button" data-action="run-import" onClick={run} disabled={busy || !preview || ready === 0}>
            {busy ? 'Procesando...' : ready ? `Importar ${ready}` : 'Importar'}
          </Button>
        </div>
      </div>
    </FormDrawer>
  );
}
