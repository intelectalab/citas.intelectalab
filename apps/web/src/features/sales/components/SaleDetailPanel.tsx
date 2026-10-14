'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { Ban, Banknote, CreditCard, Landmark, Printer, Receipt, RotateCcw, Smartphone, TicketPercent, Wallet } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Select from '@/app/components/common/Select';
import { PagedList } from '@/app/components/common/Pager';
import { CustomerLink } from '@/app/components/common/PersonLink';
import { apiError } from '@/features/appointments/appointments';
import ChargePanel from './ChargePanel';
import { cleanAmount, money, momentOf, SALE_BADGES, SUNAT_LABELS, type SaleDetail, type SalePayment, type SaleRefund } from '../sales';
import { useAuth } from '@/hooks/useAuth';

interface SaleDetailPanelProps {
  saleId: string | null;
  onClose: () => void;
  onChanged?: () => void;
}

const inputClass =
  'w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800 bg-white';
const labelClass = 'block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5';
const sectionTitle = 'text-[11px] font-bold text-slate-400 uppercase tracking-wider';
const cardHead = 'px-5 py-3 bg-slate-50/80 border-b border-slate-100';
const ghostButton = 'flex items-center gap-1.5 px-4 py-2 text-sm font-medium border border-slate-200 rounded-lg text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-50';

const METHOD_ICONS: Record<string, React.ReactNode> = {
  cash: <Banknote size={16} />,
  card: <CreditCard size={16} />,
  transfer: <Landmark size={16} />,
  yape: <Smartphone size={16} />,
  plin: <Smartphone size={16} />,
};

const chip = (active: boolean) =>
  `flex items-center gap-1.5 px-3.5 py-2 text-sm font-medium rounded-lg border transition-colors ${
    active ? 'bg-[#022340] text-white border-[#022340]' : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'
  }`;

