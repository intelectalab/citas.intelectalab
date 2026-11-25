'use client';

import Badge from '@/app/components/common/Badge';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { PagedList } from '@/app/components/common/Pager';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Ban, FileText, Loader2, Printer, RefreshCw, Search } from 'lucide-react';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { money, momentOf } from '@/features/sales/sales';

type Invoice = {
  id: string;
  doc_type: 'ticket' | 'boleta' | 'factura' | 'nota_credito';
  doc_type_label: string;
  number: string;
  customer_doc_type: string | null;
  customer_doc_number: string | null;
  customer_name: string | null;
  total: string;
  currency: string;
  status: 'issued' | 'voided';
  simulated: boolean;
  sunat_status: 'pending' | 'accepted' | 'observed' | 'rejected' | 'not_applicable';
  sunat_message: string | null;
  reason: string | null;
  issued_at: string;
  sale_id: string;
  sale_code: string | null;
  branch_name: string | null;
  reference: string | null;
  has_refund: boolean;
  voided_at: string | null;
  void_reason: string | null;
  can_resend: boolean;
  can_void: boolean;
  state_label?: string;
  received?: boolean;
  next_attempt_at?: string | null;
  gave_up?: boolean;
  pdf_url?: string | null;
  xml_url?: string | null;
  cdr_url?: string | null;
  void_method?: 'baja' | 'credit_note' | 'local' | null;
  void_status?: 'pending' | 'accepted' | 'rejected' | null;
  void_plan?: 'baja' | 'credit_note' | 'local';
};

const VOID_PLAN: Record<NonNullable<Invoice['void_plan']>, string> = {
  baja: 'Se comunica la baja a SUNAT. La venta y su pago no cambian; después puedes emitir un comprobante nuevo desde la venta.',
  credit_note: 'Ya pasó el plazo para comunicar la baja a SUNAT: se emite una nota de crédito por el total. La venta y su pago no cambian.',
  local: 'La venta y su pago no cambian. Después puedes emitir un comprobante nuevo desde la venta.',
};

const TABS = [
  { id: 'all', label: 'Todos' },
  { id: 'attention', label: 'Por enviar o rechazados' },
  { id: 'credit', label: 'Notas de crédito' },
  { id: 'voided', label: 'Anulados' },
] as const;

const SUNAT: Record<Invoice['sunat_status'], { label: string; tone: string }> = {
  accepted: { label: 'Aceptado', tone: 'bg-emerald-50 text-emerald-700' },
  observed: { label: 'Aceptado con observaciones', tone: 'bg-amber-50 text-amber-800' },
  pending: { label: 'Por enviar', tone: 'bg-amber-50 text-amber-800' },
  rejected: { label: 'Rechazado', tone: 'bg-rose-50 text-rose-700' },
  not_applicable: { label: 'Interno', tone: 'bg-slate-100 text-slate-600' },
};

const VOID_REASONS = ['Datos del cliente mal escritos.', 'Se emitió el tipo equivocado (boleta en vez de factura).', 'Se emitió por error.'];

const card = 'bg-white rounded-3xl border border-slate-200/80 shadow-xs';

const isToday = (value: string | null | undefined) => {
  if (!value) return false;
  const d = new Date(value);
  const now = new Date();
  return d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth() && d.getDate() === now.getDate();
};

