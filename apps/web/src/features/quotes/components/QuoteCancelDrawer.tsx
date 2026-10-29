'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { Ban } from 'lucide-react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Button from '@/app/components/common/Button';
import OptionCard from '@/app/components/common/OptionCard';
import Select from '@/app/components/common/Select';
import { ErrorAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { cleanAmount, money } from '@/features/sales/sales';
import { useAuth } from '@/hooks/useAuth';
import type { QuoteCancelPreview, QuoteRow, QuotesBoardData } from '../quotes';

interface Props {
  quote: QuoteRow | null;
  onClose: () => void;
  onDone: (data: QuotesBoardData, message: string) => void;
}

export default function QuoteCancelDrawer({ quote, onClose, onDone }: Props) {
  const { can } = useAuth();
  const [preview, setPreview] = useState<QuoteCancelPreview | null>(null);
  const [reason, setReason] = useState('');
  const [refund, setRefund] = useState('');
  const [method, setMethod] = useState('cash');
  const [cashSession, setCashSession] = useState('');
  const [debtAction, setDebtAction] = useState<'charge' | 'forgive'>('charge');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!quote) return;

    setPreview(null);
    setReason('');
    setError('');
    setDebtAction('charge');
    api.get<QuoteCancelPreview>(`/quotes/${quote.id}/cancel`)
      .then((res) => {
        setPreview(res.data);
        setRefund(res.data.refund_max > 0 && res.data.can_refund ? res.data.refund_max.toFixed(2) : '');
        setMethod(res.data.methods[0]?.value ?? 'cash');
        setCashSession(res.data.cash[0]?.id ?? '');
      })
      .catch((err) => setError(apiError(err, 'No se pudo calcular la anulación.')));
  }, [quote]);

  if (!quote) return null;

  const currency = preview?.currency;
  const canRefund = Boolean(preview && preview.refund > 0 && preview.can_refund && !preview.refund_blocked && preview.refund_max > 0);
  const refundValue = Number(refund || 0);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!preview) return;

    if (!reason.trim()) {
      setError('Escribe por qué se anula el presupuesto.');
      return;
    }

    if (canRefund && refundValue > preview.refund_max + 0.001) {
      setError(`La devolución no puede pasar de ${money(preview.refund_max, currency)}.`);
      return;
    }

    try {
      setBusy(true);
      setError('');
      const res = await api.post<QuotesBoardData>(`/quotes/${quote.id}/cancel`, {
        reason: reason.trim(),
        ...(canRefund && refundValue > 0 ? { refund_amount: refundValue, method, cash_session_id: method === 'cash' ? cashSession || null : null } : {}),
        ...(preview.debt > 0 ? { debt_action: debtAction } : {}),
      });
      onDone(res.data, res.data.cancel_result?.message ?? `Presupuesto ${quote.code} anulado.`);
    } catch (err) {
      setError(apiError(err, 'No se pudo anular el presupuesto.'));
    } finally {
      setBusy(false);
    }
  };

  const row = (label: string, value: string, strong = false) => (
    <div className="flex items-center justify-between text-sm">
      <span className="text-slate-600">{label}</span>
      <span className={`tabular-nums ${strong ? 'font-black text-brand-ink' : 'font-semibold text-slate-800'}`}>{value}</span>
    </div>
  );

  return (
    <FormDrawer
      isOpen={Boolean(quote)}
      onClose={onClose}
      title={`Anular ${quote.code}`}
      subtitle={`${quote.customer_name} · ${quote.title || 'Plan de tratamiento'}`}
      icon={<Ban size={20} />}
    >
      <form onSubmit={submit} className="space-y-5 pb-4" data-quote-cancel>
        <ErrorAlert message={error} />

        {!preview && !error && <p className="text-sm text-slate-500">Calculando…</p>}

        {preview && (
          <>
            <section className="rounded-2xl border border-slate-200 p-4 space-y-2 bg-slate-50/60">
              {row('Sesiones usadas', `${preview.used_sessions} de ${preview.total_sessions}`)}
              {preview.policy !== 'none' && row('Valor de lo usado', money(preview.used_value, currency))}
              {row('Pagado', money(preview.paid, currency))}
              {preview.refund > 0 && row('Se le devuelve', money(preview.refund, currency), true)}
              {preview.debt > 0 && row('Falta por cobrar', money(preview.debt, currency), true)}
              <p className="text-xs text-slate-600 pt-2 border-t border-slate-200/70" data-cancel-explain>{preview.explain}</p>
              <p className="text-[11px] text-slate-500">
                Regla de tu empresa: {preview.policy_label}.
                {can('settings.view') && <> <Link href="/settings#presupuestos" className="font-semibold text-brand-accent hover:underline">Cambiarla</Link></>}
              </p>
            </section>

            <div>
              <label className={labelClass} htmlFor="quote-cancel-reason">¿Por qué se anula? *</label>
              <input id="quote-cancel-reason" className={inputClass} maxLength={200} placeholder="Ej. se mudó de ciudad" value={reason} onChange={(e) => setReason(e.target.value)} />
            </div>

            {preview.refund > 0 && (
              <section className="space-y-3">
                <span className={labelClass}>Devolución</span>
                {preview.refund_blocked && <p className="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">{preview.refund_blocked} Puedes anular sin devolver dinero.</p>}
                {!preview.refund_blocked && !preview.can_refund && (
                  <p className="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">Para devolver dinero necesitas el permiso de Ventas &quot;Anular y devolver&quot;. Puedes anular sin devolución o pedírselo a un administrador.</p>
                )}
                {canRefund && (
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className={labelClass} htmlFor="quote-cancel-refund">Monto a devolver</label>
                      <input id="quote-cancel-refund" inputMode="decimal" className={inputClass} value={refund} onChange={(e) => setRefund(cleanAmount(e.target.value))} />
                      <span className="block text-[11px] text-slate-500 mt-1">Hasta {money(preview.refund_max, currency)}. Puedes devolver menos.</span>
                    </div>
                    <Select
                      label="Cómo se devuelve"
                      clearable={false}
                      value={method}
                      onChange={(e) => setMethod(String(e.target.value))}
                      options={preview.methods.map((m) => ({ value: m.value, label: m.label }))}
                    />
                    {method === 'cash' && preview.cash.length > 1 && (
                      <div className="col-span-2">
                        <Select
                          label="Caja"
                          clearable={false}
                          value={cashSession}
                          onChange={(e) => setCashSession(String(e.target.value))}
                          options={preview.cash.map((c) => ({ value: c.id, label: c.register_name }))}
                        />
                      </div>
                    )}
                  </div>
                )}
              </section>
            )}

            {preview.debt > 0 && (
              <section className="space-y-2">
                <span className={labelClass}>El cliente usó más de lo que pagó</span>
                <div className="grid grid-cols-1 gap-2" role="radiogroup" aria-label="Qué hacer con lo que falta">
                  {([
                    ['charge', `Cobrarle los ${money(preview.debt, currency)} que faltan`, `Las cuotas que faltaban se anulan y queda una sola por ${money(preview.debt, currency)}, que se cobra desde este presupuesto.`],
                    ['forgive', 'No cobrarle el resto', `El presupuesto se cierra con lo que ya pagó (${money(preview.paid, currency)}).`],
                  ] as const).map(([id, title, hint]) => (
                    <OptionCard
                      key={id}
                      type="button"
                      role="radio"
                      aria-checked={debtAction === id}
                      data-debt-action={id}
                      selected={debtAction === id}
                      onClick={() => setDebtAction(id)}
                      className="text-left rounded-xl border px-4 py-3 transition-colors"
                    >
                      <span className="block text-sm font-bold text-brand-ink">{title}</span>
                      <span className="block text-xs text-slate-500 mt-0.5">{hint}</span>
                    </OptionCard>
                  ))}
                </div>
              </section>
            )}

            <p className="text-xs text-slate-500">
              El plan de tratamiento se anula: las sesiones que faltaban ya no se pueden usar y las citas agendadas con él que aún no se atendieron se cancelan.
            </p>

            <div className="flex justify-end gap-3 pt-2 border-t border-slate-100">
              <Button type="button" variant="secondary" onClick={onClose}>Volver</Button>
              <Button type="submit" variant="primary" disabled={busy}>{busy ? 'Anulando…' : 'Anular presupuesto'}</Button>
            </div>
          </>
        )}
      </form>
    </FormDrawer>
  );
}
