'use client';

import Badge from '@/app/components/common/Badge';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { PagedList } from '@/app/components/common/Pager';
import { CustomerLink } from '@/app/components/common/PersonLink';
import Link from 'next/link';
import { useCallback, useEffect, useState } from 'react';
import { CheckCircle2, FileCheck2, Loader2, ReceiptText, XCircle } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { apiError, formatDate } from '@/features/appointments/appointments';
import { money, momentOf } from '@/features/sales/sales';

type Voucher = {
  id: string;
  status: 'review' | 'approved' | 'rejected';
  amount: number;
  currency: string;
  method: 'transfer' | 'yape' | 'plin';
  method_label: string;
  account: string | null;
  operation_number: string;
  paid_on: string;
  reason: string | null;
  sent_at: string;
  reviewed_at: string | null;
  reviewer: string | null;
  branch: string | null;
  customer: { id: string; name: string; phone: string | null; email: string | null } | null;
  purpose: 'sale' | 'deposit' | null;
  purpose_label: string;
  description: string | null;
  link_status: string | null;
  held_until: string | null;
  sale: { id: string; code: string } | null;
  appointment: string | null;
  voucher_type: 'pdf' | 'image';
};

const TABS: { id: 'review' | 'approved' | 'rejected' | 'all'; label: string }[] = [
  { id: 'review', label: 'Por revisar' },
  { id: 'approved', label: 'Aprobados' },
  { id: 'rejected', label: 'Rechazados' },
  { id: 'all', label: 'Todos' },
];

const STATUS: Record<Voucher['status'], { label: string; tone: string }> = {
  review: { label: 'Por revisar', tone: 'bg-amber-50 text-amber-800' },
  approved: { label: 'Aprobado', tone: 'bg-emerald-50 text-emerald-700' },
  rejected: { label: 'Rechazado', tone: 'bg-rose-50 text-rose-700' },
};

const REASONS = ['No encontramos el abono en la cuenta.', 'El monto no coincide.', 'El comprobante no se lee bien.'];

const card = 'bg-white rounded-3xl border border-slate-200/80 shadow-xs';

