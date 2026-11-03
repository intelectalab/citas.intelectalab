'use client';

import Button from '@/app/components/common/Button';
import Badge from '@/app/components/common/Badge';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { BadgeCheck, Banknote, Boxes, Copy, Gift, Mail, CreditCard, ExternalLink, FileText, Landmark, Link2, Receipt, RefreshCw, Smartphone, Sparkles, TicketPercent, Wallet, X } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Select from '@/app/components/common/Select';
import { PagedList } from '@/app/components/common/Pager';
import { apiError } from '@/features/appointments/appointments';
import { cleanAmount, money, momentOf, SALE_BADGES, SUNAT_LABELS, type ChargeContext, type SalePayment } from '../sales';
import { outcomeOf } from '@/features/messages/messages';

interface ChargePanelProps {
  appointmentId: string | null;
  saleId?: string | null;
  initialAmount?: number;
  appointmentCode?: string;
  onClose: () => void;
  onChanged?: (context: ChargeContext) => void;
}

const sectionTitle = 'text-[11px] font-bold text-slate-400 uppercase tracking-wider';

const METHOD_ICONS: Record<string, React.ReactNode> = {
  cash: <Banknote size={16} />,
  card: <CreditCard size={16} />,
  transfer: <Landmark size={16} />,
  yape: <Smartphone size={16} />,
  plin: <Smartphone size={16} />,
  gift_card: <Gift size={16} />,
  points: <Sparkles size={16} />,
  link: <Link2 size={16} />,
  deposit: <Link2 size={16} />,
  gateway: <Link2 size={16} />,
};

const STATUS_BADGES: Record<string, string> = {
  pending: 'bg-amber-50 text-amber-700',
  partial: 'bg-sky-50 text-sky-700',
  paid: 'bg-emerald-50 text-emerald-700',
};

const chip = (active: boolean) =>
  `flex items-center gap-1.5 px-3.5 py-2 text-sm font-medium rounded-lg border transition-colors ${
    active ? 'bg-brand text-white border-brand' : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'
  }`;

