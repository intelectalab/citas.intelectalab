'use client';

import { useState } from 'react';
import {
  Ban,
  Boxes,
  CalendarPlus,
  Check,
  CheckCircle2,
  Download,
  Edit2,
  FileText,
  MinusCircle,
  Send,
  Wallet,
  XCircle,
} from 'lucide-react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Button from '@/app/components/common/Button';
import Badge from '@/app/components/common/Badge';
import { ErrorAlert } from '@/app/components/common/Alert';
import { inputClass } from '@/app/components/ui/forms/styles';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { money, momentOf, SALE_BADGES } from '@/features/sales/sales';
import { TEETH_ROWS } from '@/features/records/records';
import { useAuth } from '@/hooks/useAuth';

const STEPS = ['Borrador', 'Enviado', 'Aprobado', 'En tratamiento', 'Terminado'];

const stepOf = (quote: { status: string; sessions_total: number; sessions_used: number }): number => {
  if (quote.status === 'draft') return 0;
  if (quote.status === 'sent') return 1;
  if (quote.status !== 'accepted') return -1;
  if (quote.sessions_total > 0 && quote.sessions_used >= quote.sessions_total) return 4;
  if (quote.sessions_used > 0) return 3;
  return 2;
};

const ALL_TEETH = new Set(TEETH_ROWS.flat());

const teethOf = (zone: string | null): string[] => (zone ?? '').match(/\d{2}/g)?.filter((tooth) => ALL_TEETH.has(tooth)) ?? [];

const dayOf = (iso: string | null) =>
  iso ? new Date(iso).toLocaleDateString('es-PE', { day: 'numeric', month: 'short', year: 'numeric' }).replace(/\./g, '') : '—';
import {
  INSTALLMENT_STATUS_BADGES,
  QUOTE_STATUS_BADGES,
  type QuoteRow,
  type QuotesBoardData,
} from '../quotes';

interface Props {
  quote: QuoteRow | null;
  currency: string;
  can: QuotesBoardData['can'];
  onClose: () => void;
  onEdit: (quote: QuoteRow) => void;
  onOpenCharge: (saleId: string, amount?: number) => void;
  onCancelAccepted: (quote: QuoteRow) => void;
  onUpdated: (data: QuotesBoardData, notice: string) => void;
  onSchedule?: (quote: QuoteRow, serviceId: string | null) => void;
}

const sectionTitle = 'text-[11px] font-bold text-slate-400 uppercase tracking-wider';

