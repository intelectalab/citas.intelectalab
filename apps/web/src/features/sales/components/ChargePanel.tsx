'use client';

import { useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { BadgeCheck, Banknote, CreditCard, FileText, Landmark, Receipt, Smartphone, TicketPercent, Wallet, X } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Select from '@/app/components/common/Select';
import { PagedList } from '@/app/components/common/Pager';
import { apiError } from '@/features/appointments/appointments';
import { cleanAmount, money, momentOf, SALE_BADGES, SUNAT_LABELS, type ChargeContext, type SalePayment } from '../sales';

interface ChargePanelProps {
  appointmentId: string | null;
  saleId?: string | null;
  appointmentCode?: string;
  onClose: () => void;
  onChanged?: (context: ChargeContext) => void;
}

const inputClass =
  'w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800 bg-white';
const labelClass = 'block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5';
const sectionTitle = 'text-[11px] font-bold text-slate-400 uppercase tracking-wider';

const METHOD_ICONS: Record<string, React.ReactNode> = {
  cash: <Banknote size={16} />,
  card: <CreditCard size={16} />,
  transfer: <Landmark size={16} />,
  yape: <Smartphone size={16} />,
  plin: <Smartphone size={16} />,
};

const STATUS_BADGES: Record<string, string> = {
  pending: 'bg-amber-50 text-amber-700',
  partial: 'bg-sky-50 text-sky-700',
  paid: 'bg-emerald-50 text-emerald-700',
};

const chip = (active: boolean) =>
  `flex items-center gap-1.5 px-3.5 py-2 text-sm font-medium rounded-lg border transition-colors ${
    active ? 'bg-[#022340] text-white border-[#022340]' : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'
  }`;

const newKey = () => (typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`);

export default function ChargePanel({ appointmentId, saleId = null, appointmentCode, onClose, onChanged }: ChargePanelProps) {
  const base = appointmentId ? `/appointments/${appointmentId}` : saleId ? `/sales/${saleId}` : null;
  const contextUrl = appointmentId ? `${base}/sale` : `${base}/charge`;
  const payUrl = appointmentId ? `${base}/charge` : `${base}/pay`;
  const [context, setContext] = useState<ChargeContext | null>(null);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const [method, setMethod] = useState('cash');
  const [amount, setAmount] = useState('');
  const [received, setReceived] = useState('');
  const [reference, setReference] = useState('');
  const [sessionId, setSessionId] = useState('');
  const [docType, setDocType] = useState('');
  const [ruc, setRuc] = useState('');
  const [businessName, setBusinessName] = useState('');
  const [address, setAddress] = useState('');
  const [couponInput, setCouponInput] = useState('');
  const [couponCode, setCouponCode] = useState('');
  const [couponError, setCouponError] = useState('');
  const [checkingCoupon, setCheckingCoupon] = useState(false);
  const attempt = useRef(newKey());

  const balanceOf = (data: ChargeContext) => (data.sale ? data.sale.balance : data.preview.total);

  const apply = (data: ChargeContext) => {
    setContext(data);
    setAmount(balanceOf(data).toFixed(2));
    setReceived('');
    setReference('');
    setSessionId((current) => (data.cash.sessions.some((s) => s.id === current) ? current : ''));
    setDocType((current) => current || data.default_doc_type);
    attempt.current = newKey();
  };

  useEffect(() => {
    if (!base) return;

    setContext(null);
    setError('');
    setNotice('');
    setMethod('cash');
    setDocType('');
    setRuc('');
    setBusinessName('');
    setAddress('');
    setCouponInput('');
    setCouponCode('');
    setCouponError('');
    setLoading(true);

    api.get(contextUrl)
      .then((res) => apply(res.data))
      .catch((err) => setError(apiError(err, appointmentId ? 'No se pudo cargar el cobro de la cita.' : 'No se pudo cargar el cobro de la venta.')))
      .finally(() => setLoading(false));
  }, [base, contextUrl, appointmentId]);

  const sale = context?.sale ?? null;
  const totals = sale ?? context?.preview;
  const balance = context ? balanceOf(context) : 0;
  const paidSale = sale?.status === 'paid' ? sale : null;
  const isPaid = paidSale !== null;
  const invoice = sale?.invoice ?? null;
  const canPay = !sale || sale.status === 'pending' || sale.status === 'partial';
  const isCash = method === 'cash';
  const needsReference = context?.methods.find((m) => m.value === method)?.needs_reference ?? false;
  const cashClosed = Boolean(context && isCash && context.cash.required && context.cash.sessions.length === 0);
  const amountNumber = Number(amount || 0);
  const change = isCash && received ? Math.max(0, Number(received) - amountNumber) : 0;
  const completes = amountNumber >= balance - 0.001;
  const appliedCoupon = sale ? sale.coupon : context?.coupon ?? null;

  const applyCoupon = async (code: string) => {
    if (!appointmentId) return;

    try {
      setCheckingCoupon(true);
      setCouponError('');
      const res = await api.get(`/appointments/${appointmentId}/sale`, { params: code ? { coupon: code } : {} });
      apply(res.data);
      setCouponCode(code);
      setCouponInput('');
    } catch (err) {
      setCouponError(apiError(err, 'No se pudo revisar el cupón.'));
    } finally {
      setCheckingCoupon(false);
    }
  };

  const docPayload = () => ({
    doc_type: docType,
    ...(docType === 'factura' ? { ruc: ruc.trim(), business_name: businessName.trim(), address: address.trim() || null } : {}),
  });

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!base || !context) return;

    try {
      setSaving(true);
      setError('');
      setNotice('');
      const res = await api.post(payUrl, {
        method,
        amount: amountNumber,
        received: isCash && received ? Number(received) : null,
        reference: needsReference ? reference.trim() : null,
        cash_session_id: isCash && sessionId ? sessionId : null,
        idempotency_key: attempt.current,
        coupon_code: !sale && couponCode ? couponCode : null,
        ...docPayload(),
      });
      const data: ChargeContext = res.data;
      apply(data);
      setNotice(
        data.sale?.status === 'paid'
          ? `Cobro registrado: la venta ${data.sale.code} quedó pagada.${data.change ? ` Vuelto: ${money(data.change, context?.sale?.currency ?? context?.preview.currency)}.` : ''}`
          : `Pago registrado. Saldo por cobrar: ${money(data.sale?.balance, context?.sale?.currency ?? context?.preview.currency)}.${data.change ? ` Vuelto: ${money(data.change, context?.sale?.currency ?? context?.preview.currency)}.` : ''}`,
      );
      onChanged?.(data);
    } catch (err) {
      setError(apiError(err, 'No se pudo registrar el pago.'));
    } finally {
      setSaving(false);
    }
  };

  const issueInvoice = async () => {
    if (!base) return;

    try {
      setSaving(true);
      setError('');
      const res = await api.post(`${base}/invoice`, docPayload());
      apply(res.data);
      onChanged?.(res.data);
    } catch (err) {
      setError(apiError(err, 'No se pudo emitir el comprobante.'));
    } finally {
      setSaving(false);
    }
  };

  const docFields = context && (
    <div className="space-y-3" data-doc>
      <span className={labelClass}>Comprobante</span>
      <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Tipo de comprobante">
        {context.doc_types.map((type) => (
          <button key={type.value} type="button" role="radio" aria-checked={docType === type.value} data-doc-type={type.value} onClick={() => setDocType(type.value)} className={chip(docType === type.value)}>
            <FileText size={15} /> {type.label}
          </button>
        ))}
      </div>

      {docType === 'factura' ? (
        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className={labelClass} htmlFor="charge-ruc">RUC *</label>
            <input id="charge-ruc" inputMode="numeric" maxLength={11} placeholder="20123456789" className={inputClass} value={ruc} onChange={(e) => setRuc(e.target.value.replace(/\D/g, ''))} />
          </div>
          <div>
            <label className={labelClass} htmlFor="charge-business">Razón social *</label>
            <input id="charge-business" maxLength={255} placeholder="Ej. Inversiones Lima SAC" className={inputClass} value={businessName} onChange={(e) => setBusinessName(e.target.value)} />
          </div>
          <div className="col-span-2">
            <label className={labelClass} htmlFor="charge-address">Dirección fiscal</label>
            <input id="charge-address" maxLength={255} placeholder="Opcional" className={inputClass} value={address} onChange={(e) => setAddress(e.target.value)} />
          </div>
        </div>
      ) : (
        <p className="text-xs text-slate-500">
          {docType === 'boleta'
            ? `A nombre de ${context.customer?.name ?? 'el cliente'}${context.customer?.document_number ? ` · documento ${context.customer.document_number}` : ' · sin documento registrado'}.`
            : 'Comprobante interno de tu empresa: no se envía a SUNAT.'}
        </p>
      )}
    </div>
  );

  return (
    <FormDrawer
      isOpen={base !== null}
      onClose={onClose}
      title={sale ? `Cobro ${sale.code}` : `Cobrar cita${appointmentCode ? ` ${appointmentCode}` : ''}`}
      subtitle={context ? [context.customer?.name, context.item].filter(Boolean).join(' · ') : undefined}
      icon={<Wallet size={20} />}
    >
      {loading || !context || !totals ? (
        <p role={error ? 'alert' : undefined} className={`text-sm ${error ? 'text-red-600' : 'text-slate-500'}`}>{error || 'Cargando cobro...'}</p>
      ) : (
        <div className="space-y-6 pb-4" data-charge>
          <section className="rounded-2xl border border-slate-200 overflow-hidden">
            <div className="flex items-center justify-between gap-3 px-5 py-3 bg-slate-50/80 border-b border-slate-100">
              <span className={sectionTitle}>Lo que se cobra</span>
              <span data-sale-status={sale?.status ?? 'none'} className={`px-2.5 py-1 text-xs rounded-full font-semibold ${SALE_BADGES[sale?.status ?? 'pending']}`}>
                {sale?.status_label ?? 'Por cobrar'}
              </span>
            </div>

            <div className="px-5 py-4 space-y-2 text-sm">
              {(sale?.items ?? [{ id: 'preview', description: context.item ?? 'Servicio', quantity: 1, total: context.preview.subtotal }]).map((item) => (
                <div key={item.id} className="flex items-start justify-between gap-3">
                  <span className="font-semibold text-slate-800">{item.quantity > 1 ? `${item.quantity} × ` : ''}{item.description}</span>
                  <span className="font-semibold text-slate-800 tabular-nums">{money(item.total, context?.sale?.currency ?? context?.preview.currency)}</span>
                </div>
              ))}

              {appliedCoupon && (
                <div className="flex items-start justify-between gap-3 text-emerald-700" data-coupon-line={appliedCoupon.code}>
                  <span className="flex items-center gap-1.5 font-medium"><TicketPercent size={15} /> Cupón {appliedCoupon.code} ({appliedCoupon.benefit})</span>
                  <span className="font-semibold tabular-nums">− {money(appliedCoupon.discount, context?.sale?.currency ?? context?.preview.currency)}</span>
                </div>
              )}

              <div className="pt-2 mt-2 border-t border-dashed border-slate-200 space-y-1 text-slate-500">
                <div className="flex justify-between"><span>Op. gravada</span><span className="tabular-nums">{money(totals.total - totals.tax, context?.sale?.currency ?? context?.preview.currency)}</span></div>
                <div className="flex justify-between">
                  <span>IGV {totals.tax_rate}%{totals.prices_include_tax ? ' (incluido en el precio)' : ''}</span>
                  <span className="tabular-nums" data-tax>{money(totals.tax, context?.sale?.currency ?? context?.preview.currency)}</span>
                </div>
              </div>

              <div className="flex items-center justify-between pt-2 border-t border-slate-200">
                <span className="font-bold text-[#022340]">Total</span>
                <span className="text-xl font-black text-[#022340] tabular-nums" data-total>{money(totals.total, context?.sale?.currency ?? context?.preview.currency)}</span>
              </div>

              {sale && sale.paid > 0 && !isPaid && (
                <div className="flex items-center justify-between text-sm">
                  <span className="text-slate-500">Pagado {money(sale.paid, context?.sale?.currency ?? context?.preview.currency)}</span>
                  <span className="font-bold text-amber-700" data-balance>Saldo {money(sale.balance, context?.sale?.currency ?? context?.preview.currency)}</span>
                </div>
              )}
            </div>
          </section>

          {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}
          {notice && <p data-notice className="text-sm text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-lg px-3 py-2">{notice}</p>}

          {sale && !canPay && !isPaid && (
            <p data-closed className="text-sm text-slate-600 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">
              Esta venta está {sale.status_label.toLowerCase()}{(sale.refunded ?? 0) > 0 ? `: se devolvieron ${money(sale.refunded, context?.sale?.currency ?? context?.preview.currency)}` : ''}. El detalle está en Ventas.
            </p>
          )}

          {context.blocked && !sale && <p data-blocked className="text-sm text-slate-600 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">{context.blocked}</p>}

          {canPay && !context.blocked && (
            <form onSubmit={handleSubmit} className="space-y-5" data-pay-form>
              <div>
                <span className={labelClass}>Medio de pago</span>
                <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Medio de pago">
                  {context.methods.map((option) => (
                    <button key={option.value} type="button" role="radio" aria-checked={method === option.value} data-method={option.value} onClick={() => { setMethod(option.value); setError(''); }} className={chip(method === option.value)}>
                      {METHOD_ICONS[option.value]} {option.label}
                    </button>
                  ))}
                </div>
              </div>

              {cashClosed && (
                <p data-cash-closed className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                  La caja de esta sede está cerrada: no se puede cobrar en efectivo.{' '}
                  <Link href="/cash" className="font-semibold underline underline-offset-2 hover:text-[#022340]">Abrir caja</Link>
                </p>
              )}

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className={labelClass} htmlFor="charge-amount">Monto a cobrar *</label>
                  <input
                    id="charge-amount"
                    inputMode="decimal"
                    required
                    readOnly={!context.partial_payments}
                    className={`${inputClass} ${context.partial_payments ? '' : 'bg-slate-50 text-slate-600'}`}
                    value={amount}
                    onChange={(e) => setAmount(cleanAmount(e.target.value))}
                  />
                  <p className="text-xs text-slate-500 mt-1.5">
                    {context.partial_payments ? `Saldo: ${money(balance, context?.sale?.currency ?? context?.preview.currency)}. Puedes cobrar una parte.` : 'Tu empresa cobra el total de una vez.'}
                  </p>
                </div>

                {isCash ? (
                  <div>
                    <label className={labelClass} htmlFor="charge-received">Recibido</label>
                    <input id="charge-received" inputMode="decimal" placeholder={amount || '0.00'} className={inputClass} value={received} onChange={(e) => setReceived(cleanAmount(e.target.value))} />
                    <p className="text-xs mt-1.5 font-semibold text-[#3F768C]" data-change>Vuelto: {money(change, context?.sale?.currency ?? context?.preview.currency)}</p>
                  </div>
                ) : (
                  <div>
                    <label className={labelClass} htmlFor="charge-reference">N.º de operación *</label>
                    <input id="charge-reference" required maxLength={190} placeholder={method === 'card' ? 'Voucher del POS' : 'Código de la operación'} className={inputClass} value={reference} onChange={(e) => setReference(e.target.value)} />
                  </div>
                )}
              </div>

              {isCash && context.cash.sessions.length > 1 && (
                <Select
                  label="Caja"
                  placeholder="La caja que tienes abierta"
                  value={sessionId}
                  onChange={(e) => setSessionId(String(e.target.value))}
                  options={context.cash.sessions.map((s) => ({ value: s.id, label: s.register_name }))}
                />
              )}

              {!sale && (
                <div data-coupon>
                  <label className={labelClass} htmlFor="charge-coupon">Cupón</label>
                  {appliedCoupon ? (
                    <div className="flex items-center justify-between gap-3 border border-emerald-200 bg-emerald-50/60 rounded-lg px-3.5 py-2.5">
                      <span className="min-w-0">
                        <span className="block text-sm font-bold text-emerald-800">{appliedCoupon.code} · descuenta {money(appliedCoupon.discount, context?.sale?.currency ?? context?.preview.currency)}</span>
                        {appliedCoupon.description && <span className="block text-xs text-emerald-700 truncate">{appliedCoupon.description}</span>}
                      </span>
                      <button type="button" data-coupon-remove disabled={checkingCoupon} onClick={() => applyCoupon('')} className="flex items-center gap-1 text-xs font-semibold text-slate-600 hover:text-red-600 transition-colors shrink-0">
                        <X size={13} /> Quitar
                      </button>
                    </div>
                  ) : (
                    <div className="flex items-center gap-2">
                      <input
                        id="charge-coupon"
                        maxLength={40}
                        placeholder="Código que trae el cliente"
                        className={`${inputClass} uppercase placeholder:normal-case`}
                        value={couponInput}
                        onChange={(e) => { setCouponInput(e.target.value.toUpperCase().replace(/\s/g, '')); setCouponError(''); }}
                        onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); if (couponInput) applyCoupon(couponInput); } }}
                      />
                      <button type="button" data-coupon-apply disabled={!couponInput || checkingCoupon} onClick={() => applyCoupon(couponInput)} className="shrink-0 px-4 py-2.5 text-sm font-semibold border border-slate-200 rounded-lg text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-50">
                        {checkingCoupon ? 'Revisando...' : 'Aplicar'}
                      </button>
                    </div>
                  )}
                  {couponError && <p role="alert" data-coupon-error className="text-xs text-red-600 mt-1.5">{couponError}</p>}
                </div>
              )}

              {completes ? docFields : (
                <p className="text-xs text-slate-500">El comprobante se emite con el pago que completa el total.</p>
              )}

              <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <button type="button" onClick={onClose} className="px-4 py-2.5 text-sm font-semibold text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors">
                  Cerrar
                </button>
                <button type="submit" data-pay disabled={saving || cashClosed || amountNumber <= 0} className="px-5 py-2.5 text-sm font-bold bg-[#022340] text-white rounded-xl hover:bg-[#03315a] shadow-sm transition-colors disabled:opacity-50">
                  {saving ? 'Registrando...' : `Cobrar ${money(amountNumber, context?.sale?.currency ?? context?.preview.currency)}`}
                </button>
              </div>
            </form>
          )}

          {sale && sale.payments.length > 0 && (
            <section className="rounded-2xl border border-slate-200 overflow-hidden flex flex-col" data-payments>
              <div className="px-5 py-3 bg-slate-50/80 border-b border-slate-100">
                <span className={sectionTitle}>Pagos registrados</span>
              </div>
              <PagedList<SalePayment>
                items={sale.payments}
                empty="Sin pagos."
                renderRow={(payment) => (
                  <li key={payment.id} className="flex items-center gap-3 px-5 py-3">
                    <span className="p-2 rounded-lg bg-[#84D0D9]/20 text-[#022340] shrink-0">{METHOD_ICONS[payment.method] ?? <Wallet size={16} />}</span>
                    <span className="flex-1 min-w-0">
                      <span className="block text-sm font-semibold text-slate-800">{payment.method_label}{payment.reference ? ` · ${payment.reference}` : ''}</span>
                      <span className="block text-xs text-slate-500 truncate">
                        {momentOf(payment.at)}{payment.by ? ` · ${payment.by}` : ''}{payment.register ? ` · ${payment.register}` : ''}{payment.change > 0 ? ` · vuelto ${money(payment.change, context?.sale?.currency ?? context?.preview.currency)}` : ''}
                      </span>
                    </span>
                    <span className="text-sm font-bold text-slate-800 tabular-nums">{money(payment.amount, context?.sale?.currency ?? context?.preview.currency)}</span>
                  </li>
                )}
              />
            </section>
          )}

          {invoice && (
            <section className="rounded-2xl border border-slate-200 overflow-hidden" data-invoice={invoice.number}>
              <div className="flex items-center justify-between gap-3 px-5 py-3 bg-[#022340] text-white">
                <span className="flex items-center gap-2 text-sm font-bold"><Receipt size={16} className="text-[#84D0D9]" /> {invoice.doc_type_label}</span>
                <span className="text-sm font-black tabular-nums">{invoice.number}</span>
              </div>

              <div className="px-5 py-4 space-y-3 text-sm">
                <div>
                  <span className="block font-bold text-slate-800">{context.issuer.name}</span>
                  <span className="block text-xs text-slate-500">
                    {[context.issuer.tax_id ? `RUC ${context.issuer.tax_id}` : 'RUC sin registrar', context.issuer.address].filter(Boolean).join(' · ')}
                  </span>
                </div>

                <dl className="grid grid-cols-2 gap-x-4 gap-y-2">
                  <div>
                    <dt className={sectionTitle}>Cliente</dt>
                    <dd className="font-semibold text-slate-800">{invoice.customer_name ?? 'Cliente varios'}</dd>
                  </div>
                  <div>
                    <dt className={sectionTitle}>{invoice.doc_type === 'factura' ? 'RUC' : 'Documento'}</dt>
                    <dd className="font-semibold text-slate-800">{invoice.customer_doc_number ?? '—'}</dd>
                  </div>
                  <div>
                    <dt className={sectionTitle}>Emitido</dt>
                    <dd className="font-semibold text-slate-800">{momentOf(invoice.issued_at)}</dd>
                  </div>
                  <div>
                    <dt className={sectionTitle}>Total</dt>
                    <dd className="font-semibold text-slate-800">{money(invoice.total, context?.sale?.currency ?? context?.preview.currency)} <span className="font-normal text-slate-500">(IGV {money(invoice.tax, context?.sale?.currency ?? context?.preview.currency)})</span></dd>
                  </div>
                </dl>

                <div className={`flex items-start gap-2 rounded-lg px-3 py-2 text-xs ${
                  invoice.sunat_status === 'rejected' ? 'bg-rose-50 text-rose-700' : invoice.sunat_status === 'pending' ? 'bg-amber-50 text-amber-800' : 'bg-emerald-50 text-emerald-700'
                }`} data-sunat={invoice.sunat_status}>
                  <BadgeCheck size={15} className="shrink-0 mt-0.5" />
                  <span>
                    <span className="block font-semibold">{invoice.simulated ? 'Simulación' : SUNAT_LABELS[invoice.sunat_status]}</span>
                    {invoice.simulated
                      ? 'Este comprobante no se envió a SUNAT: tu empresa aún no conecta su proveedor de facturación electrónica.'
                      : invoice.sunat_message}
                  </span>
                </div>
              </div>
            </section>
          )}

          {paidSale && !paidSale.invoice && (
            <section className="rounded-2xl border border-amber-200 bg-amber-50/60 p-5 space-y-4">
              <p className="text-sm text-amber-800">Esta venta está pagada pero aún no tiene comprobante.</p>
              {docFields}
              <div className="flex justify-end">
                <button type="button" data-issue disabled={saving} onClick={issueInvoice} className="px-5 py-2.5 text-sm font-bold bg-[#022340] text-white rounded-xl hover:bg-[#03315a] shadow-sm transition-colors disabled:opacity-50">
                  {saving ? 'Emitiendo...' : 'Emitir comprobante'}
                </button>
              </div>
            </section>
          )}
        </div>
      )}
    </FormDrawer>
  );
}
