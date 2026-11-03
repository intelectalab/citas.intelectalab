'use client';

import Badge from '@/app/components/common/Badge';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { PagedList } from '@/app/components/common/Pager';
import Link from 'next/link';
import { useCallback, useEffect, useState } from 'react';
import { CheckCircle2, Loader2, ReceiptText, XCircle } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { apiError, formatDate } from '@/features/appointments/appointments';
import { money, momentOf } from '@/features/sales/sales';
import { PAYMENT_STATUS, cycleLabel, type TransferPayment } from '@/features/billing/billing';

const TABS: { id: 'review' | 'paid' | 'rejected' | 'all'; label: string }[] = [
  { id: 'review', label: 'Por revisar' },
  { id: 'paid', label: 'Aprobados' },
  { id: 'rejected', label: 'No confirmados' },
  { id: 'all', label: 'Todos' },
];

const REASONS = ['No encontramos el abono en la cuenta.', 'El monto no coincide con el del plan.', 'El comprobante no se lee bien.'];

const card = 'bg-white rounded-3xl border border-slate-200/80 shadow-xs';

export default function PlatformCollections() {
  const { refetchUser } = useAuth();
  const [tab, setTab] = useState<(typeof TABS)[number]['id']>('review');
  const [rows, setRows] = useState<TransferPayment[] | null>(null);
  const [counts, setCounts] = useState<Record<string, number>>({});
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [open, setOpen] = useState<TransferPayment | null>(null);
  const [voucher, setVoucher] = useState<{ url: string; pdf: boolean } | null>(null);
  const [reason, setReason] = useState('');
  const [rejecting, setRejecting] = useState(false);
  const [busy, setBusy] = useState('');

  const load = useCallback(() => {
    api.get('/admin/billing/transfers', { params: { status: tab } })
      .then((res) => { setRows(res.data.transfers); setCounts(res.data.counts ?? {}); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar los cobros.')));
  }, [tab]);

  useEffect(() => { load(); }, [load]);

  useEffect(() => {
    setVoucher(null);
    setReason('');
    setRejecting(false);
    if (!open) return;
    let url = '';
    api.get(`/admin/billing/transfers/${open.id}/voucher`, { responseType: 'blob' })
      .then((res) => { url = URL.createObjectURL(res.data); setVoucher({ url, pdf: open.voucher_type === 'pdf' }); })
      .catch(() => setVoucher(null));
    return () => { if (url) URL.revokeObjectURL(url); };
  }, [open]);

  const decide = async (action: 'approve' | 'reject') => {
    if (!open) return;
    try {
      setBusy(action);
      setError('');
      await api.post(`/admin/billing/transfers/${open.id}/${action}`, action === 'reject' ? { reason } : {});
      setNotice(action === 'approve' ? `Aprobado: el plan de ${open.tenant?.name} quedó renovado y le avisamos.` : `Le avisamos a ${open.tenant?.name} que no pudimos confirmar su pago.`);
      setOpen(null);
      load();
      refetchUser();
    } catch (err) {
      setError(apiError(err, 'No se pudo completar.'));
    } finally {
      setBusy('');
    }
  };

  return (
    <div className="space-y-5 pb-10 font-sans" data-collections>
      <div>
        <h1 className="text-2xl font-black text-brand-ink tracking-tight">Cobros</h1>
        <p className="text-sm text-slate-500 font-medium">Los comprobantes de transferencia, Yape o Plin que envían las empresas para pagar su plan. Revisa que el abono esté en la cuenta antes de aprobar.</p>
      </div>

      <div className="flex flex-wrap gap-2" role="tablist">
        {TABS.map((item) => (
          <button key={item.id} type="button" role="tab" aria-selected={tab === item.id} data-collections-tab={item.id} onClick={() => setTab(item.id)} className={`px-4 py-2 rounded-xl text-sm font-semibold border transition-colors ${tab === item.id ? 'bg-brand text-white border-brand' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50'}`}>
            {item.label}{item.id !== 'all' && counts[item.id] ? ` (${counts[item.id]})` : ''}
          </button>
        ))}
      </div>

      <SuccessAlert message={notice} />
      <ErrorAlert message={error} />

      <section className={`${card} overflow-hidden flex flex-col`}>
        {rows === null ? (
          <p className="p-6 text-sm text-slate-500">Cargando...</p>
        ) : (
          <PagedList
            items={rows}
            empty={tab === 'review' ? 'No hay comprobantes por revisar.' : 'No hay cobros aquí.'}
            renderRow={(row) => (
              <li key={row.id}>
                <button type="button" data-transfer-row={row.id} onClick={() => setOpen(row)} className="w-full text-left flex flex-wrap sm:flex-nowrap items-center gap-3 px-5 py-3.5 hover:bg-slate-50">
                  <span className="p-2 rounded-xl bg-brand-soft/20 text-brand-ink shrink-0"><ReceiptText size={16} /></span>
                  <span className="flex-1 min-w-0">
                    <span className="block text-sm font-bold text-slate-800 truncate">{row.tenant?.name}</span>
                    <span className="block text-xs text-slate-500 truncate">{row.method} · operación {row.operation_number} · pagó el {row.paid_on ? formatDate(row.paid_on, { day: 'numeric', month: 'short' }) : '—'} · enviado {momentOf(row.sent_at)}</span>
                  </span>
                  <span className="text-sm font-black text-slate-800 tabular-nums">{money(row.amount, row.currency)}</span>
                  <Badge tone={PAYMENT_STATUS[row.status].badge} bold className="shrink-0">{PAYMENT_STATUS[row.status].label}</Badge>
                </button>
              </li>
            )}
          />
        )}
      </section>

      {open && (
        <FormDrawer isOpen onClose={() => setOpen(null)} title={`Pago de ${open.tenant?.name}`} subtitle={`${money(open.amount, open.currency)} · plan ${open.plan} ${cycleLabel(open.billing_cycle)}`} icon={<ReceiptText size={18} />}>
          <div className="space-y-4" data-transfer-detail>
            <dl className="rounded-2xl border border-slate-200 divide-y divide-slate-100 text-sm">
              {([
                ['Pagó a', open.method],
                ['Número de operación', open.operation_number],
                ['Fecha de pago', open.paid_on ? formatDate(open.paid_on, { day: 'numeric', month: 'long', year: 'numeric' }) : null],
                ['Enviado', momentOf(open.sent_at)],
                ['Revisado', open.reviewed_at ? `${momentOf(open.reviewed_at)}${open.reviewer ? ` por ${open.reviewer}` : ''}` : null],
                ['Motivo', open.status === 'rejected' ? open.message : null],
              ] as [string, string | null | undefined][]).filter(([, value]) => value).map(([label, value]) => (
                <div key={label} className="flex items-center justify-between gap-3 px-4 py-2.5">
                  <dt className="text-xs text-slate-500">{label}</dt>
                  <dd className="font-semibold text-slate-800 text-right">{value}</dd>
                </div>
              ))}
            </dl>

            <div className="rounded-2xl border border-slate-200 bg-slate-50 p-2 min-h-40 flex items-center justify-center" data-voucher>
              {!voucher ? <Loader2 size={20} className="animate-spin text-slate-400" /> : voucher.pdf
                ? <iframe title="Comprobante" src={voucher.url} className="w-full h-96 rounded-xl bg-white" />
                : <img src={voucher.url} alt="Comprobante" className="max-h-[28rem] rounded-xl" />}
            </div>
            {open.tenant && <Link href={`/admin/tenants/${open.tenant.id}`} className="block text-xs font-semibold text-brand-accent hover:underline">Ver la empresa</Link>}

            {open.status === 'review' && (
              rejecting ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50/40 p-4 space-y-3" data-reject-form>
                  <span className="block text-sm font-bold text-rose-800">¿Por qué no se pudo confirmar?</span>
                  <div className="flex flex-wrap gap-2">
                    {REASONS.map((text) => (
                      <button key={text} type="button" onClick={() => setReason(text)} className={`px-3 py-1.5 rounded-lg text-xs font-semibold border ${reason === text ? 'bg-rose-600 text-white border-rose-600' : 'bg-white text-rose-700 border-rose-200 hover:bg-rose-50'}`}>{text}</button>
                    ))}
                  </div>
                  <textarea aria-label="Motivo" maxLength={200} rows={2} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="O escríbelo tú. La empresa lo leerá tal cual." className="w-full text-sm border border-rose-200 rounded-xl px-3.5 py-2.5 bg-white outline-none focus:ring-2 focus:ring-rose-200" />
                  <div className="flex gap-2">
                    <button type="button" data-reject-confirm disabled={busy !== '' || !reason.trim()} onClick={() => decide('reject')} className="px-4 py-2 text-sm font-bold bg-rose-600 text-white rounded-xl hover:bg-rose-700 disabled:opacity-50">{busy === 'reject' ? 'Enviando...' : 'No confirmar y avisar'}</button>
                    <button type="button" onClick={() => setRejecting(false)} className="px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100 rounded-xl">Volver</button>
                  </div>
                </div>
              ) : (
                <div className="flex flex-wrap gap-2">
                  <button type="button" data-approve disabled={busy !== ''} onClick={() => decide('approve')} className="flex items-center gap-1.5 px-5 py-2.5 text-sm font-bold bg-emerald-600 text-white rounded-xl hover:bg-emerald-700 disabled:opacity-50">
                    {busy === 'approve' ? <Loader2 size={15} className="animate-spin" /> : <CheckCircle2 size={15} />} Aprobar: el abono está en la cuenta
                  </button>
                  <button type="button" data-reject disabled={busy !== ''} onClick={() => setRejecting(true)} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-rose-700 border border-rose-200 rounded-xl hover:bg-rose-50">
                    <XCircle size={15} /> No confirmar
                  </button>
                </div>
              )
            )}
          </div>
        </FormDrawer>
      )}
    </div>
  );
}