const newKey = () => (typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`);

export default function ChargePanel({ appointmentId, saleId = null, initialAmount, appointmentCode, onClose, onChanged }: ChargePanelProps) {
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
  const [copied, setCopied] = useState(false);

  const balanceOf = (data: ChargeContext) => (data.sale ? data.sale.balance : data.preview.total);

  const apply = (data: ChargeContext) => {
    setContext(data);
    setAmount((initialAmount && initialAmount > 0 ? Math.min(initialAmount, balanceOf(data)) : balanceOf(data)).toFixed(2));
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
  const isGiftCard = method === 'gift_card';
  const isPoints = method === 'points';
  const loyalty = context?.loyalty ?? null;
  const pointsInfo = loyalty?.points ?? null;
  const needsReference = isGiftCard || (context?.methods.find((m) => m.value === method)?.needs_reference ?? false);
  const allowPartial = Boolean(context?.partial_payments || isGiftCard || isPoints);
  const cashClosed = Boolean(context && isCash && context.cash.required && context.cash.sessions.length === 0);
  const amountNumber = Number(amount || 0);
  const pointsNeeded = isPoints && pointsInfo && pointsInfo.point_value > 0 ? Math.ceil(amountNumber / pointsInfo.point_value) : 0;
  const change = isCash && received ? Math.max(0, Number(received) - amountNumber) : 0;
  const completes = amountNumber >= balance - 0.001;
  const appliedCoupon = sale ? sale.coupon : context?.coupon ?? null;
  const links = context?.links;
  const currentLink = links?.current ?? null;
  const pendingLink = currentLink?.status === 'pending' ? currentLink : null;
  const isLink = method === 'link';
  const qr = method === 'yape' || method === 'plin' ? context?.qr?.[method] ?? null : null;

  useEffect(() => {
    if (!base || !pendingLink) return;

    const timer = setInterval(() => {
      api.get(contextUrl).then((res) => {
        const data: ChargeContext = res.data;
        setContext(data);
        if (data.sale?.status !== context?.sale?.status) onChanged?.(data);
        if (data.sale?.status === 'paid') setNotice(`Pago recibido por link: la venta ${data.sale.code} quedó pagada.`);
      }).catch(() => undefined);
    }, 10000);

    return () => clearInterval(timer);
  }, [base, contextUrl, pendingLink, context?.sale?.status, onChanged]);

  const createLink = async () => {
    if (!base) return;

    try {
      setSaving(true);
      setError('');
      setNotice('');
      if (appointmentId) {
        await api.post(`/appointments/${appointmentId}/link`, { coupon_code: !sale && couponCode ? couponCode : null });
      } else {
        await api.post(`/sales/${saleId}/links`);
      }
      const res = await api.get(contextUrl);
      apply(res.data);
      setMethod('link');
      onChanged?.(res.data);
    } catch (err) {
      setError(apiError(err, 'No se pudo crear el link de pago.'));
    } finally {
      setSaving(false);
    }
  };

  const cancelLink = async () => {
    if (!pendingLink) return;

    try {
      setSaving(true);
      setError('');
      await api.post(`/payment-links/${pendingLink.id}/cancel`);
      const res = await api.get(contextUrl);
      apply(res.data);
      setMethod('link');
    } catch (err) {
      setError(apiError(err, 'No se pudo anular el link.'));
    } finally {
      setSaving(false);
    }
  };

  const refreshLink = async () => {
    try {
      const res = await api.get(contextUrl);
      setContext(res.data);
      if (res.data.sale?.status === 'paid') {
        setNotice(`Pago recibido por link: la venta ${res.data.sale.code} quedó pagada.`);
        onChanged?.(res.data);
      } else {
        setNotice('Todavía no llega el pago.');
      }
    } catch (err) {
      setError(apiError(err, 'No se pudo revisar el pago.'));
    }
  };

  const emailLink = async () => {
    if (!pendingLink) return;
    try {
      setSaving(true);
      setError('');
      setNotice('');
      const res = await api.post(`/payment-links/${pendingLink.id}/send`);
      const outcome = outcomeOf(res.data, 'el link de pago');
      if (outcome.ok) setNotice(outcome.text); else setError(outcome.text);
    } catch (err) {
      setError(apiError(err, 'No se pudo enviar el link por correo.'));
    } finally {
      setSaving(false);
    }
  };

  const copyLink = async () => {
    if (!pendingLink) return;
    try {
      await navigator.clipboard.writeText(pendingLink.url);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      setError('No se pudo copiar: selecciona el link y cópialo a mano.');
    }
  };

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

  const applyPackage = async (packageId: string | null) => {
    if (!appointmentId) return;

    try {
      setSaving(true);
      setError('');
      setNotice('');
      const res = packageId
        ? await api.post(`/appointments/${appointmentId}/package`, { customer_package_id: packageId })
        : await api.delete(`/appointments/${appointmentId}/package`);
      apply(res.data);
      setNotice(packageId ? `Listo: esta cita se paga con el paquete ${res.data.package?.current?.code ?? ''}.` : 'La cita ya no usa el paquete: se cobra aparte.');
      onChanged?.(res.data);
    } catch (err) {
      setError(apiError(err, packageId ? 'No se pudo usar el paquete.' : 'No se pudo quitar el paquete.'));
    } finally {
      setSaving(false);
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
              <Badge data-sale-status={sale?.status ?? 'none'} tone={SALE_BADGES[sale?.status ?? 'pending']} bold>
                {sale?.status_label ?? 'Por cobrar'}
              </Badge>
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
                <span className="font-bold text-brand-ink">Total</span>
                <span className="text-xl font-black text-brand-ink tabular-nums" data-total>{money(totals.total, context?.sale?.currency ?? context?.preview.currency)}</span>
              </div>

              {sale && sale.paid > 0 && !isPaid && (
                <div className="flex items-center justify-between text-sm">
                  <span className="text-slate-500">Pagado {money(sale.paid, context?.sale?.currency ?? context?.preview.currency)}</span>
                  <span className="font-bold text-amber-700" data-balance>Saldo {money(sale.balance, context?.sale?.currency ?? context?.preview.currency)}</span>
                </div>
              )}

              {pointsInfo && pointsInfo.will_earn > 0 && !isPaid && (
                <div className="flex items-center justify-between pt-2 border-t border-slate-100 text-xs text-brand-accent">
                  <span className="flex items-center gap-1.5 font-semibold"><Sparkles size={13} /> Puntos que sumará al completar el pago</span>
                  <span className="font-bold">+{pointsInfo.will_earn} pts (disp. {pointsInfo.balance} pts)</span>
                </div>
              )}
            </div>
          </section>

          {appointmentId && context.package?.current && (
            <section className="rounded-2xl border border-brand-soft bg-brand-soft/10 px-5 py-4 space-y-2" data-package-current={context.package.current.code}>
              <span className="flex items-center gap-2 text-sm font-bold text-brand-ink"><Boxes size={16} /> Se paga con el paquete {context.package.current.code}</span>
              <span className="block text-sm text-slate-700">
                {context.package.current.name}.{' '}
                {context.package.current.consumed
                  ? `Sesión ya descontada: ${context.package.current.usage_label?.toLowerCase()}.`
                  : 'La sesión se descuenta sola al darla por atendida.'}
              </span>
              {!context.package.current.consumed && (
                <span className="block text-xs text-slate-500">
                  {context.package.current.late_policy === 'lose' ? 'Si cancela tarde o no llega, la sesión se pierde. Si avisa a tiempo, vuelve al paquete.' : 'Si no llega o cancela, la sesión vuelve al paquete.'}
                </span>
              )}
              <div className="flex flex-wrap items-center gap-3 pt-1">
                <Link href={`/packages/${context.package.current.id}`} className="text-xs font-bold text-brand-accent underline underline-offset-2">Ver el paquete</Link>
                {!context.package.current.consumed && context.package.can_use && (
                  <button type="button" data-package-release disabled={saving} onClick={() => applyPackage(null)} className="text-xs font-bold text-slate-600 hover:text-red-600 transition-colors">
                    Quitar del paquete y cobrar aparte
                  </button>
                )}
              </div>
            </section>
          )}

          {appointmentId && !context.package?.current && (context.package?.usable.length ?? 0) > 0 && canPay && !(sale && sale.paid > 0) && (
            <section className="rounded-2xl border border-emerald-200 bg-emerald-50/60 px-5 py-4 space-y-3" data-package-usable>
              <span className="flex items-center gap-2 text-sm font-bold text-emerald-900"><Boxes size={16} /> {context.customer?.name ?? 'El cliente'} tiene un paquete con sesiones de este servicio</span>
              {context.package!.usable.map((pkg) => (
                <div key={pkg.id} className="flex items-center justify-between gap-3 rounded-xl bg-white border border-emerald-100 px-3.5 py-2.5">
                  <div className="min-w-0">
                    <span className="block text-sm font-semibold text-slate-800">{pkg.name}</span>
                    <span className="block text-xs text-slate-500">{pkg.code} · quedan {pkg.sessions_available} sin fecha de {pkg.sessions_total} · vence en {pkg.days_left} días</span>
                  </div>
                  {context.package!.can_use && (
                    <button type="button" data-package-use={pkg.code} disabled={saving} onClick={() => applyPackage(pkg.id)} className="shrink-0 px-3.5 py-2 text-xs font-bold bg-brand text-white rounded-lg hover:bg-brand-hover transition-colors disabled:opacity-50">
                      Usar paquete
                    </button>
                  )}
                </div>
              ))}
              <p className="text-xs text-emerald-800">Si usa el paquete, esta cita no se cobra: la sesión se descuenta al darla por atendida.</p>
            </section>
          )}

          <ErrorAlert message={error} />
          <SuccessAlert message={notice} />

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
                    <button key={option.value} type="button" role="radio" aria-checked={method === option.value} data-method={option.value} onClick={() => { setMethod(option.value); setAmount((initialAmount && initialAmount > 0 ? Math.min(initialAmount, balance) : balance).toFixed(2)); setError(''); }} className={chip(method === option.value)}>
                      {METHOD_ICONS[option.value]} {option.label}
                    </button>
                  ))}
                  {loyalty?.gift_cards_enabled && (
                    <button
                      type="button"
                      role="radio"
                      aria-checked={isGiftCard}
                      data-method="gift_card"
                      onClick={() => {
                        setMethod('gift_card');
                        setError('');
                        const firstCard = loyalty.customer_gift_cards[0];
                        if (firstCard) {
                          setReference(firstCard.code);
                          setAmount(Math.min(balance, firstCard.balance).toFixed(2));
                        } else {
                          setReference('');
                          setAmount(balance.toFixed(2));
                        }
                      }}
                      className={chip(isGiftCard)}
                    >
                      <Gift size={16} /> Gift card
                    </button>
                  )}
                  {loyalty?.points_enabled && pointsInfo?.can_redeem && (
                    <button
                      type="button"
                      role="radio"
                      aria-checked={isPoints}
                      data-method="points"
                      onClick={() => {
                        setMethod('points');
                        setError('');
                        setAmount(Math.min(balance, pointsInfo.max_amount).toFixed(2));
                      }}
                      className={chip(isPoints)}
                    >
                      <Sparkles size={16} /> Puntos ({pointsInfo.balance} pts)
                    </button>
                  )}
                  {links?.ready && (
                    <button type="button" role="radio" aria-checked={isLink} data-method="link" onClick={() => { setMethod('link'); setError(''); }} className={chip(isLink)}>
                      <Link2 size={16} /> Link de pago
                    </button>
                  )}
                </div>
              </div>

              {pendingLink && !isLink && (
                <p data-link-pending-note className="text-sm text-brand-ink bg-brand-soft/15 border border-brand-soft/50 rounded-lg px-3 py-2">
                  Hay un link de pago esperando: si cobras aquí, ese link se anula para que no se pague dos veces.
                </p>
              )}

              {isLink && (
                <div className="space-y-3" data-link-box>
                  {pendingLink ? (
                    <div className="rounded-xl border border-brand-soft bg-brand-soft/10 p-4 space-y-3">
                      <div className="flex items-center justify-between gap-3">
                        <span className="text-sm font-bold text-brand-ink">{pendingLink.status_label} · {money(pendingLink.amount, pendingLink.currency)}</span>
                        <span className="text-xs text-slate-500">Vence {momentOf(pendingLink.expires_at)}</span>
                      </div>
                      <input readOnly aria-label="Link de pago" data-link-url value={pendingLink.url} onFocus={(e) => e.target.select()} className={`${inputClass} font-mono text-xs`} />
                      <div className="flex flex-wrap gap-2">
                        <button type="button" data-link-copy onClick={copyLink} className="flex items-center gap-1.5 px-3 py-2 text-sm font-semibold bg-brand text-white rounded-lg hover:bg-brand-hover transition-colors">
                          <Copy size={14} /> {copied ? '¡Copiado!' : 'Copiar'}
                        </button>
                        <Link href={new URL(pendingLink.url).pathname} target="_blank" rel="noreferrer" data-link-open className="flex items-center gap-1.5 px-3 py-2 text-sm font-semibold border border-slate-200 bg-white rounded-lg text-slate-700 hover:bg-slate-50 transition-colors">
                          <ExternalLink size={14} /> Abrir
                        </Link>
                        <button type="button" data-link-email disabled={saving} onClick={emailLink} className="flex items-center gap-1.5 px-3 py-2 text-sm font-semibold border border-slate-200 bg-white rounded-lg text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-50">
                          <Mail size={14} /> Enviar por correo
                        </button>
                        <button type="button" data-link-refresh onClick={refreshLink} className="flex items-center gap-1.5 px-3 py-2 text-sm font-semibold border border-slate-200 bg-white rounded-lg text-slate-700 hover:bg-slate-50 transition-colors">
                          <RefreshCw size={14} /> ¿Ya pagó?
                        </button>
                        <button type="button" data-link-cancel disabled={saving} onClick={cancelLink} className="ml-auto px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50 rounded-lg transition-colors">
                          Anular link
                        </button>
                      </div>
                      <p className="text-xs text-slate-500">
                        Envíalo por correo con el botón, o cópialo y mándalo por WhatsApp. Cuando pague, la venta pasa a pagada sola y se emite el comprobante.
                        {pendingLink.simulated ? ' Es un link de prueba: al abrirlo verás botones para simular el pago.' : ''}
                      </p>
                    </div>
                  ) : (
                    <div className="rounded-xl border border-slate-200 p-4 space-y-3">
                      {currentLink && (
                        <p className="text-xs text-slate-500" data-link-previous>
                          El link anterior quedó {currentLink.status_label.toLowerCase()}{currentLink.cancel_reason ? `: ${currentLink.cancel_reason}` : '.'}
                        </p>
                      )}
                      <p className="text-sm text-slate-600">
                        Se crea un link para pagar {money(balance, context?.sale?.currency ?? context?.preview.currency)} en línea, con tarjeta u otro medio que acepte la pasarela. Vence en {links?.hours ?? 24} horas.
                      </p>
                      {links?.simulated && <p className="text-xs text-amber-700">Tu empresa usa la pasarela simulada: el link sirve para probar, no cobra dinero.</p>}
                      <button type="button" data-link-create disabled={saving} onClick={createLink} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover shadow-sm transition-colors disabled:opacity-50">
                        <Link2 size={15} /> {saving ? 'Creando...' : 'Crear link de pago'}
                      </button>
                    </div>
                  )}
                </div>
              )}

              {qr && (
                <div className="flex items-start gap-4 rounded-xl border border-slate-200 p-4" data-qr={method}>
                  <img src={qr.image} alt={`QR de ${method === 'yape' ? 'Yape' : 'Plin'}`} className="w-32 h-32 object-contain rounded-lg border border-slate-100 bg-white" />
                  <div className="text-sm text-slate-600 space-y-1">
                    <span className="block font-bold text-brand-ink">Que el cliente escanee este QR</span>
                    {qr.holder && <span className="block text-xs">A nombre de {qr.holder}</span>}
                    <span className="block text-xs">Debe pagar {money(amountNumber, context?.sale?.currency ?? context?.preview.currency)}. Luego escribe el número de operación que le aparece.</span>
                  </div>
                </div>
              )}

              {cashClosed && (
                <p data-cash-closed className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                  La caja de esta sede está cerrada: no se puede cobrar en efectivo.{' '}
                  <Link href="/cash" className="font-semibold underline underline-offset-2 hover:text-brand-ink">Abrir caja</Link>
                </p>
              )}

              {isGiftCard && loyalty && loyalty.customer_gift_cards.length > 0 && (
                <div className="rounded-xl border border-brand-soft bg-brand-soft/10 p-3.5 space-y-2">
                  <span className="block text-xs font-bold text-brand-ink">Gift cards vigentes del cliente:</span>
                  <div className="flex flex-wrap gap-2">
                    {loyalty.customer_gift_cards.map((gc) => {
                      const activeCard = reference.toUpperCase() === gc.code.toUpperCase();
                      return (
                        <button
                          key={gc.id}
                          type="button"
                          onClick={() => {
                            setReference(gc.code);
                            setAmount(Math.min(balance, gc.balance).toFixed(2));
                          }}
                          className={`px-3 py-1.5 rounded-lg border text-xs font-semibold transition-colors ${
                            activeCard ? 'bg-brand text-white border-brand' : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'
                          }`}
                        >
                          {gc.code} · saldo {money(gc.balance, context?.sale?.currency ?? context?.preview.currency)}
                        </button>
                      );
                    })}
                  </div>
                </div>
              )}

              {isPoints && pointsInfo && (
                <div className="rounded-xl border border-brand-soft bg-brand-soft/10 p-3.5 space-y-1 text-xs text-slate-700">
                  <div className="flex items-center justify-between font-bold text-brand-ink">
                    <span>Canje de puntos de fidelización</span>
                    <span>{pointsInfo.balance} pts disponibles ({money(pointsInfo.redeemable_value, context?.sale?.currency ?? context?.preview.currency)})</span>
                  </div>
                  <p className="text-slate-600">
                    Se descontarán <strong className="text-brand-ink">{pointsNeeded} puntos</strong> ({money(pointsInfo.point_value, context?.sale?.currency ?? context?.preview.currency)} por punto). Si no cubre el total, podrás cobrar el resto con otro medio.
                  </p>
                </div>
              )}

              {!isLink && <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className={labelClass} htmlFor="charge-amount">Monto a cobrar *</label>
                  <input
                    id="charge-amount"
                    inputMode="decimal"
                    required
                    readOnly={!allowPartial}
                    className={`${inputClass} ${allowPartial ? '' : 'bg-slate-50 text-slate-600'}`}
                    value={amount}
                    onChange={(e) => setAmount(cleanAmount(e.target.value))}
                  />
                  <p className="text-xs text-slate-500 mt-1.5">
                    {allowPartial ? `Saldo: ${money(balance, context?.sale?.currency ?? context?.preview.currency)}. Puedes cobrar una parte.` : 'Tu empresa cobra el total de una vez.'}
                  </p>
                </div>

                {isCash ? (
                  <div>
                    <label className={labelClass} htmlFor="charge-received">Recibido</label>
                    <input id="charge-received" inputMode="decimal" placeholder={amount || '0.00'} className={inputClass} value={received} onChange={(e) => setReceived(cleanAmount(e.target.value))} />
                    <p className="text-xs mt-1.5 font-semibold text-brand-accent" data-change>Vuelto: {money(change, context?.sale?.currency ?? context?.preview.currency)}</p>
                  </div>
                ) : isPoints ? (
                  <div>
                    <span className={labelClass}>Puntos a descontar</span>
                    <div className={`${inputClass} bg-slate-50 text-slate-700 font-semibold flex items-center justify-between`}>
                      <span>{pointsNeeded} pts</span>
                      <span className="text-xs text-slate-500">Quedan {Math.max(0, (pointsInfo?.balance ?? 0) - pointsNeeded)} pts</span>
                    </div>
                  </div>
                ) : (
                  <div>
                    <label className={labelClass} htmlFor="charge-reference">{isGiftCard ? 'Código de gift card *' : 'N.º de operación *'}</label>
                    <input
                      id="charge-reference"
                      required
                      maxLength={190}
                      placeholder={isGiftCard ? 'Ej. GC-ABCD12' : method === 'card' ? 'Voucher del POS' : 'Código de la operación'}
                      className={`${inputClass} ${isGiftCard ? 'uppercase font-mono' : ''}`}
                      value={reference}
                      onChange={(e) => setReference(isGiftCard ? e.target.value.toUpperCase().replace(/\s/g, '') : e.target.value)}
                    />
                  </div>
                )}
              </div>}

              {!isLink && isCash && context.cash.sessions.length > 1 && (
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

              {isLink ? null : completes ? docFields : (
                <p className="text-xs text-slate-500">El comprobante se emite con el pago que completa el total.</p>
              )}

              <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <Button type="button" onClick={onClose} variant="secondary">
                  Cerrar
                </Button>
                {!isLink && <Button type="submit" data-pay disabled={saving || cashClosed || amountNumber <= 0} variant="primary">
                  {saving ? 'Registrando...' : `Cobrar ${money(amountNumber, context?.sale?.currency ?? context?.preview.currency)}`}
                </Button>}
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
                    <span className="p-2 rounded-lg bg-brand-soft/20 text-brand-ink shrink-0">{METHOD_ICONS[payment.method] ?? <Wallet size={16} />}</span>
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
              <div className="flex items-center justify-between gap-3 px-5 py-3 bg-brand text-white">
                <span className="flex items-center gap-2 text-sm font-bold"><Receipt size={16} className="text-brand-soft" /> {invoice.doc_type_label}</span>
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
                <Button type="button" data-issue disabled={saving} onClick={issueInvoice} variant="primary">
                  {saving ? 'Emitiendo...' : 'Emitir comprobante'}
                </Button>
              </div>
            </section>
          )}
        </div>
      )}
    </FormDrawer>
  );
}