export default function SaleDetailPanel({ saleId, onClose, onChanged }: SaleDetailPanelProps) {
  const { can } = useAuth();
  const [sale, setSale] = useState<SaleDetail | null>(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);
  const [action, setAction] = useState<'' | 'void' | 'refund'>('');
  const [reason, setReason] = useState('');
  const [amount, setAmount] = useState('');
  const [method, setMethod] = useState('cash');
  const [sessionId, setSessionId] = useState('');
  const [charging, setCharging] = useState(false);

  const load = (id: string) =>
    api.get(`/sales/${id}`)
      .then((res) => setSale(res.data))
      .catch((err) => setError(apiError(err, 'No se pudo cargar la venta.')));

  useEffect(() => {
    if (!saleId) return;

    setSale(null);
    setError('');
    setNotice('');
    setAction('');
    load(saleId);
  }, [saleId]);

  const open = (next: 'void' | 'refund') => {
    setError('');
    setNotice('');
    setReason('');
    setMethod('cash');
    setSessionId('');
    setAmount(sale ? sale.refund_available.toFixed(2) : '');
    setAction(action === next ? '' : next);
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!sale || !action) return;

    try {
      setBusy(true);
      setError('');
      const res = action === 'void'
        ? await api.post(`/sales/${sale.id}/void`, { reason: reason.trim() })
        : await api.post(`/sales/${sale.id}/refunds`, { amount: Number(amount || 0), method, reason: reason.trim(), cash_session_id: method === 'cash' && sessionId ? sessionId : null });
      setSale(res.data);
      setNotice(action === 'void' ? 'La venta quedó anulada.' : `Devolución de ${money(Number(amount || 0))} registrada.`);
      setAction('');
      onChanged?.();
    } catch (err) {
      setError(apiError(err, action === 'void' ? 'No se pudo anular la venta.' : 'No se pudo registrar la devolución.'));
    } finally {
      setBusy(false);
    }
  };

  const refunded = sale?.refunded ?? 0;
  const canCharge = can('sales.create') && Boolean(sale?.appointment && (sale.status === 'pending' || sale.status === 'partial'));
  const cashClosed = Boolean(sale && action === 'refund' && method === 'cash' && sale.cash_sessions.length === 0);

  return (
    <FormDrawer
      isOpen={saleId !== null}
      onClose={onClose}
      title={sale ? `Venta ${sale.code}` : 'Venta'}
      subtitle={sale ? `${momentOf(sale.created_at)} · ${sale.branch.name ?? ''}` : undefined}
      icon={<Receipt size={20} />}
    >
      {!sale ? (
        <p role={error ? 'alert' : undefined} className={`text-sm ${error ? 'text-red-600' : 'text-slate-500'}`}>{error || 'Cargando venta...'}</p>
      ) : (
        <div className="space-y-6 pb-4" data-sale={sale.status}>
          <section className="flex items-start justify-between gap-4">
            <div>
              <span className="block text-3xl font-black text-[#022340] tabular-nums">{money(sale.total)}</span>
              <span className="block text-sm text-slate-600 mt-1">
                {sale.customer ? <CustomerLink id={sale.customer.id} className="font-semibold text-slate-800">{sale.customer.name}</CustomerLink> : 'Sin cliente'}
                {sale.appointment?.code ? ` · cita ${sale.appointment.code}` : ''}
              </span>
            </div>
            <span data-sale-status={sale.status} className={`px-2.5 py-1 text-xs rounded-full font-semibold shrink-0 ${SALE_BADGES[sale.status]}`}>{sale.status_label}</span>
          </section>

          {sale.status === 'void' && sale.void_reason && (
            <p className="text-sm text-slate-600 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">Anulada: {sale.void_reason}</p>
          )}

          {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}
          {notice && <p data-notice className="text-sm text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-lg px-3 py-2">{notice}</p>}

          {(canCharge || sale.can_manage) && (
            <section className="flex flex-wrap gap-2" data-actions>
              {canCharge && (
                <button type="button" data-action="charge" onClick={() => setCharging(true)} className="flex items-center gap-1.5 px-4 py-2 text-sm font-medium bg-[#022340] text-white rounded-lg hover:bg-[#03315a] shadow-sm transition-colors">
                  <Wallet size={15} /> Cobrar {money(sale.balance)}
                </button>
              )}
              {sale.invoice && (
                <Link href={`/print/sales/${sale.id}`} target="_blank" data-action="print" className={ghostButton}>
                  <Printer size={15} /> Imprimir comprobante
                </Link>
              )}
              {sale.can_manage && !sale.refund_blocked && (
                <button type="button" data-action="refund" onClick={() => open('refund')} className={ghostButton}>
                  <RotateCcw size={15} /> Devolver
                </button>
              )}
              {sale.can_manage && !sale.void_blocked && (
                <button type="button" data-action="void" onClick={() => open('void')} className="flex items-center gap-1.5 px-4 py-2 text-sm font-medium border border-rose-200 rounded-lg text-rose-700 hover:bg-rose-50 transition-colors">
                  <Ban size={15} /> Anular venta
                </button>
              )}
            </section>
          )}

          {action === 'void' && (
            <form onSubmit={submit} className="p-4 bg-rose-50 border border-rose-200 rounded-xl space-y-3" data-form="void">
              <p className="text-sm text-rose-800">
                La venta queda sin efecto{sale.appointment ? ' y la cita vuelve a estar por cobrar' : ''}{sale.coupon ? `. El cupón ${sale.coupon.code} recupera su uso` : ''}. No se borra: queda en la lista como anulada.
              </p>
              <div>
                <label className="block text-xs font-semibold uppercase tracking-wider text-rose-700 mb-1.5" htmlFor="void-reason">Motivo *</label>
                <input id="void-reason" required maxLength={255} placeholder="Ej. Se registró por error" className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} />
              </div>
              <div className="flex justify-end gap-2">
                <button type="button" onClick={() => setAction('')} className="px-3 py-2 text-sm font-medium border border-slate-200 bg-white rounded-lg text-slate-600 hover:bg-slate-50 transition-colors">No anular</button>
                <button type="submit" data-submit disabled={busy} className="px-3 py-2 text-sm font-medium bg-rose-600 text-white rounded-lg hover:bg-rose-700 transition-colors disabled:opacity-50">{busy ? 'Anulando...' : 'Anular la venta'}</button>
              </div>
            </form>
          )}

          {action === 'refund' && (
            <form onSubmit={submit} className="p-4 bg-slate-50 border border-slate-200 rounded-xl space-y-4" data-form="refund">
              <p className="text-sm text-slate-600">
                Se le devuelve dinero al cliente. Lo cobrado no se borra: queda un pago en negativo
                {sale.invoice && sale.status !== 'partial' ? ' y se emite una nota de crédito que corrige el comprobante' : ''}.
              </p>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className={labelClass} htmlFor="refund-amount">Monto a devolver *</label>
                  <input id="refund-amount" inputMode="decimal" required className={inputClass} value={amount} onChange={(e) => setAmount(cleanAmount(e.target.value))} />
                  <p className="text-xs text-slate-500 mt-1.5">Hasta {money(sale.refund_available)}. Puede ser una parte.</p>
                </div>
                <div>
                  <label className={labelClass} htmlFor="refund-reason">Motivo *</label>
                  <input id="refund-reason" required maxLength={255} placeholder="Ej. No se realizó el servicio" className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} />
                </div>
              </div>

              <div>
                <span className={labelClass}>¿Cómo se devuelve el dinero?</span>
                <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Medio de la devolución">
                  {sale.refund_methods.map((option) => (
                    <button key={option.value} type="button" role="radio" aria-checked={method === option.value} data-refund-method={option.value} onClick={() => { setMethod(option.value); setError(''); }} className={chip(method === option.value)}>
                      {METHOD_ICONS[option.value]} {option.label}
                    </button>
                  ))}
                </div>
                <p className="text-xs text-slate-500 mt-1.5">
                  {method === 'cash' ? 'El efectivo sale de la caja abierta de la sede y queda como movimiento del turno.' : 'Haz la devolución por ese medio fuera del sistema; aquí queda registrada.'}
                </p>
              </div>

              {cashClosed && (
                <p data-cash-closed className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                  La caja de esta sede está cerrada. <Link href="/cash" className="font-semibold underline underline-offset-2 hover:text-[#022340]">Abrir caja</Link> o devuelve por otro medio.
                </p>
              )}

              {method === 'cash' && sale.cash_sessions.length > 1 && (
                <Select label="Caja" placeholder="La caja que tienes abierta" value={sessionId} onChange={(e) => setSessionId(String(e.target.value))} options={sale.cash_sessions.map((s) => ({ value: s.id, label: s.register_name }))} />
              )}

              <div className="flex justify-end gap-2">
                <button type="button" onClick={() => setAction('')} className="px-3 py-2 text-sm font-medium border border-slate-200 bg-white rounded-lg text-slate-600 hover:bg-slate-50 transition-colors">Cancelar</button>
                <button type="submit" data-submit disabled={busy || Number(amount || 0) <= 0} className="px-4 py-2 text-sm font-bold bg-[#022340] text-white rounded-lg hover:bg-[#03315a] transition-colors disabled:opacity-50">
                  {busy ? 'Registrando...' : `Devolver ${money(Number(amount || 0))}`}
                </button>
              </div>
            </form>
          )}

          {sale.can_manage && sale.refund_blocked && sale.status !== 'void' && sale.status !== 'refunded' && sale.paid > 0 && (
            <p data-refund-blocked className="text-xs text-slate-500">{sale.refund_blocked}</p>
          )}

          <section className="rounded-2xl border border-slate-200 overflow-hidden">
            <div className={cardHead}><span className={sectionTitle}>Detalle</span></div>
            <div className="px-5 py-4 space-y-2 text-sm">
              {sale.items.map((item) => (
                <div key={item.id} className="flex items-start justify-between gap-3">
                  <span className="font-semibold text-slate-800">{item.quantity > 1 ? `${item.quantity} × ` : ''}{item.description}</span>
                  <span className="font-semibold text-slate-800 tabular-nums">{money(item.total)}</span>
                </div>
              ))}
              {sale.coupon && (
                <div className="flex items-start justify-between gap-3 text-emerald-700">
                  <span className="flex items-center gap-1.5 font-medium"><TicketPercent size={15} /> Cupón {sale.coupon.code} ({sale.coupon.benefit})</span>
                  <span className="font-semibold tabular-nums">− {money(sale.coupon.discount)}</span>
                </div>
              )}
              <div className="pt-2 mt-2 border-t border-dashed border-slate-200 space-y-1 text-slate-500">
                <div className="flex justify-between"><span>Op. gravada</span><span className="tabular-nums">{money(sale.total - sale.tax)}</span></div>
                <div className="flex justify-between"><span>IGV {sale.tax_rate}%{sale.prices_include_tax ? ' (incluido en el precio)' : ''}</span><span className="tabular-nums">{money(sale.tax)}</span></div>
              </div>
              <div className="flex items-center justify-between pt-2 border-t border-slate-200">
                <span className="font-bold text-[#022340]">Total</span>
                <span className="text-lg font-black text-[#022340] tabular-nums">{money(sale.total)}</span>
              </div>
              {sale.balance > 0 && sale.status !== 'void' && (
                <div className="flex justify-between text-amber-700 font-semibold" data-balance><span>Por cobrar</span><span className="tabular-nums">{money(sale.balance)}</span></div>
              )}
              {refunded > 0 && (
                <div className="flex justify-between text-rose-700 font-semibold" data-refunded><span>Devuelto</span><span className="tabular-nums">− {money(refunded)}</span></div>
              )}
            </div>
          </section>

          <section className="rounded-2xl border border-slate-200 overflow-hidden flex flex-col" data-payments>
            <div className={cardHead}><span className={sectionTitle}>Pagos y devoluciones de dinero</span></div>
            <PagedList<SalePayment>
              items={sale.payments}
              empty="Aún no se ha cobrado nada."
              renderRow={(payment) => (
                <li key={payment.id} className="flex items-center gap-3 px-5 py-3">
                  <span className={`p-2 rounded-lg shrink-0 ${payment.amount < 0 ? 'bg-rose-50 text-rose-600' : 'bg-[#84D0D9]/20 text-[#022340]'}`}>{payment.amount < 0 ? <RotateCcw size={16} /> : METHOD_ICONS[payment.method] ?? <Wallet size={16} />}</span>
                  <span className="flex-1 min-w-0">
                    <span className="block text-sm font-semibold text-slate-800">{payment.amount < 0 ? `Devolución · ${payment.method_label}` : `${payment.method_label}${payment.reference ? ` · ${payment.reference}` : ''}`}</span>
                    <span className="block text-xs text-slate-500 truncate">{momentOf(payment.at)}{payment.by ? ` · ${payment.by}` : ''}{payment.register ? ` · ${payment.register}` : ''}</span>
                  </span>
                  <span className={`text-sm font-bold tabular-nums ${payment.amount < 0 ? 'text-rose-700' : 'text-slate-800'}`}>{payment.amount < 0 ? `− ${money(Math.abs(payment.amount))}` : money(payment.amount)}</span>
                </li>
              )}
            />
          </section>

          {sale.invoice && (
            <section className="rounded-2xl border border-slate-200 overflow-hidden" data-invoice={sale.invoice.number}>
              <div className="flex items-center justify-between gap-3 px-5 py-3 bg-[#022340] text-white">
                <span className="flex items-center gap-2 text-sm font-bold"><Receipt size={16} className="text-[#84D0D9]" /> {sale.invoice.doc_type_label}</span>
                <span className="text-sm font-black tabular-nums">{sale.invoice.number}</span>
              </div>
              <div className="px-5 py-3 text-sm text-slate-600">
                <span className="block">A nombre de <span className="font-semibold text-slate-800">{sale.invoice.customer_name ?? 'Cliente varios'}</span>{sale.invoice.customer_doc_number ? ` · ${sale.invoice.customer_doc_number}` : ''}</span>
                <span className="block text-xs text-slate-500 mt-0.5">
                  {momentOf(sale.invoice.issued_at)} · {sale.invoice.simulated ? 'Simulación: no se envió a SUNAT' : SUNAT_LABELS[sale.invoice.sunat_status]}
                </span>
              </div>
            </section>
          )}

          {sale.refunds.length > 0 && (
            <section className="rounded-2xl border border-slate-200 overflow-hidden flex flex-col" data-refunds>
              <div className={cardHead}><span className={sectionTitle}>Devoluciones</span></div>
              <PagedList<SaleRefund>
                items={sale.refunds}
                empty="Sin devoluciones."
                renderRow={(refund) => (
                  <li key={refund.id} className="px-5 py-3">
                    <div className="flex items-start justify-between gap-3">
                      <span className="text-sm font-semibold text-slate-800">{refund.reason}</span>
                      <span className="text-sm font-bold text-rose-700 tabular-nums shrink-0">− {money(refund.amount)}</span>
                    </div>
                    <span className="block text-xs text-slate-500">
                      {refund.method_label} · {momentOf(refund.at)}{refund.by ? ` · ${refund.by}` : ''}
                    </span>
                    {refund.credit_note && (
                      <span className="block text-xs font-medium text-[#3F768C] mt-0.5" data-credit-note={refund.credit_note.number}>
                        Nota de crédito {refund.credit_note.number}{refund.credit_note.simulated ? ' · simulación' : ''}
                      </span>
                    )}
                  </li>
                )}
              />
            </section>
          )}
        </div>
      )}

      <ChargePanel
        appointmentId={charging && sale?.appointment ? sale.appointment.id : null}
        appointmentCode={sale?.appointment?.code ?? undefined}
        onClose={() => { setCharging(false); if (saleId) load(saleId); }}
        onChanged={() => onChanged?.()}
      />
    </FormDrawer>
  );
}