export default function InvoicesPage() {
  const [tab, setTab] = useState<(typeof TABS)[number]['id']>('all');
  const [sunatFilter, setSunatFilter] = useState<'' | 'today' | 'accepted' | 'rejected' | 'pending'>('');
  const [rows, setRows] = useState<Invoice[] | null>(null);
  const [canManage, setCanManage] = useState(false);
  const [canIssue, setCanIssue] = useState(false);
  const [provider, setProvider] = useState('simulated');
  const [notReady, setNotReady] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [open, setOpen] = useState<Invoice | null>(null);
  const [voiding, setVoiding] = useState(false);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState('');
  const [panelError, setPanelError] = useState('');

  const load = useCallback(() => {
    api.get('/invoices')
      .then((res) => {
        setRows(res.data.invoices);
        setCanManage(Boolean(res.data.can_manage));
        setCanIssue(Boolean(res.data.can_issue));
        setProvider(res.data.provider ?? 'simulated');
        setNotReady(res.data.not_ready ?? null);
        setError('');
      })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar los comprobantes.')));
  }, []);

  useEffect(() => { load(); }, [load]);
  useEffect(() => { setVoiding(false); setReason(''); setPanelError(''); }, [open]);

  const shown = useMemo(() => {
    const term = search.trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    return (rows ?? []).filter((row) => {
      if (tab === 'attention' && !(row.status === 'issued' && (row.sunat_status === 'pending' || row.sunat_status === 'rejected'))) return false;
      if (tab === 'credit' && row.doc_type !== 'nota_credito') return false;
      if (tab === 'voided' && row.status !== 'voided') return false;
      if (sunatFilter === 'today' && !(row.status === 'issued' && isToday(row.issued_at))) return false;
      if (sunatFilter === 'accepted' && !(row.status === 'issued' && (row.sunat_status === 'accepted' || row.sunat_status === 'observed'))) return false;
      if (sunatFilter === 'rejected' && !(row.status === 'issued' && row.sunat_status === 'rejected')) return false;
      if (sunatFilter === 'pending' && !(row.status === 'issued' && row.sunat_status === 'pending')) return false;
      if (!term) return true;
      const haystack = [row.number, row.customer_name, row.customer_doc_number, row.sale_code].filter(Boolean).join(' ').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
      return haystack.includes(term);
    });
  }, [rows, tab, search, sunatFilter]);

  const stats = useMemo(() => {
    const issued = (rows ?? []).filter((row) => row.status === 'issued' && row.sunat_status !== 'not_applicable');
    return {
      today: (rows ?? []).filter((row) => row.status === 'issued' && isToday(row.issued_at)).length,
      accepted: issued.filter((row) => row.sunat_status === 'accepted' || row.sunat_status === 'observed').length,
      rejected: issued.filter((row) => row.sunat_status === 'rejected').length,
      pending: issued.filter((row) => row.sunat_status === 'pending').length,
    };
  }, [rows]);

  const counts = useMemo(() => ({
    attention: (rows ?? []).filter((row) => row.status === 'issued' && (row.sunat_status === 'pending' || row.sunat_status === 'rejected')).length,
  }), [rows]);

  const act = async (action: 'resend' | 'void') => {
    if (!open) return;
    try {
      setBusy(action);
      setPanelError('');
      const res = await api.post(`/invoices/${open.id}/${action}`, action === 'void' ? { reason } : {});
      setNotice(action === 'void'
        ? `${open.doc_type_label} ${open.number}: ${(res.data.state_label ?? 'anulado').toLowerCase()}. ${res.data.sunat_message ?? ''} Si hace falta, emite otro comprobante desde la venta ${open.sale_code ?? ''}.`
        : `${open.doc_type_label} ${open.number}: ${(res.data.state_label ?? SUNAT[res.data.sunat_status as Invoice['sunat_status']]?.label ?? 'reenviada').toLowerCase()}.`);
      setOpen(null);
      load();
    } catch (err) {
      setPanelError(apiError(err, 'No se pudo completar.'));
    } finally {
      setBusy('');
    }
  };

  return (
    <div className="space-y-5 pb-10 font-sans" data-invoices>
      <div>
        <h1 className="flex items-center gap-2.5 text-2xl font-black text-brand-ink tracking-tight"><FileText className="w-6 h-6 text-brand-accent" /> Boletas y facturas</h1>
        <p className="text-sm text-slate-500 font-medium">Todos los comprobantes emitidos en tus ventas: boletas, facturas, tickets y notas de crédito. Desde aquí se reenvían los pendientes y se anula uno emitido por error.</p>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <div className="flex flex-wrap gap-2" role="tablist">
          {TABS.map((item) => (
            <button key={item.id} type="button" role="tab" aria-selected={tab === item.id} data-invoices-tab={item.id} onClick={() => setTab(item.id)} className={`px-4 py-2 rounded-xl text-sm font-semibold border transition-colors ${tab === item.id ? 'bg-brand text-white border-brand' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50'}`}>
              {item.label}{item.id === 'attention' && counts.attention ? ` (${counts.attention})` : ''}
            </button>
          ))}
        </div>
        <label className="relative flex-1 min-w-[14rem] sm:max-w-xs sm:ml-auto">
          <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Número, cliente o venta" aria-label="Buscar comprobante" className="w-full text-sm border border-slate-200 rounded-xl pl-9 pr-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-brand/20 focus:border-brand" />
        </label>
      </div>

      {rows !== null && (
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3" data-invoice-stats>
          {([
            ['today', 'Emitidos hoy', stats.today, 'text-brand-ink'],
            ['accepted', 'Aceptados por SUNAT', stats.accepted, 'text-emerald-700'],
            ['rejected', 'Con error', stats.rejected, 'text-rose-700'],
            ['pending', 'En proceso', stats.pending, 'text-amber-700'],
          ] as const).map(([id, label, value, tone]) => (
            <button
              key={id}
              type="button"
              data-invoice-stat={id}
              aria-pressed={sunatFilter === id}
              onClick={() => setSunatFilter(sunatFilter === id ? '' : id)}
              className={`text-left bg-white px-4 py-3 rounded-2xl border transition-colors ${sunatFilter === id ? 'border-brand ring-2 ring-brand/20' : 'border-slate-100 hover:border-slate-300'}`}
            >
              <span className="block text-[10px] font-semibold tracking-wider text-slate-400 uppercase">{label}</span>
              <span className={`block mt-0.5 text-2xl font-extrabold tabular-nums ${tone}`}>{value}</span>
            </button>
          ))}
        </div>
      )}

      <SuccessAlert message={notice} />
      <ErrorAlert message={error} />
      {notReady && <p className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">{notReady}</p>}

      <section className={`${card} overflow-hidden flex flex-col`}>
        {rows === null ? (
          <p className="p-6 text-sm text-slate-500">Cargando...</p>
        ) : (
          <PagedList
            items={shown}
            empty={tab === 'attention' ? 'No hay comprobantes por enviar ni rechazados.' : 'No hay comprobantes aquí.'}
            renderRow={(row) => (
              <li key={row.id}>
                <button type="button" data-invoice-row={row.number} onClick={() => setOpen(row)} className="w-full text-left flex flex-wrap sm:flex-nowrap items-center gap-3 px-5 py-3.5 hover:bg-slate-50">
                  <span className="p-2 rounded-xl bg-brand-soft/20 text-brand-ink shrink-0"><FileText size={16} /></span>
                  <span className="flex-1 min-w-0">
                    <span className={`block text-sm font-bold truncate ${row.status === 'voided' ? 'text-slate-400 line-through' : 'text-slate-800'}`}>{row.doc_type_label} {row.number}</span>
                    <span className="block text-xs text-slate-500 truncate">{row.customer_name ?? 'Sin nombre'}{row.customer_doc_number ? ` · ${row.customer_doc_type ?? 'Doc.'} ${row.customer_doc_number}` : ''} · {momentOf(row.issued_at)}{row.sale_code ? ` · venta ${row.sale_code}` : ''}{row.branch_name ? ` · ${row.branch_name}` : ''}</span>
                  </span>
                  <span className="text-sm font-black text-slate-800 tabular-nums">{row.doc_type === 'nota_credito' ? '−' : ''}{money(row.total, row.currency)}</span>
                  {row.status === 'voided'
                    ? <Badge tone="bg-slate-100 text-slate-600" bold className="shrink-0">{row.void_status === 'pending' ? 'Anulando' : 'Anulado'}</Badge>
                    : <Badge tone={SUNAT[row.sunat_status].tone} bold className="shrink-0">{row.sunat_status === 'pending' ? (row.received ? 'Esperando a SUNAT' : 'Pendiente de envío') : SUNAT[row.sunat_status].label}</Badge>}
                </button>
              </li>
            )}
          />
        )}
      </section>

      {open && (
        <FormDrawer isOpen onClose={() => setOpen(null)} title={`${open.doc_type_label} ${open.number}`} subtitle={`${money(open.total, open.currency)} · ${open.customer_name ?? 'Sin nombre'}`} icon={<FileText size={18} />}>
          <div className="space-y-4" data-invoice-detail>
            <dl className="rounded-2xl border border-slate-200 divide-y divide-slate-100 text-sm">
              {([
                ['Cliente', open.customer_name],
                ['Documento', open.customer_doc_number ? `${open.customer_doc_type ?? ''} ${open.customer_doc_number}`.trim() : null],
                ['Emitido', momentOf(open.issued_at)],
                ['Venta', open.sale_code],
                ['Sede', open.branch_name],
                ['Corrige a', open.reference],
                ['Motivo', open.doc_type === 'nota_credito' ? open.reason : null],
                ['Estado', open.state_label ?? (open.status === 'voided' ? null : SUNAT[open.sunat_status].label)],
                ['Próximo intento', open.status === 'issued' && open.sunat_status === 'pending' && open.next_attempt_at ? `Solo, ${momentOf(open.next_attempt_at)}` : null],
                ['Respuesta', open.sunat_message],
                ['Anulado', open.voided_at ? momentOf(open.voided_at) : null],
                ['Motivo de anulación', open.void_reason],
              ] as [string, string | null | undefined][]).filter(([, value]) => value).map(([label, value]) => (
                <div key={label} className="flex items-center justify-between gap-3 px-4 py-2.5">
                  <dt className="text-xs text-slate-500">{label}</dt>
                  <dd className="font-semibold text-slate-800 text-right">{value}</dd>
                </div>
              ))}
            </dl>

            {open.simulated && open.status === 'issued' && (
              <p className="text-xs text-slate-500 bg-slate-50 border border-slate-200 rounded-xl px-4 py-3">
                {provider === 'simulated'
                  ? 'Comprobante de prueba: no se envía a SUNAT porque no hay un proveedor de facturación conectado. Se conecta en Configuración → Integraciones.'
                  : 'Comprobante de prueba: se emitió antes de conectar el proveedor de facturación y no se envió a SUNAT.'}
              </p>
            )}

            {open.gave_up && (
              <p className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3" data-gave-up>El sistema dejó de intentarlo solo. Revisa la respuesta del proveedor y usa «Volver a enviar».</p>
            )}

            {(open.pdf_url || open.xml_url || open.cdr_url) && (
              <div className="flex flex-wrap gap-2 text-sm" data-invoice-files>
                {([['PDF', open.pdf_url], ['XML', open.xml_url], ['Constancia de SUNAT (CDR)', open.cdr_url]] as [string, string | null | undefined][]).filter(([, url]) => url).map(([label, url]) => (
                  <a key={label} href={url ?? '#'} target="_blank" rel="noopener noreferrer" className="px-3 py-1.5 rounded-lg border border-slate-200 font-semibold text-brand-accent hover:bg-slate-50">{label}</a>
                ))}
                <p className="w-full text-xs text-slate-500">Por seguridad, estos enlaces sirven media hora. Si alguno ya no abre, recarga la página.</p>
              </div>
            )}

            <ErrorAlert message={panelError} />

            <div className="flex flex-wrap gap-2">
              <a href={`/print/sales/${open.sale_id}`} target="_blank" rel="noopener noreferrer" className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-50">
                <Printer size={15} /> Ver e imprimir
              </a>
              {open.can_resend && canIssue && (
                <button type="button" data-resend disabled={busy !== ''} onClick={() => act('resend')} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50">
                  {busy === 'resend' ? <Loader2 size={15} className="animate-spin" /> : <RefreshCw size={15} />} Volver a enviar
                </button>
              )}
              {open.can_void && canManage && !voiding && (
                <button type="button" data-void disabled={busy !== '' || open.has_refund} onClick={() => setVoiding(true)} title={open.has_refund ? 'La venta tiene devoluciones con nota de crédito' : undefined} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-rose-700 border border-rose-200 rounded-xl hover:bg-rose-50 disabled:opacity-50">
                  <Ban size={15} /> Anular comprobante
                </button>
              )}
            </div>

            {open.has_refund && open.can_void && canManage && (
              <p className="text-xs text-slate-500">No se puede anular: la venta tiene devoluciones con nota de crédito.</p>
            )}

            {voiding && (
              <div className="rounded-2xl border border-rose-200 bg-rose-50/40 p-4 space-y-3" data-void-form>
                <span className="block text-sm font-bold text-rose-800">¿Por qué se anula?</span>
                <div className="flex flex-wrap gap-2">
                  {VOID_REASONS.map((text) => (
                    <button key={text} type="button" onClick={() => setReason(text)} className={`px-3 py-1.5 rounded-lg text-xs font-semibold border ${reason === text ? 'bg-rose-600 text-white border-rose-600' : 'bg-white text-rose-700 border-rose-200 hover:bg-rose-50'}`}>{text}</button>
                  ))}
                </div>
                <textarea aria-label="Motivo de anulación" maxLength={255} rows={2} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="O escríbelo tú." className="w-full text-sm border border-rose-200 rounded-xl px-3.5 py-2.5 bg-white outline-none focus:ring-2 focus:ring-rose-200" />
                <p className="text-xs text-slate-600" data-void-plan={open.void_plan ?? 'local'}>{VOID_PLAN[open.void_plan ?? 'local']}</p>
                <div className="flex gap-2">
                  <button type="button" data-void-confirm disabled={busy !== '' || reason.trim().length < 5} onClick={() => act('void')} className="px-4 py-2 text-sm font-bold bg-rose-600 text-white rounded-xl hover:bg-rose-700 disabled:opacity-50">{busy === 'void' ? 'Anulando...' : 'Anular'}</button>
                  <button type="button" onClick={() => setVoiding(false)} className="px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100 rounded-xl">Volver</button>
                </div>
              </div>
            )}
          </div>
        </FormDrawer>
      )}
    </div>
  );
}
