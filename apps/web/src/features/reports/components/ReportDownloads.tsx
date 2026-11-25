'use client';

import { useCallback, useEffect, useState } from 'react';
import { CheckCircle2, Clock, Download, FileSpreadsheet, Loader2, XCircle } from 'lucide-react';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';
import { cardClass } from './ReportParts';

export interface ReportDownload {
  id: string;
  status: 'queued' | 'running' | 'ready' | 'failed';
  label: string;
  title: string | null;
  rows: number;
  created_at: string;
  expires_at: string | null;
  expired: boolean;
  url: string | null;
  error: string | null;
}

export interface ReportDownloadFilters {
  tab: string;
  period: string;
  from?: string;
  to?: string;
  branch_id?: string | null;
}

const openFile = (url: string) => {
  const link = document.createElement('a');
  link.href = url;
  link.rel = 'noopener';
  document.body.appendChild(link);
  link.click();
  link.remove();
};

export function useReportDownloads(enabled: boolean) {
  const [items, setItems] = useState<ReportDownload[]>([]);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  const load = useCallback(() => {
    if (!enabled) return;
    api.get<{ items: ReportDownload[] }>('/reports/exports').then((res) => setItems(res.data.items ?? [])).catch(() => undefined);
  }, [enabled]);

  useEffect(() => { load(); }, [load]);

  const waiting = items.some((item) => item.status === 'queued' || item.status === 'running');

  useEffect(() => {
    if (!waiting) return;
    const timer = setInterval(load, 15000);
    return () => clearInterval(timer);
  }, [waiting, load]);

  const request = async (filters: ReportDownloadFilters) => {
    try {
      setBusy(true);
      setError('');
      setMessage('');
      const res = await api.post('/reports/export', filters);
      setItems(res.data.items ?? []);
      if (!res.data.queued && res.data.export?.url) openFile(res.data.export.url);
      if (res.data.export?.status === 'failed') setError(res.data.message);
      else setMessage(res.data.message);
    } catch (err) {
      setError(apiError(err, 'No se pudo preparar el reporte.'));
    } finally {
      setBusy(false);
    }
  };

  return { items, busy, message, error, request, clear: () => { setMessage(''); setError(''); } };
}

export function ReportDownloadButton({ busy, onClick }: { busy: boolean; onClick: () => void }) {
  return (
    <button type="button" data-report-download onClick={onClick} disabled={busy} className="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold text-slate-600 bg-white border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors disabled:opacity-50">
      {busy ? <Loader2 size={15} className="animate-spin" /> : <FileSpreadsheet size={15} />} Descargar reporte
    </button>
  );
}

export function ReportDownloads({ items }: { items: ReportDownload[] }) {
  if (items.length === 0) return null;

  return (
    <section id="descargas" data-report-downloads className={`${cardClass} p-5 sm:p-6 space-y-3`}>
      <div>
        <h2 className="text-sm font-black text-brand-ink">Tus descargas</h2>
        <p className="text-xs text-slate-500">Los reportes completos que pediste, en Excel. Los grandes se preparan por detrás y te avisamos en la campana. Cada enlace sirve 7 días.</p>
      </div>
      <ul className="divide-y divide-slate-100">
        {items.map((item) => {
          const ready = item.status === 'ready' && item.url && !item.expired;
          return (
            <li key={item.id} data-report-export={item.status} className="flex flex-col sm:flex-row sm:items-center gap-2 py-2.5">
              <span className="flex items-center gap-2 flex-1 min-w-0 text-sm">
                {item.status === 'ready' ? <CheckCircle2 size={15} className={item.expired ? 'text-slate-400 shrink-0' : 'text-emerald-600 shrink-0'} />
                  : item.status === 'failed' ? <XCircle size={15} className="text-rose-600 shrink-0" />
                    : <Clock size={15} className="text-amber-600 shrink-0" />}
                <span className="min-w-0">
                  <span className="block font-semibold text-slate-800 truncate first-letter:uppercase">{item.title ?? 'Reporte'}</span>
                  <span className="block text-xs text-slate-500">
                    Pedido el {momentOf(item.created_at)}
                    {item.status === 'ready' && !item.expired && item.expires_at ? ` · sirve hasta el ${momentOf(item.expires_at)}` : ''}
                    {item.status === 'failed' && item.error ? ` · ${item.error}` : ''}
                  </span>
                </span>
              </span>
              {ready ? (
                <a href={item.url ?? undefined} data-report-export-link className="inline-flex items-center gap-1.5 text-xs font-bold text-brand-accent hover:text-brand-ink shrink-0">
                  <Download size={14} /> Descargar
                </a>
              ) : (
                <span className="text-xs font-semibold text-slate-500 shrink-0">{item.status === 'ready' ? 'Venció' : item.label}</span>
              )}
            </li>
          );
        })}
      </ul>
    </section>
  );
}