export default function VouchersPage() {
  const { refetchUser } = useAuth();
  const [tab, setTab] = useState<(typeof TABS)[number]['id']>('review');
  const [rows, setRows] = useState<Voucher[] | null>(null);
  const [counts, setCounts] = useState<Record<string, number>>({});
  const [enabled, setEnabled] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [open, setOpen] = useState<Voucher | null>(null);
  const [file, setFile] = useState<{ url: string; pdf: boolean } | null>(null);
  const [reason, setReason] = useState('');
  const [rejecting, setRejecting] = useState(false);
  const [busy, setBusy] = useState('');
  const [panelError, setPanelError] = useState('');

  const load = useCallback(() => {
    api.get('/payment-vouchers', { params: { status: tab } })
      .then((res) => {
        setRows(res.data.vouchers);
        setCounts(res.data.counts ?? {});
        setEnabled(res.data.enabled !== false);
        setError('');
        const wanted = new URLSearchParams(window.location.search).get('id');
        if (wanted) setOpen((current) => current ?? (res.data.vouchers as Voucher[]).find((v) => v.id === wanted) ?? null);
      })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar los comprobantes.')));
  }, [tab]);

  useEffect(() => { load(); }, [load]);

  useEffect(() => {
    setFile(null);
    setReason('');
    setRejecting(false);
    setPanelError('');
    if (!open) return;
    let url = '';
    api.get(`/payment-vouchers/${open.id}/file`, { responseType: 'blob' })
      .then((res) => { url = URL.createObjectURL(res.data); setFile({ url, pdf: open.voucher_type === 'pdf' }); })
      .catch(() => setFile(null));
    return () => { if (url) URL.revokeObjectURL(url); };
  }, [open]);

  const decide = async (action: 'approve' | 'reject') => {
    if (!open) return;
    try {
      setBusy(action);
      setPanelError('');
      const res = await api.post(`/payment-vouchers/${open.id}/${action}`, action === 'reject' ? { reason } : {});
      setNotice(res.data.message);
      setOpen(null);
      load();
      refetchUser();
    } catch (err) {
      setPanelError(apiError(err, 'No se pudo completar.'));
    } finally {
      setBusy('');
    }
  };

  const waiting = open?.status === 'review' && open.link_status === 'review';

  return (
    <div className="space-y-5 pb-10 font-sans" data-vouchers>
      <div>
        <h1 className="flex items-center gap-2.5 text-2xl font-black text-brand-ink tracking-tight"><FileCheck2 className="w-6 h-6 text-brand-accent" /> Comprobantes</h1>
        <p className="text-sm text-slate-500 font-medium">Los comprobantes de transferencia, Yape o Plin que envían los clientes al pagar en línea. Revisa que el abono esté en tu cuenta antes de aprobar: al aprobar, el pago queda registrado.</p>
      </div>

      {!enabled && (
        <p className="text-sm text-slate-600 bg-slate-50 border border-slate-200 rounded-xl px-4 py-3">
          Tu empresa no está recibiendo comprobantes. Se enciende en <Link href="/settings#integraciones" className="font-semibold text-brand-accent hover:underline">Configuración → Integraciones</Link>.
        </p>
      )}

      <div className="flex flex-wrap gap-2" role="tablist">
        {TABS.map((item) => (
          <button key={item.id} type="button" role="tab" aria-selected={tab === item.id} data-vouchers-tab={item.id} onClick={() => setTab(item.id)} className={`px-4 py-2 rounded-xl text-sm font-semibold border transition-colors ${tab === item.id ? 'bg-brand text-white border-brand' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50'}`}>
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
            empty={tab === 'review' ? 'No hay comprobantes por revisar.' : 'No hay comprobantes aquí.'}
            renderRow={(row) => (
              <li key={row.id}>
                <button type="button" data-voucher-row={row.id} onClick={() => setOpen(row)} className="w-full text-left flex flex-wrap sm:flex-nowrap items-center gap-3 px-5 py-3.5 hover:bg-slate-50">
                  <span className="p-2 rounded-xl bg-brand-soft/20 text-brand-ink shrink-0"><ReceiptText size={16} /></span>
                  <span className="flex-1 min-w-0">
                    <span className="block text-sm font-bold text-slate-800 truncate">{row.customer?.name ?? 'Cliente'} · {row.purpose_label}</span>
                    <span className="block text-xs text-slate-500 truncate">{row.account ?? row.method_label} · operación {row.operation_number} · pagó el {formatDate(row.paid_on, { day: 'numeric', month: 'short' })} · enviado {momentOf(row.sent_at)}{row.branch ? ` · ${row.branch}` : ''}</span>
                  </span>
                  <span className="text-sm font-black text-slate-800 tabular-nums">{money(row.amount, row.currency)}</span>
                  <Badge tone={STATUS[row.status].tone} bold className="shrink-0">{STATUS[row.status].label}</Badge>
                </button>
              </li>
            )}
          />
        )}
      </section>

      {open && (
        <FormDrawer isOpen onClose={() => setOpen(null)} title={open.purpose_label} subtitle={`${money(open.amount, open.currency)} · ${open.customer?.name ?? 'Cliente'}`} icon={<ReceiptText size={18} />}>
          <div className="space-y-4" data-voucher-detail>
            {open.customer && (
              <p className="text-sm text-slate-600">
                <CustomerLink id={open.customer.id}>{open.customer.name}</CustomerLink>
                {open.customer.phone ? ` · ${open.customer.phone}` : ''}
              </p>
            )}
            <dl className="rounded-2xl border border-slate-200 divide-y divide-slate-100 text-sm">
              {([
                ['Qué paga', open.description],
                ['Pagó a', open.account ?? open.method_label],
                ['Número de operación', open.operation_number],
                ['Fecha de pago', formatDate(open.paid_on, { day: 'numeric', month: 'long', year: 'numeric' })],
                ['Enviado', momentOf(open.sent_at)],
                ['Horario apartado hasta', open.status === 'review' && open.held_until ? momentOf(open.held_until) : null],
                ['Venta', open.sale?.code ?? null],
                ['Cita', open.appointment],
                ['Revisado', open.reviewed_at ? `${momentOf(open.reviewed_at)}${open.reviewer ? ` por ${open.reviewer}` : ''}` : null],
                ['Motivo', open.status === 'rejected' ? open.reason : null],
              ] as [string, string | null | undefined][]).filter(([, value]) => value).map(([label, value]) => (
                <div key={label} className="flex items-center justify-between gap-3 px-4 py-2.5">
                  <dt className="text-xs text-slate-500">{label}</dt>
                  <dd className="font-semibold text-slate-800 text-right">{value}</dd>
                </div>
              ))}
            </dl>

            <div className="rounded-2xl border border-slate-200 bg-slate-50 p-2 min-h-40 flex items-center justify-center" data-voucher-file>
              {!file ? <Loader2 size={20} className="animate-spin text-slate-400" /> : file.pdf
                ? <iframe title="Comprobante" src={file.url} className="w-full h-96 rounded-xl bg-white" />
                : <img src={file.url} alt="Comprobante" className="max-h-[28rem] rounded-xl" />}
            </div>

            {open.status === 'review' && !waiting && (
              <p className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">El pago de este comprobante ya no espera revisión (se anuló o se pagó de otra forma). Recházalo y, si el cliente transfirió, devuélvele el dinero.</p>
            )}

            <ErrorAlert message={panelError} />

            {open.status === 'review' && (
              rejecting ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50/40 p-4 space-y-3" data-reject-form>
                  <span className="block text-sm font-bold text-rose-800">¿Por qué lo rechazas?</span>
                  <div className="flex flex-wrap gap-2">
                    {REASONS.map((text) => (
                      <button key={text} type="button" onClick={() => setReason(text)} className={`px-3 py-1.5 rounded-lg text-xs font-semibold border ${reason === text ? 'bg-rose-600 text-white border-rose-600' : 'bg-white text-rose-700 border-rose-200 hover:bg-rose-50'}`}>{text}</button>
                    ))}
                  </div>
                  <textarea aria-label="Motivo" maxLength={200} rows={2} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="O escríbelo tú. El cliente lo leerá tal cual." className="w-full text-sm border border-rose-200 rounded-xl px-3.5 py-2.5 bg-white outline-none focus:ring-2 focus:ring-rose-200" />
                  <div className="flex gap-2">
                    <button type="button" data-reject-confirm disabled={busy !== '' || reason.trim().length < 5} onClick={() => decide('reject')} className="px-4 py-2 text-sm font-bold bg-rose-600 text-white rounded-xl hover:bg-rose-700 disabled:opacity-50">{busy === 'reject' ? 'Enviando...' : 'Rechazar'}</button>
                    <button type="button" onClick={() => setRejecting(false)} className="px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100 rounded-xl">Volver</button>
                  </div>
                </div>
              ) : (
                <div className="flex flex-wrap gap-2">
                  {waiting && (
                    <button type="button" data-approve disabled={busy !== ''} onClick={() => decide('approve')} className="flex items-center gap-1.5 px-5 py-2.5 text-sm font-bold bg-emerald-600 text-white rounded-xl hover:bg-emerald-700 disabled:opacity-50">
                      {busy === 'approve' ? <Loader2 size={15} className="animate-spin" /> : <CheckCircle2 size={15} />} Aprobar: el abono está en la cuenta
                    </button>
                  )}
                  <button type="button" data-reject disabled={busy !== ''} onClick={() => setRejecting(true)} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-rose-700 border border-rose-200 rounded-xl hover:bg-rose-50">
                    <XCircle size={15} /> Rechazar
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
