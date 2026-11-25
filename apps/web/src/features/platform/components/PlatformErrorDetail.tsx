'use client';

import { useEffect, useState } from 'react';
import { Bug, CheckCircle2, Loader2, RotateCcw } from 'lucide-react';
import { api } from '@/lib/axios';
import BackLink from '@/app/components/common/BackLink';
import Badge from '@/app/components/common/Badge';
import SectionTitle from '@/app/components/common/SectionTitle';
import { ErrorAlert } from '@/app/components/common/Alert';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';
import { SOURCE_LABELS, type ErrorRow } from './PlatformErrors';

interface ErrorDetail extends ErrorRow {
  method: string | null;
  user_email: string | null;
  sample: { trace?: string[]; stack?: string; browser?: string; url?: string };
}

const card = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs p-5';

export default function PlatformErrorDetail({ id }: { id: string }) {
  const [row, setRow] = useState<ErrorDetail | null>(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api.get(`/admin/errors/${id}`).then((res) => setRow(res.data)).catch((err) => setError(apiError(err, 'No se pudo cargar el error.')));
  }, [id]);

  const toggle = async () => {
    if (!row) return;
    try {
      setBusy(true);
      const res = await api.put(`/admin/errors/${id}`, { resolved: !row.resolved_at });
      setRow(res.data);
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar.'));
    } finally {
      setBusy(false);
    }
  };

  const trace = row?.sample?.trace?.length ? row.sample.trace.join('\n') : row?.sample?.stack;

  return (
    <div className="space-y-5 pb-10 font-sans" data-error-detail>
      <BackLink href="/admin/errors">Errores del sistema</BackLink>
      <ErrorAlert message={error} />
      {!row ? (!error && <p className="flex items-center gap-2 text-sm text-slate-500"><Loader2 size={15} className="animate-spin" /> Cargando…</p>) : (
        <>
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0">
              <h1 className="flex items-start gap-2.5 text-xl font-black text-brand-ink tracking-tight break-words"><Bug className="w-6 h-6 text-brand-accent shrink-0 mt-0.5" /> {row.message}</h1>
              <p className="text-sm text-slate-500 mt-1">{row.kind}</p>
            </div>
            <button type="button" data-error-resolve disabled={busy} onClick={toggle} className={`inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-bold rounded-xl disabled:opacity-50 ${row.resolved_at ? 'text-slate-700 border border-slate-200 hover:bg-slate-50 bg-white' : 'bg-brand text-white hover:bg-brand-hover'}`}>
              {busy ? <Loader2 size={15} className="animate-spin" /> : row.resolved_at ? <RotateCcw size={15} /> : <CheckCircle2 size={15} />} {row.resolved_at ? 'Volver a «Por revisar»' : 'Marcar como resuelto'}
            </button>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div className={`${card} space-y-2 text-sm`}>
              <SectionTitle>Resumen</SectionTitle>
              <p><span className="text-slate-500">Dónde:</span> <Badge tone="bg-slate-100 text-slate-700" bold>{SOURCE_LABELS[row.source]}</Badge></p>
              <p><span className="text-slate-500">Veces:</span> <b>{row.occurrences.toLocaleString('es-PE')}</b></p>
              <p><span className="text-slate-500">Primera vez:</span> {momentOf(row.first_seen_at)}</p>
              <p><span className="text-slate-500">Última vez:</span> {momentOf(row.last_seen_at)}</p>
              {row.resolved_at && <p className="text-emerald-700">Resuelto {momentOf(row.resolved_at)}</p>}
            </div>
            <div className={`${card} space-y-2 text-sm`}>
              <SectionTitle>Última vez que pasó</SectionTitle>
              <p className="break-all"><span className="text-slate-500">Página:</span> {[row.method, row.url].filter(Boolean).join(' ') || '—'}</p>
              <p className="break-all"><span className="text-slate-500">Archivo:</span> {row.file ? `${row.file}:${row.line ?? ''}` : '—'}</p>
              <p><span className="text-slate-500">Empresa:</span> {row.tenant ?? '—'}</p>
              <p className="break-all"><span className="text-slate-500">Usuario:</span> {row.user_email ?? '—'}</p>
              {row.sample?.browser && <p className="break-all text-xs text-slate-500">{row.sample.browser}</p>}
            </div>
          </div>

          {trace && (
            <div className={card}>
              <SectionTitle>Recorrido del error</SectionTitle>
              <pre className="mt-2 text-xs text-slate-700 bg-slate-50 border border-slate-200 rounded-lg p-3 overflow-x-auto whitespace-pre-wrap break-all">{trace}</pre>
            </div>
          )}
        </>
      )}
    </div>
  );
}