export default function QuoteDetailDrawer({
  quote,
  currency,
  can,
  onClose,
  onEdit,
  onOpenCharge,
  onCancelAccepted,
  onUpdated,
  onSchedule,
}: Props) {
  const { user, can: allowed, hasModule } = useAuth();
  const [busy, setBusy] = useState(false);
  const [downloading, setDownloading] = useState(false);
  const [error, setError] = useState('');
  const [manual, setManual] = useState<{ itemId: string; note: string } | null>(null);
  const [rejecting, setRejecting] = useState<string | null>(null);

  if (!quote) {
    return (
      <FormDrawer isOpen={false} onClose={onClose} title="Presupuesto" icon={<FileText size={20} />}>
        <div />
      </FormDrawer>
    );
  }

  const changeStatus = async (status: QuoteRow['status'], label: string, note?: string) => {
    try {
      setBusy(true);
      setError('');
      const res = await api.post<QuotesBoardData>(`/quotes/${quote.id}/status`, { status, ...(note ? { note } : {}) });
      setRejecting(null);
      onUpdated(res.data, label);
    } catch (err) {
      setError(apiError(err, 'No se pudo cambiar el estado del presupuesto.'));
    } finally {
      setBusy(false);
    }
  };

  const discountSession = async () => {
    if (!manual) return;
    if (!manual.note.trim()) {
      setError('Escribe por qué se descuenta la sesión sin cita.');
      return;
    }

    try {
      setBusy(true);
      setError('');
      const res = await api.post<QuotesBoardData>(`/quotes/${quote.id}/items/${manual.itemId}/sessions`, { note: manual.note.trim() });
      onUpdated(res.data, 'Se descontó una sesión del plan.');
      setManual(null);
    } catch (err) {
      setError(apiError(err, 'No se pudo descontar la sesión.'));
    } finally {
      setBusy(false);
    }
  };

  const handleChargeQuote = async () => {
    if (quote.sale_id) {
      onOpenCharge(quote.sale_id);
      return;
    }

    try {
      setBusy(true);
      setError('');
      const res = await api.post<QuotesBoardData>(`/quotes/${quote.id}/sale`);
      onUpdated(res.data, 'Venta generada para el presupuesto.');
      if (res.data.created_sale_id) {
        onOpenCharge(res.data.created_sale_id);
      }
    } catch (err) {
      setError(apiError(err, 'No se pudo preparar el cobro del presupuesto.'));
    } finally {
      setBusy(false);
    }
  };

  const downloadPdf = async () => {
    try {
      setDownloading(true);
      setError('');
      const res = await api.get(`/quotes/${quote.id}/pdf`, { responseType: 'blob' });
      const url = URL.createObjectURL(res.data);
      const link = document.createElement('a');
      link.href = url;
      link.download = `Presupuesto ${quote.code} ${quote.customer_name}`.trim() + '.pdf';
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
    } catch {
      setError('No se pudo descargar el presupuesto.');
    } finally {
      setDownloading(false);
    }
  };

  const canModifyLines = quote.status === 'draft' || quote.status === 'sent';
  const step = stepOf(quote);
  const nextItem = quote.items.find((item) => item.item_type === 'service' && item.sessions_total > item.sessions_used) ?? quote.items.find((item) => item.sessions_total > item.sessions_used) ?? null;
  const canSchedule = Boolean(onSchedule) && quote.status === 'accepted' && nextItem !== null && hasModule('agenda') && allowed('agenda.create');
  const teethByLine = user?.tenant?.business_type !== 'dental' ? [] : quote.items.map((item) => ({ item, teeth: teethOf(item.zone) })).filter((line) => line.teeth.length > 0);
  const marked = new Map<string, string[]>();
  teethByLine.forEach(({ item, teeth }) => teeth.forEach((tooth) => marked.set(tooth, [...(marked.get(tooth) ?? []), item.description])));

  return (
    <FormDrawer
      isOpen={Boolean(quote)}
      onClose={onClose}
      title={`${quote.code} · ${quote.title || 'Plan de tratamiento'}`}
      subtitle={[quote.customer_name, quote.dependent_name, quote.professional_name ? `Atiende: ${quote.professional_name}` : null, quote.branch_name].filter(Boolean).join(' · ')}
      icon={<FileText size={20} />}
    >
      <div className="space-y-5 pb-4">
        <ErrorAlert message={error} />

        {step >= 0 ? (
          <ol className="flex items-start" data-quote-steps aria-label="Pasos del presupuesto">
            {STEPS.map((label, index) => (
              <li key={label} className="flex-1 flex flex-col items-center gap-1 relative" aria-current={index === step ? 'step' : undefined}>
                {index > 0 && <span className={`absolute top-3 right-1/2 w-full h-0.5 ${index <= step ? 'bg-brand-accent' : 'bg-slate-200'}`} />}
                <span className={`relative z-10 w-6 h-6 rounded-full flex items-center justify-center text-[11px] font-bold border-2 ${index < step ? 'bg-brand-accent border-brand-accent text-white' : index === step ? 'bg-white border-brand-accent text-brand-ink' : 'bg-white border-slate-200 text-slate-400'}`}>
                  {index < step ? <Check size={12} /> : index + 1}
                </span>
                <span className={`text-[10px] sm:text-[11px] text-center leading-tight ${index === step ? 'font-bold text-brand-ink' : 'text-slate-500'}`}>{label}</span>
              </li>
            ))}
          </ol>
        ) : (
          <p className="text-xs text-slate-600 bg-slate-50 border border-slate-200 rounded-xl px-3 py-2" data-quote-steps="closed">Este presupuesto quedó {quote.status_label.toLowerCase()}: ya no avanza.</p>
        )}

        <section className="rounded-2xl border border-slate-200 p-4 space-y-3 bg-slate-50/60">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="flex items-center gap-2">
              <Badge tone={QUOTE_STATUS_BADGES[quote.status]} bold>
                {quote.status_label}
              </Badge>
              {quote.valid_until && (
                <span className="text-xs text-slate-500">
                  Válido hasta: <strong>{quote.valid_until}</strong>
                </span>
              )}
            </div>

            <div className="flex flex-wrap gap-2">
              <button
                type="button"
                data-action="quote-pdf"
                disabled={downloading}
                onClick={downloadPdf}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-50"
              >
                <Download size={13} /> {downloading ? 'Preparando…' : 'Descargar PDF'}
              </button>
              {canModifyLines && can.edit && (
                <button
                  type="button"
                  onClick={() => onEdit(quote)}
                  className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 transition-colors"
                >
                  <Edit2 size={13} /> Editar plan
                </button>
              )}
            </div>
          </div>

          {quote.sessions_total > 0 && (
            <div className="space-y-1.5 pt-2 border-t border-slate-200/70">
              <div className="flex items-center justify-between text-xs">
                <span className="font-bold text-brand-ink">
                  Avance clínico: {quote.sessions_used} de {quote.sessions_total} sesiones realizadas
                </span>
                <span className="font-black text-brand-accent">{quote.progress_percent}%</span>
              </div>
              <div className="w-full h-2 rounded-full bg-slate-200 overflow-hidden">
                <div
                  className="h-full bg-brand-accent transition-all"
                  style={{ width: `${quote.progress_percent}%` }}
                />
              </div>
            </div>
          )}

          {can.edit && (
            <div className="flex flex-wrap items-center gap-2 pt-2">
              {quote.status === 'draft' && (
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => changeStatus('sent', 'Presupuesto marcado como enviado al cliente.')}
                  className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold rounded-lg border border-sky-200 bg-sky-50 text-sky-800 hover:bg-sky-100 transition-colors disabled:opacity-50"
                >
                  <Send size={13} /> Marcar enviado
                </button>
              )}

              {(quote.status === 'draft' || quote.status === 'sent') && (
                <>
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() => changeStatus('accepted', 'Presupuesto aprobado.')}
                    className="inline-flex items-center gap-1.5 px-3.5 py-1.5 text-xs font-bold rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 transition-colors disabled:opacity-50"
                  >
                    <CheckCircle2 size={14} /> Aprobar plan
                  </button>
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() => setRejecting('')}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold rounded-lg border border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100 transition-colors disabled:opacity-50"
                  >
                    <XCircle size={13} /> Rechazar
                  </button>
                  {rejecting !== null && (
                    <div className="w-full space-y-2" data-reject-reason>
                      <input
                        autoFocus
                        maxLength={255}
                        aria-label="Motivo del rechazo"
                        placeholder="¿Por qué lo rechaza? Por ejemplo: le pareció caro"
                        className={inputClass}
                        value={rejecting}
                        onChange={(e) => setRejecting(e.target.value)}
                      />
                      <div className="flex gap-2">
                        <button type="button" disabled={busy || !rejecting.trim()} onClick={() => changeStatus('rejected', 'Presupuesto marcado como rechazado.', rejecting.trim())} className="px-3 py-1.5 text-xs font-bold rounded-lg bg-rose-600 text-white hover:bg-rose-700 transition-colors disabled:opacity-50">Confirmar rechazo</button>
                        <button type="button" onClick={() => setRejecting(null)} className="px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-600 hover:bg-slate-50">Volver</button>
                      </div>
                    </div>
                  )}
                </>
              )}

              {canSchedule && (
                <button
                  type="button"
                  data-action="schedule-next-session"
                  disabled={busy}
                  onClick={() => onSchedule!(quote, nextItem!.item_type === 'service' ? nextItem!.reference_id : null)}
                  className="inline-flex items-center gap-1.5 px-3.5 py-1.5 text-xs font-bold rounded-lg border border-brand-soft bg-white text-brand-ink hover:bg-brand-soft/10 transition-colors disabled:opacity-50"
                >
                  <CalendarPlus size={14} className="text-brand-accent" /> Agendar la siguiente sesión
                </button>
              )}

              {can.charge && quote.status === 'accepted' && (
                <button
                  type="button"
                  disabled={busy}
                  onClick={handleChargeQuote}
                  className="inline-flex items-center gap-1.5 px-3.5 py-1.5 text-xs font-bold rounded-lg bg-brand text-white hover:bg-brand-hover transition-colors disabled:opacity-50"
                >
                  <Wallet size={14} /> {quote.sale_id ? `Ver / Cobrar venta (${quote.sale_code})` : 'Pasar a cobro'}
                </button>
              )}

              {can.delete && !['cancelled', 'rejected', 'expired'].includes(quote.status) && (
                <button
                  type="button"
                  disabled={busy}
                  data-cancel-quote
                  onClick={() => (quote.status === 'accepted' ? onCancelAccepted(quote) : changeStatus('cancelled', 'Presupuesto anulado.'))}
                  className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-500 hover:text-rose-700 hover:bg-rose-50 transition-colors ml-auto disabled:opacity-50"
                >
                  <Ban size={13} /> Anular
                </button>
              )}
            </div>
          )}
        </section>

        {quote.status === 'accepted' && !quote.plan && !quote.sale_id && quote.sessions_total > 0 && (
          <p className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2" data-quote-no-sale>
            El plan de tratamiento se crea al pasar a cobro: sin la venta por cobrar no se pueden usar sus sesiones.
          </p>
        )}

        {quote.plan && (
          <section className="rounded-2xl border border-slate-200 px-4 py-3 flex items-start gap-3" data-quote-plan>
            <span className="p-2 rounded-lg shrink-0 bg-brand-soft/20 text-brand-ink"><Boxes size={16} /></span>
            <div className="text-xs text-slate-600 space-y-0.5">
              <div className="flex flex-wrap items-center gap-2">
                <span className="font-bold text-brand-ink text-sm">Plan de tratamiento {quote.plan.code}</span>
                <Badge tone={quote.plan.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'} bold>{quote.plan.status_label}</Badge>
              </div>
              {quote.plan.status === 'active' && (
                <>
                  <span className="block">Vigente hasta el {dayOf(quote.plan.expires_at)}.</span>
                  <span className="block">Al agendar o cobrar una cita de este plan, elige &quot;Usar el plan&quot;: la sesión se descuenta sola cuando la cita queda atendida y no se cobra aparte.</span>
                </>
              )}
            </div>
          </section>
        )}

        {quote.sale_id && (
          <section className="rounded-2xl border border-brand-soft bg-brand-soft/10 px-4 py-3 flex items-center justify-between gap-3">
            <div className="text-xs">
              <div className="flex items-center gap-2">
                <span className="font-bold text-brand-ink text-sm">Venta vinculada: {quote.sale_code}</span>
                {quote.sale_status && (
                  <Badge tone={SALE_BADGES[quote.sale_status as keyof typeof SALE_BADGES] ?? 'bg-slate-100 text-slate-700'} bold>
                    {quote.sale_status_label}
                  </Badge>
                )}
              </div>
              <span className="block text-slate-600 mt-0.5">
                Pagado: <strong>{money(quote.sale_paid, currency)}</strong> · Saldo pendiente: <strong>{money(quote.sale_balance, currency)}</strong>
              </span>
            </div>

            {can.charge && (
              <button
                type="button"
                onClick={() => onOpenCharge(quote.sale_id!)}
                className="shrink-0 px-3.5 py-2 text-xs font-bold rounded-xl bg-brand text-white hover:bg-brand-hover transition-colors"
              >
                Cobrar / Ver pagos
              </button>
            )}
          </section>
        )}

        <section className="rounded-2xl border border-slate-200 overflow-hidden">
          <div className="px-4 py-3 bg-slate-50/80 border-b border-slate-100 flex items-center justify-between">
            <span className={sectionTitle}>Detalle del tratamiento ({quote.items.length})</span>
            <span className="text-xs font-bold text-brand-ink">Total: {money(quote.total, currency)}</span>
          </div>

          <div className="divide-y divide-slate-100">
            {quote.items.map((item) => (
              <div key={item.id} className="p-4 space-y-2 text-sm">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <div className="flex flex-wrap items-center gap-1.5">
                      <span className="font-bold text-slate-800">
                        {item.quantity > 1 ? `${item.quantity} × ` : ''}
                        {item.description}
                      </span>
                      <Badge tone="bg-slate-100 text-slate-600">{item.item_type_label}</Badge>
                      {item.zone && <Badge tone="bg-brand-soft/25 text-brand-ink" bold>{item.zone}</Badge>}
                      {item.phase && <Badge tone="bg-violet-50 text-violet-700">{item.phase}</Badge>}
                    </div>
                    {item.discount > 0 && (
                      <span className="block text-xs text-emerald-700 mt-0.5">
                        Precio unit.: {money(item.unit_price, currency)} · Descuento: −{money(item.discount, currency)}
                      </span>
                    )}
                  </div>
                  <span className="font-black text-brand-ink tabular-nums">{money(item.total, currency)}</span>
                </div>

                {item.sessions_total > 0 && (
                  <div className="space-y-2 pt-1">
                    <div className="flex items-center justify-between gap-3">
                      <span className="text-xs font-semibold text-slate-600">
                        Sesiones: <strong className="text-brand-ink">{item.sessions_used} / {item.sessions_total}</strong> realizadas
                      </span>

                      {quote.status === 'accepted' && quote.plan?.status === 'active' && can.edit && item.sessions_used < item.sessions_total && manual?.itemId !== item.id && (
                        <button
                          type="button"
                          data-manual-session={item.description}
                          disabled={busy}
                          onClick={() => { setError(''); setManual({ itemId: item.id, note: '' }); }}
                          className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg border border-slate-200 text-slate-700 text-xs font-semibold hover:bg-slate-50 disabled:opacity-40"
                        >
                          <MinusCircle size={12} /> Descontar una sesión sin cita
                        </button>
                      )}
                    </div>

                    {manual?.itemId === item.id && (
                      <div className="rounded-xl border border-slate-200 bg-slate-50 p-3 space-y-2">
                        <label className="block text-xs font-semibold text-slate-700" htmlFor={`manual-${item.id}`}>¿Por qué se descuenta sin cita?</label>
                        <input
                          id={`manual-${item.id}`}
                          className={inputClass}
                          maxLength={255}
                          placeholder="Ej. sesión hecha a domicilio"
                          value={manual.note}
                          onChange={(e) => setManual({ itemId: item.id, note: e.target.value })}
                        />
                        <div className="flex justify-end gap-2">
                          <Button type="button" variant="secondary" onClick={() => setManual(null)}>Cancelar</Button>
                          <Button type="button" variant="primary" disabled={busy} onClick={discountSession}>Descontar</Button>
                        </div>
                      </div>
                    )}
                  </div>
                )}
              </div>
            ))}
          </div>
        </section>

        {teethByLine.length > 0 && (
          <section className="rounded-2xl border border-slate-200 p-4 space-y-3" data-quote-teeth>
            <span className={sectionTitle}>Piezas del tratamiento</span>
            <div>
              <div className="max-w-md mx-auto space-y-2">
                {TEETH_ROWS.map((row, rowIndex) => (
                  <div key={rowIndex} className="flex gap-0.5 sm:gap-1">
                    {row.map((tooth, index) => {
                      const lines = marked.get(tooth);
                      return (
                        <span
                          key={tooth}
                          data-tooth={tooth}
                          data-marked={lines ? 'yes' : 'no'}
                          title={lines ? `Pieza ${tooth}: ${lines.join(', ')}` : `Pieza ${tooth}`}
                          className={`flex-1 min-w-0 h-8 rounded-md border text-[9px] sm:text-[10px] font-bold flex items-center justify-center ${index === 8 ? 'ml-1.5' : ''} ${lines ? 'bg-brand text-white border-brand' : 'bg-white border-slate-200 text-slate-400'}`}
                        >
                          {tooth}
                        </span>
                      );
                    })}
                  </div>
                ))}
              </div>
            </div>
            <ul className="space-y-1 text-xs text-slate-600">
              {teethByLine.map(({ item, teeth }) => (
                <li key={item.id}><span className="font-semibold text-slate-800">{item.description}:</span> piezas {teeth.join(', ')}</li>
              ))}
            </ul>
          </section>
        )}

        {quote.installments.length > 0 && (
          <section className="rounded-2xl border border-slate-200 overflow-hidden">
            <div className="px-4 py-3 bg-slate-50/80 border-b border-slate-100 flex items-center justify-between">
              <span className={sectionTitle}>Cronograma de cuotas ({quote.installments.length})</span>
            </div>
            <div className="divide-y divide-slate-100">
              {quote.installments.map((inst) => (
                <div key={inst.id} className="px-4 py-3 flex items-center justify-between gap-3 text-sm">
                  <div>
                    <span className="font-bold text-slate-800">Cuota #{inst.number}</span>
                    <span className="block text-xs text-slate-500">Vence: {inst.due_date}</span>
                  </div>
                  <div className="flex items-center gap-2.5">
                    <span className="font-bold text-brand-ink tabular-nums">{money(inst.amount, currency)}</span>
                    <Badge tone={INSTALLMENT_STATUS_BADGES[inst.status]} bold>
                      {inst.status_label}
                    </Badge>
                    {can.charge && quote.sale_id && quote.sale_balance > 0 && (inst.status === 'pending' || inst.status === 'overdue') && (
                      <button
                        type="button"
                        data-charge-installment={inst.number}
                        disabled={busy}
                        onClick={() => onOpenCharge(quote.sale_id!, Math.min(inst.amount, quote.sale_balance))}
                        className="px-2.5 py-1 text-xs font-bold rounded-lg bg-brand text-white hover:bg-brand-hover"
                      >
                        Cobrar cuota
                      </button>
                    )}
                  </div>
                </div>
              ))}
            </div>
          </section>
        )}

        {quote.notes && (
          <section className="rounded-2xl border border-slate-200 p-4 space-y-1">
            <span className={sectionTitle}>Observaciones e indicaciones</span>
            <p className="text-sm text-slate-700 whitespace-pre-line">{quote.notes}</p>
          </section>
        )}

        {quote.history.length > 0 && (
          <section className="rounded-2xl border border-slate-200 overflow-hidden">
            <div className="px-4 py-3 bg-slate-50/80 border-b border-slate-100">
              <span className={sectionTitle}>Historial del presupuesto</span>
            </div>
            <ul className="divide-y divide-slate-100 text-xs">
              {quote.history.map((h) => (
                <li key={h.id} className="px-4 py-2.5 flex items-center justify-between gap-3">
                  <div>
                    <span className="font-bold text-slate-800">{h.to_status_label}</span>
                    {h.note && <span className="text-slate-600"> · {h.note}</span>}
                    {h.actor && <span className="block text-slate-400">Por {h.actor}</span>}
                  </div>
                  <span className="text-slate-400 shrink-0">{momentOf(h.created_at)}</span>
                </li>
              ))}
            </ul>
          </section>
        )}

        <div className="flex justify-end pt-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Cerrar
          </Button>
        </div>
      </div>
    </FormDrawer>
  );
}
