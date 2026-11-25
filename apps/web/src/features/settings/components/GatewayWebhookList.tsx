'use client';

import Badge from '@/app/components/common/Badge';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { PagedList } from '@/app/components/common/Pager';
import { useCallback, useEffect, useState } from 'react';
import { RefreshCw } from 'lucide-react';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';

export interface GatewayWebhook {
  id: string;
  provider: string;
  provider_label: string;
  topic: string | null;
  reference: string | null;
  status: 'received' | 'processed' | 'ignored' | 'duplicate' | 'failed' | 'gave_up' | 'rejected';
  status_label: string;
  message: string | null;
  attempts: number;
  repeats: number;
  can_retry: boolean;
  next_retry_at: string | null;
  at: string;
}

const TONES: Record<GatewayWebhook['status'], string> = {
  received: 'bg-slate-100 text-slate-600',
  processed: 'bg-emerald-50 text-emerald-700',
  ignored: 'bg-slate-100 text-slate-600',
  duplicate: 'bg-slate-100 text-slate-600',
  failed: 'bg-amber-50 text-amber-800',
  gave_up: 'bg-rose-50 text-rose-700',
  rejected: 'bg-rose-50 text-rose-700',
};

export default function GatewayWebhookList({ endpoint, canRetry, hint }: { endpoint: string; canRetry: boolean; hint: string }) {
  const [rows, setRows] = useState<GatewayWebhook[] | null>(null);
  const [ready, setReady] = useState(true);
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const load = useCallback(() => {
    api.get(endpoint)
      .then((res) => { setRows(res.data.webhooks); setReady(Boolean(res.data.ready)); })
      .catch(() => setRows(null));
  }, [endpoint]);

  useEffect(() => { load(); }, [load]);

  if (!rows || !ready) return null;

  const retry = async (id: string) => {
    try {
      setBusy(id);
      setError('');
      setNotice('');
      const res = await api.post(`${endpoint}/${id}/retry`);
      setRows(res.data.webhooks);
      setNotice(res.data.message);
    } catch (err) {
      setError(apiError(err, 'No se pudo reintentar el aviso.'));
    } finally {
      setBusy('');
    }
  };

  return (
    <div className="space-y-2" data-gateway-webhooks>
      <span className="block text-[11px] font-bold text-slate-600 uppercase tracking-wider">Avisos que mandan las pasarelas</span>
      <p className="text-xs text-slate-500">{hint}</p>
      <div className="rounded-xl border border-slate-200 overflow-hidden flex flex-col">
        <PagedList<GatewayWebhook>
          items={rows}
          empty="Todavía no llegó ningún aviso. Aparecen aquí cuando Culqi, Mercado Pago o Izipay avisan de un pago."
          renderRow={(w) => (
            <li key={w.id} className="flex flex-col sm:flex-row sm:items-start gap-2 sm:gap-3 px-5 py-3" data-webhook-status={w.status}>
              <Badge tone={TONES[w.status] ?? TONES.received} bold nowrap className="self-start shrink-0">{w.status_label}</Badge>
              <span className="flex-1 min-w-0">
                <span className="block text-sm font-semibold text-slate-800">{w.provider_label}{w.topic ? ` · ${w.topic}` : ''}{w.reference ? ` · ${w.reference}` : ''}</span>
                <span className="block text-xs text-slate-500 break-words">
                  {w.message ?? 'Sin detalle.'} · {momentOf(w.at)}
                  {w.attempts > 1 ? ` · ${w.attempts} intentos` : ''}{w.repeats > 0 ? ` · llegó ${w.repeats + 1} veces` : ''}
                  {w.status === 'failed' && w.next_retry_at ? ` · se reintenta solo ${momentOf(w.next_retry_at)}` : ''}
                </span>
              </span>
              {canRetry && w.can_retry && (
                <button type="button" data-retry-webhook={w.id} disabled={busy !== ''} onClick={() => retry(w.id)} className="self-start flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold border border-slate-200 rounded-lg text-slate-700 hover:bg-slate-50 disabled:opacity-50">
                  <RefreshCw size={12} className={busy === w.id ? 'animate-spin' : ''} /> Reintentar
                </button>
              )}
            </li>
          )}
        />
      </div>
      <SuccessAlert message={notice} />
      <ErrorAlert message={error} />
    </div>
  );
}
